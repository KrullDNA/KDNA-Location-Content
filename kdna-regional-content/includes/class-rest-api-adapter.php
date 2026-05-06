<?php
/**
 * REST API integration: replace serialised Multilingual meta values in
 * REST responses with the language-resolved value matching the request's
 * Accept-Language header.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_Rest_Api_Adapter
 *
 * Hooks rest_prepare_post and rest_prepare_{cpt} for every public
 * post type. When the response carries a multilingual meta field as a
 * serialised array (the Stage 12 storage shape), the array is replaced
 * with the value resolved against the request's Accept-Language header.
 *
 * Opt-out: callers can include ?multilingual=raw on the REST URL to
 * receive the raw serialised array (useful for admin tooling). The
 * admin can also disable the resolver entirely via the General-tab
 * setting "Resolve Multilingual fields in REST API by Accept-Language
 * header", which defaults to on.
 */
class KDNA_RC_Rest_Api_Adapter {

	/**
	 * Wire REST filters across every public post type.
	 *
	 * Runs on rest_api_init so we know the post type list is fully
	 * registered and we can target each one's rest_prepare_{cpt} filter.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_filters' ) );
	}

	/**
	 * Register the filters.
	 *
	 * @return void
	 */
	public function register_filters() {
		if ( ! $this->resolver_enabled() ) {
			return;
		}
		$post_types = get_post_types( array( 'show_in_rest' => true ), 'names' );
		foreach ( $post_types as $type ) {
			add_filter( 'rest_prepare_' . $type, array( $this, 'filter_response' ), 10, 3 );
		}
	}

	/**
	 * Whether the admin has the resolver toggle on.
	 *
	 * Default-on per the brief: opt out by ticking the setting off, not by
	 * leaving it untouched.
	 *
	 * @return bool
	 */
	public function resolver_enabled() {
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		if ( ! is_array( $settings ) || ! array_key_exists( 'rest_resolve_multilingual', $settings ) ) {
			return true;
		}
		return ! empty( $settings['rest_resolve_multilingual'] );
	}

	/**
	 * Replace serialised multilingual values in the REST response.
	 *
	 * Walks the meta block in the response data, finds entries that look
	 * like our storage shape (associative array with a 'default' key),
	 * and replaces them with the language-resolved scalar.
	 *
	 * For Image fields the resolved value is the attachment URL (resolved
	 * via wp_get_attachment_image_url at the registered "full" size) so
	 * external API consumers do not have to chase IDs.
	 *
	 * @param WP_REST_Response $response Response.
	 * @param WP_Post          $post     Post being rendered.
	 * @param WP_REST_Request  $request  Request.
	 * @return WP_REST_Response
	 */
	public function filter_response( $response, $post, $request ) {
		// Opt-out via query param.
		$raw_param = $request instanceof WP_REST_Request ? $request->get_param( 'multilingual' ) : null;
		if ( 'raw' === $raw_param ) {
			return $response;
		}

		$language = $this->resolve_language_from_request( $request );
		if ( '' === $language ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) || ! isset( $data['meta'] ) || ! is_array( $data['meta'] ) ) {
			return $response;
		}

		foreach ( $data['meta'] as $key => $value ) {
			if ( ! KDNA_RC_Multilingual_Query_Helper::is_multilingual_field( $key, $post->post_type ) ) {
				continue;
			}
			$resolved = KDNA_RC_Multilingual_Base::resolve_value( $post->ID, $key, $language );

			// Image fields hold attachment IDs; resolve to URL for REST consumers.
			$field_type = $this->detect_field_type( $key );
			if ( 'kdna_rc_ml_image' === $field_type && is_numeric( $resolved ) ) {
				$attachment_id = (int) $resolved;
				if ( $attachment_id > 0 ) {
					$url             = (string) wp_get_attachment_image_url( $attachment_id, 'full' );
					$data['meta'][ $key ] = array(
						'id'  => $attachment_id,
						'url' => $url,
					);
					continue;
				}
				$data['meta'][ $key ] = '';
				continue;
			}

			$data['meta'][ $key ] = $resolved;
		}

		$response->set_data( $data );
		return $response;
	}

	/**
	 * Resolve the language from the REST request.
	 *
	 * Order: explicit ?lang= query param, Accept-Language header, configured default.
	 * Returns the empty string when no configured language matches so the
	 * caller can decide to skip the rewrite entirely.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return string
	 */
	private function resolve_language_from_request( $request ) {
		$languages = ( new KDNA_RC_Languages() )->get_all();
		if ( empty( $languages ) ) {
			return '';
		}

		// 1. Explicit ?lang= parameter.
		if ( $request instanceof WP_REST_Request ) {
			$param = $request->get_param( 'lang' );
			if ( is_string( $param ) && '' !== $param ) {
				$slug = sanitize_key( $param );
				foreach ( $languages as $lang ) {
					if ( $lang['slug'] === $slug ) {
						return $slug;
					}
				}
			}
		}

		// 2. Accept-Language header.
		if ( $request instanceof WP_REST_Request ) {
			$header = (string) $request->get_header( 'accept_language' );
			if ( '' === $header ) {
				$header = (string) $request->get_header( 'accept-language' );
			}
			if ( '' !== $header ) {
				$detector = new KDNA_RC_Language_Detector();
				$matched  = $detector->match_accept_language( $header, $languages );
				if ( is_array( $matched ) && isset( $matched['slug'] ) ) {
					return (string) $matched['slug'];
				}
			}
		}

		// 3. Configured default.
		$settings = get_option( KDNA_RC_OPTION_SETTINGS, array() );
		if ( is_array( $settings ) && ! empty( $settings['default_language'] ) ) {
			return sanitize_key( (string) $settings['default_language'] );
		}

		return '';
	}

	/**
	 * Look up a field's stored type so the response shape is correct.
	 *
	 * @param string $field_name Meta key.
	 * @return string
	 */
	private function detect_field_type( $field_name ) {
		$option = get_option( 'jet_engine_meta_boxes' );
		if ( ! is_array( $option ) ) {
			$option = get_option( 'jet-engine-meta-boxes' );
		}
		if ( ! is_array( $option ) ) {
			return 'kdna_rc_ml_text';
		}
		foreach ( $option as $box ) {
			$fields = isset( $box['meta_fields'] ) ? (array) $box['meta_fields'] : array();
			foreach ( $fields as $field ) {
				if ( is_array( $field ) && isset( $field['name'] ) && $field['name'] === $field_name ) {
					return isset( $field['type'] ) ? (string) $field['type'] : 'kdna_rc_ml_text';
				}
			}
		}
		return 'kdna_rc_ml_text';
	}
}
