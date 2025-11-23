<?php
/**
 * Recompute superstar counters and ensure match saves are idempotent.
 *
 * Path:
 *   wp-content/plugins/wf-match-reign-processor/includes/rebuild-superstar-counters.php
 *
 * Behavior:
 * - On save_post for match, recomputes counters for involved superstars from scratch.
 * - Regenerates wf_match_snapshot from current participants on every save.
 * - Handles scalar/JSON participants_details and ACF subkeys.
 * - Recomputes counters when matches are trashed/untrashed/deleted.
 * - Keeps a normalized match_participants meta in sync for backwards compatibility.
 *
 * Backup your site/db before applying.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* Ensure helpers are loaded (safe: helpers guard functions with if !function_exists checks).
   This makes the expansion/team lookups and wf_is_match_tag available even if plugin bootstrap didn't include helpers early. */
$helpers_file = __DIR__ . '/helpers.php';
if ( file_exists( $helpers_file ) ) {
	require_once $helpers_file;
}

/**
 * Extract participant rows from a match post.
 * Returns array of rows with participant id, is_winner (bool), role and raw.
 */
function wf_get_match_participants_rows( $match_id ) {
    $rows = array();

    // 1) Try ACF repeater 'participants_details' (common)
    if ( function_exists( 'get_field' ) ) {
        // Support both 'participants_details' (plural) and 'participant_details' (singular) ACF field names
        $acf_rows = get_field( 'participants_details', $match_id );
        if ( empty( $acf_rows ) ) $acf_rows = get_field( 'participant_details', $match_id );
        if ( is_array( $acf_rows ) && ! empty( $acf_rows ) ) {
            foreach ( $acf_rows as $r ) {
                $row = array( 'raw' => $r, 'participant' => 0, 'is_winner' => false, 'role' => null );
                if ( is_array( $r ) ) {
                    if ( isset( $r['participant'] ) ) {
                        $v = $r['participant'];
                        if ( is_numeric( $v ) ) $row['participant'] = intval( $v );
                        elseif ( is_object( $v ) && isset( $v->ID ) ) $row['participant'] = intval( $v->ID );
                    }
                    if ( isset( $r['is_winner'] ) ) $row['is_winner'] = (bool) $r['is_winner'];
                    if ( isset( $r['role'] ) ) $row['role'] = $r['role'];
                }
                if ( $row['participant'] ) $rows[] = $row;
            }
            if ( ! empty( $rows ) ) return $rows;
        }
    }

    // 1b) Handle participants_details stored as scalar or JSON array (legacy/odd storage)
    // Try meta key 'participants_details' then fallback to 'participant_details'
    $raw_pd = get_post_meta( $match_id, 'participants_details', true );
    if ( $raw_pd === '' || $raw_pd === null ) $raw_pd = get_post_meta( $match_id, 'participant_details', true );

    if ( $raw_pd !== '' && $raw_pd !== null ) {
        // If numeric scalar like "8"
        if ( is_numeric( $raw_pd ) || ( is_string( $raw_pd ) && ctype_digit( $raw_pd ) ) ) {
            $pid = intval( $raw_pd );
            if ( $pid ) {
                $rows[] = array( 'raw' => $raw_pd, 'participant' => $pid, 'is_winner' => false, 'role' => null );
                return $rows;
            }
        }
        // JSON encoded array like "[8]" or '[{"id":8,"is_winner":1}]'
        if ( is_string( $raw_pd ) ) {
            $maybe = json_decode( $raw_pd, true );
            if ( is_array( $maybe ) && ! empty( $maybe ) ) {
                foreach ( $maybe as $item ) {
                    $row = array( 'raw' => $item, 'participant' => 0, 'is_winner' => false, 'role' => null );
                    if ( is_numeric( $item ) ) {
                        $row['participant'] = intval( $item );
                    } elseif ( is_array( $item ) ) {
                        if ( isset( $item['id'] ) ) $row['participant'] = intval( $item['id'] );
                        if ( isset( $item['is_winner'] ) ) $row['is_winner'] = (bool) $item['is_winner'];
                        if ( isset( $item['role'] ) ) $row['role'] = $item['role'];
                    }
                    if ( $row['participant'] ) $rows[] = $row;
                }
                if ( ! empty( $rows ) ) return $rows;
            }
        }
        // try maybe_unserialize() fallback (serialized PHP array)
        $maybe_ser = maybe_unserialize( $raw_pd );
        if ( is_array( $maybe_ser ) && ! empty( $maybe_ser ) ) {
            foreach ( $maybe_ser as $item ) {
                $row = array( 'raw' => $item, 'participant' => 0, 'is_winner' => false, 'role' => null );
                if ( is_numeric( $item ) ) {
                    $row['participant'] = intval( $item );
                } elseif ( is_array( $item ) ) {
                    if ( isset( $item['participant'] ) ) $row['participant'] = intval( $item['participant'] );
                    if ( isset( $item['is_winner'] ) ) $row['is_winner'] = (bool) $item['is_winner'];
                    if ( isset( $item['role'] ) ) $row['role'] = $item['role'];
                    if ( isset( $item['id'] ) ) $row['participant'] = intval( $item['id'] );
                }
                if ( $row['participant'] ) $rows[] = $row;
            }
            if ( ! empty( $rows ) ) return $rows;
        }
    }

    // 2) Try meta keys like participants_details_0_participant etc.
    $all_meta = get_post_meta( $match_id );
    if ( is_array( $all_meta ) && ! empty( $all_meta ) ) {
        $temp = array();
        foreach ( $all_meta as $k => $v ) {
            // Accept either participants_details_0_participant or participant_details_0_participant
            if ( preg_match( '/^participants?_details_(\d+)_(.+)$/', $k, $m ) ) {
                $idx = intval( $m[1] );
                $sub = $m[2];
                if ( ! isset( $temp[$idx] ) ) $temp[$idx] = array();
                // meta values are arrays; pick first
                $val = is_array( $v ) ? $v[0] : $v;
                $temp[$idx][ $sub ] = $val;
            }
        }
        if ( ! empty( $temp ) ) {
            ksort( $temp );
            foreach ( $temp as $t ) {
                $row = array( 'raw' => $t, 'participant' => 0, 'is_winner' => false, 'role' => null );
                if ( isset( $t['participant'] ) ) $row['participant'] = intval( $t['participant'] );
                if ( isset( $t['is_winner'] ) ) $row['is_winner'] = (bool) $t['is_winner'];
                if ( isset( $t['role'] ) ) $row['role'] = $t['role'];
                if ( $row['participant'] ) $rows[] = $row;
            }
            if ( ! empty( $rows ) ) return $rows;
        }
    }

    // 3) Try simpler meta arrays
    $keys_to_try = array( 'match_participants', 'match_players', 'participants', 'wf_match_participants', 'participants_list', 'participant_details', 'participants_details' );
    foreach ( $keys_to_try as $k ) {
        $v = get_post_meta( $match_id, $k, true );
        if ( empty( $v ) ) continue;
        if ( is_array( $v ) ) {
            foreach ( $v as $item ) {
                $row = array( 'raw' => $item, 'participant' => 0, 'is_winner' => false, 'role' => null );
                if ( is_numeric( $item ) ) $row['participant'] = intval( $item );
                elseif ( is_array( $item ) && isset( $item['ID'] ) ) $row['participant'] = intval( $item['ID'] );
                if ( $row['participant'] ) $rows[] = $row;
            }
            if ( ! empty( $rows ) ) return $rows;
        }
    }

    // 4) Fallback: wf_winners (legacy) - only used when nothing else present
    $winners = get_post_meta( $match_id, 'wf_winners', true );
    if ( is_array( $winners ) && ! empty( $winners ) ) {
        foreach ( $winners as $win ) {
            $rows[] = array( 'raw' => null, 'participant' => intval( $win ), 'is_winner' => true, 'role' => null );
        }
        if ( ! empty( $rows ) ) return $rows;
    }

    return array();
}

/**
 * Sync normalized 'match_participants' meta from canonical ACF/participants rows.
 * Call this after ACF save and after normal save_post handling (where appropriate).
 *
 * $match_id: int
 * Returns true on success.
 */
function wf_sync_match_participants_meta( $match_id ) {
    $match_id = intval( $match_id );
    if ( ! $match_id ) return false;
    if ( ! function_exists( 'wf_get_match_participants_rows' ) ) return false;

    $rows = wf_get_match_participants_rows( $match_id );
    $pids = array();
    foreach ( (array) $rows as $r ) {
        if ( ! empty( $r['participant'] ) ) $pids[] = intval( $r['participant'] );
    }
    $pids = array_values( array_unique( array_filter( $pids ) ) );

    // store normalized array (legacy consumers / fast meta_query compatibility)
    update_post_meta( $match_id, 'match_participants', $pids );

    return true;
}

/**
 * Recompute counters for a single superstar by scanning all matches they appear in.
 * Writes wf_total_matches, wf_wins, wf_losses, wf_draws, wf_nocontests,
 *        wf_tag_matches, wf_tag_wins, wf_tag_losses, wf_tag_draws, wf_tag_nocontests
 *
 * Returns true on success.
 */
function wf_recompute_superstar_counters( $superstar_id ) {
    $superstar_id = intval( $superstar_id );
    if ( ! $superstar_id ) return false;

    // Build meta_query to find matches referencing this superstar OR any team that contains the superstar.
    $search_ids = array( $superstar_id );

    if ( function_exists( 'jjc_mh_get_team_ids_for_superstar' ) ) {
        $team_ids = jjc_mh_get_team_ids_for_superstar( $superstar_id );
        if ( is_array( $team_ids ) && ! empty( $team_ids ) ) {
            $team_ids = array_map( 'intval', $team_ids );
            $search_ids = array_values( array_unique( array_merge( $search_ids, $team_ids ) ) );
        }
    }

    $meta_queries = array( 'relation' => 'OR' );
    $keys = array( 'participants_details', 'wf_winners', 'match_participants', 'match_players', 'participants', 'participants_list' );
    foreach ( $keys as $k ) {
        foreach ( $search_ids as $sid ) {
            $meta_queries[] = array( 'key' => $k, 'value' => '"' . $sid . '"', 'compare' => 'LIKE' );
            $meta_queries[] = array( 'key' => $k, 'value' => (string) $sid, 'compare' => 'LIKE' );
        }
    }

    $args = array(
        'post_type' => defined( 'WF_MATCH_CPT' ) ? WF_MATCH_CPT : 'match',
        'post_status' => array( 'publish', 'private', 'draft' ),
        'numberposts' => -1,
        'meta_query' => $meta_queries,
        'fields' => 'ids',
    );

    $match_ids = get_posts( $args );
    if ( ! is_array( $match_ids ) ) $match_ids = array();

    $total_matches = 0;
    $wins = $losses = $draws = $nocontests = 0;
    $tag_matches = 0;
    $tag_wins = $tag_losses = $tag_draws = $tag_noconts = 0;

    foreach ( $match_ids as $mid ) {
        $mid = intval( $mid );
        if ( ! $mid ) continue;

        // Use expanded individual participant IDs (expands team posts to member superstar IDs) when available
        if ( function_exists( 'wf_expand_match_participants_to_individuals' ) ) {
            $participant_ids = wf_expand_match_participants_to_individuals( $mid );
        } else {
            // Fallback behavior (original)
            $rows = wf_get_match_participants_rows( $mid );
            $participant_ids = wp_list_pluck( $rows, 'participant' );
            if ( empty( $participant_ids ) ) {
                $pmeta = get_post_meta( $mid, 'participants', true );
                if ( is_array( $pmeta ) ) {
                    foreach ( $pmeta as $pv ) {
                        if ( is_numeric( $pv ) ) $participant_ids[] = intval( $pv );
                        elseif ( is_array( $pv ) && isset( $pv['ID'] ) ) $participant_ids[] = intval( $pv['ID'] );
                    }
                }
            }
        }

        // Defensive: If this match doesn't include superstar and legacy winners don't include them, skip
        if ( ! in_array( $superstar_id, $participant_ids, true ) ) {
            $winners = get_post_meta( $mid, 'wf_winners', true );
            // If winners contain teams, expand winners as well to check membership
            $check_winners = array();
            if ( is_array( $winners ) && ! empty( $winners ) ) {
                foreach ( $winners as $w ) {
                    $w = intval( $w );
                    if ( ! $w ) continue;
                    $ptype = function_exists( 'get_post_type' ) ? get_post_type( $w ) : '';
                    if ( in_array( $ptype, array( 'team', 'teams', 'stable' ), true ) ) {
                        if ( function_exists( 'jjc_mh_expand_team_to_members' ) ) {
                            $members = jjc_mh_expand_team_to_members( $w );
                            if ( is_array( $members ) ) foreach ( $members as $m ) $check_winners[] = intval( $m );
                        }
                    } else {
                        $check_winners[] = $w;
                    }
                }
            }
            if ( ! ( is_array( $winners ) && in_array( $superstar_id, $check_winners, true ) ) ) {
                continue;
            }
        }

        $total_matches++;

        // Decide if this match is a tag match using centralized helper if available
        $rows = function_exists( 'wf_get_match_participants_rows' ) ? wf_get_match_participants_rows( $mid ) : array();
        if ( function_exists( 'wf_is_match_tag' ) ) {
            $is_tag = (bool) wf_is_match_tag( $mid, $participant_ids, $rows );
        } else {
            // Heuristic fallback
            $is_tag = false;
            if ( count( $participant_ids ) > 2 ) $is_tag = true;
            if ( count( $participant_ids ) > 1 && count( $participant_ids ) % 2 == 0 ) $is_tag = true;
            foreach ( $rows as $r ) {
                if ( ! empty( $r['role'] ) && stripos( $r['role'], 'tag' ) !== false ) { $is_tag = true; break; }
            }
        }

        if ( $is_tag ) {
            $tag_matches++;
        }

        // Determine winners for this match (prefer ACF rows over legacy wf_winners)
        $match_winner_ids = array();
        $wf_winners = get_post_meta( $mid, 'wf_winners', true );
        if ( is_array( $wf_winners ) && ! empty( $wf_winners ) && empty( $rows ) ) {
            // Only use wf_winners when participants rows are absent (legacy)
            $match_winner_ids = array_map( 'intval', $wf_winners );
        } else {
            foreach ( $rows as $r ) {
                if ( ! empty( $r['is_winner'] ) ) $match_winner_ids[] = intval( $r['participant'] );
            }
        }

        // Expand winner IDs: if a winner is a team, expand to its members
        $expanded_match_winner_ids = array();
        if ( ! empty( $match_winner_ids ) ) {
            foreach ( $match_winner_ids as $wid ) {
                $wid = intval( $wid );
                if ( ! $wid ) continue;

                $w_ptype = function_exists( 'get_post_type' ) ? get_post_type( $wid ) : '';
                if ( in_array( $w_ptype, array( 'team', 'teams', 'stable' ), true ) ) {
                    if ( function_exists( 'jjc_mh_expand_team_to_members' ) ) {
                        $members = jjc_mh_expand_team_to_members( $wid );
                    } elseif ( function_exists( 'wfmc_expand_team_safe' ) ) {
                        $members = wfmc_expand_team_safe( $wid );
                    } else {
                        $members = array();
                    }
                    if ( is_array( $members ) && ! empty( $members ) ) {
                        foreach ( $members as $m ) $expanded_match_winner_ids[] = intval( $m );
                        continue;
                    }
                }
                // fallback: include the winner id itself (covers superstar winners)
                $expanded_match_winner_ids[] = $wid;
            }
            $expanded_match_winner_ids = array_values( array_unique( array_filter( $expanded_match_winner_ids ) ) );
        } else {
            $expanded_match_winner_ids = array();
        }

        // Heuristic for tag match could be used above, but we've already set $is_tag

        $match_result_meta = get_post_meta( $mid, 'wf_match_result', true );
        $is_draw = false;
        $is_nocontest = false;
        if ( is_string( $match_result_meta ) ) {
            $mr = strtolower( trim( $match_result_meta ) );
            if ( in_array( $mr, array( 'draw', 'no contest', 'nc' ), true ) ) $is_draw = true;
            if ( in_array( $mr, array( 'no contest', 'nc' ), true ) ) $is_nocontest = true;
        }

        if ( ! empty( $expanded_match_winner_ids ) ) {
            if ( in_array( $superstar_id, $expanded_match_winner_ids, true ) ) {
                if ( $is_tag ) $tag_wins++; else $wins++;
            } else {
                if ( $is_tag ) $tag_losses++; else $losses++;
            }
        } else {
            if ( $is_nocontest ) {
                if ( $is_tag ) $tag_noconts++; else $nocontests++;
            } elseif ( $is_draw ) {
                if ( $is_tag ) $tag_draws++; else $draws++;
            } else {
                // No winner recorded and not draw: we count the match (total_matches already incremented)
                // but cannot attribute a win/loss to anyone yet.
            }
        }
    }

    // Write computed values back
    update_post_meta( $superstar_id, 'wf_total_matches', $total_matches );
    update_post_meta( $superstar_id, 'wf_wins', $wins );
    update_post_meta( $superstar_id, 'wf_losses', $losses );
    update_post_meta( $superstar_id, 'wf_draws', $draws );
    update_post_meta( $superstar_id, 'wf_nocontests', $nocontests );

    // New: store how many tag matches this superstar has participated in
    update_post_meta( $superstar_id, 'wf_tag_matches', $tag_matches );

    update_post_meta( $superstar_id, 'wf_tag_wins', $tag_wins );
    update_post_meta( $superstar_id, 'wf_tag_losses', $tag_losses );
    update_post_meta( $superstar_id, 'wf_tag_draws', $tag_draws );
    update_post_meta( $superstar_id, 'wf_tag_nocontests', $tag_noconts );

    return true;
}

/**
 * When a match is saved, recompute counters for its participants.
 * This runs after the main processor (priority 25).
 */
add_action( 'save_post', 'wf_on_match_save_recompute_counters', 25, 3 );
function wf_on_match_save_recompute_counters( $post_id, $post, $update ) {
    if ( defined( 'WF_MATCH_CPT' ) ) $match_cpt = WF_MATCH_CPT;
    else $match_cpt = 'match';

    if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) return;
    if ( ! isset( $post->post_type ) || $post->post_type !== $match_cpt ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    // NOTE: do NOT bail on REST_REQUEST here — Gutenberg/REST saves must trigger recompute.
    // if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return;

    // Gather participants (expand teams -> individuals). This ensures tag counters increment for team posts.
    if ( function_exists( 'wf_expand_match_participants_to_individuals' ) ) {
        $participant_ids = wf_expand_match_participants_to_individuals( $post_id );
    } else {
        $rows = wf_get_match_participants_rows( $post_id );
        $participant_ids = array();
        foreach ( $rows as $row ) {
            if ( ! empty( $row['participant'] ) ) $participant_ids[] = intval( $row['participant'] );
        }

        // Fallback to wf_winners if NO participants rows discovered (legacy)
        if ( empty( $participant_ids ) ) {
            $wf_winners = get_post_meta( $post_id, 'wf_winners', true );
            if ( is_array( $wf_winners ) && ! empty( $wf_winners ) ) {
                foreach ( $wf_winners as $w ) $participant_ids[] = intval( $w );
            }
        }
    }

    $participant_ids = array_values( array_unique( array_filter( $participant_ids ) ) );

    // Sync normalized match_participants for backward compatibility
    if ( function_exists( 'wf_sync_match_participants_meta' ) ) {
        wf_sync_match_participants_meta( $post_id );
    }

    // Recompute counters for each participant (idempotent)
    if ( ! empty( $participant_ids ) ) {
        foreach ( $participant_ids as $sid ) {
            wf_recompute_superstar_counters( $sid );
        }
    } else {
        // No participants found: if there was a snapshot or legacy winners, still attempt to clear/warn
    }

    // Update the match snapshot so stored JSON remains authoritative
    if ( function_exists( 'wf_update_match_snapshot' ) ) wf_update_match_snapshot( $post_id );
}

/**
 * Regenerate/update the wf_match_snapshot meta for a match from current participants data.
 * - Preserves existing snapshot fields except applied_participants, winners, match_result, applied_at and applied.
 */
function wf_update_match_snapshot( $match_id ) {
    $match_id = intval( $match_id );
    if ( ! $match_id ) return false;

    // Read existing snapshot if present
    $existing = get_post_meta( $match_id, 'wf_match_snapshot', true );
    $snapshot = array();
    if ( is_string( $existing ) ) {
        $decoded = json_decode( $existing, true );
        if ( is_array( $decoded ) ) $snapshot = $decoded;
    }

    // Build applied_participants from current match rows
    $rows = function_exists( 'wf_get_match_participants_rows' ) ? wf_get_match_participants_rows( $match_id ) : array();

    $applied_participants = array();
    $winners = array();
    foreach ( (array) $rows as $row ) {
        $participant_id = isset( $row['participant'] ) ? intval( $row['participant'] ) : 0;
        if ( ! $participant_id ) continue;
        $is_win = ! empty( $row['is_winner'] ) ? 1 : 0;
        if ( $is_win ) $winners[] = $participant_id;
        $applied_participants[] = array(
            'id' => $participant_id,
            'is_winner' => $is_win,
            'outcome' => $is_win ? 'win' : '',
            'is_tag' => ! empty( $row['role'] ) && stripos( $row['role'], 'tag' ) !== false ? 1 : 0,
        );
    }

    $snapshot['applied'] = 1;
    $snapshot['applied_at'] = current_time( 'mysql', 0 );
    $snapshot['applied_participants'] = $applied_participants;
    $snapshot['winners'] = array_values( array_unique( array_map( 'intval', $winners ) ) );

    // Determine and store explicit is_tag in snapshot (uses centralized helper if available)
    if ( function_exists( 'wf_is_match_tag' ) ) {
        $snapshot['is_tag'] = (bool) wf_is_match_tag( $match_id, null, $rows );
    } else {
        // best-effort conservative flag using participant count & roles
        $expanded = function_exists( 'wf_expand_match_participants_to_individuals' ) ? wf_expand_match_participants_to_individuals( $match_id ) : wp_list_pluck( $rows, 'participant' );
        $is_tag = false;
        foreach ( $applied_participants as $ap ) {
            if ( ! empty( $ap['is_tag'] ) ) { $is_tag = true; break; }
        }
        if ( !$is_tag && is_array( $expanded ) && count( $expanded ) > 2 ) $is_tag = true;
        $snapshot['is_tag'] = $is_tag ? 1 : 0;
    }

    // Ensure per-applied participant is_tag matches snapshot-level flag so snapshot is consistent
    if ( isset( $snapshot['is_tag'] ) ) {
        foreach ( $applied_participants as &$ap ) {
            $ap['is_tag'] = ! empty( $snapshot['is_tag'] ) ? 1 : 0;
        }
        unset( $ap );
        $snapshot['applied_participants'] = $applied_participants;
    }

    // Ensure normalized match_participants is present for legacy consumers
    if ( function_exists( 'wf_sync_match_participants_meta' ) ) {
        wf_sync_match_participants_meta( $match_id );
    } else {
        // Fallback: ensure match_participants meta reflects applied_participants
        $participant_ids = array();
        foreach ( $applied_participants as $ap ) {
            if ( isset( $ap['id'] ) && $ap['id'] ) $participant_ids[] = intval( $ap['id'] );
        }
        $participant_ids = array_values( array_unique( array_filter( $participant_ids ) ) );
        update_post_meta( $match_id, 'match_participants', $participant_ids );
    }

    // Keep legacy wf_winners in sync for backward compatibility
    if ( ! empty( $snapshot['winners'] ) ) {
        $legacy_winners = array_values( array_unique( array_map( 'intval', $snapshot['winners'] ) ) );
        update_post_meta( $match_id, 'wf_winners', $legacy_winners );
        update_post_meta( $match_id, 'winners', $legacy_winners );
    } else {
        // If no winners in snapshot, remove legacy winners to reflect the cleared state
        delete_post_meta( $match_id, 'wf_winners' );
        delete_post_meta( $match_id, 'winners' );
    }

    // Determine match_result if none present (attempt to read wf_match_result or match_result meta)
    if ( empty( $snapshot['match_result'] ) ) {
        $mr = get_post_meta( $match_id, 'wf_match_result', true );
        if ( empty( $mr ) ) $mr = get_post_meta( $match_id, 'match_result', true );
        $snapshot['match_result'] = is_string( $mr ) ? $mr : '';
    }

    // Encode and save (use wp_json_encode for safe encoding)
    update_post_meta( $match_id, 'wf_match_snapshot', wp_json_encode( $snapshot ) );

    return true;
}

/**
 * Gather all unique participant IDs across matches (for full rebuilds).
 */
function wf_get_all_participant_ids() {
    $ids = array();
    $matches = get_posts( array(
        'post_type' => defined( 'WF_MATCH_CPT' ) ? WF_MATCH_CPT : 'match',
        'post_status' => array( 'publish', 'private', 'draft' ),
        'numberposts' => -1,
        'fields' => 'ids',
    ) );
    foreach ( $matches as $mid ) {
        // Use expansion so teams contribute their members when possible
        if ( function_exists( 'wf_expand_match_participants_to_individuals' ) ) {
            $rows_ids = wf_expand_match_participants_to_individuals( $mid );
            foreach ( $rows_ids as $rid ) $ids[] = intval( $rid );
        } else {
            $rows = wf_get_match_participants_rows( $mid );
            foreach ( $rows as $r ) {
                if ( ! empty( $r['participant'] ) ) $ids[] = intval( $r['participant'] );
            }
            $wf_winners = get_post_meta( $mid, 'wf_winners', true );
            if ( is_array( $wf_winners ) ) {
                foreach ( $wf_winners as $w ) $ids[] = intval( $w );
            }
            // Also check snapshot for coverage
            $snap = get_post_meta( $mid, 'wf_match_snapshot', true );
            if ( is_string( $snap ) ) {
                $dec = json_decode( $snap, true );
                if ( isset( $dec['applied_participants'] ) && is_array( $dec['applied_participants'] ) ) {
                    foreach ( $dec['applied_participants'] as $ap ) {
                        if ( isset( $ap['id'] ) && $ap['id'] ) $ids[] = intval( $ap['id'] );
                    }
                }
                if ( isset( $dec['winners'] ) && is_array( $dec['winners'] ) ) {
                    foreach ( $dec['winners'] as $w ) $ids[] = intval( $w );
                }
            }
        }
    }
    $ids = array_values( array_unique( array_filter( $ids ) ) );
    return $ids;
}

/* -----------------------
   Hooks for trash/delete/untrash to keep counters accurate
   ----------------------- */

/**
 * When a match is trashed, recompute counters for its participants.
 */
add_action( 'trashed_post', 'wf_on_match_trashed', 10, 1 );
function wf_on_match_trashed( $post_id ) {
    $match_cpt = defined( 'WF_MATCH_CPT' ) ? WF_MATCH_CPT : 'match';
    if ( get_post_type( $post_id ) !== $match_cpt ) return;

    // Expand team participants -> individual superstar IDs
    if ( function_exists( 'wf_expand_match_participants_to_individuals' ) ) {
        $pids = wf_expand_match_participants_to_individuals( $post_id );
    } else {
        $rows = wf_get_match_participants_rows( $post_id );
        $pids = array();
        foreach ( $rows as $r ) if ( ! empty( $r['participant'] ) ) $pids[] = intval( $r['participant'] );
    }

    $pids = array_values( array_unique( array_filter( $pids ) ) );
    foreach ( $pids as $sid ) wf_recompute_superstar_counters( $sid );
}

/**
 * When a match is untrashed (restored), recompute counters for its participants.
 */
add_action( 'untrashed_post', 'wf_on_match_untrashed', 10, 1 );
function wf_on_match_untrashed( $post_id ) {
    $match_cpt = defined( 'WF_MATCH_CPT' ) ? WF_MATCH_CPT : 'match';
    if ( get_post_type( $post_id ) !== $match_cpt ) return;

    if ( function_exists( 'wf_expand_match_participants_to_individuals' ) ) {
        $pids = wf_expand_match_participants_to_individuals( $post_id );
    } else {
        $rows = wf_get_match_participants_rows( $post_id );
        $pids = array();
        foreach ( $rows as $r ) if ( ! empty( $r['participant'] ) ) $pids[] = intval( $r['participant'] );
    }

    $pids = array_values( array_unique( array_filter( $pids ) ) );
    foreach ( $pids as $sid ) wf_recompute_superstar_counters( $sid );
}

/**
 * Before a match is deleted, store its participants temporarily.
 * After deletion, recompute counters for those participants.
 */
add_action( 'before_delete_post', 'wf_on_match_before_delete', 10, 1 );
function wf_on_match_before_delete( $post_id ) {
    $match_cpt = defined( 'WF_MATCH_CPT' ) ? WF_MATCH_CPT : 'match';
    if ( get_post_type( $post_id ) !== $match_cpt ) return;

    if ( function_exists( 'wf_expand_match_participants_to_individuals' ) ) {
        $pids = wf_expand_match_participants_to_individuals( $post_id );
    } else {
        $rows = wf_get_match_participants_rows( $post_id );
        $pids = array();
        foreach ( $rows as $r ) if ( ! empty( $r['participant'] ) ) $pids[] = intval( $r['participant'] );
    }

    $pids = array_values( array_unique( array_filter( $pids ) ) );
    if ( ! empty( $pids ) ) {
        set_transient( 'wf_predelete_match_participants_' . $post_id, $pids, MINUTE_IN_SECONDS * 5 );
    }
}

add_action( 'delete_post', 'wf_on_match_after_delete', 10, 1 );
function wf_on_match_after_delete( $post_id ) {
    $match_cpt = defined( 'WF_MATCH_CPT' ) ? WF_MATCH_CPT : 'match';
    // Even if the post_type is gone, we stored participants transient pre-delete
    $key = 'wf_predelete_match_participants_' . $post_id;
    $pids = get_transient( $key );
    if ( $pids && is_array( $pids ) ) {
        foreach ( $pids as $sid ) wf_recompute_superstar_counters( intval( $sid ) );
        delete_transient( $key );
    }
}

/* -----------------------
   ACF save hook to ensure recompute runs after ACF writes fields
   ----------------------- */

/**
 * ACF saves post meta on acf/save_post after WP save_post. Run recompute and snapshot update
 * after ACF has written fields so we use the authoritative ACF data.
 */
add_action( 'acf/save_post', 'wf_on_acf_save_recompute', 20 );
function wf_on_acf_save_recompute( $post_id ) {
    // Only run in admin context and for match CPTs
    if ( ! is_admin() ) return;
    // If ACF passes a numeric 0 or 'options' skip
    if ( ! $post_id || $post_id === 'options' ) return;

    // Ensure the post exists and is the match type
    $post = get_post( $post_id );
    if ( ! $post ) return;
    $match_cpt = defined( 'WF_MATCH_CPT' ) ? WF_MATCH_CPT : 'match';
    if ( $post->post_type !== $match_cpt ) return;

    // Recompute counters for any participants present after ACF saved meta
    if ( function_exists( 'wf_get_match_participants_rows' ) ) {
        if ( function_exists( 'wf_expand_match_participants_to_individuals' ) ) {
            $participant_ids = wf_expand_match_participants_to_individuals( $post_id );
        } else {
            $rows = wf_get_match_participants_rows( $post_id );
            $participant_ids = array();
            foreach ( (array) $rows as $r ) {
                if ( ! empty( $r['participant'] ) ) $participant_ids[] = intval( $r['participant'] );
            }
        }
        $participant_ids = array_values( array_unique( array_filter( $participant_ids ) ) );

        // sync normalized participant list for legacy/compat code
        if ( function_exists( 'wf_sync_match_participants_meta' ) ) {
            wf_sync_match_participants_meta( $post_id );
        }
        foreach ( $participant_ids as $sid ) {
            if ( function_exists( 'wf_recompute_superstar_counters' ) ) {
                wf_recompute_superstar_counters( $sid );
            }
        }
    } else {
        // Fallback: call existing save_post handler if present
        if ( function_exists( 'wf_on_match_save_recompute_counters' ) ) {
            wf_on_match_save_recompute_counters( $post_id, $post, true );
        }
    }

    // Regenerate stored snapshot so wf_match_snapshot remains authoritative
    if ( function_exists( 'wf_update_match_snapshot' ) ) {
        wf_update_match_snapshot( $post_id );
    }
}

/* End of file */