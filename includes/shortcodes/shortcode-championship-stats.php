<?php
/**
 * wf_championship_stats shortcode (minimal)
 *
 * Expects helpers.php to be loaded (wf_get_championship_image_html).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'wf_shortcode_championship_stats' ) ) {
	function wf_shortcode_championship_stats( $atts = array() ) {
		$a = shortcode_atts( array( 'id' => 0 ), $atts, 'wf_championship_stats' );
		$id = intval( $a['id'] );
		if ( ! $id ) return '';
		$img = '';
		if ( function_exists( 'wf_get_championship_image_html' ) ) $img = wf_get_championship_image_html( $id, 'medium' );
		$out = $img ? '<div class="wf-championship-image">' . $img . '</div>' : '';
		$out .= '<h2 class="wf-championship-title">' . esc_html( get_the_title( $id ) ) . '</h2>';
		return '<div class="wf-championship-stats">' . $out . '</div>';
	}
}