<?php
/**
 * Central config for which match_type values should be treated as tag matches.
 *
 * Place this file in includes/ and ensure helpers.php requires it:
 *   require_once __DIR__ . '/match-type-config.php';
 *
 * To override the list in your theme or another plugin, hook the filter:
 *   add_filter( 'wf_tag_match_types', function( $types ){ $types[] = 'my-custom-tag'; return $types; } );
 *
 * Values are case-insensitive and matched against:
 *  - ACF or meta match_type string
 *  - taxonomy term name or slug
 *  - numeric term IDs will be resolved by wf_is_match_tag
 *
 * Keep this list conservative; add types you actually use in the admin UI.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'wf_get_tag_match_types' ) ) {
	function wf_get_tag_match_types() {
		$defaults = array(
			// common variants (lowercase)
			'tag',
			'tag team',
			'tag-team',
			'tagteam',
			'six-man tag',
			'trios',
			'trios tag',
			'eight-man tag',
			'four-on-four tag',
			'gauntlet tag',
			'mens-war-games',
			'wargames'
		);

		// Allow extensions to add/remove items
		$types = apply_filters( 'wf_tag_match_types', $defaults );

		// Normalize to lowercase strings
		$norm = array();
		if ( is_array( $types ) ) {
			foreach ( $types as $t ) {
				if ( ! is_scalar( $t ) ) continue;
				$val = trim( strtolower( (string) $t ) );
				if ( $val !== '' ) $norm[ $val ] = $val;
			}
		}
		return array_values( $norm );
	}
}