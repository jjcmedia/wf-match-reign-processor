<?php
/**
 * Save handlers & fixes for WF Match Reign Processor
 *
 * - Detects explicit "clear winners" actions in the match editor and removes wf_winners/wf_match_snapshot.
 * - Synchronizes wf_winners / winners postmeta from participants_details is_winner flags on save.
 *
 * Path:
 *   wp-content/plugins/wf-match-reign-processor/includes/save-handlers-wf-fixes.php
 *
 * Notes:
 * - Designed to be a single authoritative save-handler module to avoid multiple files doing overlapping tasks.
 * - Hook ordering:
 *     - wf_handle_explicit_cleared_match_winners runs early on save_post (priority 9) to clear legacy meta when editor explicitly cleared winners.
 *     - jjc_mh_sync_winners_on_save runs after ACF/core saves (acf/save_post and save_post priority 20) to set wf_winners when winners exist.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -----------------------
 * Explicit clear detection
 * ----------------------- */

if ( ! function_exists( 'wf_handle_explicit_cleared_match_winners' ) ) {
    add_action( 'save_post', 'wf_handle_explicit_cleared_match_winners', 9, 3 );

    function wf_handle_explicit_cleared_match_winners( $post_id, $post = null, $update = null ) {
        // Only operate on match CPT
        if ( defined( 'WF_MATCH_CPT' ) ) {
            $match_cpt = WF_MATCH_CPT;
        } else {
            $match_cpt = 'match';
        }

        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) return;
        if ( ! $post ) $post = get_post( $post_id );
        if ( ! isset( $post->post_type ) || $post->post_type !== $match_cpt ) return;

        // Avoid autosave contexts
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        // NOTE: do NOT bail on REST_REQUEST here; we must detect explicit clears coming from Gutenberg/REST saves.

        $explicit_clear = false;

        // 1) If wf_winners present in POST and submitted empty -> explicit clear
        if ( isset( $_POST['wf_winners'] ) ) {
            $posted = wp_unslash( $_POST['wf_winners'] );
            if ( $posted === '' || $posted === 'a:0:{}' || $posted === '[]' || ( is_array( $posted ) && count( $posted ) === 0 ) ) {
                $explicit_clear = true;
            }
        }

        // 2) Look into ACF POST if present (structured data)
        if ( ! $explicit_clear && isset( $_POST['acf'] ) && is_array( $_POST['acf'] ) ) {
            foreach ( $_POST['acf'] as $acf_key => $acf_val ) {
                $key_l = strtolower( $acf_key );
                if ( strpos( $key_l, 'winner' ) !== false || strpos( $key_l, 'participants_details' ) !== false || strpos( $key_l, 'reign_champions' ) !== false || strpos( $key_l, 'participant_details' ) !== false ) {
                    if ( is_array( $acf_val ) ) {
                        $nonempty = false;
                        foreach ( $acf_val as $v ) {
                            if ( is_array( $v ) ) {
                                foreach ( $v as $vv ) {
                                    if ( (string) $vv !== '' ) { $nonempty = true; break 2; }
                                }
                            } else {
                                if ( (string) $v !== '' ) { $nonempty = true; break; }
                            }
                        }
                        if ( ! $nonempty ) {
                            $explicit_clear = true;
                            break;
                        }
                    } else {
                        $val = trim( (string) $acf_val );
                        if ( $val === '' || $val === '[]' || $val === 'a:0:{}' ) {
                            $explicit_clear = true;
                            break;
                        }
                    }
                }
            }
        }

        // 3) Heuristic: If the editor posted individual participants_details_N_is_winner keys with empty values
        if ( ! $explicit_clear ) {
            foreach ( $_POST as $k => $v ) {
                if ( preg_match( '/^participants_details_\\d+_is_winner$/', $k ) || preg_match( '/^participant_details_\\d+_is_winner$/', $k ) ) {
                    $found_any_checked = false;
                    foreach ( $_POST as $kk => $vv ) {
                        if ( ( preg_match( '/^participants_details_\\d+_is_winner$/', $kk ) || preg_match( '/^participant_details_\\d+_is_winner$/', $kk ) ) && ! empty( $vv ) ) {
                            $found_any_checked = true;
                            break;
                        }
                    }
                    if ( ! $found_any_checked ) $explicit_clear = true;
                    break;
                }
            }
        }

        if ( $explicit_clear ) {
            // Delete canonical winners meta and snapshot so main processor won't reapply old values
            delete_post_meta( $post_id, 'wf_winners' );
            delete_post_meta( $post_id, 'winners' );
            delete_post_meta( $post_id, 'wf_match_snapshot' );

            // Also attempt to clear any ACF field if present (best-effort)
            if ( function_exists( 'update_field' ) ) {
                @update_field( 'wf_winners', array(), $post_id );
                @update_field( 'wf_match_snapshot', '', $post_id );
            }

            error_log( '[WF Fixes] Detected explicit winners clear on match ' . intval( $post_id ) . '; removed wf_winners, winners and wf_match_snapshot.' );
        }
    }
}

/* -------------------------------------
 * Winner sync helpers (normalize & sync)
 * ------------------------------------- */

if ( ! function_exists( 'jjc_mh_normalize_participant_field_to_id' ) ) {
    function jjc_mh_normalize_participant_field_to_id( $val ) {
        if ( empty( $val ) ) return 0;
        if ( function_exists( 'jjc_mh_get_post_id_from_field' ) ) {
            return intval( jjc_mh_get_post_id_from_field( $val ) );
        }
        return intval( $val );
    }
}

if ( ! function_exists( 'jjc_mh_get_winner_ids_from_match' ) ) {
    function jjc_mh_get_winner_ids_from_match( $post_id ) {
        $post_id = intval( $post_id );
        if ( ! $post_id ) return array();

        $winner_ids = array();

        // 1) participants_details (ACF or postmeta serialized)
        $rows = array();
        if ( function_exists( 'get_field' ) ) {
            // Accept either 'participants_details' or 'participant_details'
            $maybe = get_field( 'participants_details', $post_id );
            if ( empty( $maybe ) ) $maybe = get_field( 'participant_details', $post_id );
            if ( is_array( $maybe ) ) $rows = $maybe;
        }
        if ( empty( $rows ) ) {
            // Try both meta keys as fallback
            $raw = get_post_meta( $post_id, 'participants_details', true );
            if ( $raw === '' || $raw === null ) $raw = get_post_meta( $post_id, 'participant_details', true );
            if ( $raw !== '' && $raw !== null ) {
                $maybe = maybe_unserialize( $raw );
                if ( is_array( $maybe ) ) $rows = $maybe;
            }
        }

        if ( is_array( $rows ) && ! empty( $rows ) ) {
            foreach ( $rows as $r ) {
                if ( ! is_array( $r ) ) continue;
                if ( isset( $r['is_winner'] ) && filter_var( $r['is_winner'], FILTER_VALIDATE_BOOLEAN ) ) {
                    $pid = isset( $r['participant'] ) ? $r['participant'] : 0;
                    $pid = jjc_mh_normalize_participant_field_to_id( $pid );
                    if ( $pid ) $winner_ids[] = $pid;
                }
            }
        }

        // 2) fallback: existing wf_winners or winners meta (used only if no participants_details winners found)
        if ( empty( $winner_ids ) ) {
            $meta = get_post_meta( $post_id, 'wf_winners', true );
            if ( empty( $meta ) ) $meta = get_post_meta( $post_id, 'winners', true );
            if ( $meta ) {
                $maybe = is_array( $meta ) ? $meta : maybe_unserialize( $meta );
                if ( is_array( $maybe ) ) {
                    foreach ( $maybe as $m ) {
                        $m = intval( $m );
                        if ( $m ) $winner_ids[] = $m;
                    }
                } elseif ( is_numeric( $maybe ) ) {
                    $winner_ids[] = intval( $maybe );
                }
            }
        }

        $winner_ids = array_values( array_unique( array_map( 'intval', array_filter( $winner_ids ) ) ) );
        return $winner_ids;
    }
}

if ( ! function_exists( 'jjc_mh_sync_winners_on_save' ) ) {
    function jjc_mh_sync_winners_on_save( $post_id ) {
        // Accept either numeric post_id (save_post) or "post_{$id}" from ACF; normalize
        if ( is_string( $post_id ) && preg_match( '/^post_(\d+)$/', $post_id, $m ) ) {
            $post_id = intval( $m[1] );
        } else {
            $post_id = intval( $post_id );
        }
        if ( ! $post_id ) return;

        // Basic protections
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( wp_is_post_revision( $post_id ) ) return;
        if ( wp_is_post_autosave( $post_id ) ) return;

        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== ( defined( 'WF_MATCH_CPT' ) ? WF_MATCH_CPT : 'match' ) ) return;

        // Build winner IDs from participants_details (primary source)
        $winner_ids = jjc_mh_get_winner_ids_from_match( $post_id );

        // If we found winners, update canonical metas; if none, remove the metas.
        if ( ! empty( $winner_ids ) ) {
            update_post_meta( $post_id, 'wf_winners', $winner_ids );
            update_post_meta( $post_id, 'winners', $winner_ids );
        } else {
            delete_post_meta( $post_id, 'wf_winners' );
            delete_post_meta( $post_id, 'winners' );
        }
    }

    // Hook sync after ACF saves and on core save_post for match posts (priority 20).
    if ( function_exists( 'add_action' ) ) {
        add_action( 'acf/save_post', 'jjc_mh_sync_winners_on_save', 20 );
        // Also run on generic save_post to cover REST/gutenberg save flows.
        add_action( 'save_post', 'jjc_mh_sync_winners_on_save', 20 );
    }
}

/* End of save-handlers-wf-fixes.php */