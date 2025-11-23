<?php
/**
 * Register GenerateBlocks-friendly WF Superstar Record server-rendered block.
 *
 * Location: includes/blocks/wf-superstar-gb-block.php
 *
 * This block renders the same HTML as the shortcode and exposes simple inspector
 * attributes so editors can preview other posts and toggle a few sections.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', function() {
	if ( ! function_exists( 'register_block_type' ) ) {
		return;
	}

	$plugin_dir = defined( 'WF_MR_PLUGIN_DIR' ) ? trailingslashit( WF_MR_PLUGIN_DIR ) : plugin_dir_path( __FILE__ ) . '../../..';
	$editor_js_rel  = 'includes/assets/js/wf-superstar-gb-block.js';
	$editor_js_path = $plugin_dir . $editor_js_rel;
	$editor_js_url  = defined( 'WF_MR_PLUGIN_FILE' ) ? plugin_dir_url( WF_MR_PLUGIN_FILE ) . $editor_js_rel : plugin_dir_url( __FILE__ ) . '../../../' . $editor_js_rel;

	if ( file_exists( $editor_js_path ) ) {
		wp_register_script(
			'wf-superstar-gb-block-editor',
			$editor_js_url,
			array( 'wp-blocks', 'wp-element', 'wp-server-side-render', 'wp-editor', 'wp-components' ),
			filemtime( $editor_js_path ),
			true
		);
	} else {
		wp_register_script( 'wf-superstar-gb-block-editor', '' );
	}

	register_block_type( 'wf/superstar-record-gb', array(
		'editor_script'   => 'wf-superstar-gb-block-editor',
		'render_callback' => 'wf_render_superstar_record_gb_block',
		'attributes'      => array(
			'postId'     => array( 'type' => 'integer' ),
			'showBio'    => array( 'type' => 'boolean', 'default' => true ),
			'showChamps' => array( 'type' => 'boolean', 'default' => true ),
			'showFeuds'  => array( 'type' => 'boolean', 'default' => true ),
		),
	) );
} );

/**
 * Server render callback for the GB block.
 *
 * Calls the shortcode renderer for parity.
 *
 * @param array $attributes Block attributes.
 * @return string
 */
function wf_render_superstar_record_gb_block( $attributes = array() ) {
	$post_id = 0;
	if ( isset( $attributes['postId'] ) && ! empty( $attributes['postId'] ) ) {
		$post_id = intval( $attributes['postId'] );
	}
	if ( ! $post_id ) {
		$post = get_post();
		if ( $post && isset( $post->ID ) ) $post_id = intval( $post->ID );
	}

	// Fallback defaults for toggles
	$show_bio    = isset( $attributes['showBio'] ) ? (bool) $attributes['showBio'] : true;
	$show_champs = isset( $attributes['showChamps'] ) ? (bool) $attributes['showChamps'] : true;
	$show_feuds  = isset( $attributes['showFeuds'] ) ? (bool) $attributes['showFeuds'] : true;

	if ( function_exists( 'wf_shortcode_superstar_record' ) ) {
		return wf_shortcode_superstar_record( array(
			'id'         => $post_id,
			'show_bio'   => $show_bio,
			'show_champs'=> $show_champs,
			'show_feuds' => $show_feuds,
		), '' );
	}

	$shortcode = '[wf_superstar_record id="' . esc_attr( $post_id ) . '"';
	$shortcode .= ' show_bio="' . ( $show_bio ? '1' : '0' ) . '"';
	$shortcode .= ' show_champs="' . ( $show_champs ? '1' : '0' ) . '"';
	$shortcode .= ' show_feuds="' . ( $show_feuds ? '1' : '0' ) . '"]';

	return do_shortcode( $shortcode );
}