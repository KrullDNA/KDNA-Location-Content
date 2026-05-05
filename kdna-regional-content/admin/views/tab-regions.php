<?php
/**
 * Regions tab view.
 *
 * Renders the regions list with drag handles, edit and delete buttons, and an
 * inline editor that expands beneath each row. New regions are added by
 * clicking the Add Region button, which clones the empty editor template.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$regions_handler = new KDNA_RC_Regions();
$regions         = $regions_handler->get_all();
$countries       = KDNA_RC_Regions::country_list();

/**
 * Render a region row plus its hidden editor panel.
 *
 * @param array $region Region data.
 * @return void
 */
$render_row = function ( array $region ) {
	$slug      = isset( $region['slug'] ) ? $region['slug'] : '';
	$name      = isset( $region['name'] ) ? $region['name'] : '';
	$type      = isset( $region['type'] ) ? $region['type'] : 'single';
	$countries = isset( $region['countries'] ) && is_array( $region['countries'] ) ? $region['countries'] : array();
	$language  = isset( $region['language'] ) ? $region['language'] : '';
	$direction = isset( $region['direction'] ) ? $region['direction'] : 'ltr';
	?>
	<li class="kdna-rc-region" data-slug="<?php echo esc_attr( $slug ); ?>">
		<div class="kdna-rc-region-row">
			<span class="kdna-rc-handle dashicons dashicons-menu" aria-hidden="true" title="<?php echo esc_attr__( 'Drag to reorder', 'kdna-regional-content' ); ?>"></span>
			<span class="kdna-rc-region-name"><?php echo esc_html( $name ); ?></span>
			<code class="kdna-rc-region-slug"><?php echo esc_html( $slug ); ?></code>
			<span class="kdna-rc-region-meta">
				<?php
				if ( 'group' === $type ) {
					/* translators: %d: number of countries. */
					echo esc_html( sprintf( _n( 'Group, %d country', 'Group, %d countries', count( $countries ), 'kdna-regional-content' ), count( $countries ) ) );
				} else {
					echo esc_html__( 'Single country', 'kdna-regional-content' );
				}
				?>
			</span>
			<span class="kdna-rc-region-actions">
				<button type="button" class="button-link kdna-rc-edit"><?php echo esc_html__( 'Edit', 'kdna-regional-content' ); ?></button>
				<button type="button" class="button-link kdna-rc-delete" data-confirm="<?php echo esc_attr__( 'Delete this region? Editors using it will need to pick a different region.', 'kdna-regional-content' ); ?>"><?php echo esc_html__( 'Delete', 'kdna-regional-content' ); ?></button>
			</span>
		</div>

		<div class="kdna-rc-region-editor" hidden>
			<div class="kdna-rc-form-grid">
				<label class="kdna-rc-field">
					<span class="kdna-rc-label"><?php echo esc_html__( 'Display Name', 'kdna-regional-content' ); ?></span>
					<input type="text" class="regular-text kdna-rc-input-name" value="<?php echo esc_attr( $name ); ?>" />
				</label>

				<label class="kdna-rc-field">
					<span class="kdna-rc-label"><?php echo esc_html__( 'Slug', 'kdna-regional-content' ); ?></span>
					<input type="text" class="regular-text kdna-rc-input-slug" value="<?php echo esc_attr( $slug ); ?>" pattern="[a-z0-9_-]+" />
					<span class="description"><?php echo esc_html__( 'Lowercase letters, numbers, and hyphens. Used in cookies and the ?region= override.', 'kdna-regional-content' ); ?></span>
				</label>

				<fieldset class="kdna-rc-field">
					<legend class="kdna-rc-label"><?php echo esc_html__( 'Type', 'kdna-regional-content' ); ?></legend>
					<label><input type="radio" class="kdna-rc-input-type" value="single"<?php checked( 'single', $type ); ?> /> <?php echo esc_html__( 'Single Country', 'kdna-regional-content' ); ?></label>
					<label><input type="radio" class="kdna-rc-input-type" value="group"<?php checked( 'group', $type ); ?> /> <?php echo esc_html__( 'Group of Countries', 'kdna-regional-content' ); ?></label>
				</fieldset>

				<div class="kdna-rc-field kdna-rc-field-countries">
					<span class="kdna-rc-label"><?php echo esc_html__( 'Countries', 'kdna-regional-content' ); ?></span>
					<div class="kdna-rc-country-picker" data-selected="<?php echo esc_attr( implode( ',', $countries ) ); ?>">
						<input type="search" class="kdna-rc-country-search" placeholder="<?php echo esc_attr__( 'Search countries...', 'kdna-regional-content' ); ?>" />
						<div class="kdna-rc-country-list" role="listbox" aria-multiselectable="true"></div>
					</div>
					<span class="description"><?php echo esc_html__( 'Single-country regions accept one selection. Group regions accept many.', 'kdna-regional-content' ); ?></span>
				</div>

				<label class="kdna-rc-field">
					<span class="kdna-rc-label"><?php echo esc_html__( 'Language Code', 'kdna-regional-content' ); ?></span>
					<input type="text" class="regular-text kdna-rc-input-language" value="<?php echo esc_attr( $language ); ?>" placeholder="en-AU" />
					<span class="description"><?php echo esc_html__( 'Optional. Applied as the lang attribute on variant output, for example en-AU or pt-BR.', 'kdna-regional-content' ); ?></span>
				</label>

				<fieldset class="kdna-rc-field">
					<legend class="kdna-rc-label"><?php echo esc_html__( 'Direction', 'kdna-regional-content' ); ?></legend>
					<label><input type="radio" class="kdna-rc-input-direction" value="ltr"<?php checked( 'ltr', $direction ); ?> /> <?php echo esc_html__( 'Left to right', 'kdna-regional-content' ); ?></label>
					<label><input type="radio" class="kdna-rc-input-direction" value="rtl"<?php checked( 'rtl', $direction ); ?> /> <?php echo esc_html__( 'Right to left', 'kdna-regional-content' ); ?></label>
				</fieldset>
			</div>

			<p class="kdna-rc-form-actions">
				<button type="button" class="button button-primary kdna-rc-save"><?php echo esc_html__( 'Save Region', 'kdna-regional-content' ); ?></button>
				<button type="button" class="button kdna-rc-cancel"><?php echo esc_html__( 'Cancel', 'kdna-regional-content' ); ?></button>
				<span class="spinner kdna-rc-spinner" aria-hidden="true"></span>
				<span class="kdna-rc-form-message" role="status" aria-live="polite"></span>
			</p>
		</div>
	</li>
	<?php
};
?>

<div class="kdna-rc-regions">

	<div class="kdna-rc-regions-header">
		<h2><?php echo esc_html__( 'Regions', 'kdna-regional-content' ); ?></h2>
		<button type="button" class="button button-primary kdna-rc-add-region">
			<span class="dashicons dashicons-plus" aria-hidden="true"></span>
			<?php echo esc_html__( 'Add Region', 'kdna-regional-content' ); ?>
		</button>
	</div>

	<p class="description">
		<?php echo esc_html__( 'Drag regions to reorder them. The order shown here is the order they appear in dropdowns elsewhere in the plugin.', 'kdna-regional-content' ); ?>
	</p>

	<?php if ( empty( $regions ) ) : ?>
		<div class="notice notice-info inline kdna-rc-empty">
			<p><?php echo esc_html__( 'No regions configured yet. Click Add Region to create your first one.', 'kdna-regional-content' ); ?></p>
		</div>
	<?php endif; ?>

	<ul class="kdna-rc-region-list" id="kdna-rc-region-list">
		<?php foreach ( $regions as $region ) { $render_row( $region ); } ?>
	</ul>

	<template id="kdna-rc-region-template">
		<?php $render_row( array(
			'slug'      => '',
			'name'      => '',
			'type'      => 'single',
			'countries' => array(),
			'language'  => '',
			'direction' => 'ltr',
		) ); ?>
	</template>

</div>
