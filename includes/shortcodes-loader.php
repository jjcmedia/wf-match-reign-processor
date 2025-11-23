<?php
/**
 * Shortcodes loader - load helpers, include all shortcode implementation files,
 * and register expected shortcode tags. Conservative: does not remove or change
 * implementation files; registers explicit mappings with runtime wrappers so
 * shortcodes won't render as raw text even if callbacks are defined later.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', function() {

	$includes_dir   = dirname( __DIR__ );      // plugin includes dir (one level up)
	$shortcodes_dir = __DIR__ . '/shortcodes';
	$helpers_file   = $includes_dir . '/helpers.php';

	// 1) Load helpers first so helper functions exist
	if ( file_exists( $helpers_file ) ) {
		require_once $helpers_file;
	}

	// 2) Include every PHP file inside includes/shortcodes (non-recursive).
	if ( is_dir( $shortcodes_dir ) ) {
		foreach ( glob( $shortcodes_dir . '/*.php' ) as $file ) {
			@include_once $file;
		}
	}

	// 3) Explicit map of shortcode tag => implementation function name.
	$explicit_map = array(
		'wf_superstar_reigns'         => 'wf_shortcode_superstar_reigns',
		'match_participants_title_acf'=> 'jjc_mh_match_participants_title_acf',
		'wf_championship_stats'       => 'wf_shortcode_championship_stats',
		'wf_superstar_record'         => 'wf_shortcode_superstar_reigns',
		'participants_title_acf'      => 'jjc_mh_match_participants_title_acf',
		'wf_match_participants'       => 'jjc_mh_match_participants_title_acf',
	);

	foreach ( $explicit_map as $tag => $fn ) {
		if ( shortcode_exists( $tag ) ) continue;

		if ( function_exists( $fn ) ) {
			add_shortcode( $tag, $fn );
			continue;
		}

		add_shortcode( $tag, function( $atts = array(), $content = null, $tag_name = '' ) use ( $fn ) {
			if ( function_exists( $fn ) ) {
				return call_user_func( $fn, $atts, $content, $tag_name );
			}
			return '';
		} );
	}

	// 4) Auto-register for common naming patterns
	$user_funcs = get_defined_functions();
	$user_funcs = isset( $user_funcs['user'] ) ? $user_funcs['user'] : array();

	foreach ( $user_funcs as $fn ) {
		if ( strpos( $fn, 'wf_shortcode_' ) === 0 ) {
			$tag = substr( $fn, strlen( 'wf_shortcode_' ) );
			if ( $tag && ! shortcode_exists( $tag ) ) add_shortcode( $tag, $fn );
			continue;
		}
		if ( strpos( $fn, 'jjc_mh_' ) === 0 ) {
			$tag = substr( $fn, strlen( 'jjc_mh_' ) );
			if ( $tag && ! shortcode_exists( $tag ) ) {
				if ( strpos( $tag, '_' ) !== false || preg_match( '/match|participant|title|reign|champ|participants/i', $tag ) ) {
					add_shortcode( $tag, $fn );
				}
			}
			continue;
		}
		if ( strpos( $fn, 'wf_' ) === 0 && substr( $fn, -10 ) === '_shortcode' ) {
			$tag = substr( $fn, 3, -10 );
			if ( $tag && ! shortcode_exists( $tag ) ) add_shortcode( $tag, $fn );
			continue;
		}
	}

}, 5 );