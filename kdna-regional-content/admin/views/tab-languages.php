<?php
/**
 * Languages tab view.
 *
 * Mirrors the Regions tab UI with simpler per-row fields. Existing rows are
 * pre-rendered server-side; new rows are cloned from the <template> block.
 *
 * @package KDNA_Regional_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$languages_handler = new KDNA_RC_Languages();
$languages         = $languages_handler->get_all();
$library           = KDNA_RC_Languages::library();

/**
 * Render a language row plus its hidden editor panel.
 *
 * @param array $language Language data.
 * @return void
 */
$render_row = function ( array $language ) {
	$slug = isset( $language['slug'] ) ? $language['slug'] : '';
	$name = isset( $language['name'] ) ? $language['name'] : '';
	$flag = isset( $language['flag'] ) ? $language['flag'] : '';
	?>
	<li class="kdna-rc-language" data-slug="<?php echo esc_attr( $slug ); ?>">
		<div class="kdna-rc-region-row">
			<span class="kdna-rc-handle dashicons dashicons-menu" aria-hidden="true" title="<?php echo esc_attr__( 'Drag to reorder', 'kdna-regional-content' ); ?>"></span>
			<span class="kdna-rc-flag-cell">
				<?php if ( '' !== $flag ) : ?>
					<span class="fi fi-<?php echo esc_attr( $flag ); ?> kdna-rc-flag-display" aria-hidden="true"></span>
				<?php endif; ?>
			</span>
			<span class="kdna-rc-region-name"><?php echo esc_html( $name ); ?></span>
			<code class="kdna-rc-region-slug"><?php echo esc_html( $slug ); ?></code>
			<span class="kdna-rc-region-actions">
				<button type="button" class="button-link kdna-rc-edit"><?php echo esc_html__( 'Edit', 'kdna-regional-content' ); ?></button>
				<button type="button" class="button-link kdna-rc-delete" data-confirm="<?php echo esc_attr__( 'Delete this language? Visitor cookies referencing it will fall back to the default.', 'kdna-regional-content' ); ?>"><?php echo esc_html__( 'Delete', 'kdna-regional-content' ); ?></button>
			</span>
		</div>

		<div class="kdna-rc-region-editor" hidden>
			<div class="kdna-rc-form-grid">
				<label class="kdna-rc-field">
					<span class="kdna-rc-label"><?php echo esc_html__( 'Display Name', 'kdna-regional-content' ); ?></span>
					<input type="text" class="regular-text kdna-rc-input-name" value="<?php echo esc_attr( $name ); ?>" placeholder="<?php echo esc_attr__( 'e.g. Français, 日本語, العربية', 'kdna-regional-content' ); ?>" />
					<span class="description"><?php echo esc_html__( 'Native script is fine. UTF-8 supported.', 'kdna-regional-content' ); ?></span>
				</label>

				<label class="kdna-rc-field">
					<span class="kdna-rc-label"><?php echo esc_html__( 'Slug', 'kdna-regional-content' ); ?></span>
					<input type="text" class="regular-text kdna-rc-input-slug" value="<?php echo esc_attr( $slug ); ?>" pattern="[a-z0-9_-]+" />
					<span class="description"><?php echo esc_html__( 'ISO 639-1 code where possible (en, fr, de, ja). Used in cookies and the ?lang= override.', 'kdna-regional-content' ); ?></span>
				</label>

				<label class="kdna-rc-field">
					<span class="kdna-rc-label"><?php echo esc_html__( 'Flag Country Code', 'kdna-regional-content' ); ?></span>
					<span class="kdna-rc-flag-input-row">
						<input type="text" class="regular-text kdna-rc-input-flag" value="<?php echo esc_attr( $flag ); ?>" maxlength="2" pattern="[a-zA-Z]{2}" placeholder="gb" />
						<span class="kdna-rc-flag-preview <?php echo '' !== $flag ? 'fi fi-' . esc_attr( $flag ) : ''; ?>" aria-hidden="true"></span>
					</span>
					<span class="description"><?php echo esc_html__( 'Two-letter ISO 3166-1 alpha-2 country code (gb, fr, de, jp). Used to render the flag icon.', 'kdna-regional-content' ); ?></span>
				</label>
			</div>

			<p class="kdna-rc-form-actions">
				<button type="button" class="button button-primary kdna-rc-save"><?php echo esc_html__( 'Save Language', 'kdna-regional-content' ); ?></button>
				<button type="button" class="button kdna-rc-cancel"><?php echo esc_html__( 'Cancel', 'kdna-regional-content' ); ?></button>
				<span class="spinner kdna-rc-spinner" aria-hidden="true"></span>
				<span class="kdna-rc-form-message" role="status" aria-live="polite"></span>
			</p>
		</div>
	</li>
	<?php
};
?>

<div class="kdna-rc-languages">

	<div class="kdna-rc-regions-header">
		<h2><?php echo esc_html__( 'Languages', 'kdna-regional-content' ); ?></h2>
		<span class="kdna-rc-header-actions">
			<button type="button" class="button kdna-rc-import-language">
				<span class="dashicons dashicons-download" aria-hidden="true"></span>
				<?php echo esc_html__( 'Import from Library', 'kdna-regional-content' ); ?>
			</button>
			<button type="button" class="button button-primary kdna-rc-add-language">
				<span class="dashicons dashicons-plus" aria-hidden="true"></span>
				<?php echo esc_html__( 'Add Language', 'kdna-regional-content' ); ?>
			</button>
		</span>
	</div>

	<p class="description">
		<?php echo esc_html__( 'Drag languages to reorder them. Order controls the language selector dropdown order on the front end.', 'kdna-regional-content' ); ?>
	</p>

	<?php if ( empty( $languages ) ) : ?>
		<div class="notice notice-info inline kdna-rc-empty">
			<p><?php echo esc_html__( 'No languages configured yet. Click Import from Library to add common languages with one click, or use Add Language to create one manually.', 'kdna-regional-content' ); ?></p>
		</div>
	<?php endif; ?>

	<ul class="kdna-rc-language-list" id="kdna-rc-language-list">
		<?php foreach ( $languages as $language ) { $render_row( $language ); } ?>
	</ul>

	<template id="kdna-rc-language-template">
		<?php $render_row( array( 'slug' => '', 'name' => '', 'flag' => '' ) ); ?>
	</template>

	<div class="kdna-rc-modal" id="kdna-rc-language-library-modal" hidden role="dialog" aria-modal="true" aria-labelledby="kdna-rc-language-library-title">
		<div class="kdna-rc-modal-card">
			<div class="kdna-rc-modal-header">
				<h2 id="kdna-rc-language-library-title"><?php echo esc_html__( 'Import Language from Library', 'kdna-regional-content' ); ?></h2>
				<button type="button" class="button-link kdna-rc-modal-close" aria-label="<?php echo esc_attr__( 'Close', 'kdna-regional-content' ); ?>">&times;</button>
			</div>
			<div class="kdna-rc-modal-body">
				<input type="search" class="kdna-rc-library-search" placeholder="<?php echo esc_attr__( 'Search languages...', 'kdna-regional-content' ); ?>" />
				<ul class="kdna-rc-library-list">
					<?php foreach ( $library as $row ) :
						$flag_class = '' !== $row['flag'] ? 'fi fi-' . $row['flag'] : '';
						?>
						<li class="kdna-rc-library-item" data-slug="<?php echo esc_attr( $row['slug'] ); ?>" data-name="<?php echo esc_attr( $row['name'] ); ?>" data-flag="<?php echo esc_attr( $row['flag'] ); ?>">
							<span class="kdna-rc-library-flag <?php echo esc_attr( $flag_class ); ?>" aria-hidden="true"></span>
							<span class="kdna-rc-library-name"><?php echo esc_html( $row['name'] ); ?></span>
							<code class="kdna-rc-library-slug"><?php echo esc_html( $row['slug'] ); ?></code>
							<button type="button" class="button kdna-rc-library-add"><?php echo esc_html__( 'Add', 'kdna-regional-content' ); ?></button>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>
	</div>

</div>
