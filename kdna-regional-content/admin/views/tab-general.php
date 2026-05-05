<?php
/**
 * General tab view.
 *
 * Renders the Settings API form for the kdna_rc_settings_group. Stage 1 only
 * exposes the MaxMind License Key field; later stages add Default Region,
 * Test Override Mode, Trust Proxy Headers, and Cookie Lifetime here.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
	<?php
	settings_fields( 'kdna_rc_settings_group' );
	do_settings_sections( KDNA_RC_Settings::PAGE_SLUG . '-general' );
	submit_button();
	?>
</form>
