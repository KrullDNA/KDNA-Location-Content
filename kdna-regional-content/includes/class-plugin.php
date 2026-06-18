<?php
/**
 * Main plugin bootstrap.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * KDNA_RC_Plugin
 *
 * Singleton bootstrap that wires up admin and front-end components. Stage 1
 * only loads the admin settings page; later stages add detection, Elementor
 * integration, and asset management here.
 */
final class KDNA_RC_Plugin {

	/**
	 * Holds the single instance of this class.
	 *
	 * @var KDNA_RC_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Settings page handler.
	 *
	 * @var KDNA_RC_Settings|null
	 */
	private $settings = null;

	/**
	 * Database updater handler.
	 *
	 * @var KDNA_RC_Database_Updater|null
	 */
	private $database_updater = null;

	/**
	 * Regions handler.
	 *
	 * @var KDNA_RC_Regions|null
	 */
	private $regions = null;

	/**
	 * Visitor detection handler.
	 *
	 * @var KDNA_RC_Detector|null
	 */
	private $detector = null;

	/**
	 * Elementor element visibility handler.
	 *
	 * @var KDNA_RC_Elementor_Visibility|null
	 */
	private $elementor_visibility = null;

	/**
	 * Post-level visibility handler.
	 *
	 * @var KDNA_RC_Post_Visibility|null
	 */
	private $post_visibility = null;

	/**
	 * JetEngine integration.
	 *
	 * @var KDNA_RC_JetEngine_Integration|null
	 */
	private $jetengine = null;

	/**
	 * Front-end assets handler.
	 *
	 * @var KDNA_RC_Assets|null
	 */
	private $assets = null;

	/**
	 * Active widget variant and per-item visibility extensions (Stage 6+).
	 *
	 * Each entry implements an init() method but they do not all share a
	 * common base class because Icon List uses a per-item visibility model
	 * rather than the variant-wrapper pattern.
	 *
	 * @var array<int,object>
	 */
	private $variant_extensions = array();

	/**
	 * Return the singleton instance, creating it on first call.
	 *
	 * @return KDNA_RC_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	/**
	 * Private constructor enforces the singleton pattern.
	 */
	private function __construct() {
	}

	/**
	 * Disallow cloning so the singleton stays single.
	 */
	private function __clone() {
	}

	/**
	 * Disallow unserialisation so the singleton stays single.
	 */
	public function __wakeup() {
		throw new \RuntimeException( 'KDNA_RC_Plugin cannot be unserialised.' );
	}

	/**
	 * Wire up hooks and instantiate child components.
	 *
	 * Called once from instance() on first construction. Keeps load order
	 * predictable and makes future stages easy to slot in.
	 *
	 * @return void
	 */
	private function init() {
		// Load translations as early as possible.
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Database updater registers the cron schedule filter and the cron
		// callback in every context (admin, front-end, cron) so scheduled
		// runs work even when no admin is logged in.
		$this->database_updater = new KDNA_RC_Database_Updater();
		$this->database_updater->init();

		// Regions handler. Instantiated everywhere so future stages (variant
		// rendering, detection) can use it on the front end too. AJAX handlers
		// register only once, gated below to admin.
		$this->regions = new KDNA_RC_Regions();
		if ( is_admin() ) {
			$this->regions->init();
		}

		// Stage 10: Languages handler. Same instantiate-everywhere pattern as
		// Regions; AJAX registered only inside admin.
		$languages = new KDNA_RC_Languages();
		if ( is_admin() ) {
			$languages->init();
		}

		// Visitor detection. init() registers public AJAX (priv + nopriv),
		// the early ?region= override handler, and the wp_head inline
		// configuration printer. Safe to run in every context.
		$this->detector = new KDNA_RC_Detector();
		$this->detector->init();

		// Stage 10 language detection. Public AJAX (priv + nopriv) for the
		// Language Selector widget plus the early ?lang= override handler.
		( new KDNA_RC_Language_Detector() )->init();

		// Stage 5 visibility layer. Each handler is harmless when its
		// integration target is missing (Elementor, JetEngine), so they
		// can be instantiated unconditionally.
		$this->elementor_visibility = new KDNA_RC_Elementor_Visibility();
		$this->elementor_visibility->init();

		$this->post_visibility = new KDNA_RC_Post_Visibility();
		$this->post_visibility->init();

		( new KDNA_RC_Menu_Visibility() )->init();
		( new KDNA_RC_Term_Visibility() )->init();

		$this->jetengine = new KDNA_RC_JetEngine_Integration();
		$this->jetengine->init();

		$this->assets = new KDNA_RC_Assets();
		$this->assets->init();

		// Stage 8 polish layer: WP Rocket + cache integration and admin
		// notices for misconfigured states. Both are admin-leaning but
		// register a couple of front-side filters so cache exclusion works
		// outside wp-admin.
		( new KDNA_RC_Cache_Integration() )->init();
		( new KDNA_RC_Admin_Notices() )->init();

		// Stage 11: register the kdna-widgets Elementor category and the
		// Language Selector widget. Hooks are guarded with class_exists so
		// the registration is silently skipped on Elementor-less sites.
		add_action( 'elementor/elements/categories_registered', array( $this, 'register_elementor_category' ) );
		add_action( 'elementor/widgets/register', array( $this, 'register_elementor_widgets' ) );

		// Stage 12: register multilingual JetEngine field types + their
		// admin assets. The classes silently skip themselves when JetEngine
		// is absent, so this is safe to run unconditionally.
		( new KDNA_RC_Multilingual_Text_Field() )->init();
		( new KDNA_RC_Multilingual_Image_Field() )->init();
		( new KDNA_RC_Multilingual_WYSIWYG_Field() )->init();
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_multilingual_admin_assets' ) );

		// Stage 12 migration tool. Backs the Tools-tab Migrate UI.
		( new KDNA_RC_Migration_Tool() )->init();
		add_action( 'wp_ajax_kdna_rc_migration_fields', array( $this, 'ajax_migration_fields' ) );

		// Stage 13 query / search / REST adapters and audit tool. Each
		// guards itself when its target plugin is missing, so registering
		// unconditionally is safe.
		( new KDNA_RC_JetSmartFilters_Adapter() )->init();
		( new KDNA_RC_JetSearch_Adapter() )->init();
		( new KDNA_RC_JetEngine_Query_Adapter() )->init();
		( new KDNA_RC_Rest_Api_Adapter() )->init();
		( new KDNA_RC_Field_Audit_Tool() )->init();

		// Stage 14 per-region / per-language URL routing. Hooks
		// do_parse_request before WordPress parses the URL so every
		// permalink context (page, post, CPT, taxonomy, paginated
		// archive, search) automatically supports KDNA URL prefixes.
		( new KDNA_RC_URL_Routing() )->init();

		// Stage 15 Yoast / SEO integration. Each helper guards itself
		// against Yoast being absent so registering unconditionally is
		// safe; only the URL-routing-driven pieces (hreflang) emit on
		// every install.
		( new KDNA_RC_SEO_Meta_Box() )->init();
		( new KDNA_RC_Yoast_Integration() )->init();
		( new KDNA_RC_Yoast_MF_Variable_Resolver() )->init();
		( new KDNA_RC_Hreflang() )->init();
		( new KDNA_RC_Yoast_Sitemap_Integration() )->init();
		( new KDNA_RC_Yoast_Schema_Integration() )->init();
		( new KDNA_RC_SEO_Health_Check() )->init();

		// Optional Google Analytics 4 integration. Off by default. When
		// on, pushes the visitor's resolved region + language into GA4
		// as user properties and a one-shot kdna_resolution event.
		( new KDNA_RC_Google_Analytics_Integration() )->init();

		// Optional region-switch banner. Off by default. When on, shows
		// a dismissible top-of-page prompt the first time the visitor's
		// IP-detected region differs from the URL they landed on.
		( new KDNA_RC_Region_Banner() )->init();

		// Stage 6 widget variant extensions. New widgets are added by
		// appending to this list; everything else routes through the shared
		// base class.
		$this->variant_extensions = array(
			new KDNA_RC_Heading_Extension(),
			new KDNA_RC_Text_Editor_Extension(),
			new KDNA_RC_Button_Extension(),
			new KDNA_RC_Image_Extension(),
			new KDNA_RC_Icon_Extension(),
			new KDNA_RC_Icon_List_Extension(),
		);
		foreach ( $this->variant_extensions as $extension ) {
			$extension->init();
		}

		// Boot the admin settings page only inside wp-admin.
		if ( is_admin() ) {
			$this->settings = new KDNA_RC_Settings();
			$this->settings->init();
		}

		// Keep the cron event in sync whenever settings are saved so a
		// schedule change takes effect on the next request.
		add_action( 'update_option_' . KDNA_RC_OPTION_SETTINGS, array( $this, 'on_settings_updated' ), 10, 2 );
		add_action( 'add_option_' . KDNA_RC_OPTION_SETTINGS, array( $this, 'on_settings_added' ), 10, 2 );
	}

	/**
	 * Load the plugin text domain so all strings can be translated.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			KDNA_RC_TEXT_DOMAIN,
			false,
			dirname( KDNA_RC_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Convenience accessor for the settings handler.
	 *
	 * @return KDNA_RC_Settings|null
	 */
	public function settings() {
		return $this->settings;
	}

	/**
	 * Convenience accessor for the database updater.
	 *
	 * @return KDNA_RC_Database_Updater|null
	 */
	public function database_updater() {
		return $this->database_updater;
	}

	/**
	 * Convenience accessor for the regions handler.
	 *
	 * @return KDNA_RC_Regions|null
	 */
	public function regions() {
		return $this->regions;
	}

	/**
	 * Convenience accessor for the visitor detection handler.
	 *
	 * @return KDNA_RC_Detector|null
	 */
	public function detector() {
		return $this->detector;
	}

	/**
	 * Reconcile the WP-Cron event whenever the settings option is updated.
	 *
	 * @param mixed $old_value Previous option value.
	 * @param mixed $new_value New option value.
	 * @return void
	 */
	public function on_settings_updated( $old_value, $new_value ) {
		unset( $old_value, $new_value );
		if ( $this->database_updater ) {
			$this->database_updater->reconcile_cron_schedule();
		}
	}

	/**
	 * Reconcile the WP-Cron event when the settings option is created.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Option value.
	 * @return void
	 */
	public function on_settings_added( $option, $value ) {
		unset( $option, $value );
		if ( $this->database_updater ) {
			$this->database_updater->reconcile_cron_schedule();
		}
	}

	/**
	 * Stage 11: register the kdna-widgets category in the Elementor panel.
	 *
	 * Adds the category if it does not already exist; if a downstream
	 * project plugin registered the same slug first we keep that one and
	 * just slot our widget under it.
	 *
	 * @param mixed $elements_manager Elementor elements manager.
	 * @return void
	 */
	public function register_elementor_category( $elements_manager ) {
		if ( ! is_object( $elements_manager ) || ! method_exists( $elements_manager, 'add_category' ) ) {
			return;
		}

		$elements_manager->add_category(
			'kdna-widgets',
			array(
				'title' => __( 'KDNA Widgets', 'kdna-regional-content' ),
				'icon'  => 'eicon-globe',
			)
		);
	}

	/**
	 * Stage 11: register Elementor widgets shipped by this plugin.
	 *
	 * @param mixed $widgets_manager Elementor widgets manager.
	 * @return void
	 */
	public function register_elementor_widgets( $widgets_manager ) {
		if ( ! is_object( $widgets_manager ) || ! method_exists( $widgets_manager, 'register' ) ) {
			return;
		}
		if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
			return;
		}
		if ( class_exists( 'KDNA_RC_Language_Selector_Widget' ) ) {
			$widgets_manager->register( new KDNA_RC_Language_Selector_Widget() );
		}

		// Stage 12 dynamic multilingual widgets.
		if ( class_exists( 'KDNA_RC_Dynamic_Multilingual_Field_Widget' ) ) {
			$widgets_manager->register( new KDNA_RC_Dynamic_Multilingual_Field_Widget() );
		}
		if ( class_exists( 'KDNA_RC_Dynamic_Multilingual_Image_Widget' ) ) {
			$widgets_manager->register( new KDNA_RC_Dynamic_Multilingual_Image_Widget() );
		}
		if ( class_exists( 'KDNA_RC_Dynamic_Multilingual_Link_Widget' ) ) {
			$widgets_manager->register( new KDNA_RC_Dynamic_Multilingual_Link_Widget() );
		}
	}

	/**
	 * Enqueue the multilingual field tabbed-editor assets on post edit
	 * screens that may host JetEngine meta boxes.
	 *
	 * Only enqueues the WordPress media library when the post-edit screen
	 * is in scope so we do not bloat unrelated admin pages.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_multilingual_admin_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		// Required so the Image-field tab can open the WP media library.
		if ( function_exists( 'wp_enqueue_media' ) ) {
			wp_enqueue_media();
		}

		// flag-icons must be registered BEFORE kdna-rc-mlf because
		// kdna-rc-mlf declares it as a dependency. WP 6.9.1 warns when a
		// style is enqueued with an unregistered dependency, so we
		// register/enqueue this one first.
		if ( ! wp_style_is( 'kdna-rc-flag-icons', 'registered' ) ) {
			wp_enqueue_style(
				'kdna-rc-flag-icons',
				KDNA_RC_PLUGIN_URL . 'lib/flag-icons/css/flag-icons.min.css',
				array(),
				'7.5.0'
			);
		} elseif ( ! wp_style_is( 'kdna-rc-flag-icons', 'enqueued' ) ) {
			wp_enqueue_style( 'kdna-rc-flag-icons' );
		}

		wp_enqueue_style(
			'kdna-rc-mlf',
			KDNA_RC_PLUGIN_URL . 'assets/css/multilingual-fields.css',
			array( 'kdna-rc-flag-icons' ),
			KDNA_RC_VERSION
		);

		wp_enqueue_script(
			'kdna-rc-mlf',
			KDNA_RC_PLUGIN_URL . 'assets/js/multilingual-fields.js',
			array( 'jquery' ),
			KDNA_RC_VERSION,
			true
		);
	}

	/**
	 * AJAX: list JetEngine simple-text fields (Text/Textarea/WYSIWYG) for
	 * the Tools-tab migration UI's Source field dropdown.
	 *
	 * @return void
	 */
	public function ajax_migration_fields() {
		check_ajax_referer( 'kdna_rc_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'kdna-regional-content' ) ), 403 );
		}
		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
		$fields    = '' !== $post_type ? KDNA_RC_Migration_Tool::discover_simple_fields( $post_type ) : array();
		wp_send_json_success( array( 'fields' => $fields ) );
	}
}
