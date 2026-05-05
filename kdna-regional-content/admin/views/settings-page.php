<?php
/**
 * Settings page wrapper view.
 *
 * Renders the page heading, the tab navigation, and includes the markup for
 * whichever tab is currently active. Variables provided by the caller:
 *   - $tabs        Array of slug => label.
 *   - $current_tab Active tab slug.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap kdna-rc-wrap">
	<h1><?php echo esc_html__( 'Regional Content', 'kdna-regional-content' ); ?></h1>

	<nav class="nav-tab-wrapper kdna-rc-tabs" aria-label="<?php echo esc_attr__( 'Regional Content tabs', 'kdna-regional-content' ); ?>">
		<?php
		foreach ( $tabs as $slug => $label ) {
			$classes = 'nav-tab' . ( $slug === $current_tab ? ' nav-tab-active' : '' );
			printf(
				'<a href="%1$s" class="%2$s">%3$s</a>',
				esc_url(
					add_query_arg(
						array(
							'page' => KDNA_RC_Settings::PAGE_SLUG,
							'tab'  => $slug,
						),
						admin_url( 'admin.php' )
					)
				),
				esc_attr( $classes ),
				esc_html( $label )
			);
		}
		?>
	</nav>

	<div class="kdna-rc-tab-content kdna-rc-tab-<?php echo esc_attr( $current_tab ); ?>">
		<?php
		$tab_view = KDNA_RC_PLUGIN_DIR . 'admin/views/tab-' . $current_tab . '.php';
		if ( is_readable( $tab_view ) ) {
			include $tab_view;
		} else {
			echo '<p>' . esc_html__( 'This tab is not yet available.', 'kdna-regional-content' ) . '</p>';
		}
		?>
	</div>
</div>
