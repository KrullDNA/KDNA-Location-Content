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

	<h2><?php echo esc_html__( 'Test Detection', 'kdna-regional-content' ); ?></h2>
	<p><?php echo esc_html__( 'Enter an IP address to see which country and region the plugin would resolve for that visitor. Useful for debugging GeoIP and region configuration.', 'kdna-regional-content' ); ?></p>

	<div class="kdna-rc-test-detection">
		<p>
			<label for="kdna-rc-test-ip" class="screen-reader-text"><?php echo esc_html__( 'IP address', 'kdna-regional-content' ); ?></label>
			<input type="text" id="kdna-rc-test-ip" class="regular-text code" placeholder="<?php echo esc_attr__( 'e.g. 8.8.8.8 or 2001:4860:4860::8888', 'kdna-regional-content' ); ?>" />
			<button type="button" class="button" id="kdna-rc-test-detect"><?php echo esc_html__( 'Test', 'kdna-regional-content' ); ?></button>
			<span class="spinner kdna-rc-spinner" aria-hidden="true"></span>
		</p>
		<div class="kdna-rc-test-result" aria-live="polite"></div>
	</div>

	<hr />

	<h2><?php echo esc_html__( 'Test Language Detection', 'kdna-regional-content' ); ?></h2>
	<p><?php echo esc_html__( 'Probe the four-step language detection chain with a fake browser Accept-Language header. Useful for confirming priority order without leaving wp-admin.', 'kdna-regional-content' ); ?></p>

	<div class="kdna-rc-test-language">
		<p>
			<label for="kdna-rc-test-accept-language" class="screen-reader-text"><?php echo esc_html__( 'Accept-Language header', 'kdna-regional-content' ); ?></label>
			<input type="text" id="kdna-rc-test-accept-language" class="regular-text code" placeholder="<?php echo esc_attr__( 'e.g. fr-FR,fr;q=0.9,en;q=0.8', 'kdna-regional-content' ); ?>" />
		</p>
		<?php
		$languages_for_test = ( new KDNA_RC_Languages() )->get_all();
		$regions_for_test   = ( new KDNA_RC_Regions() )->get_all();
		?>
		<p>
			<label for="kdna-rc-test-lang-override"><?php echo esc_html__( '?lang= override', 'kdna-regional-content' ); ?></label>
			<select id="kdna-rc-test-lang-override">
				<option value=""><?php echo esc_html__( 'None', 'kdna-regional-content' ); ?></option>
				<?php foreach ( $languages_for_test as $language ) : ?>
					<option value="<?php echo esc_attr( $language['slug'] ); ?>"><?php echo esc_html( $language['name'] ); ?></option>
				<?php endforeach; ?>
			</select>

			<label for="kdna-rc-test-region" style="margin-left:1em;"><?php echo esc_html__( 'Pretend region', 'kdna-regional-content' ); ?></label>
			<select id="kdna-rc-test-region">
				<option value=""><?php echo esc_html__( 'None', 'kdna-regional-content' ); ?></option>
				<?php foreach ( $regions_for_test as $region ) : ?>
					<option value="<?php echo esc_attr( $region['slug'] ); ?>"><?php echo esc_html( $region['name'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label><input type="checkbox" id="kdna-rc-test-first-visit" /> <?php echo esc_html__( 'Simulate first visit (skip cookie reuse)', 'kdna-regional-content' ); ?></label>
		</p>
		<p>
			<button type="button" class="button" id="kdna-rc-test-language-detect"><?php echo esc_html__( 'Test', 'kdna-regional-content' ); ?></button>
			<span class="spinner kdna-rc-spinner" aria-hidden="true"></span>
		</p>
		<div class="kdna-rc-test-language-result" aria-live="polite"></div>
	</div>

	<hr />

	<h2><?php echo esc_html__( 'Clear Caches', 'kdna-regional-content' ); ?></h2>
	<p>
		<?php
		if ( KDNA_RC_Cache_Integration::is_wp_rocket_active() ) {
			echo esc_html__( 'WP Rocket detected. Clearing caches will flush the WP Rocket page cache, the WordPress object cache, and any plugin transients.', 'kdna-regional-content' );
		} else {
			echo esc_html__( 'Clears the WordPress object cache and plugin transients. WP Rocket is not active on this site.', 'kdna-regional-content' );
		}
		?>
	</p>

	<p class="kdna-rc-actions">
		<button type="button" class="button" id="kdna-rc-clear-caches">
			<?php echo esc_html__( 'Clear All Caches', 'kdna-regional-content' ); ?>
		</button>
		<span class="spinner kdna-rc-spinner" aria-hidden="true"></span>
		<span class="kdna-rc-clear-message" role="status" aria-live="polite"></span>
	</p>

	<hr />

	<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
		<?php
		settings_fields( 'kdna_rc_settings_group' );
		do_settings_sections( KDNA_RC_Settings::PAGE_SLUG . '-tools' );
		submit_button( __( 'Save Schedule', 'kdna-regional-content' ) );
		?>
	</form>

</div>
