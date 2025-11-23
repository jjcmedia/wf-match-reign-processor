<?php
/**
 * Reign apply/reverse helpers and save handler
 *
 * Centralized processing for Reign posts and automatic Reign creation from Matches:
 * - wf_apply_reign( $reign_id, $opts = array() ) applies a reign (idempotent).
 * - wf_reign_reverse_snapshot_effects( $reign_id, $snapshot ) reverses applied snapshot.
 * - save_post hook processes manual reign posts.
 * - save_post hook wf_on_match_sync_reign_on_save automatically creates or reverses a Reign when
 *   a championship match is saved and the winners / title outcome changed.
 *
 * Install:
 *   Save to:
 *     wp-content/plugins/wf-match-reign-processor/includes/reign-save-handler.php
 *
 * Notes:
 * - This file is defensive and idempotent.
 * - It computes wf_title_changed automatically and writes it to the match.
 * - When an existing reign was created from a match and the match is edited so the
 *   winners no longer match that Reign’s champions, the Reign will be reversed and permanently deleted.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -------------------------
 * Core utilities
 * ------------------------- */

/**
 * Reverse effects previously applied to a saved reign snapshot.
 * Used internally by wf_apply_reign and on delete flows.
 *
 * @param int $reign_id
 * @param array $snapshot
 * @return void
 */
function wf_reign_reverse_snapshot_effects( $reign_id, $snapshot ) {
    if ( empty( $snapshot ) || ! is_array( $snapshot ) ) {
        return;
    }

    $applied_champs = isset( $snapshot['champions'] ) ? (array) $snapshot['champions'] : array();
    $was_current = ! empty( $snapshot['is_current'] );

    foreach ( $applied_champs as $s ) {
        $s = intval( $s );
        if ( ! $s ) continue;

        // decrement reign count safely
        $curcnt = intval( get_post_meta( $s, 'wf_title_reign_count', true ) );
        $newcnt = max( 0, $curcnt - 1 );
        update_post_meta( $s, 'wf_title_reign_count', $newcnt );

        // remove reign from wf_current_titles if it was current before
        if ( $was_current ) {
            $cur = get_post_meta( $s, 'wf_current_titles', true );
            if ( empty( $cur ) ) $cur = array();
            if ( ! is_array( $cur ) ) $cur = (array) $cur;
            if ( ( $k = array_search( $reign_id, $cur, true ) ) !== false ) {
                unset( $cur[ $k ] );
                update_post_meta( $s, 'wf_current_titles', array_values( $cur ) );
            }
        }
    }

    // Re-open any closed reigns that we previously closed for this application
    if ( ! empty( $snapshot['closed_reign_ids'] ) && is_array( $snapshot['closed_reign_ids'] ) ) {
        foreach ( $snapshot['closed_reign_ids'] as $rid ) {
            $rid = intval( $rid );
            if ( ! $rid ) continue;
            update_post_meta( $rid, 'wf_reign_end_date', '' );
            update_post_meta( $rid, 'wf_reign_ended_by_match', 0 );
            update_post_meta( $rid, 'wf_reign_is_current', 1 );
            if ( function_exists( 'update_field' ) ) {
                update_field( 'wf_reign_end_date', '', $rid );
                update_field( 'wf_reign_ended_by_match', 0, $rid );
                update_field( 'wf_reign_is_current', 1, $rid );
            }

            // re-add reopened reign id to their champions' wf_current_titles
            $meta_champs = get_post_meta( $rid, 'wf_reign_champions', true );
            if ( ! is_array( $meta_champs ) ) $meta_champs = (array) $meta_champs;
            foreach ( $meta_champs as $sc ) {
                $sc = intval( $sc );
                if ( ! $sc ) continue;
                $cur = get_post_meta( $sc, 'wf_current_titles', true );
                if ( empty( $cur ) ) $cur = array();
                if ( ! is_array( $cur ) ) $cur = (array) $cur;
                if ( ! in_array( $rid, $cur, true ) ) {
                    $cur[] = $rid;
                    update_post_meta( $sc, 'wf_current_titles', $cur );
                }
            }
        }
    }

    // Remove snapshot so that it's not reversed again accidentally
    delete_post_meta( $reign_id, 'wf_reign_snapshot' );
}

/**
 * Apply a Reign: perform bookkeeping (close prior reigns, update champs counters, snapshot).
 *
 * @param int $reign_id
 * @param array $opts Optional: array('manual'=>true) etc.
 * @return array Result summary: ['status'=>'ok'|'error', 'created_closed'=>[], 'applied_champions'=>[], 'message'=>'']
 */
function wf_apply_reign( $reign_id, $opts = array() ) {
    $reign_id = intval( $reign_id );
    if ( ! $reign_id ) {
        return array( 'status' => 'error', 'message' => 'Invalid reign id' );
    }

    // simple lock to avoid concurrent runs / recursion
    $lock = 'wf_apply_reign_lock_' . $reign_id;
    if ( get_transient( $lock ) ) {
        return array( 'status' => 'error', 'message' => 'Lock active' );
    }
    set_transient( $lock, 1, 10 );

    $result = array(
        'status' => 'ok',
        'applied_champions' => array(),
        'closed_reign_ids' => array(),
    );

    // get canonical data for this reign
    $champ_id = intval( get_post_meta( $reign_id, 'wf_reign_title', true ) );
    if ( ! $champ_id ) $champ_id = intval( get_post_meta( $reign_id, 'championship', true ) );

    $champions = get_post_meta( $reign_id, 'wf_reign_champions', true );
    if ( empty( $champions ) ) $champions = get_post_meta( $reign_id, 'championship_holders', true );
    if ( ! is_array( $champions ) ) $champions = (array) $champions;
    $champions = array_values( array_filter( array_map( 'intval', $champions ) ) );

    $start_date = get_post_meta( $reign_id, 'wf_reign_start_date', true );
    if ( empty( $start_date ) ) $start_date = get_post_meta( $reign_id, 'reign_start_date', true );

    $end_date = get_post_meta( $reign_id, 'wf_reign_end_date', true );
    if ( empty( $end_date ) ) $end_date = get_post_meta( $reign_id, 'reign_end_date', true );

    $is_current = get_post_meta( $reign_id, 'wf_reign_is_current', true );
    if ( $is_current === '' ) $is_current = get_post_meta( $reign_id, 'reign_is_current', true );
    $is_current = ! empty( $is_current ) ? 1 : 0;

    // Reverse previous snapshot if applied
    $prev_raw = get_post_meta( $reign_id, 'wf_reign_snapshot', true );
    $prev = $prev_raw ? json_decode( $prev_raw, true ) : array();
    if ( ! empty( $prev ) && ! empty( $prev['applied'] ) ) {
        wf_reign_reverse_snapshot_effects( $reign_id, $prev );
    }

    // If this reign is marked current, close any existing current reigns for same championship
    $closed_reign_ids = array();
    if ( $is_current && $champ_id ) {
        $args = array(
            'post_type' => 'reign',
            'post_status' => 'publish',
            'fields' => 'ids',
            'nopaging' => true,
            'meta_query' => array(
                array( 'key' => 'wf_reign_title', 'value' => $champ_id, 'compare' => '=' ),
                array( 'key' => 'wf_reign_is_current', 'value' => '1', 'compare' => '=' ),
            ),
        );
        $current = get_posts( $args );
        if ( $current ) {
            foreach ( $current as $rid ) {
                $rid = intval( $rid );
                if ( $rid === $reign_id ) continue;
                $closed_reign_ids[] = $rid;
                update_post_meta( $rid, 'wf_reign_end_date', $start_date ? $start_date : current_time( 'Y-m-d' ) );
                update_post_meta( $rid, 'wf_reign_ended_by_match', 0 );
                update_post_meta( $rid, 'wf_reign_is_current', 0 );
                if ( function_exists( 'update_field' ) ) {
                    update_field( 'wf_reign_end_date', $start_date ? $start_date : current_time( 'Y-m-d' ), $rid );
                    update_field( 'wf_reign_ended_by_match', 0, $rid );
                    update_field( 'wf_reign_is_current', 0, $rid );
                }

                // Remove closed reign id from its champions' wf_current_titles
                $meta_champs = get_post_meta( $rid, 'wf_reign_champions', true );
                if ( ! is_array( $meta_champs ) ) $meta_champs = (array) $meta_champs;
                foreach ( $meta_champs as $sc ) {
                    $sc = intval( $sc );
                    if ( ! $sc ) continue;
                    $cur = get_post_meta( $sc, 'wf_current_titles', true );
                    if ( empty( $cur ) ) $cur = array();
                    if ( ! is_array( $cur ) ) $cur = (array) $cur;
                    if ( ( $k = array_search( $rid, $cur, true ) ) !== false ) {
                        unset( $cur[ $k ] );
                        update_post_meta( $sc, 'wf_current_titles', array_values( $cur ) );
                    }
                }
            }
        }
    }

    // Apply this reign: increment counters and add to wf_current_titles where necessary
    $applied_champions = array();
    foreach ( $champions as $s ) {
        $s = intval( $s );
        if ( ! $s ) continue;
        $curcnt = intval( get_post_meta( $s, 'wf_title_reign_count', true ) );
        update_post_meta( $s, 'wf_title_reign_count', $curcnt + 1 );

        if ( $is_current ) {
            $cur = get_post_meta( $s, 'wf_current_titles', true );
            if ( empty( $cur ) ) $cur = array();
            if ( ! is_array( $cur ) ) $cur = (array) $cur;
            if ( ! in_array( $reign_id, $cur, true ) ) {
                $cur[] = intval( $reign_id );
                update_post_meta( $s, 'wf_current_titles', $cur );
            }
        }

        $applied_champions[] = $s;
    }

    // Ensure canonical wf_ meta exist on this reign (do not override if already present)
    update_post_meta( $reign_id, 'wf_reign_manual', ! empty( $opts['manual'] ) ? 1 : 1 ); // mark manual by default for editor path
    if ( $champ_id ) update_post_meta( $reign_id, 'wf_reign_title', $champ_id );
    if ( ! empty( $champions ) ) update_post_meta( $reign_id, 'wf_reign_champions', $champions );
    if ( $start_date ) update_post_meta( $reign_id, 'wf_reign_start_date', $start_date );
    if ( $end_date ) update_post_meta( $reign_id, 'wf_reign_end_date', $end_date );
    update_post_meta( $reign_id, 'wf_reign_is_current', $is_current ? 1 : 0 );

    // ACF-friendly writes
    if ( function_exists( 'update_field' ) ) {
        if ( $champ_id ) update_field( 'wf_reign_title', $champ_id, $reign_id );
        if ( ! empty( $champions ) ) update_field( 'wf_reign_champions', $champions, $reign_id );
        if ( $start_date ) update_field( 'wf_reign_start_date', $start_date, $reign_id );
        if ( $end_date ) update_field( 'wf_reign_end_date', $end_date, $reign_id );
        update_field( 'wf_reign_is_current', $is_current ? 1 : 0, $reign_id );
        update_field( 'wf_reign_manual', 1, $reign_id );
    }

    // Build and save snapshot for idempotency
    $snapshot = array(
        'applied' => 1,
        'applied_at' => current_time( 'mysql' ),
        'champions' => $applied_champions,
        'is_current' => $is_current ? 1 : 0,
        'start_date' => $start_date,
        'end_date' => $end_date,
        'closed_reign_ids' => $closed_reign_ids,
        'processor' => 'wf_apply_reign',
    );
    update_post_meta( $reign_id, 'wf_reign_snapshot', wp_json_encode( $snapshot ) );

    $result['applied_champions'] = $applied_champions;
    $result['closed_reign_ids'] = $closed_reign_ids;

    // clear lock
    delete_transient( $lock );

    /**
     * Action hook after a reign is applied
     * do_action( 'wf_reign_applied', $reign_id, $result );
     */
    do_action( 'wf_reign_applied', $reign_id, $result );

    return $result;
}

/* -------------------------
 * Save handlers
 * ------------------------- */

/**
 * Hook: process reign saves (editor path)
 * This ensures editors creating/editing Reign posts get the same processing as match-generated reigns.
 */
add_action( 'save_post', 'wf_reign_save_post_handler', 20, 3 );
function wf_reign_save_post_handler( $post_id, $post, $update ) {
    if ( $post->post_type !== 'reign' ) return;
    if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) return;

    // call the central apply function; mark as manual
    wf_apply_reign( $post_id, array( 'manual' => true ) );
}

/**
 * Optional helper: before delete reverse effects (if a Reign is being permanently deleted)
 * If you permanently delete a reign (force delete), run this to reverse applied snapshot.
 */
add_action( 'before_delete_post', 'wf_reign_before_delete', 10, 1 );
function wf_reign_before_delete( $post_id ) {
    if ( get_post_type( $post_id ) !== 'reign' ) return;
    $raw = get_post_meta( $post_id, 'wf_reign_snapshot', true );
    if ( empty( $raw ) ) return;
    $snapshot = json_decode( $raw, true );
    if ( empty( $snapshot ) ) return;
    wf_reign_reverse_snapshot_effects( $post_id, $snapshot );
}

/* --------------------------------------------------------
   Automatic Reign sync from championship Matches (create / reverse)
   - Creates a Reign only when title_on_the_line is true AND winners differ from current champions
   - Reverses an existing Reign tied to the match when winners change such that the Reign
     no longer matches the match winners. Reversed Reigns are permanently deleted (force delete).
   - Idempotent: checks for existing reign linked to the match
   -------------------------------------------------------- */

/**
 * Helper: find a reign linked to a match (via wf_reign_won_match or wf_reign_created).
 */
function wf_find_reign_for_match( $match_id ) {
    $match_id = intval( $match_id );
    if ( ! $match_id ) return 0;

    // Try direct meta on match first
    $rid = intval( get_post_meta( $match_id, 'wf_reign_created', true ) );
    if ( $rid ) return $rid;

    // Query reigns that reference this match in wf_reign_won_match
    $found = get_posts( array(
        'post_type'   => 'reign',
        'post_status' => array( 'publish', 'private', 'draft' ),
        'numberposts' => 1,
        'meta_query'  => array(
            array( 'key' => 'wf_reign_won_match', 'value' => $match_id, 'compare' => '=' ),
        ),
        'fields' => 'ids',
    ) );
    if ( ! empty( $found ) ) return intval( $found[0] );

    return 0;
}

/**
 * Reverse and permanently delete a reign safely:
 * - reverses snapshot effects (wf_reign_reverse_snapshot_effects)
 * - marks reign meta to record reversal
 * - permanently deletes the Reign post (wp_delete_post with force=true)
 * - recomputes counters for affected participants (previous champions + current winners)
 */
function wf_reverse_and_close_reign_for_match( $reign_id, $match_id, $note = '' ) {
    $reign_id = intval( $reign_id );
    $match_id = intval( $match_id );
    if ( ! $reign_id ) return false;

    $raw = get_post_meta( $reign_id, 'wf_reign_snapshot', true );
    $snapshot = $raw ? json_decode( $raw, true ) : array();

    // Reverse any applied snapshot effects
    if ( ! empty( $snapshot ) && ! empty( $snapshot['applied'] ) ) {
        wf_reign_reverse_snapshot_effects( $reign_id, $snapshot );
    }

    // Mark reign as closed/ended by this match (metadata)
    $end_date = date_i18n( 'Y-m-d' );
    update_post_meta( $reign_id, 'wf_reign_end_date', $end_date );
    update_post_meta( $reign_id, 'wf_reign_ended_by_match', $match_id );
    update_post_meta( $reign_id, 'wf_reign_is_current', 0 );
    update_post_meta( $reign_id, 'wf_reign_reversed_by_match', $match_id );
    if ( $note ) update_post_meta( $reign_id, 'wf_reign_reversed_note', wp_strip_all_tags( $note ) );

    if ( function_exists( 'update_field' ) ) {
        update_field( 'wf_reign_end_date', $end_date, $reign_id );
        update_field( 'wf_reign_ended_by_match', $match_id, $reign_id );
        update_field( 'wf_reign_is_current', 0, $reign_id );
        update_field( 'wf_reign_reversed_by_match', $match_id, $reign_id );
    }

    // Permanently delete the reign post since it no longer represents a valid title change.
    // We do this after reversing snapshot effects to ensure counters and wf_current_titles are corrected.
    // IMPORTANT: This will remove the Reign post and its postmeta. Make a DB backup before running in production.
    if ( function_exists( 'wp_delete_post' ) ) {
        // log for audit
        error_log( '[WF] Permanently deleting Reign #' . intval( $reign_id ) . ' because match #' . intval( $match_id ) . ' winners changed.' );
        // force delete
        wp_delete_post( $reign_id, true );
    } else {
        // Fallback: if wp_delete_post is not available, move to draft as a safe fallback.
        wp_update_post( array( 'ID' => $reign_id, 'post_status' => 'draft' ) );
    }

    // Recompute counters for prior champions (if available) and anything in snapshot
    $affected = array();
    if ( isset( $snapshot['champions'] ) && is_array( $snapshot['champions'] ) ) {
        foreach ( $snapshot['champions'] as $c ) $affected[] = intval( $c );
    }
    // also include winners from the match snapshot (if any)
    $match_snap = get_post_meta( $match_id, 'wf_match_snapshot', true );
    if ( is_string( $match_snap ) ) $match_snap = json_decode( $match_snap, true );
    if ( is_array( $match_snap ) && isset( $match_snap['winners'] ) && is_array( $match_snap['winners'] ) ) {
        foreach ( $match_snap['winners'] as $w ) $affected[] = intval( $w );
    }

    $affected = array_values( array_unique( array_filter( $affected ) ) );
    if ( ! empty( $affected ) && function_exists( 'wf_recompute_superstar_counters' ) ) {
        foreach ( $affected as $sid ) wf_recompute_superstar_counters( $sid );
    }

    return true;
}

/**
 * Main match save handler to sync/create/reverse reigns.
 * Runs after snapshot/meta updated (priority 30).
 */
add_action( 'save_post', 'wf_on_match_sync_reign_on_save', 30, 3 );
function wf_on_match_sync_reign_on_save( $post_id, $post, $update ) {
    // Only operate on the match CPT
    $match_cpt = defined( 'WF_MATCH_CPT' ) ? WF_MATCH_CPT : 'match';
    if ( ! isset( $post->post_type ) || $post->post_type !== $match_cpt ) return;
    if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;

    // Ensure snapshot/meta are authoritative (idempotent)
    if ( function_exists( 'wf_update_match_snapshot' ) ) wf_update_match_snapshot( $post_id );
    if ( function_exists( 'wf_sync_match_participants_meta' ) ) wf_sync_match_participants_meta( $post_id );

    // Read winners from snapshot (may be empty)
    $snap_raw = get_post_meta( $post_id, 'wf_match_snapshot', true );
    $snapshot = is_string( $snap_raw ) ? json_decode( $snap_raw, true ) : ( is_array( $snap_raw ) ? $snap_raw : array() );
    $winners = array();
    if ( isset( $snapshot['winners'] ) && is_array( $snapshot['winners'] ) ) {
        foreach ( $snapshot['winners'] as $w ) $winners[] = intval( $w );
    }
    $winners = array_values( array_unique( array_filter( $winners ) ) );

    // Read ACF/meta title flag and championship id
    $is_title_on_line = false;
    $championship_id = 0;
    if ( function_exists( 'get_field' ) ) {
        $acf_flag = get_field( 'title_on_the_line', $post_id );
        if ( $acf_flag === null ) $acf_flag = get_field( 'title_on_line', $post_id );
        $acf_champ = get_field( 'championship', $post_id );
        if ( $acf_champ ) {
            if ( is_object( $acf_champ ) && isset( $acf_champ->ID ) ) $acf_champ = intval( $acf_champ->ID );
            elseif ( is_array( $acf_champ ) && isset( $acf_champ['ID'] ) ) $acf_champ = intval( $acf_champ['ID'] );
            else $acf_champ = intval( $acf_champ );
        }
        $is_title_on_line = filter_var( $acf_flag, FILTER_VALIDATE_BOOLEAN );
        if ( $acf_champ ) $championship_id = intval( $acf_champ );
    } else {
        $meta_flag = get_post_meta( $post_id, 'title_on_the_line', true );
        if ( $meta_flag === '' || $meta_flag === null ) $meta_flag = get_post_meta( $post_id, 'title_on_line', true );
        $meta_champ = get_post_meta( $post_id, 'championship', true );
        $is_title_on_line = filter_var( $meta_flag, FILTER_VALIDATE_BOOLEAN );
        if ( $meta_champ ) $championship_id = intval( $meta_champ );
    }

    // Normalize winners
    $winners_norm = $winners;
    sort( $winners_norm );

    // Find any existing Reign linked to this match
    $existing_rid = wf_find_reign_for_match( $post_id );

    if ( $existing_rid ) {
        // Compare existing reign champions to current winners.
        $existing_champs = get_post_meta( $existing_rid, 'wf_reign_champions', true );
        if ( ! is_array( $existing_champs ) ) $existing_champs = (array) $existing_champs;
        $existing_champs = array_values( array_filter( array_map( 'intval', $existing_champs ) ) );
        sort( $existing_champs );

        if ( $existing_champs !== $winners_norm ) {
            // Winners changed — reverse and permanently delete existing reign safely
            wf_reverse_and_close_reign_for_match( $existing_rid, $post_id, 'Match winners changed; automated reversal and deletion.' );
            // after reversal, fall through: if title_on_the_line AND winners now differ from current champs,
            // new Reign may be created below.
            $existing_rid = 0;
        } else {
            // winners match existing champions -> nothing to change; ensure wf_title_changed is recorded
            update_post_meta( $post_id, 'wf_title_changed', '1' );
            return;
        }
    }

    // Determine current champions for this championship (from current reigns)
    $current_champion_ids = array();
    if ( $championship_id ) {
        $current_reigns = get_posts( array(
            'post_type'   => 'reign',
            'post_status' => 'publish',
            'numberposts' => -1,
            'meta_query'  => array(
                array( 'key' => 'wf_reign_title', 'value' => intval( $championship_id ), 'compare' => '=' ),
                array( 'key' => 'wf_reign_is_current', 'value' => '1', 'compare' => '=' ),
            ),
            'fields' => 'ids',
        ) );
        if ( ! empty( $current_reigns ) ) {
            foreach ( $current_reigns as $crid ) {
                $chs = get_post_meta( $crid, 'wf_reign_champions', true );
                if ( ! is_array( $chs ) ) $chs = (array) $chs;
                foreach ( $chs as $c ) $current_champion_ids[] = intval( $c );
            }
            $current_champion_ids = array_values( array_unique( array_filter( $current_champion_ids ) ) );
        }
    }
    $cur_norm = $current_champion_ids;
    sort( $cur_norm );

    // Compute title_changed (winners differ from current champions)
    $title_changed = ( $winners_norm !== $cur_norm );
    update_post_meta( $post_id, 'wf_title_changed', $title_changed ? '1' : '0' );

    // If it's not a title match or no championship ID, nothing to do
    if ( ! $is_title_on_line || ! $championship_id ) {
        return;
    }

    // If winners empty or title did not change, do not create a new reign.
    if ( empty( $winners_norm ) || ! $title_changed ) {
        return;
    }

    // By now: either no existing reign, or we reversed the prior one because winners changed.
    // Create a new Reign post (idempotent because we checked earlier).
    $champion_names = array();
    foreach ( $winners_norm as $cid ) {
        $p = get_post( $cid );
        if ( $p ) $champion_names[] = $p->post_title;
    }
    $title_label = get_the_title( $championship_id );
    $reign_title = wp_strip_all_tags( trim( $title_label . ' — ' . ( ! empty( $champion_names ) ? implode( ', ', $champion_names ) : 'Unknown' ) . ' (won ' . get_the_date( 'Y-m-d', $post_id ) . ')' ) );

    $reign_post = array(
        'post_title'   => $reign_title,
        'post_type'    => 'reign',
        'post_status'  => 'publish',
        'post_content' => 'Auto-created from match #' . intval( $post_id ),
    );

    $rid = wp_insert_post( $reign_post );
    if ( is_wp_error( $rid ) || ! $rid ) {
        error_log( '[WF] Failed to create reign from match ' . intval( $post_id ) . ' : ' . ( is_wp_error( $rid ) ? $rid->get_error_message() : 'unknown' ) );
        return;
    }

    // Set canonical meta so wf_apply_reign reads consistent values
    update_post_meta( $rid, 'wf_reign_title', intval( $championship_id ) );
    update_post_meta( $rid, 'wf_reign_champions', $winners_norm );
    update_post_meta( $rid, 'wf_reign_start_date', date_i18n( 'Ymd', strtotime( get_post_field( 'post_date', $post_id ) ) ) );
    update_post_meta( $rid, 'wf_reign_won_match', intval( $post_id ) );
    update_post_meta( $rid, 'wf_reign_is_current', 1 );
    update_post_meta( $post_id, 'wf_reign_created', intval( $rid ) ); // link back to match for idempotency

    // ACF writes (best-effort)
    if ( function_exists( 'update_field' ) ) {
        update_field( 'wf_reign_title', intval( $championship_id ), $rid );
        update_field( 'wf_reign_champions', $winners_norm, $rid );
        update_field( 'wf_reign_start_date', date_i18n( 'Ymd', strtotime( get_post_field( 'post_date', $post_id ) ) ), $rid );
        update_field( 'wf_reign_won_match', intval( $post_id ), $rid );
        update_field( 'wf_reign_is_current', 1, $rid );
    }

    // Delegate to wf_apply_reign if available to run canonical bookkeeping (idempotent)
    if ( function_exists( 'wf_apply_reign' ) ) {
        wf_apply_reign( $rid, array( 'manual' => false ) );
    }
}

/* End of file */