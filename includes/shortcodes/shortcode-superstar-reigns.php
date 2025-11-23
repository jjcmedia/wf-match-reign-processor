<?php
/**
 * wf_superstar_reigns shortcode implementation.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'jjc_mh_ensure_string' ) ) {
	function jjc_mh_ensure_string( $val ) {
		if ( is_null( $val ) ) return '';
		if ( is_scalar( $val ) ) return (string) $val;
		if ( is_object( $val ) ) {
			if ( isset( $val->ID ) ) return (string) intval( $val->ID );
			if ( isset( $val->post_title ) ) return (string) $val->post_title;
		}
		if ( is_array( $val ) ) return implode( ', ', array_map( 'jjc_mh_ensure_string', $val ) );
		return '';
	}
}

if ( ! function_exists( 'wf_shortcode_superstar_reigns' ) ) {
	function wf_shortcode_superstar_reigns( $atts = array() ) {
		$a = shortcode_atts( array(
			'id' => 0,
			'image_size' => 'thumbnail',
			'show_images' => '1',
			'limit' => 0,
		), $atts, 'wf_superstar_reigns' );

		$id = intval( $a['id'] );
		if ( ! $id ) {
			$qid = intval( get_queried_object_id() );
			if ( $qid ) $id = $qid;
		}
		if ( ! $id ) {
			global $post;
			if ( isset( $post ) && is_object( $post ) ) $id = intval( $post->ID );
		}
		if ( ! $id ) return '';

		$limit = intval( $a['limit'] );
		$rposts = get_posts( array(
			'post_type' => 'reign',
			'post_status' => 'publish',
			'numberposts' => ( $limit > 0 ? $limit : -1 ),
			'meta_query' => array( array( 'key' => 'wf_reign_champions', 'value' => '"' . intval( $id ) . '"', 'compare' => 'LIKE' ) ),
			'orderby' => 'meta_value',
			'meta_key' => 'wf_reign_start_date',
			'order' => 'DESC',
		) );

		if ( empty( $rposts ) ) return '<div class="wf-superstar-reigns"><p>No reigns found for this superstar.</p></div>';

		$out = '<ul class="wf-reign-list">';
		$show_images = filter_var( $a['show_images'], FILTER_VALIDATE_BOOLEAN );

		$to_ts = function( $raw ) {
			if ( empty( $raw ) ) return false;
			$raw = (string) $raw;
			if ( preg_match( '/^\d{8}$/', $raw ) ) {
				$dt = DateTime::createFromFormat( 'Ymd', $raw );
				if ( $dt ) return $dt->getTimestamp();
			}
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
				$ts = strtotime( $raw );
				if ( $ts !== false ) return $ts;
			}
			if ( ctype_digit( $raw ) && strlen( $raw ) <= 10 ) return intval( $raw );
			$ts = strtotime( $raw );
			if ( $ts !== false ) return $ts;
			return false;
		};

		foreach ( $rposts as $r ) {
			$title_id   = get_post_meta( $r->ID, 'wf_reign_title', true );
			$title_name = $title_id ? get_the_title( $title_id ) : '(unknown title)';

			$start_raw = get_post_meta( $r->ID, 'wf_reign_start_date', true );
			$end_raw   = get_post_meta( $r->ID, 'wf_reign_end_date', true );
			$is_current_meta = get_post_meta( $r->ID, 'wf_reign_is_current', true );
			$is_current = ( $is_current_meta !== '' ) ? (bool) $is_current_meta : ( empty( $end_raw ) ? true : false );

			$start = '';
			if ( $start_raw !== '' && $start_raw !== null ) $start = function_exists( 'wf_format_reign_date_long' ) ? wf_format_reign_date_long( $start_raw ) : jjc_mh_ensure_string( $start_raw );
			$end = '';
			if ( $end_raw !== '' && $end_raw !== null ) $end = function_exists( 'wf_format_reign_date_long' ) ? wf_format_reign_date_long( $end_raw ) : jjc_mh_ensure_string( $end_raw );

			$img_html = '';
			if ( $show_images && $title_id && function_exists( 'wf_get_championship_image_html' ) ) {
				$img_html = wf_get_championship_image_html( intval( $title_id ), $a['image_size'] );
			}

			$days_text = '';
			$start_ts = $to_ts( $start_raw );
			$end_ts = $to_ts( $end_raw );
			if ( ! $end_ts || $is_current ) $end_ts = current_time( 'timestamp' );
			if ( $start_ts && $end_ts && $end_ts >= $start_ts ) {
				$days = floor( ( $end_ts - $start_ts ) / 86400 ) + 1;
				$days_text = $days . ' ' . ( $days === 1 ? 'day' : 'days' );
			}

			$defenses = intval( get_post_meta( $r->ID, 'wf_reign_defenses', true ) );
			if ( $defenses < 0 ) $defenses = 0;

			$out .= '<li class="wf-reign-row">';
			if ( $img_html ) $out .= '<div class="wf-reign-thumb">' . $img_html . '</div>';
			$out .= '<div class="wf-reign-main">';
			$out .= '<div class="wf-reign-title"><strong>' . esc_html( $title_name ) . '</strong>' . ( $is_current ? ' <span class="wf-reign-current" aria-hidden="true">— current</span>' : '' ) . '</div>';
			if ( $start ) $out .= '<div class="wf-reign-meta">' . esc_html( $start ) . ( $end ? ' — ' . esc_html( $end ) : '' ) . '</div>';
			$notes = trim( strip_tags( $r->post_content ) );
			if ( $notes ) {
				$excerpt = wp_html_excerpt( $notes, 140, '...' );
				$out .= '<div class="wf-reign-excerpt">' . esc_html( $excerpt ) . '</div>';
			}
			$out .= '</div>';
			$out .= '<div class="wf-reign-side" aria-hidden="true">';
			$out .= '<div class="wf-reign-side-item"><span class="wf-reign-side-label">Days</span><span class="wf-reign-side-value">' . esc_html( $days_text ? $days_text : '—' ) . '</span></div>';
			$out .= '<div class="wf-reign-side-item"><span class="wf-reign-side-label">Defenses</span><span class="wf-reign-side-value">' . esc_html( number_format_i18n( $defenses ) ) . '</span></div>';
			$out .= '</div>';
			$out .= '</li>';
		}

		$out .= '</ul>';
		return '<div class="wf-superstar-reigns">' . $out . '</div>';
	}
}