<?php
/**
 * Plugin Name: WF Match Reign Processor
 * Plugin URI:  https://www.wrestlefanatic.com
 * Description: Handles match listings, shortcodes and reigns for the WF site.
 * Version:     1.0.0
 * Author:      Wrestlefanatic
 * Text Domain: Wrestlefanatic.com
 *
 * Note: This main plugin bootstrap ensures the new shortcodes loader is used.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Basic constants
 */
if ( ! defined( 'WF_MR_PLUGIN_FILE' ) ) {
	define( 'WF_MR_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'WF_MR_PLUGIN_DIR' ) ) {
	define( 'WF_MR_PLUGIN_DIR', plugin_dir_path( WF_MR_PLUGIN_FILE ) );
}

/**
 * Enqueue the single canonical stylesheet (the main stylesheet for plugin shortcodes/layout).
 * Per your request, this is the single place where styles live:
 *   includes/assets/css/shortcodes.css
 *
 * This enqueues the stylesheet globally on the front-end if the file exists.
 */
add_action( 'wp_enqueue_scripts', function() {
	$css_rel  = 'includes/assets/css/shortcodes.css';
	$css_path = WF_MR_PLUGIN_DIR . $css_rel;
	if ( file_exists( $css_path ) ) {
		wp_enqueue_style(
			'wf-mr-shortcodes',
			plugin_dir_url( WF_MR_PLUGIN_FILE ) . $css_rel,
			array(),
			'1.0.0'
		);
	}
} );

/**
 * Ensure helpers are loaded early so all plugin code can call wf_is_match_tag() and other helpers.
 * This makes helper functions available before shortcodes or backend processors require them.
 */
$helpers = WF_MR_PLUGIN_DIR . 'includes/helpers.php';
if ( file_exists( $helpers ) ) {
	require_once $helpers;
}

/**
 * Load shortcodes loader (preferred) with a safe fallback to the old file.
 * - includes/shortcodes-loader.php is the new bootstrap that loads helpers.php
 *   and registers/loads all shortcodes (match-listing included).
 * - If for any reason that file is missing, fall back to the legacy
 *   includes/public-shortcodes.php so the site continues to function.
 */
$shortcodes_loader = WF_MR_PLUGIN_DIR . 'includes/shortcodes-loader.php';
$legacy_shortcodes  = WF_MR_PLUGIN_DIR . 'includes/public-shortcodes.php';

if ( file_exists( $shortcodes_loader ) ) {
	require_once $shortcodes_loader;
} elseif ( file_exists( $legacy_shortcodes ) ) {
	// Fallback for safety while migrating.
	require_once $legacy_shortcodes;
} else {
	// Neither file found: log an admin notice (no HTML output here).
	add_action( 'admin_notices', function() {
		echo '<div class="notice notice-error"><p><strong>WF Match Reign Processor:</strong> missing shortcodes loader. Please restore includes/shortcodes-loader.php or includes/public-shortcodes.php.</p></div>';
	} );
}

/**
 * Ensure backend processors and save handlers are loaded so their hooks register.
 * This makes the plugin resilient when WordPress loads files in different contexts
 * (REST, ajax, admin). We only require existing files and never fail if a file is missing.
 *
 * Files included here are intentionally limited to server-side processors that
 * register save_post / acf/save_post / transition_post_status / delete hooks.
 */
$backend_includes = array(
	'includes/rebuild-superstar-counters.php',
	'includes/save-handlers-wf-fixes.php',
	'includes/reign-save-handler.php',
	'includes/admin-manual-reigns.php',
	// additional integration helpers that contain save/time-sensitive logic
	'includes/wf-field-integrations.php',
);

foreach ( $backend_includes as $inc ) {
	$path = WF_MR_PLUGIN_DIR . $inc;
	if ( file_exists( $path ) ) {
		require_once $path;
	}
}

// in wf-match-reign-processor.php (plugin bootstrap) near other includes:
$manifest_admin = WF_MR_PLUGIN_DIR . 'includes/admin/superstar-manifest.php';
if ( file_exists( $manifest_admin ) ) {
	require_once $manifest_admin;
}

// Optionally include the CLI command when WP-CLI is running:
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	$cli_gen = WF_MR_PLUGIN_DIR . 'includes/cli/generate-manifest.php';
	if ( file_exists( $cli_gen ) ) {
		require_once $cli_gen;
	}
}

/**
 * Register/enqueue editor assets and register the GenerateBlocks server-side block.
 *
 * - Enqueue the canonical stylesheet into the block editor so GenerateBlocks preview matches the front-end.
 * - Load the server-side block registration file if present (blocks/wf-superstar-gb-block.php).
 */

/* Enqueue stylesheet in block editor for accurate preview */
add_action( 'enqueue_block_editor_assets', function() {
	$css_rel  = 'includes/assets/css/shortcodes.css';
	$css_path = WF_MR_PLUGIN_DIR . $css_rel;
	if ( file_exists( $css_path ) ) {
		wp_enqueue_style(
			'wf-mr-shortcodes-editor',
			plugin_dir_url( WF_MR_PLUGIN_FILE ) . $css_rel,
			array(),
			'1.0.0'
		);
	}
}, 20 );

/* Load GenerateBlocks-friendly server-side block registration (if present) */
$gb_block_file = WF_MR_PLUGIN_DIR . 'includes/blocks/wf-superstar-gb-block.php';
if ( file_exists( $gb_block_file ) ) {
	require_once $gb_block_file;
}

/**
 * If you have other plugin components (admin pages, REST endpoints, etc.)
 * require them here. Example (uncomment and adapt if you have those files):
 *
 * if ( file_exists( WF_MR_PLUGIN_DIR . 'includes/admin.php' ) ) {
 *     require_once WF_MR_PLUGIN_DIR . 'includes/admin.php';
 * }
 *
 * Keep activation/deactivation hooks here if needed.
 */

// Example activation/deactivation hooks (uncomment and implement if needed):
// register_activation_hook( __FILE__, 'wf_mr_activate' );
// register_deactivation_hook( __FILE__, 'wf_mr_deactivate' );
// function wf_mr_activate() { /* activation tasks */ }
// function wf_mr_deactivate() { /* deactivation tasks */ }