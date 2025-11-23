<?php
/**
 * WF Field Integrations
 * Central helpers to read ACF/Postmeta fields for Matches and Championships.
 *
 * - Robust team expansion helper used by shortcode.
 *
 * Path: wp-content/plugins/wf-match-reign-processor/includes/wf-field-integrations.php
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Return incoming champion post ID for a Match post.
 */
function wf_get_incoming_champion( $match_id ) {
    $match_id = intval( $match_id );
    if ( ! $match_id ) return 0;
    if ( function_exists( 'get_field' ) ) {
        $acf = get_field( 'incoming_champion', $match_id );
        if ( ! empty( $acf ) ) {
            if ( is_array( $acf ) ) {
                $first = reset( $acf );
                if ( is_object( $first ) && isset( $first->ID ) ) return intval( $first->ID );
                if ( is_numeric( $first ) ) return intval( $first );
            } else {
                if ( is_object( $acf ) && isset( $acf->ID ) ) return intval( $acf->ID );
                if ( is_numeric( $acf ) ) return intval( $acf );
            }
        }
    }
    $meta = get_post_meta( $match_id, 'incoming_champion', true );
    if ( is_numeric( $meta ) && intval( $meta ) ) return intval( $meta );
    return 0;
}

/**
 * Expand a team post ID to an array of member superstar IDs.
 */
if ( ! function_exists( 'jjc_mh_expand_team_to_members' ) ) {
    function jjc_mh_expand_team_to_members( $team_id ) {
        $team_id = intval( $team_id );
        if ( ! $team_id ) return array();

        $members = array();

        if ( function_exists( 'get_field' ) ) {
            $possible_fields = array( 'team_members', 'members', 'wf_team_members', 'team_members_list' );
            foreach ( $possible_fields as $field ) {
                $acf = get_field( $field, $team_id );
                if ( ! empty( $acf ) ) break;
            }
            if ( ! empty( $acf ) ) {
                if ( is_array( $acf ) ) {
                    foreach ( $acf as $item ) {
                        if ( is_object( $item ) && isset( $item->ID ) ) $members[] = intval( $item->ID );
                        elseif ( is_array( $item ) && isset( $item['ID'] ) ) $members[] = intval( $item['ID'] );
                        elseif ( is_numeric( $item ) ) $members[] = intval( $item );
                    }
                } elseif ( is_object( $acf ) && isset( $acf->ID ) ) {
                    $members[] = intval( $acf->ID );
                } elseif ( is_numeric( $acf ) ) {
                    $members[] = intval( $acf );
                }
            }
        }

        if ( empty( $members ) ) {
            $possible_meta_keys = array( 'team_members', 'members', 'wf_team_members', 'team_members_list' );
            $meta = null;
            foreach ( $possible_meta_keys as $k ) {
                $val = get_post_meta( $team_id, $k, true );
                if ( $val !== '' && $val !== null ) { $meta = $val; break; }
            }
            if ( $meta ) {
                if ( is_array( $meta ) ) {
                    foreach ( $meta as $m ) { $m = intval( $m ); if ( $m ) $members[] = $m; }
                } elseif ( is_string( $meta ) && strpos( $meta, ',' ) !== false ) {
                    $parts = array_map( 'trim', explode( ',', $meta ) );
                    foreach ( $parts as $p ) { if ( is_numeric( $p ) ) $members[] = intval( $p ); }
                } else {
                    $maybe = maybe_unserialize( $meta );
                    if ( is_array( $maybe ) ) {
                        foreach ( $maybe as $m ) { $m = intval( $m ); if ( $m ) $members[] = $m; }
                    } elseif ( is_numeric( $meta ) ) {
                        $members[] = intval( $meta );
                    }
                }
            }
        }

        $members = array_values( array_unique( array_map( 'intval', array_filter( $members ) ) ) );
        return $members;
    }
}