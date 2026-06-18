<?php
/**
 * Nav menu item region/language visibility.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_Menu_Visibility
 *
 * Mirrors the post-visibility pattern (KDNA_RC_Post_Visibility) for items in
 * a WordPress nav menu. Editors tick which regions (and, when configured,
 * which languages) a given menu item is allowed to appear for; items with no
 * restriction show everywhere. The visitor's resolved region / language is
 * read from the same chain the rest of the plugin uses (URL override → cookie
 * → GeoIP → default).
 *
 * Storage uses two postmeta entries on the nav_menu_item posts:
 *   _kdna_rc_menu_regions   : array of region slugs
 *   _kdna_rc_menu_languages : array of language slugs
 *
 * Front-end filtering hangs off wp_nav_menu_objects so it sees the final
 * resolved item list, after parents/children have already been linked.
 * Items hidden by visibility cascade — any direct children of a hidden item
 * are also removed so the menu does not present orphaned sub-items.
 */
class KDNA_RC_Menu_Visibility {

	const META_REGIONS   = '_kdna_rc_menu_regions';
	const META_LANGUAGES = '_kdna_rc_menu_languages';

	/**
	 * Wire admin UI and front-end filter.
	 *
	 * @return void
	 */
	public function init() {
		if ( is_admin() ) {
			add_action( 'wp_nav_menu_item_custom_fields', array( $this, 'render_item_fields' ), 10, 2 );
			add_action( 'wp_update_nav_menu_item', array( $this, 'save_item_fields' ), 10, 3 );
		}
		add_filter( 'wp_nav_menu_objects', array( $this, 'filter_menu_objects' ), 10, 2 );
	}

	/**
	 * Render the Region/Language checkbox groups inside a menu item card
	 * in the Appearance → Menus editor.
	 *
	 * @param int   $item_id Menu item ID.
	 * @param mixed $item    Menu item object (unused; kept for hook signature compatibility).
	 * @return void
	 */
	public function render_item_fields( $item_id, $item = null ) {
		unset( $item );

		$regions   = $this->configured_regions();
		$languages = $this->configured_languages();
		if ( empty( $regions ) && empty( $languages ) ) {
			return;
		}

		$saved_regions   = $this->get_item_regions( (int) $item_id );
		$saved_languages = $this->get_item_languages( (int) $item_id );

		wp_nonce_field( 'kdna_rc_menu_item_' . $item_id, 'kdna_rc_menu_item_nonce' );

		if ( ! empty( $regions ) ) {
			echo '<p class="description description-wide kdna-rc-menu-visibility-regions">';
			echo '<strong>' . esc_html__( 'Show in regions', 'kdna-regional-content' ) . '</strong><br />';
			echo '<span class="description">' . esc_html__( 'Tick the regions allowed to see this menu item. Leave all unticked to show everywhere.', 'kdna-regional-content' ) . '</span><br />';
			foreach ( $regions as $region ) {
				printf(
					'<label style="display:inline-block;margin-right:12px;margin-top:4px;"><input type="checkbox" name="kdna_rc_menu_regions[%1$d][]" value="%2$s"%3$s /> %4$s</label>',
					(int) $item_id,
					esc_attr( $region['slug'] ),
					checked( in_array( $region['slug'], $saved_regions, true ), true, false ),
					esc_html( $region['name'] )
				);
			}
			echo '</p>';
		}

		if ( ! empty( $languages ) ) {
			echo '<p class="description description-wide kdna-rc-menu-visibility-languages">';
			echo '<strong>' . esc_html__( 'Show in languages', 'kdna-regional-content' ) . '</strong><br />';
			echo '<span class="description">' . esc_html__( 'Tick the languages allowed to see this menu item. Leave all unticked to show in any language.', 'kdna-regional-content' ) . '</span><br />';
			foreach ( $languages as $language ) {
				printf(
					'<label style="display:inline-block;margin-right:12px;margin-top:4px;"><input type="checkbox" name="kdna_rc_menu_languages[%1$d][]" value="%2$s"%3$s /> %4$s</label>',
					(int) $item_id,
					esc_attr( $language['slug'] ),
					checked( in_array( $language['slug'], $saved_languages, true ), true, false ),
					esc_html( $language['name'] )
				);
			}
			echo '</p>';
		}
	}

	/**
	 * Persist the region/language selections when a menu item is saved.
	 *
	 * Hooked at wp_update_nav_menu_item which fires once per item during
	 * menu save. The hidden checkbox-presence trick is unnecessary here
	 * because the entire item record posts as part of menu submission;
	 * a missing key genuinely means "no restriction" (empty array).
	 *
	 * @param int   $menu_id           Parent menu ID (unused).
	 * @param int   $menu_item_db_id   Menu item post ID.
	 * @param array $args              Item args (unused; menu item walker passes posted data via $_POST).
	 * @return void
	 */
	public function save_item_fields( $menu_id, $menu_item_db_id, $args = array() ) {
		unset( $menu_id, $args );

		$menu_item_db_id = (int) $menu_item_db_id;
		if ( $menu_item_db_id <= 0 ) {
			return;
		}

		$nonce = isset( $_POST['kdna_rc_menu_item_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['kdna_rc_menu_item_nonce'] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'kdna_rc_menu_item_' . $menu_item_db_id ) ) {
			return;
		}

		// Regions
		$valid_region_slugs = array_map(
			static function ( $r ) {
				return isset( $r['slug'] ) ? (string) $r['slug'] : '';
			},
			$this->configured_regions()
		);
		$posted_regions = array();
		if ( isset( $_POST['kdna_rc_menu_regions'][ $menu_item_db_id ] ) && is_array( $_POST['kdna_rc_menu_regions'][ $menu_item_db_id ] ) ) {
			foreach ( $_POST['kdna_rc_menu_regions'][ $menu_item_db_id ] as $slug ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$slug = sanitize_key( wp_unslash( $slug ) );
				if ( '' !== $slug && in_array( $slug, $valid_region_slugs, true ) && ! in_array( $slug, $posted_regions, true ) ) {
					$posted_regions[] = $slug;
				}
			}
		}
		if ( empty( $posted_regions ) ) {
			delete_post_meta( $menu_item_db_id, self::META_REGIONS );
		} else {
			update_post_meta( $menu_item_db_id, self::META_REGIONS, $posted_regions );
		}

		// Languages
		$valid_language_slugs = array_map(
			static function ( $l ) {
				return isset( $l['slug'] ) ? (string) $l['slug'] : '';
			},
			$this->configured_languages()
		);
		$posted_languages = array();
		if ( isset( $_POST['kdna_rc_menu_languages'][ $menu_item_db_id ] ) && is_array( $_POST['kdna_rc_menu_languages'][ $menu_item_db_id ] ) ) {
			foreach ( $_POST['kdna_rc_menu_languages'][ $menu_item_db_id ] as $slug ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$slug = sanitize_key( wp_unslash( $slug ) );
				if ( '' !== $slug && in_array( $slug, $valid_language_slugs, true ) && ! in_array( $slug, $posted_languages, true ) ) {
					$posted_languages[] = $slug;
				}
			}
		}
		if ( empty( $posted_languages ) ) {
			delete_post_meta( $menu_item_db_id, self::META_LANGUAGES );
		} else {
			update_post_meta( $menu_item_db_id, self::META_LANGUAGES, $posted_languages );
		}
	}

	/**
	 * Strip menu items whose region/language restriction does not match
	 * the current visitor. Children of removed items are also dropped so
	 * the menu does not present a dangling sub-item.
	 *
	 * @param array  $items Menu item objects.
	 * @param object $args  wp_nav_menu args (unused).
	 * @return array
	 */
	public function filter_menu_objects( $items, $args = null ) {
		unset( $args );

		if ( empty( $items ) || is_admin() ) {
			return $items;
		}

		$visitor = $this->resolve_visitor();
		$kept    = array();
		$removed = array();

		foreach ( $items as $item ) {
			$parent_id = isset( $item->menu_item_parent ) ? (int) $item->menu_item_parent : 0;
			if ( $parent_id > 0 && isset( $removed[ $parent_id ] ) ) {
				$removed[ (int) $item->ID ] = true;
				continue;
			}

			if ( ! $this->item_visible_to_visitor( (int) $item->ID, $visitor ) ) {
				$removed[ (int) $item->ID ] = true;
				continue;
			}

			$kept[] = $item;
		}

		return $kept;
	}

	/**
	 * Whether the given menu item is visible to the current visitor.
	 *
	 * Items with no restriction always show. Items with a region list
	 * show only when the visitor's region matches; same for language.
	 * When both lists are present, both must match.
	 *
	 * @param int   $item_id Menu item post ID.
	 * @param array $visitor { 'region' => slug, 'language' => slug }.
	 * @return bool
	 */
	private function item_visible_to_visitor( $item_id, array $visitor ) {
		$item_regions   = $this->get_item_regions( $item_id );
		$item_languages = $this->get_item_languages( $item_id );

		if ( ! empty( $item_regions ) ) {
			$visitor_region = isset( $visitor['region'] ) ? (string) $visitor['region'] : '';
			if ( '' === $visitor_region || ! in_array( $visitor_region, $item_regions, true ) ) {
				return false;
			}
		}
		if ( ! empty( $item_languages ) ) {
			$visitor_language = isset( $visitor['language'] ) ? (string) $visitor['language'] : '';
			if ( '' === $visitor_language || ! in_array( $visitor_language, $item_languages, true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Resolve the visitor's region + language using the detector chain.
	 *
	 * Region uses the full KDNA_RC_Detector chain (URL override → cookie →
	 * GeoIP → default). Language reads the URL-routing query var (set by
	 * KDNA_RC_URL_Routing for prefixed URLs) first, then falls back to the
	 * kdna_language cookie — mirroring how the SEO adapters resolve it.
	 *
	 * @return array{region:string,language:string}
	 */
	private function resolve_visitor() {
		$region   = '';
		$language = '';

		if ( class_exists( 'KDNA_RC_Detector' ) ) {
			$result = ( new KDNA_RC_Detector() )->resolve_visitor_region();
			if ( is_array( $result ) && isset( $result['slug'] ) ) {
				$region = (string) $result['slug'];
			}
		}

		global $wp;
		if ( $wp instanceof WP && ! empty( $wp->query_vars['kdna_language'] ) ) {
			$language = sanitize_key( (string) $wp->query_vars['kdna_language'] );
		}
		if ( '' === $language && ! empty( $_COOKIE['kdna_language'] ) ) {
			$language = sanitize_key( wp_unslash( $_COOKIE['kdna_language'] ) );
		}

		return array(
			'region'   => $region,
			'language' => $language,
		);
	}

	/**
	 * Read region restriction list for a menu item.
	 *
	 * @param int $item_id Menu item post ID.
	 * @return array<int,string>
	 */
	public function get_item_regions( $item_id ) {
		$raw = get_post_meta( (int) $item_id, self::META_REGIONS, true );
		return is_array( $raw ) ? array_values( array_filter( array_map( 'sanitize_key', $raw ) ) ) : array();
	}

	/**
	 * Read language restriction list for a menu item.
	 *
	 * @param int $item_id Menu item post ID.
	 * @return array<int,string>
	 */
	public function get_item_languages( $item_id ) {
		$raw = get_post_meta( (int) $item_id, self::META_LANGUAGES, true );
		return is_array( $raw ) ? array_values( array_filter( array_map( 'sanitize_key', $raw ) ) ) : array();
	}

	/**
	 * Configured region list (slug + name) for rendering the checkbox UI.
	 *
	 * @return array<int,array{slug:string,name:string}>
	 */
	private function configured_regions() {
		if ( ! class_exists( 'KDNA_RC_Regions' ) ) {
			return array();
		}
		$out = array();
		foreach ( ( new KDNA_RC_Regions() )->get_all() as $region ) {
			if ( isset( $region['slug'] ) && '' !== $region['slug'] ) {
				$out[] = array(
					'slug' => (string) $region['slug'],
					'name' => isset( $region['name'] ) ? (string) $region['name'] : (string) $region['slug'],
				);
			}
		}
		return $out;
	}

	/**
	 * Configured language list (slug + name) for rendering the checkbox UI.
	 *
	 * @return array<int,array{slug:string,name:string}>
	 */
	private function configured_languages() {
		if ( ! class_exists( 'KDNA_RC_Languages' ) ) {
			return array();
		}
		$out = array();
		foreach ( ( new KDNA_RC_Languages() )->get_all() as $language ) {
			if ( isset( $language['slug'] ) && '' !== $language['slug'] ) {
				$out[] = array(
					'slug' => (string) $language['slug'],
					'name' => isset( $language['name'] ) ? (string) $language['name'] : (string) $language['slug'],
				);
			}
		}
		return $out;
	}
}
