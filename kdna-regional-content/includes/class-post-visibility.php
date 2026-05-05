<?php
/**
 * Post-level region visibility (meta box and post meta storage).
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_Post_Visibility
 *
 * Lets editors restrict an entire post (or any selected post type) to one or
 * more regions. The selected slugs are stored on a single post meta entry
 * and read back by the front-end (and by the JetEngine integration in
 * KDNA_RC_JetEngine_Integration) to drive the data-kdna-show-in attribute.
 *
 * Posts with no meta or an empty array show everywhere; posts with one or
 * more region slugs show only to visitors whose resolved region is in the
 * list.
 */
class KDNA_RC_Post_Visibility {

	/**
	 * Post meta key holding the restriction list.
	 *
	 * @var string
	 */
	const META_KEY = '_kdna_rc_regions';

	/**
	 * Settings key holding the list of post types that participate.
	 *
	 * @var string
	 */
	const SETTING_KEY = 'restricted_post_types';

	/**
	 * Nonce action name for the meta box save handler.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'kdna_rc_post_visibility';

	/**
	 * Nonce field name posted with the meta box.
	 *
	 * @var string
	 */
	const NONCE_NAME = 'kdna_rc_post_visibility_nonce';

	/**
	 * Wire up admin hooks.
	 *
	 * Meta box registration runs on add_meta_boxes; save runs on save_post.
	 * This class is admin-only because it only mutates post meta from the
	 * editor UI, but the helper get_post_regions() is callable everywhere.
	 *
	 * @return void
	 */
	public function init() {
		if ( is_admin() ) {
			add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
			add_action( 'save_post', array( $this, 'save_meta' ), 10, 2 );
		}

		// Tag every restricted post with kdna-rc-post-{ID} via post_class()
		// so the front-end JS has a reliable selector regardless of which
		// listing widget is rendering the loop. Runs on the front end only;
		// admin list tables use a separate get_post_class() codepath that
		// does not need our marker.
		if ( ! is_admin() ) {
			add_filter( 'post_class', array( $this, 'add_post_class' ), 10, 3 );
		}
	}

	/**
	 * Add a kdna-rc-post-{ID} class to restricted posts.
	 *
	 * Returns the array unchanged for posts with no _kdna_rc_regions meta so
	 * we do not pollute every post's class list.
	 *
	 * @param array $classes Existing classes.
	 * @param array $class   Extra classes passed to post_class() (unused).
	 * @param int   $post_id Post ID.
	 * @return array
	 */
	public function add_post_class( $classes, $class, $post_id ) {
		unset( $class );
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return $classes;
		}
		$regions = self::get_post_regions( $post_id );
		if ( ! empty( $regions ) ) {
			$classes[] = 'kdna-rc-post-' . $post_id;
		}
		return $classes;
	}

	/**
	 * Read the list of region slugs restricting a post.
	 *
	 * Always returns an array. An empty array means the post shows in every
	 * region (no restriction in effect).
	 *
	 * @param int $post_id Post ID.
	 * @return array<int,string>
	 */
	public static function get_post_regions( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return array();
		}
		$value = get_post_meta( $post_id, self::META_KEY, true );
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'sanitize_key', $value ) ) );
	}

	/**
	 * Get the configured list of post type slugs that participate.
	 *
	 * @return array<int,string>
	 */
	public static function configured_post_types() {
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		$types    = isset( $settings[ self::SETTING_KEY ] ) ? (array) $settings[ self::SETTING_KEY ] : array();
		$clean    = array();
		foreach ( $types as $slug ) {
			$slug = sanitize_key( $slug );
			if ( $slug && post_type_exists( $slug ) ) {
				$clean[] = $slug;
			}
		}
		return array_values( array_unique( $clean ) );
	}

	/**
	 * Build a map of post IDs to their region restrictions.
	 *
	 * Returns every post that has a non-empty _kdna_rc_regions meta entry.
	 * Used by the front-end script to apply data-kdna-show-in to listing
	 * items at runtime. Single SELECT against an indexed meta_key, so this
	 * runs cheaply on every front-end page load with no caching needed.
	 *
	 * @return array<int,array<int,string>>
	 */
	public static function get_restricted_posts_map() {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''",
				self::META_KEY
			)
		);

		$map = array();
		foreach ( (array) $rows as $row ) {
			$value = maybe_unserialize( $row->meta_value );
			if ( ! is_array( $value ) || empty( $value ) ) {
				continue;
			}
			$slugs = array_values( array_filter( array_map( 'sanitize_key', $value ) ) );
			if ( empty( $slugs ) ) {
				continue;
			}
			$map[ (int) $row->post_id ] = $slugs;
		}

		return $map;
	}

	/**
	 * Register the Regional Visibility meta box on every configured post type.
	 *
	 * @return void
	 */
	public function register_meta_box() {
		foreach ( self::configured_post_types() as $post_type ) {
			add_meta_box(
				'kdna_rc_post_visibility',
				__( 'Regional Visibility', 'kdna-regional-content' ),
				array( $this, 'render_meta_box' ),
				$post_type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Render the meta box body.
	 *
	 * @param WP_Post $post Current post being edited.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$regions  = ( new KDNA_RC_Regions() )->get_all();
		$selected = self::get_post_regions( $post->ID );
		$selected_index = array_flip( $selected );

		echo '<p class="description">' . esc_html__( 'Tick the regions allowed to see this post. Posts with nothing ticked show everywhere.', 'kdna-regional-content' ) . '</p>';

		if ( empty( $regions ) ) {
			$url = admin_url( 'admin.php?page=kdna-regional-content&tab=regions' );
			echo '<p>';
			printf(
				/* translators: %s: link to the Regions tab. */
				esc_html__( 'No regions configured yet. Add some on the %s.', 'kdna-regional-content' ),
				'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Regions tab', 'kdna-regional-content' ) . '</a>'
			);
			echo '</p>';
			return;
		}

		echo '<ul class="kdna-rc-postvis-list" style="margin:0; max-height:220px; overflow-y:auto;">';
		foreach ( $regions as $region ) {
			$checked = isset( $selected_index[ $region['slug'] ] ) ? ' checked' : '';
			printf(
				'<li><label><input type="checkbox" name="kdna_rc_regions[]" value="%1$s"%2$s /> %3$s <code style="font-size:11px; color:#6c7079;">%1$s</code></label></li>',
				esc_attr( $region['slug'] ),
				$checked, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal " checked" string above.
				esc_html( $region['name'] )
			);
		}
		echo '</ul>';
	}

	/**
	 * Persist the meta box selection on post save.
	 *
	 * Skips autosaves and revisions, verifies the nonce, and checks the
	 * edit_post capability for the current user against the post being saved.
	 *
	 * @param int     $post_id Post ID being saved.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function save_meta( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! is_object( $post ) || empty( $post->post_type ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, self::configured_post_types(), true ) ) {
			return;
		}
		if ( empty( $_POST[ self::NONCE_NAME ] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$raw   = isset( $_POST['kdna_rc_regions'] ) && is_array( $_POST['kdna_rc_regions'] ) ? wp_unslash( $_POST['kdna_rc_regions'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$valid = array();
		$known = array();
		foreach ( ( new KDNA_RC_Regions() )->get_all() as $region ) {
			$known[ $region['slug'] ] = true;
		}
		foreach ( $raw as $slug ) {
			$slug = sanitize_key( $slug );
			if ( '' !== $slug && isset( $known[ $slug ] ) && ! in_array( $slug, $valid, true ) ) {
				$valid[] = $slug;
			}
		}

		if ( empty( $valid ) ) {
			delete_post_meta( $post_id, self::META_KEY );
		} else {
			update_post_meta( $post_id, self::META_KEY, $valid );
		}
	}
}
