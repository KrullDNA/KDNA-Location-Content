<?php
/**
 * JetEngine Listing Grid integration.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_JetEngine_Integration
 *
 * Adds data-kdna-show-in to the outer wrapper of every JetEngine listing
 * item whose post has _kdna_rc_regions configured. Query args are NEVER
 * filtered (the brief is explicit on this point) so the cached HTML still
 * contains every post; the client-side filter in frontend.js then hides
 * the items the visitor's region is not allowed to see.
 *
 * Two strategies are used together so we cover the broadest spread of
 * JetEngine versions without depending on any one filter being present:
 *   - When the modern jet-engine/listing/grid/item-attributes filter exists,
 *     contribute the attribute through it (cleanest, no markup parsing).
 *   - In addition, output-buffer each rendered item via the
 *     jet-engine/listing/grid/before-item and after-item actions and inject
 *     the attribute on the outer wrapper as a fallback. The injection runs
 *     only when the buffered HTML does not already include data-kdna-show-in.
 */
class KDNA_RC_JetEngine_Integration {

	/**
	 * Output buffer state for the current item, when the fallback strategy is in play.
	 *
	 * @var bool
	 */
	private $buffering = false;

	/**
	 * Region slugs gathered for the currently buffered item.
	 *
	 * @var array<int,string>
	 */
	private $buffered_regions = array();

	/**
	 * Wire up hooks if (and only if) JetEngine is active on this site.
	 *
	 * @return void
	 */
	public function init() {
		if ( ! self::is_active() ) {
			return;
		}

		// Modern path: JetEngine filter that contributes attribute pairs to
		// the listing item wrapper. Two-arg signature in current versions.
		add_filter( 'jet-engine/listing/grid/item-attributes', array( $this, 'filter_item_attributes' ), 10, 2 );

		// Class-based marker. The CSS class is the most reliable way to pin
		// a JetEngine listing item to its post because JetEngine has used
		// several different per-item action hook names across versions, and
		// the item-classes filter has been stable for a long time.
		add_filter( 'jet-engine/listing/grid/item-classes', array( $this, 'filter_item_classes' ), 10, 1 );

		// Fallback path: capture the rendered item HTML and patch the outer
		// wrapper. Activated unconditionally so we still cover the case
		// where neither filter above lands on the right element.
		add_action( 'jet-engine/listing/grid/before-item', array( $this, 'start_item_buffer' ), 10, 1 );
		add_action( 'jet-engine/listing/grid/after-item', array( $this, 'end_item_buffer' ), 10, 1 );
	}

	/**
	 * Add a kdna-rc-post-{ID} marker class to the JetEngine listing item.
	 *
	 * Mirrors the post_class() marker added by KDNA_RC_Post_Visibility so
	 * the front-end JS has a reliable hook regardless of how the listing
	 * template renders the inner HTML.
	 *
	 * @param array $classes Existing CSS classes.
	 * @return array
	 */
	public function filter_item_classes( $classes ) {
		if ( ! is_array( $classes ) ) {
			$classes = array();
		}
		$post_id = (int) get_the_ID();
		if ( $post_id <= 0 ) {
			return $classes;
		}
		$regions = KDNA_RC_Post_Visibility::get_post_regions( $post_id );
		if ( empty( $regions ) ) {
			return $classes;
		}
		$marker = 'kdna-rc-post-' . $post_id;
		if ( ! in_array( $marker, $classes, true ) ) {
			$classes[] = $marker;
		}
		return $classes;
	}

	/**
	 * Return true when JetEngine is loaded.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return class_exists( 'Jet_Engine' );
	}

	/**
	 * Filter callback contributing the data attribute on supported versions.
	 *
	 * Returns the attributes array unchanged when the current post has no
	 * region restrictions configured.
	 *
	 * @param array $attributes Existing wrapper attributes.
	 * @param mixed $listing    Current listing context (object varies).
	 * @return array
	 */
	public function filter_item_attributes( $attributes, $listing = null ) {
		unset( $listing );

		if ( ! is_array( $attributes ) ) {
			$attributes = array();
		}

		$post_id = (int) get_the_ID();
		$regions = KDNA_RC_Post_Visibility::get_post_regions( $post_id );
		if ( empty( $regions ) ) {
			return $attributes;
		}

		$attributes['data-kdna-show-in'] = implode( ',', $regions );
		return $attributes;
	}

	/**
	 * Open an output buffer around the current listing item.
	 *
	 * @param mixed $listing JetEngine listing object (signature varies).
	 * @return void
	 */
	public function start_item_buffer( $listing = null ) {
		unset( $listing );

		$post_id                 = (int) get_the_ID();
		$this->buffered_regions  = KDNA_RC_Post_Visibility::get_post_regions( $post_id );

		if ( empty( $this->buffered_regions ) ) {
			$this->buffering = false;
			return;
		}

		$this->buffering = true;
		ob_start();
	}

	/**
	 * Close the output buffer and inject data-kdna-show-in on the outer wrapper.
	 *
	 * Uses a simple regex on the first opening tag because JetEngine items
	 * are always wrapped in a single root element. If the attribute is
	 * already present (added by filter_item_attributes()) the buffered HTML
	 * is emitted unchanged.
	 *
	 * @param mixed $listing JetEngine listing object (signature varies).
	 * @return void
	 */
	public function end_item_buffer( $listing = null ) {
		unset( $listing );

		if ( ! $this->buffering ) {
			return;
		}

		$html                   = (string) ob_get_clean();
		$this->buffering        = false;
		$regions                = $this->buffered_regions;
		$this->buffered_regions = array();

		if ( '' === $html ) {
			return;
		}

		// Skip injection when the modern filter already wrote the attribute.
		if ( false !== strpos( $html, 'data-kdna-show-in=' ) ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- buffered output already escaped by JetEngine.
			return;
		}

		$attr  = ' data-kdna-show-in="' . esc_attr( implode( ',', $regions ) ) . '"';
		// Inject the attribute on the first opening tag in the buffered HTML.
		// Limit replacement to one to leave any inner markup untouched.
		$patched = preg_replace( '/(<[a-zA-Z][a-zA-Z0-9-]*)(\s|>)/', '$1' . $attr . '$2', ltrim( $html ), 1 );

		if ( null === $patched ) {
			$patched = $html;
		}

		echo $patched; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- buffered output already escaped by JetEngine; we only injected our own escaped attribute.
	}
}
