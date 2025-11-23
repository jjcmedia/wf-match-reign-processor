<?php
/**
 * Shortcode: match listing (wfmc-* classes)
 *
 * Final replacement: robust team/member/stable handling and winner expansion so
 * individual members of a winning team get the winner class.
 *
 * Overwrite this file at includes/shortcodes/shortcode-match-listing.php
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ---------- Small local helpers ---------- */

if ( ! function_exists( 'jjc_mh_clean_label_html' ) ) {
	function jjc_mh_clean_label_html( $html_fragment ) {
		$s = trim( (string) $html_fragment );
		$s = preg_replace( '/\s*,\s*/u', ', ', $s );
		$s = preg_replace( '/\s*&\s*/u', ' & ', $s );
		$s = preg_replace( '/^[,;\s]+|[,;\s]+$/u', '', $s );
		$s = preg_replace( '/\s{2,}/u', ' ', $s );
		return trim( $s );
	}
}

/* join that preserves HTML for items (do not escape items) */
if ( ! function_exists( 'wf_human_join_html_allow_html' ) ) {
	function wf_human_join_html_allow_html( $items ) {
		$parts = array();
		foreach ( (array) $items as $it ) {
			$s = (string) $it;
			$s = trim( $s );
			if ( $s !== '' ) $parts[] = $s;
		}
		$count = count( $parts );
		if ( $count === 0 ) return '';
		if ( $count === 1 ) return $parts[0];
		if ( $count === 2 ) return $parts[0] . ' & ' . $parts[1];
		$last = array_pop( $parts );
		return implode( ', ', $parts ) . ', & ' . $last;
	}
}

if ( ! function_exists( 'wf_human_join_html' ) ) {
	function wf_human_join_html( $items ) {
		$parts = array();
		foreach ( (array) $items as $it ) {
			$clean = jjc_mh_clean_label_html( $it );
			if ( $clean !== '' ) $parts[] = $clean;
		}
		$count = count( $parts );
		if ( $count === 0 ) return '';
		if ( $count === 1 ) return $parts[0];
		if ( $count === 2 ) return $parts[0] . ' & ' . $parts[1];
		$last = array_pop( $parts );
		return implode( ', ', $parts ) . ', & ' . $last;
	}
}

/* Render a participant (superstar or team link) */
if ( ! function_exists( 'jjc_mh_render_participant_element' ) ) {
	function jjc_mh_render_participant_element( $pid, $link = true, $is_winner = false, $extra_classes = '' ) {
		$pid = intval( $pid );
		if ( ! $pid ) return '';
		$title = function_exists( 'jjc_mh_ensure_string' ) ? jjc_mh_ensure_string( get_the_title( $pid ) ) : get_the_title( $pid );
		$classes = 'wfmc-participant';
		if ( $is_winner ) $classes .= ' wfmc-participant-winner';
		if ( $extra_classes ) $classes .= ' ' . sanitize_html_class( $extra_classes );
		$data = ' data-wfmc-participant-id="' . esc_attr( $pid ) . '"';
		if ( $link ) {
			$plink = get_permalink( $pid );
			if ( $plink ) $inner = '<a class="wfmc-participant-link" href="' . esc_url( $plink ) . '">' . esc_html( $title ) . '</a>';
			else $inner = esc_html( $title );
		} else {
			$inner = esc_html( $title );
		}
		return '<span class="' . esc_attr( $classes ) . '"' . $data . '>' . $inner . '</span>';
	}
}

/* Prefer stable term name from taxonomy if present */
if ( ! function_exists( 'jjc_mh_get_team_stable_term_name' ) ) {
	function jjc_mh_get_team_stable_term_name( $team_id ) {
		$team_id = intval( $team_id );
		if ( ! $team_id ) return '';
		$taxonomies = array( 'stable', 'stables', 'team_stable', 'stable_taxonomy', 'stable-name', 'stable_name' );
		foreach ( $taxonomies as $tax ) {
			if ( ! taxonomy_exists( $tax ) ) continue;
			$terms = get_the_terms( $team_id, $tax );
			if ( is_array( $terms ) && ! empty( $terms ) ) {
				foreach ( $terms as $t ) {
					if ( isset( $t->name ) && $t->name !== '' ) return (string) $t->name;
				}
			}
		}
		return '';
	}
}

/* Robust local expansion of team members (tries helpers then common meta keys) */
if ( ! function_exists( 'jjc_mh_expand_team_to_members_local' ) ) {
	function jjc_mh_expand_team_to_members_local( $team_id ) {
		$team_id = intval( $team_id );
		if ( ! $team_id ) return array();

		// 1) prefer existing project helper functions if available
		if ( function_exists( 'wfmc_expand_team_safe' ) ) {
			$members = wfmc_expand_team_safe( $team_id );
			if ( is_array( $members ) && ! empty( $members ) ) return array_values( array_map( 'intval', $members ) );
		}
		if ( function_exists( 'jjc_mh_expand_team_to_members' ) ) {
			$members = jjc_mh_expand_team_to_members( $team_id );
			if ( is_array( $members ) && ! empty( $members ) ) return array_values( array_map( 'intval', $members ) );
		}

		// 2) Check common postmeta keys for team membership
		$possible_keys = array( 'team_members', 'members', 'member_ids', 'members_list', 'wf_team_members', 'roster', 'team_roster' );
		foreach ( $possible_keys as $mk ) {
			$v = get_post_meta( $team_id, $mk, true );
			if ( empty( $v ) ) continue;
			// already array
			if ( is_array( $v ) ) {
				$out = array();
				foreach ( $v as $i ) if ( is_numeric( $i ) ) $out[] = intval( $i );
				if ( ! empty( $out ) ) return array_values( array_unique( $out ) );
			}
			// serialized
			$maybe = @maybe_unserialize( $v );
			if ( is_array( $maybe ) ) {
				$out = array();
				foreach ( $maybe as $i ) if ( is_numeric( $i ) ) $out[] = intval( $i );
				if ( ! empty( $out ) ) return array_values( array_unique( $out ) );
			}
			// json
			$dec = @json_decode( $v, true );
			if ( is_array( $dec ) ) {
				$out = array();
				foreach ( $dec as $i ) if ( is_numeric( $i ) ) $out[] = intval( $i );
				if ( ! empty( $out ) ) return array_values( array_unique( $out ) );
			}
			// single numeric string
			if ( is_string( $v ) && ctype_digit( $v ) ) return array( intval( $v ) );
		}

		// 3) Fallback: maybe team post has child posts that are superstars
		$children = get_children( array( 'post_parent' => $team_id, 'post_type' => 'superstar', 'numberposts' => -1 ) );
		if ( is_array( $children ) && ! empty( $children ) ) {
			$out = array();
			foreach ( $children as $c ) $out[] = intval( $c->ID );
			if ( ! empty( $out ) ) return array_values( array_unique( $out ) );
		}

		return array();
	}
}

/* -----------------------
   Shortcode implementation
   ----------------------- */

if ( ! function_exists( 'jjc_mh_match_participants_title_acf' ) ) {
	function jjc_mh_match_participants_title_acf( $atts = array() ) {
		$atts = shortcode_atts( array(
			'id' => 0,
			'link_participants' => '1',
			'item_class' => '',
		), $atts, 'match_participants_title_acf' );

		$provided = intval( $atts['id'] );

		$render_participants_for_match = function( $match_id ) use ( $atts ) {
			// normalize match id
			if ( isset( $match_id ) ) {
				if ( is_object( $match_id ) && isset( $match_id->ID ) ) $match_id = intval( $match_id->ID );
				elseif ( is_array( $match_id ) && ! empty( $match_id['ID'] ) ) $match_id = intval( $match_id['ID'] );
				else $match_id = intval( $match_id );
			}
			if ( ! $match_id ) return '';

			// gather participants rows similar to previous logic
			$rows = array();
			if ( function_exists( 'get_field' ) ) {
				$maybe = get_field( 'participants_details', $match_id );
				if ( is_array( $maybe ) ) $rows = $maybe;
			}
			if ( empty( $rows ) ) {
				$raw = get_post_meta( $match_id, 'participants_details', true );
				if ( $raw !== '' && $raw !== null ) {
					$maybe = maybe_unserialize( $raw );
					if ( is_array( $maybe ) ) $rows = $maybe;
				}
				if ( empty( $rows ) && $raw !== '' && $raw !== null ) {
					if ( is_numeric( $raw ) || ( is_string( $raw ) && ctype_digit( $raw ) ) ) {
						$pid = intval( $raw );
						if ( $pid ) $rows[] = array( 'participant' => $pid );
					} else {
						$dec = @json_decode( $raw, true );
						if ( is_array( $dec ) && ! empty( $dec ) ) {
							foreach ( $dec as $item ) {
								$row = array( 'participant' => 0 );
								if ( is_numeric( $item ) ) $row['participant'] = intval( $item );
								elseif ( is_array( $item ) && isset( $item['id'] ) ) $row['participant'] = intval( $item['id'] );
								if ( isset( $item['is_winner'] ) ) $row['is_winner'] = (bool) $item['is_winner'];
								if ( $row['participant'] ) $rows[] = $row;
							}
						}
					}
				}
				if ( empty( $rows ) ) {
					$meta = get_post_meta( $match_id );
					foreach ( $meta as $mk => $mv ) {
						if ( preg_match( '/^participants_details_\\d+_participant$/', $mk ) ) {
							$val = get_post_meta( $match_id, $mk, true );
							if ( $val !== '' ) $rows[] = array( 'participant' => $val );
						}
					}
				}
			}

			// extract winners
			$winner_ids = array();
			foreach ( (array) $rows as $r ) {
				if ( isset( $r['is_winner'] ) && filter_var( $r['is_winner'], FILTER_VALIDATE_BOOLEAN ) ) {
					$pid = isset( $r['participant'] ) ? intval( $r['participant'] ) : 0;
					if ( $pid ) $winner_ids[] = $pid;
				}
			}
			if ( empty( $winner_ids ) && empty( $rows ) ) {
				$meta_winners = get_post_meta( $match_id, 'wf_winners', true );
				if ( empty( $meta_winners ) ) $meta_winners = get_post_meta( $match_id, 'winners', true );
				if ( $meta_winners ) {
					$maybe = is_array( $meta_winners ) ? $meta_winners : maybe_unserialize( $meta_winners );
					if ( is_array( $maybe ) ) foreach ( $maybe as $m ) { $m = intval( $m ); if ( $m ) $winner_ids[] = $m; }
					elseif ( is_numeric( $maybe ) ) $winner_ids[] = intval( $maybe );
				}
			}
			$winner_ids = array_values( array_unique( $winner_ids ) );

			// Expand team winner IDs into individual member IDs so members get winner styling.
			if ( ! empty( $winner_ids ) ) {
				$expanded_winner_ids = array();
				foreach ( $winner_ids as $wid ) {
					$wid = intval( $wid );
					if ( ! $wid ) continue;
					$ptype = function_exists( 'get_post_type' ) ? get_post_type( $wid ) : '';
					if ( $ptype === 'team' ) {
						if ( function_exists( 'jjc_mh_expand_team_to_members_local' ) ) {
							$members = jjc_mh_expand_team_to_members_local( $wid );
						} elseif ( function_exists( 'wfmc_expand_team_safe' ) ) {
							$members = wfmc_expand_team_safe( $wid );
						} elseif ( function_exists( 'jjc_mh_expand_team_to_members' ) ) {
							$members = jjc_mh_expand_team_to_members( $wid );
						} else {
							$members = array();
						}
						if ( is_array( $members ) && ! empty( $members ) ) {
							foreach ( $members as $m ) $expanded_winner_ids[] = intval( $m );
						}
					}
				}
				if ( ! empty( $expanded_winner_ids ) ) {
					$winner_ids = array_values( array_unique( array_merge( $winner_ids, $expanded_winner_ids ) ) );
				}
			}

			// incoming champion handling preserved
			$incoming_refs = array();
			$incoming_id = 0;
			if ( function_exists( 'wf_get_incoming_champion' ) ) {
				$incoming_id = intval( wf_get_incoming_champion( $match_id ) );
			}
			if ( $incoming_id ) {
				$incoming_refs[] = $incoming_id;
				$members = function_exists( 'wfmc_expand_team_safe' ) ? wfmc_expand_team_safe( $incoming_id ) : array();
				if ( ! empty( $members ) ) foreach ( $members as $m ) $incoming_refs[] = intval( $m );
			}
			$incoming_refs = array_values( array_unique( array_map( 'intval', $incoming_refs ) ) );

			$participants_html = '';

			// If no participants_details rows, read match_participants meta
			if ( empty( $rows ) ) {
				$raw = get_post_meta( $match_id, 'match_participants', true );
				$pids = is_array( $raw ) ? $raw : ( is_string( $raw ) ? @maybe_unserialize( $raw ) : array() );
				$pids = is_array( $pids ) ? array_map( 'intval', $pids ) : array();
				if ( empty( $pids ) ) return '';
				$parts = array();
				foreach ( $pids as $pid ) {
					$is_win = in_array( $pid, $winner_ids, true );
					$part = jjc_mh_render_participant_element( $pid, $atts['link_participants'] === '1', $is_win );
					if ( in_array( $pid, $incoming_refs, true ) ) $part .= ' <span class="wfmc-incoming-champ">(C)</span>';
					$parts[] = jjc_mh_clean_label_html( $part );
				}
				if ( count( $parts ) === 2 ) {
					$participants_html = $parts[0] . ' <span class="wfmc-vs">vs</span> ' . $parts[1];
				} else {
					$participants_html = wf_human_join_html( $parts );
				}
			} else {
				// grouping logic preserved and improved for teams
				$all_have_role = true;
				foreach ( $rows as $r ) {
					if ( ! isset( $r['role'] ) || $r['role'] === '' ) { $all_have_role = false; break; }
				}
				if ( $all_have_role ) {
					$groups = array();
					foreach ( $rows as $r ) {
						$k = trim( (string)( isset( $r['role'] ) ? $r['role'] : 'side' ) );
						if ( $k === '' ) $k = 'side';
						if ( ! isset( $groups[$k] ) ) $groups[$k] = array();
						$groups[$k][] = $r;
					}
				} else {
					$count = count( $rows );
					if ( $count <= 1 ) $groups = array(0 => $rows);
					elseif ( $count === 2 ) $groups = array(0 => array($rows[0]), 1 => array($rows[1]));
					else { $mid = ceil( $count / 2 ); $groups = array(0 => array_slice( $rows, 0, $mid ), 1 => array_slice( $rows, $mid )); }
				}

				$group_labels = array();
				$group_member_ids = array();

				foreach ( $groups as $grows ) {
					$team_parts = array();
					$non_team_parts = array();
					$member_ids = array();

					foreach ( $grows as $r ) {
						$pid = isset( $r['participant'] ) ? intval( $r['participant'] ) : 0;
						if ( ! $pid ) continue;
						$ptype = function_exists( 'get_post_type' ) ? get_post_type( $pid ) : '';
						if ( $ptype === 'team' ) {
							// robust expansion: try helper then local meta-based checks
							$team_members = jjc_mh_expand_team_to_members_local( $pid );
							if ( ! empty( $team_members ) ) {
								$member_labels = array();
								foreach ( $team_members as $tm ) {
									$member_ids[] = $tm;
									$is_win = in_array( $tm, $winner_ids, true );
									// render linked member HTML and preserve anchors
									$member_labels[] = jjc_mh_render_participant_element( $tm, $atts['link_participants'] === '1', $is_win, 'wfmc-participant-member' );
								}

								// stable name: taxonomy-first, then helper/postmeta/title
								$stable_name = '';
								if ( function_exists( 'jjc_mh_get_team_stable_term_name' ) ) $stable_name = jjc_mh_get_team_stable_term_name( $pid );
								if ( empty( $stable_name ) && function_exists( 'jjc_mh_get_team_stable_name' ) ) $stable_name = jjc_mh_get_team_stable_name( $pid );

								if ( empty( $stable_name ) ) {
									$try_keys = array( 'stable_name', 'team_name', 'name', 'title', 'display_name' );
									foreach ( $try_keys as $k ) {
										$v = get_post_meta( $pid, $k, true );
										if ( $v !== '' && $v !== null ) { $stable_name = ( is_scalar( $v ) ? (string) $v : '' ); break; }
									}
								}
								if ( empty( $stable_name ) ) {
									$tp = get_post( $pid );
									if ( $tp ) $stable_name = get_the_title( $tp );
								}
								$stable_name = $stable_name ? ( function_exists( 'jjc_mh_ensure_string' ) ? jjc_mh_ensure_string( $stable_name ) : (string) $stable_name ) : '';

								if ( $stable_name ) {
									$team_label = '<span class="wfmc-group-stable" data-wfmc-team-id="' . esc_attr( $pid ) . '">' . esc_html( $stable_name ) . '</span>';
									$team_label .= ' (' . wf_human_join_html_allow_html( $member_labels ) . ')';
								} else {
									$team_label = wf_human_join_html_allow_html( $member_labels );
								}
								$team_parts[] = $team_label;
							} else {
								$member_ids[] = $pid;
								$is_win = in_array( $pid, $winner_ids, true );
								$part = jjc_mh_render_participant_element( $pid, $atts['link_participants'] === '1', $is_win, 'wfmc-participant-team' );
								$non_team_parts[] = jjc_mh_clean_label_html( $part );
							}
						} else {
							$member_ids[] = $pid;
							$is_win = in_array( $pid, $winner_ids, true );
							$part = jjc_mh_render_participant_element( $pid, $atts['link_participants'] === '1', $is_win );
							if ( in_array( $pid, $incoming_refs, true ) ) $part .= ' <span class="wfmc-incoming-champ">(C)</span>';
							$non_team_parts[] = jjc_mh_clean_label_html( $part );
						}
					}

					$side_items = array();
					if ( ! empty( $team_parts ) ) $side_items = array_merge( $side_items, $team_parts );
					if ( ! empty( $non_team_parts ) ) $side_items = array_merge( $side_items, $non_team_parts );

					if ( ! empty( $team_parts ) ) {
						$label = wf_human_join_html_allow_html( $side_items );
					} else {
						$label = jjc_mh_clean_label_html( wf_human_join_html( $side_items ) );
					}

					$group_labels[] = $label;
					$group_member_ids[] = $member_ids;
				}

				// winners/losers grouping preserved
				if ( ! empty( $winner_ids ) ) {
					$winner_labels = $loser_labels = array();
					foreach ( $group_member_ids as $idx => $mids ) {
						if ( count( array_intersect( $mids, $winner_ids ) ) > 0 ) { $winner_labels[] = $group_labels[ $idx ]; }
						else { $loser_labels[] = $group_labels[ $idx ]; }
					}
					if ( ! empty( $winner_labels ) ) {
						$participants_html = implode( ' <span class="wfmc-vs">vs</span> ', $winner_labels );
						if ( ! empty( $loser_labels ) ) {
							$participants_html .= ' <span class="wfmc-def">def.</span> ' . implode( ' <span class="wfmc-vs">vs</span> ', $loser_labels );
						}
					} else {
						$participants_html = implode( ' <span class="wfmc-vs">vs</span> ', $group_labels );
					}
				} else {
					if ( count( $group_labels ) > 1 ) {
						$participants_html = implode( ' <span class="wfmc-vs">vs</span> ', $group_labels );
					} else {
						$participants_html = isset( $group_labels[0] ) ? $group_labels[0] : '';
					}
				}
			}

			// map/label match result if present
			$match_result_raw = function_exists( 'get_field' ) ? get_field( 'wf_match_result', $match_id ) : '';
			if ( $match_result_raw === null || $match_result_raw === '' ) $match_result_raw = get_post_meta( $match_id, 'wf_match_result', true ) ?: get_post_meta( $match_id, 'match_result', true );
			$match_result = is_string( $match_result_raw ) ? strtolower( trim( $match_result_raw ) ) : '';
			if ( $match_result && ! empty( $winner_ids ) ) {
				$map = array(
					'draw' => 'Draw', 'double count' => 'Draw', 'no contest' => 'No Contest', 'nc' => 'No Contest',
					'dq' => 'Disqualification', 'disqualification' => 'Disqualification', 'disq' => 'Disqualification',
					'countout' => 'Count Out', 'count out' => 'Count Out', 'count-out' => 'Count Out',
					'pinfall' => 'Pinfall', 'submission' => 'Submission', 'win' => 'Win', 'loss' => 'Loss',
				);
				$label = isset( $map[ $match_result ] ) ? $map[ $match_result ] : ucfirst( $match_result );
				$participants_html .= ' <span class="wfmc-match-result">(' . esc_html( $label ) . ')</span>';
			}

			return $participants_html;
		}; // end participants renderer

		/* ---------- render single match row (uses participants renderer) ---------- */

		$render_full_match_row = function( $m_id ) use ( $render_participants_for_match ) {
			if ( isset( $m_id ) ) {
				if ( is_object( $m_id ) && isset( $m_id->ID ) ) $m_id = intval( $m_id->ID );
				elseif ( is_array( $m_id ) && ! empty( $m_id['ID'] ) ) $m_id = intval( $m_id['ID'] );
				else $m_id = intval( $m_id );
			}
			if ( ! $m_id ) return '';

			$participants_html = $render_participants_for_match( $m_id );

			$event = function_exists( 'jjc_mh_get_event_details' ) ? jjc_mh_get_event_details( $m_id ) : array();
			$event_title = isset( $event['title'] ) ? ( function_exists( 'jjc_mh_resolve_label' ) ? jjc_mh_resolve_label( $event['title'] ) : (string) $event['title'] ) : '';
			$promotion = isset( $event['promotion'] ) ? $event['promotion'] : '';
			$match_type = isset( $event['match_type'] ) ? ( function_exists( 'jjc_mh_resolve_label' ) ? jjc_mh_resolve_label( $event['match_type'] ) : (string) $event['match_type'] ) : '';
			$event_post_id = isset( $event['id'] ) ? intval( $event['id'] ) : 0;
			$event_link = $event_post_id ? get_permalink( $event_post_id ) : '';

			// title/champ detection
			$is_title_on_line = false; $champ_id = 0; $champ_title = ''; $champ_link = '';
			if ( function_exists( 'get_field' ) ) {
				$acf_flag = get_field( 'title_on_the_line', $m_id ); if ( $acf_flag === null ) $acf_flag = get_field( 'title_on_line', $m_id );
				$acf_champ = get_field( 'championship', $m_id ); if ( $acf_champ === null ) $acf_champ = get_field( 'title_championship', $m_id );
				$is_title_on_line = filter_var( $acf_flag, FILTER_VALIDATE_BOOLEAN );
				$champ_id = function_exists( 'jjc_mh_get_post_id_from_field' ) ? jjc_mh_get_post_id_from_field( $acf_champ ) : intval( $acf_champ );
			} else {
				$meta_flag = get_post_meta( $m_id, 'title_on_the_line', true ); if ( $meta_flag === '' || $meta_flag === null ) $meta_flag = get_post_meta( $m_id, 'title_on_line', true );
				$meta_champ = get_post_meta( $m_id, 'championship', true ); if ( $meta_champ === '' || $meta_champ === null ) $meta_champ = get_post_meta( $m_id, 'title_championship', true );
				$is_title_on_line = filter_var( $meta_flag, FILTER_VALIDATE_BOOLEAN );
				$champ_id = function_exists( 'jjc_mh_get_post_id_from_field' ) ? jjc_mh_get_post_id_from_field( $meta_champ ) : intval( $meta_champ );
			}
			if ( $is_title_on_line && $champ_id ) {
				$cp = get_post( $champ_id );
				if ( $cp ) {
					$champ_title = function_exists( 'jjc_mh_ensure_string' ) ? jjc_mh_ensure_string( get_the_title( $champ_id ) ) : get_the_title( $champ_id );
					$champ_link = get_permalink( $champ_id );
				}
			}

			// promo image
			$promo_html = '';
			if ( function_exists( 'jjc_mh_output_promo_image_html' ) ) {
				$promo_html = jjc_mh_output_promo_image_html( $promotion, $m_id, 'full' );
				$promo_html = preg_replace( '/\s*(width|height)=["\']?\d+["\']?/i', '', $promo_html );
				if ( strpos( $promo_html, '<img' ) !== false ) {
					$promo_html = preg_replace( '/<img([^>]*)>/i', '<img$1 class="wfmc-promo-img wf_match_event_image" width="36" height="18" loading="lazy" decoding="async">', $promo_html );
				}
			} elseif ( $event_post_id ) {
				$url = get_the_post_thumbnail_url( $event_post_id, 'full' );
				if ( $url ) $promo_html = '<img class="wfmc-promo-img wf_match_event_image" src="' . esc_url( $url ) . '" width="36" height="18" alt="' . esc_attr( $event_title ) . '" loading="lazy" decoding="async" />';
			}

			// venue/location normalize
			$venue = ''; $location = '';
			if ( $event_post_id ) {
				if ( function_exists( 'get_field' ) ) $venue = get_field( 'event_venue', $event_post_id );
				if ( empty( $venue ) ) $venue = get_post_meta( $event_post_id, 'event_venue', true );
				if ( empty( $venue ) ) $venue = get_post_meta( $event_post_id, 'venue', true );

				if ( function_exists( 'get_field' ) ) $location = get_field( 'event_location', $event_post_id );
				if ( empty( $location ) ) $location = get_post_meta( $event_post_id, 'event_location', true );
				if ( empty( $location ) ) $location = get_post_meta( $event_post_id, 'location', true );
			}
			if ( empty( $venue ) ) {
				if ( function_exists( 'get_field' ) ) $venue = get_field( 'venue', $m_id );
				if ( empty( $venue ) ) $venue = get_post_meta( $m_id, 'event_venue', true );
				if ( empty( $venue ) ) $venue = get_post_meta( $m_id, 'venue', true );
			}
			if ( empty( $location ) ) {
				if ( function_exists( 'get_field' ) ) $location = get_field( 'location', $m_id );
				if ( empty( $location ) ) $location = get_post_meta( $m_id, 'event_location', true );
				if ( empty( $location ) ) $location = get_post_meta( $m_id, 'location', true );
			}
			$venue = function_exists( 'jjc_mh_resolve_label' ) ? jjc_mh_resolve_label( $venue ) : ( is_scalar( $venue ) ? (string) $venue : '' );
			$location = function_exists( 'jjc_mh_resolve_label' ) ? jjc_mh_resolve_label( $location ) : ( is_scalar( $location ) ? (string) $location : '' );

			// Date & match time
			$post_date = '';
			$event_date_raw = '';
			if ( $event_post_id ) {
				if ( function_exists( 'get_field' ) ) $event_date_raw = get_field( 'event_date', $event_post_id );
				if ( empty( $event_date_raw ) ) $event_date_raw = get_post_meta( $event_post_id, 'event_date', true );
				if ( empty( $event_date_raw ) ) $event_date_raw = get_post_meta( $event_post_id, 'event_date_ymd', true );
			}
			if ( empty( $event_date_raw ) ) {
				if ( function_exists( 'get_field' ) ) $event_date_raw = get_field( 'event_date', $m_id );
				if ( empty( $event_date_raw ) ) $event_date_raw = get_post_meta( $m_id, 'event_date', true );
				if ( empty( $event_date_raw ) ) $event_date_raw = get_post_meta( $m_id, 'match_date', true );
			}
			if ( ! empty( $event_date_raw ) ) {
				$ts = false;
				if ( preg_match( '/^\d{8}$/', $event_date_raw ) ) {
					$dt = DateTime::createFromFormat( 'Ymd', $event_date_raw );
					if ( $dt ) $ts = $dt->getTimestamp();
				} elseif ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $event_date_raw ) ) {
					$ts = strtotime( $event_date_raw );
				} elseif ( is_numeric( $event_date_raw ) && strlen( (string) $event_date_raw ) <= 10 ) {
					$ts = intval( $event_date_raw );
				} else {
					$try = strtotime( $event_date_raw );
					if ( $try !== false && $try !== -1 ) $ts = $try;
				}
				if ( $ts && $ts > 0 ) $post_date = date_i18n( 'd.m.Y', $ts );
				else $post_date = get_the_date( 'd.m.Y', $m_id );
			} else {
				$post_date = get_the_date( 'd.m.Y', $m_id );
			}
			$match_time = '';
			if ( function_exists( 'get_field' ) ) $match_time = get_field( 'match_time', $m_id );
			if ( $match_time === null || $match_time === '' ) $match_time = get_post_meta( $m_id, 'match_time', true );
			$match_time = trim( (string) $match_time );

			// Build row
			$tr = '<tr class="wfmc-row">';
			$tr .= '<td class="wfmc-col wfmc-col-date">' . esc_html( $post_date ) . '</td>';
			$tr .= '<td class="wfmc-col wfmc-col-promo">';
			if ( $promo_html ) {
				if ( $event_link ) $tr .= '<a href="' . esc_url( $event_link ) . '">' . $promo_html . '</a>';
				else $tr .= $promo_html;
			}
			$tr .= '</td>';
			$tr .= '<td class="wfmc-col wfmc-col-main">';

			$first_parts  = '<div class="wfmc-first-line">';
			$first_parts .= '<span class="wfmc-matchcard" style="display:inline-block;white-space:normal;font-weight:700;">';

			if ( $is_title_on_line && $champ_id && $champ_title ) {
				if ( $champ_link ) $first_parts .= '<span class="wfmc-champ" style="display:inline;white-space:nowrap;"><a class="wfmc-champ-link" href="' . esc_url( $champ_link ) . '">' . esc_html( $champ_title ) . '</a></span> ';
				else $first_parts .= '<span class="wfmc-champ" style="display:inline;white-space:nowrap;">' . esc_html( $champ_title ) . '</span> ';
			}

			if ( $match_type ) {
				$mt = rtrim( $match_type );
				if ( substr( $mt, -1 ) !== ':' && substr( $mt, -1 ) !== '.' && substr( $mt, -1 ) !== '!' ) {
					$mt_out = esc_html( $mt ) . ':';
				} else {
					$mt_out = esc_html( $mt );
				}
				$first_parts .= '<span class="wfmc-match-type" style="display:inline;white-space:nowrap;font-weight:700;">' . $mt_out . '&nbsp;</span>';
			}

			$first_parts .= '<span class="wfmc-participants-inline" style="display:inline;white-space:normal;">' . $participants_html . '</span>';

			if ( $match_time !== '' ) {
				$first_parts .= '<span class="wfmc-match-time" style="white-space:nowrap"> (' . esc_html( $match_time ) . ')</span>';
			}

			$first_parts .= '</span></div>';

			$tr .= $first_parts;

			$event_line = '<div class="wfmc-eventline">';
			if ( $event_title ) {
				if ( $event_link ) $event_line .= '<a href="' . esc_url( $event_link ) . '">' . esc_html( $event_title ) . '</a>';
				else $event_line .= esc_html( $event_title );
			}
			$meta_parts = array();
			if ( $venue ) $meta_parts[] = esc_html( $venue );
			if ( $location ) $meta_parts[] = esc_html( $location );
			if ( ! empty( $meta_parts ) ) {
				$event_line .= ' @ ' . implode( ' in ', $meta_parts );
			}
			$event_line .= '</div>';
			$tr .= $event_line;

			$tr .= '</td></tr>';

			return $tr;
		};

		// explicit id -> single-row table; return debug if not found
		if ( $provided ) {
			$row = $render_full_match_row( $provided );
			$debug_comment_for_provided = '<!-- wf-debug: provided=' . esc_html( (string) $provided ) . '; row_exists=' . ( $row ? '1' : '0' ) . ' -->';
			if ( trim( $row ) === '' ) {
				return $debug_comment_for_provided;
			}
			return '<table class="wfmc-table" cellpadding="0" cellspacing="0" border="0"><tbody>' . $row . '</tbody></table>' . $debug_comment_for_provided;
		}

		// Superstar listing
		$superstar_id = 0;
		if ( is_singular( 'superstar' ) ) $superstar_id = get_the_ID();
		else { global $post; if ( isset( $post->ID ) && get_post_type( $post->ID ) === 'superstar' ) $superstar_id = intval( $post->ID ); }

		if ( ! $superstar_id ) return '<!-- wf-debug: superstar_id=0 (no resolved superstar) -->';

		$search_ids = array( intval( $superstar_id ) );
		if ( function_exists( 'jjc_mh_get_team_ids_for_superstar' ) ) {
			$teams = jjc_mh_get_team_ids_for_superstar( $superstar_id );
			if ( is_array( $teams ) ) $search_ids = array_values( array_unique( array_merge( $search_ids, array_map( 'intval', $teams ) ) ) );
		}

		$participant_keys = array( 'match_participants', 'participants_details', 'participants', 'participants_list' );
		$mq = array( 'relation' => 'OR' );
		foreach ( $participant_keys as $meta_key ) {
			foreach ( $search_ids as $sid ) {
				$sid = intval( $sid );
				$mq[] = array( 'key' => $meta_key, 'value' => '"' . $sid . '"', 'compare' => 'LIKE' );
				$mq[] = array( 'key' => $meta_key, 'value' => (string) $sid, 'compare' => 'LIKE' );
			}
		}

		$query_args = array(
			'post_type'   => 'match',
			'post_status' => 'publish',
			'numberposts' => -1,
			'meta_query'  => $mq,
			'orderby'     => 'meta_value_num date',
			'meta_key'    => 'match_order',
			'order'       => 'ASC',
		);

		$matches = get_posts( $query_args );

		$debug_comment = '<!-- wf-debug: provided=' . esc_html( (string) $provided )
			. '; superstar_id=' . esc_html( (string) $superstar_id )
			. '; search_ids=' . esc_html( implode( ',', array_map( 'intval', $search_ids ) ) )
			. '; matches=' . intval( count( $matches ) )
			. ' -->';

		if ( empty( $matches ) ) {
			return $debug_comment;
		}

		$match_ids = array_map( function( $m ){ return intval( $m->ID ); }, $matches );
		$unique_ids = array_values( array_unique( $match_ids ) );

		$rows = '';
		foreach ( $unique_ids as $m_id ) {
			$rows .= $render_full_match_row( intval( $m_id ) );
		}

		if ( trim( $rows ) === '' ) return $debug_comment;

		return '<table class="wfmc-table" cellpadding="0" cellspacing="0" border="0"><tbody>' . $rows . '</tbody></table>' . $debug_comment;
	}
}