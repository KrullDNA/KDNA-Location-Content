<?php
/**
 * Taxonomy term region/language visibility.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_Term_Visibility
 *
 * Mirrors the post-visibility pattern (KDNA_RC_Post_Visibility) for terms
 * in a public taxonomy. Editors tick which regions a given term should
 * appear for; terms with no boxes ticked appear everywhere. The visitor's
 * region resolves the same way menu visibility does: URL routing query
 * var first, then the standard detector chain.
 *
 * Storage: termmeta key _kdna_rc_term_regions (array of region slugs).
 *
 * Front-end filtering: get_terms_args is filtered to add a meta_query
 * dropping terms whose region restriction excludes the visitor. Two
 * properties this gives us "for free":
 *   - JetSmartFilters taxonomy filters that source their option list
 *     from get_terms() lose the excluded term outright — no need to
 *     special-case JSF.
 *   - Any Elementor/JE listing that asks for terms (term grids, post
 *     tax filters, archive widget) drops the term too.
 *
 * Direct visits to a hidden term archive (e.g. /indications/hair-removal/)
 * are NOT 404'd by this filter — core uses get_term_by(), not
 * get_terms(), to resolve a slug. A future patch can add a 404 hook if
 * needed; for now the URL still resolves but the term simply does not
 * appear in any list.
 */
class KDNA_RC_Term_Visibility {

	const META_REGIONS = '_kdna_rc_term_regions';

	/**
	 * Wire admin UI for every public taxonomy + the front-end term filter.
	 *
	 * @return void
	 */
	public function init() {
		if ( is_admin() ) {
			add_action( 'admin_init', array( $this, 'register_admin_ui' ) );
		}

		// Apply to every get_terms() call on the front end. JSF, JE,
		// Elementor and core widgets all bottom out on get_terms().
		add_filter( 'get_terms_args', array( $this, 'filter_terms_args' ), 10, 2 );
	}

	/**
	 * Hook the edit/add-term forms and save handlers for every public
	 * taxonomy. Deferred until admin_init so all CPTs/taxonomies are
	 * registered before we enumerate.
	 *
	 * @return void
	 */
	public function register_admin_ui() {
		$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
		foreach ( $taxonomies as $taxonomy ) {
			add_action( $taxonomy . '_edit_form_fields', array( $this, 'render_edit_fields' ), 10, 2 );
			add_action( $taxonomy . '_add_form_fields', array( $this, 'render_add_fields' ), 10, 1 );
			add_action( 'edited_' . $taxonomy, array( $this, 'save_term_fields' ), 10, 2 );
			add_action( 'created_' . $taxonomy, array( $this, 'save_term_fields' ), 10, 2 );
		}
	}

	/**
	 * Render the region checkbox group on the Edit Term screen
	 * (post-creation view, fields are wrapped in a <table>).
	 *
	 * @param WP_Term $tag      Term object.
	 * @param string  $taxonomy Taxonomy slug.
	 * @return void
	 */
	public function render_edit_fields( $tag, $taxonomy ) {
		unset( $taxonomy );
		$regions = $this->configured_regions();
		if ( empty( $regions ) ) {
			return;
		}

		$saved = $this->get_term_regions( (int) $tag->term_id );
		wp_nonce_field( 'kdna_rc_term_visibility_' . $tag->term_id, 'kdna_rc_term_visibility_nonce' );
		?>
		<tr class="form-field kdna-rc-term-visibility-row">
			<th scope="row"><label><?php esc_html_e( 'Show in regions', 'kdna-regional-content' ); ?></label></th>
			<td>
				<?php foreach ( $regions as $region ) : ?>
					<label style="display:inline-block;margin-right:14px;">
						<input type="checkbox" name="kdna_rc_term_regions[]" value="<?php echo esc_attr( $region['slug'] ); ?>" <?php checked( in_array( $region['slug'], $saved, true ) ); ?> />
						<?php echo esc_html( $region['name'] ); ?>
					</label>
				<?php endforeach; ?>
				<p class="description"><?php esc_html_e( 'Tick the regions allowed to see this term. Leave all unticked to show everywhere. Hidden terms disappear from filter widgets, term grids and listing queries for visitors outside the allowed regions.', 'kdna-regional-content' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render the region checkbox group on the Add Term screen
	 * (pre-creation view, fields are flat <div>s).
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return void
	 */
	public function render_add_fields( $taxonomy ) {
		unset( $taxonomy );
		$regions = $this->configured_regions();
		if ( empty( $regions ) ) {
			return;
		}
		wp_nonce_field( 'kdna_rc_term_visibility_new', 'kdna_rc_term_visibility_nonce' );
		?>
		<div class="form-field kdna-rc-term-visibility-row">
			<label><?php esc_html_e( 'Show in regions', 'kdna-regional-content' ); ?></label>
			<?php foreach ( $regions as $region ) : ?>
				<label style="display:inline-block;margin-right:14px;">
					<input type="checkbox" name="kdna_rc_term_regions[]" value="<?php echo esc_attr( $region['slug'] ); ?>" />
					<?php echo esc_html( $region['name'] ); ?>
				</label>
			<?php endforeach; ?>
			<p class="description"><?php esc_html_e( 'Tick the regions allowed to see this term. Leave all unticked to show everywhere.', 'kdna-regional-content' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Persist region selection on edited/created term.
	 *
	 * Hooked once per taxonomy at edited_{taxonomy} / created_{taxonomy}.
	 * Nonces differ between the edit and add forms; accept either.
	 *
	 * @param int $term_id Term ID.
	 * @return void
	 */
	public function save_term_fields( $term_id ) {
		$term_id = (int) $term_id;
		if ( $term_id <= 0 ) {
			return;
		}

		$nonce = isset( $_POST['kdna_rc_term_visibility_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['kdna_rc_term_visibility_nonce'] ) ) : '';
		if ( '' === $nonce ) {
			return;
		}
		$is_edit = wp_verify_nonce( $nonce, 'kdna_rc_term_visibility_' . $term_id );
		$is_add  = wp_verify_nonce( $nonce, 'kdna_rc_term_visibility_new' );
		if ( ! $is_edit && ! $is_add ) {
			return;
		}

		$valid = array_map(
			static function ( $r ) {
				return isset( $r['slug'] ) ? (string) $r['slug'] : '';
			},
			$this->configured_regions()
		);

		$posted = array();
		if ( isset( $_POST['kdna_rc_term_regions'] ) && is_array( $_POST['kdna_rc_term_regions'] ) ) {
			foreach ( $_POST['kdna_rc_term_regions'] as $slug ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$slug = sanitize_key( wp_unslash( $slug ) );
				if ( '' !== $slug && in_array( $slug, $valid, true ) && ! in_array( $slug, $posted, true ) ) {
					$posted[] = $slug;
				}
			}
		}

		if ( empty( $posted ) ) {
			delete_term_meta( $term_id, self::META_REGIONS );
		} else {
			update_term_meta( $term_id, self::META_REGIONS, $posted );
		}
	}

	/**
	 * Append a meta_query clause to get_terms() that drops every term
	 * whose region restriction excludes the visitor.
	 *
	 * Three skip conditions:
	 *   - admin screens (editors must always see every term),
	 *   - REST requests (JE/Elementor edit panels query terms via REST),
	 *   - no resolved visitor region (treat as "show everything").
	 *
	 * Clause: term either has no _kdna_rc_term_regions meta at all, OR
	 * the value (stored as a serialized array) contains the visitor's
	 * region slug. WP's meta_query LIKE comparator handles the
	 * "needle in serialized array" check because slugs are surrounded
	 * by quotes in the serialized string.
	 *
	 * @param array $args       get_terms args.
	 * @param array $taxonomies Taxonomies being queried (unused).
	 * @return array
	 */
	public function filter_terms_args( $args, $taxonomies = array() ) {
		unset( $taxonomies );
		if ( is_admin() ) {
			return $args;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return $args;
		}

		$region = $this->current_region();
		if ( '' === $region ) {
			return $args;
		}

		$visibility_clause = array(
			'relation' => 'OR',
			array(
				'key'     => self::META_REGIONS,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => self::META_REGIONS,
				'value'   => '"' . $region . '"',
				'compare' => 'LIKE',
			),
		);

		if ( ! empty( $args['meta_query'] ) && is_array( $args['meta_query'] ) ) {
			$args['meta_query'] = array(
				'relation' => 'AND',
				$args['meta_query'],
				$visibility_clause,
			);
		} else {
			$args['meta_query'] = $visibility_clause;
		}
		return $args;
	}

	/**
	 * Resolve the visitor's region: URL routing query var first
	 * (so /nz/ always wins), then the standard detector chain
	 * (cookie → geoip → default).
	 *
	 * @return string
	 */
	private function current_region() {
		global $wp;
		if ( $wp instanceof WP && ! empty( $wp->query_vars['kdna_region'] ) ) {
			return sanitize_key( (string) $wp->query_vars['kdna_region'] );
		}
		if ( class_exists( 'KDNA_RC_Detector' ) ) {
			$result = ( new KDNA_RC_Detector() )->resolve_visitor_region();
			if ( is_array( $result ) && ! empty( $result['slug'] ) ) {
				return (string) $result['slug'];
			}
		}
		return '';
	}

	/**
	 * Read region restriction list for a term.
	 *
	 * @param int $term_id Term ID.
	 * @return array<int,string>
	 */
	public function get_term_regions( $term_id ) {
		$raw = get_term_meta( (int) $term_id, self::META_REGIONS, true );
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
}
