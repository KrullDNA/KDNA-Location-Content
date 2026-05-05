<?php
/**
 * Tools tab view.
 *
 * Renders the MaxMind database status panel, the Update Database Now button,
 * and the auto-update schedule field. Test detection and cache controls are
 * added in later stages.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$updater  = new KDNA_RC_Database_Updater();
$status   = $updater->status_for_response();
$metadata = $status['metadata'];
?>

<div class="kdna-rc-tools">

	<h2><?php echo esc_html__( 'MaxMind Database', 'kdna-regional-content' ); ?></h2>

	<table class="widefat striped kdna-rc-status-table" id="kdna-rc-db-status">
		<tbody>
			<tr>
				<th scope="row"><?php echo esc_html__( 'Database installed', 'kdna-regional-content' ); ?></th>
				<td data-field="exists">
					<?php
					if ( $status['exists'] ) {
						echo '<span class="kdna-rc-status-pill is-ok">' . esc_html__( 'Yes', 'kdna-regional-content' ) . '</span>';
					} else {
						echo '<span class="kdna-rc-status-pill is-warn">' . esc_html__( 'No', 'kdna-regional-content' ) . '</span>';
					}
					?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php echo esc_html__( 'Last updated', 'kdna-regional-content' ); ?></th>
				<td data-field="last_updated_human">
					<?php echo $status['last_updated_human'] ? esc_html( $status['last_updated_human'] ) : esc_html__( 'Never', 'kdna-regional-content' ); ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php echo esc_html__( 'File size', 'kdna-regional-content' ); ?></th>
				<td data-field="file_size_human">
					<?php echo $status['file_size_human'] ? esc_html( $status['file_size_human'] ) : esc_html__( 'Not available', 'kdna-regional-content' ); ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php echo esc_html__( 'Database type', 'kdna-regional-content' ); ?></th>
				<td data-field="database_type">
					<?php echo ! empty( $metadata['database_type'] ) ? esc_html( $metadata['database_type'] ) : esc_html__( 'Not available', 'kdna-regional-content' ); ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php echo esc_html__( 'Build date (per MaxMind)', 'kdna-regional-content' ); ?></th>
				<td data-field="build_epoch">
					<?php
					if ( ! empty( $metadata['build_epoch'] ) ) {
						echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $metadata['build_epoch'] ) );
					} else {
						echo esc_html__( 'Not available', 'kdna-regional-content' );
					}
					?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php echo esc_html__( 'IP version', 'kdna-regional-content' ); ?></th>
				<td data-field="ip_version">
					<?php
					if ( ! empty( $metadata['ip_version'] ) ) {
						/* translators: %d: IP version (4 or 6). */
						echo esc_html( sprintf( __( 'IPv%d', 'kdna-regional-content' ), (int) $metadata['ip_version'] ) );
					} else {
						echo esc_html__( 'Not available', 'kdna-regional-content' );
					}
					?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php echo esc_html__( 'Next scheduled update', 'kdna-regional-content' ); ?></th>
				<td data-field="next_scheduled">
					<?php
					if ( $status['next_scheduled'] ) {
						echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $status['next_scheduled'] ) );
					} else {
						echo esc_html__( 'Not scheduled', 'kdna-regional-content' );
					}
					?>
				</td>
			</tr>
		</tbody>
	</table>

	<?php if ( ! $status['license_key_present'] ) : ?>
		<div class="notice notice-warning inline kdna-rc-license-warning">
			<p>
				<?php
				printf(
					/* translators: %s: link to the General tab. */
					esc_html__( 'Add your MaxMind license key on the %s before downloading the database.', 'kdna-regional-content' ),
					'<a href="' . esc_url(
						add_query_arg(
							array(
								'page' => KDNA_RC_Settings::PAGE_SLUG,
								'tab'  => 'general',
							),
							admin_url( 'admin.php' )
						)
					) . '">' . esc_html__( 'General tab', 'kdna-regional-content' ) . '</a>'
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<p class="kdna-rc-actions">
		<button type="button"
			class="button button-primary"
			id="kdna-rc-update-db"
			<?php disabled( ! $status['license_key_present'] ); ?>>
			<?php echo esc_html__( 'Update Database Now', 'kdna-regional-content' ); ?>
		</button>
		<span class="spinner kdna-rc-spinner" aria-hidden="true"></span>
		<span class="kdna-rc-update-message" role="status" aria-live="polite"></span>
	</p>

	<?php if ( ! empty( $status['last_error'] ) ) : ?>
		<div class="notice notice-error inline kdna-rc-last-error">
			<p>
				<strong><?php echo esc_html__( 'Last error:', 'kdna-regional-content' ); ?></strong>
				<?php echo esc_html( $status['last_error'] ); ?>
			</p>
		</div>
	<?php endif; ?>

	<hr />

	<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
		<?php
		settings_fields( 'kdna_rc_settings_group' );
		do_settings_sections( KDNA_RC_Settings::PAGE_SLUG . '-tools' );
		submit_button( __( 'Save Schedule', 'kdna-regional-content' ) );
		?>
	</form>

</div>
