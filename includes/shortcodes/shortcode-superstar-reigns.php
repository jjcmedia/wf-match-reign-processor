<?php
/**
 * wf_superstar_reigns shortcode implementation.
 *
 * Outputs reigns as wfmc-table rows so visuals match the match listing rows.
 * - Championship title no longer includes "— current"
 * - Date range formatted as "Month jS, Y - Month jS, Y" (or "Current")
 * - Days calculated by whole-day boundaries (midnight-to-midnight in WP timezone)
 *   so Nov 1 → Nov 23 = 22 days.
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
			'id'         => 0,
			'image_size' => 'thumbnail',
			'show_images' => '1',
			'limit'      => 0,
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

		$champion_meta_keys = array(
			'wf_reign_champions',
			'wf_reign_champion',
			'wf_reign_champions_list',
			'champions',
			'reign_champions',
			'wf_reign_champs',
		);

		// loose candidate query — strict filtering below
		$mq = array( 'relation' => 'OR' );
		foreach ( $champion_meta_keys as $mk ) {
			$mq[] = array( 'key' => $mk, 'value' => '"' . intval( $id ) . '"', 'compare' => 'LIKE' );
			$mq[] = array( 'key' => $mk, 'value' => (string) intval( $id ), 'compare' => 'LIKE' );
		}

		$rposts = get_posts( array(
			'post_type'   => 'reign',
			'post_status' => 'publish',
			'numberposts' => ( $limit > 0 ? $limit : -1 ),
			'meta_query'  => $mq,
			'orderby'     => 'meta_value',
			'meta_key'    => 'wf_reign_start_date',
			'order'       => 'DESC',
		) );

		if ( empty( $rposts ) ) return '<div class="wf-superstar-reigns"><p>No reigns found for this superstar.</p></div>';

		// Normalizer for champion-like meta values
		$normalize_champion_ids = function( $raw ) {
			$out = array();
			if ( $raw === null || $raw === '' ) return $out;

			if ( is_array( $raw ) ) {
				foreach ( $raw as $v ) {
					if ( is_array( $v ) ) {
						if ( isset( $v['ID'] ) && intval( $v['ID'] ) ) $out[] = intval( $v['ID'] );
						elseif ( isset( $v['id'] ) && intval( $v['id'] ) ) $out[] = intval( $v['id'] );
						else {
							foreach ( $v as $nv ) {
								if ( is_scalar( $nv ) && ctype_digit( (string) $nv ) ) $out[] = intval( $nv );
							}
						}
					} elseif ( is_object( $v ) ) {
						if ( isset( $v->ID ) && intval( $v->ID ) ) $out[] = intval( $v->ID );
					} elseif ( is_scalar( $v ) && ctype_digit( (string) $v ) ) {
						$out[] = intval( $v );
					}
				}
				return array_values( array_unique( array_map( 'intval', $out ) ) );
			}

			if ( is_string( $raw ) ) {
				$maybe = @maybe_unserialize( $raw );
				if ( is_array( $maybe ) ) return $normalize_champion_ids( $maybe );

				$dec = @json_decode( $raw, true );
				if ( is_array( $dec ) ) return $normalize_champion_ids( $dec );

				if ( strpos( $raw, ',' ) !== false ) {
					$parts = array_map( 'trim', explode( ',', $raw ) );
					return $normalize_champion_ids( $parts );
				}

				if ( preg_match_all( '/\d+/', $raw, $m ) ) {
					$vals = array_map( 'intval', $m[0] );
					return array_values( array_unique( $vals ) );
				}

				if ( ctype_digit( $raw ) ) return array( intval( $raw ) );
			}

			if ( is_numeric( $raw ) ) return array( intval( $raw ) );

			return $out;
		};

		// Team membership check
		$team_has_member = function( $team_id, $superstar_id ) use ( $normalize_champion_ids ) {
			$team_id = intval( $team_id );
			$superstar_id = intval( $superstar_id );
			if ( ! $team_id || ! $superstar_id ) return false;

			$possible_keys = array( 'team_members', 'members', 'member_ids', 'members_list', 'wf_team_members', 'roster', 'team_roster' );
			foreach ( $possible_keys as $k ) {
				$v = get_post_meta( $team_id, $k, true );
				if ( $v === '' || $v === null ) continue;
				$ids = $normalize_champion_ids( $v );
				if ( ! empty( $ids ) && in_array( $superstar_id, $ids, true ) ) return true;
			}

			$children = get_children( array( 'post_parent' => $team_id, 'post_type' => 'superstar', 'numberposts' => -1 ) );
			if ( is_array( $children ) && ! empty( $children ) ) {
				foreach ( $children as $c ) {
					if ( intval( $c->ID ) === $superstar_id ) return true;
				}
			}

			return false;
		};

		// Precompute teams
		$superstar_team_ids = array();
		if ( function_exists( 'jjc_mh_get_team_ids_for_superstar' ) ) {
			$superstar_team_ids = jjc_mh_get_team_ids_for_superstar( $id );
			if ( ! is_array( $superstar_team_ids ) ) $superstar_team_ids = array();
			$superstar_team_ids = array_map( 'intval', $superstar_team_ids );
		}

		// Strictly filter candidates
		$filtered = array();
		foreach ( $rposts as $r ) {
			$found = false;
			foreach ( $champion_meta_keys as $mk ) {
				$raw = get_post_meta( $r->ID, $mk, true );
				$ids = $normalize_champion_ids( $raw );
				if ( empty( $ids ) ) continue;

				if ( in_array( $id, $ids, true ) ) { $found = true; break; }

				foreach ( $ids as $cid ) {
					if ( $cid <= 0 ) continue;
					$ptype = function_exists( 'get_post_type' ) ? get_post_type( $cid ) : '';
					if ( $ptype === 'team' ) {
						if ( in_array( $cid, $superstar_team_ids, true ) ) { $found = true; break 2; }
						if ( $team_has_member( $cid, $id ) ) { $found = true; break 2; }
					}
				}
			}

			// fallback champion meta 'champion'
			if ( ! $found ) {
				$maybe_single = get_post_meta( $r->ID, 'champion', true );
				$ids = $normalize_champion_ids( $maybe_single );
				if ( in_array( $id, $ids, true ) ) $found = true;
				else {
					foreach ( $ids as $cid ) {
						if ( $cid <= 0 ) continue;
						$ptype = function_exists( 'get_post_type' ) ? get_post_type( $cid ) : '';
						if ( $ptype === 'team' ) {
							if ( in_array( $cid, $superstar_team_ids, true ) ) { $found = true; break; }
							if ( $team_has_member( $cid, $id ) ) { $found = true; break; }
						}
					}
				}
			}

			if ( $found ) $filtered[] = $r;
		}

		if ( empty( $filtered ) ) return '<div class="wf-superstar-reigns"><p>No reigns found for this superstar.</p></div>';

		// Helper: format raw reign date into "F jS, Y" (e.g. November 1st, 2025)
		$format_human_date = function( $raw ) {
			$raw_s = (string) $raw;

			// FIRST: detect YYYYMMDD numeric form (e.g. 20251101) — handle before generic numeric-to-timestamp
			if ( preg_match( '/^\d{8}$/', $raw_s ) ) {
				$dt = DateTime::createFromFormat( 'Ymd', $raw_s );
				if ( $dt ) return date_i18n( 'F jS, Y', $dt->getTimestamp() );
			}

			// If numeric and looks like a unix timestamp (10 digits or fewer and > 1970), treat as timestamp
			if ( is_numeric( $raw_s ) && strlen( $raw_s ) <= 10 ) {
				$ts = intval( $raw_s );
				if ( $ts > 1000000000 ) return date_i18n( 'F jS, Y', $ts ); // timestamp check
			}

			// YYYY-MM-DD
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw_s ) ) {
				$dt = DateTime::createFromFormat( 'Y-m-d', $raw_s );
				if ( $dt ) return date_i18n( 'F jS, Y', $dt->getTimestamp() );
			}

			// fallback: strtotime
			$ts = strtotime( $raw_s );
			if ( $ts !== false && $ts !== -1 ) return date_i18n( 'F jS, Y', $ts );

			return '';
		};

		// Helper: get midnight timestamp in WP timezone for a given timestamp/string
		$get_midnight_ts = function( $raw ) use ( $format_human_date ) {
			// reuse the same parsing rules as to_ts below
			$raw_s = (string) $raw;
			$ts = false;

			// YYYYMMDD numeric
			if ( preg_match( '/^\d{8}$/', $raw_s ) ) {
				$dt = DateTime::createFromFormat( 'Ymd', $raw_s );
				if ( $dt ) $ts = $dt->getTimestamp();
			}

			// YYYY-MM-DD
			if ( $ts === false && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw_s ) ) {
				$try = strtotime( $raw_s );
				if ( $try !== false ) $ts = $try;
			}

			// numeric timestamp (guard to avoid interpreting YYYYMMDD as timestamp)
			if ( $ts === false && ctype_digit( $raw_s ) && strlen( $raw_s ) <= 10 ) {
				$maybe_ts = intval( $raw_s );
				if ( $maybe_ts > 0 ) $ts = $maybe_ts;
			}

			// fallback to strtotime
			if ( $ts === false ) {
				$try = strtotime( $raw_s );
				if ( $try !== false ) $ts = $try;
			}

			if ( $ts === false ) return false;

			// convert to WP timezone midnight
			if ( function_exists( 'wp_timezone' ) ) {
				$tz = wp_timezone();
			} else {
				$tz = new DateTimeZone( get_option( 'timezone_string' ) ?: date_default_timezone_get() );
			}

			$dt = new DateTime( "@$ts" );
			$dt->setTimezone( $tz );
			$mid_str = $dt->format( 'Y-m-d' ) . ' 00:00:00';
			$mid_dt = DateTime::createFromFormat( 'Y-m-d H:i:s', $mid_str, $tz );
			if ( $mid_dt ) return (int) $mid_dt->format( 'U' );

			// last fallback: use floor of day UTC
			return (int) ( floor( $ts / 86400 ) * 86400 );
		};

		// Build HTML: use a wfmc-table to reuse table visuals
		$out_rows    = '';
		$show_images = filter_var( $a['show_images'], FILTER_VALIDATE_BOOLEAN );

		$i = 0;
		foreach ( $filtered as $r ) {
			$title_id   = get_post_meta( $r->ID, 'wf_reign_title', true );
			$title_name = $title_id ? get_the_title( $title_id ) : '(unknown title)';

			$start_raw = get_post_meta( $r->ID, 'wf_reign_start_date', true );
			$end_raw   = get_post_meta( $r->ID, 'wf_reign_end_date', true );
			$is_current_meta = get_post_meta( $r->ID, 'wf_reign_is_current', true );
			$is_current = ( $is_current_meta !== '' ) ? (bool) $is_current_meta : ( empty( $end_raw ) ? true : false );

			// date range per request: human formatted start - human formatted end (or "Current")
			$start_label = $format_human_date( $start_raw );
			$end_label   = $is_current ? 'Current' : $format_human_date( $end_raw );
			$date_range  = $start_label . ( $end_label ? ' - ' . $end_label : '' );

			$img_html = '';
			if ( $show_images && $title_id && function_exists( 'wf_get_championship_image_html' ) ) {
				$img_html = wf_get_championship_image_html( intval( $title_id ), $a['image_size'] );
				// ensure the returned img has the wfmc-promo-img & wf-champ-image classes
				if ( $img_html ) {
					if ( strpos( $img_html, 'class="' ) !== false ) {
						$img_html = preg_replace( '/class="([^"]*)"/', 'class="$1 wfmc-promo-img wf-champ-image"', $img_html, 1 );
					} else {
						$img_html = preg_replace( '/<img /', '<img class="wfmc-promo-img wf-champ-image" ', $img_html, 1 );
					}
				}
			}

			// days calculation using midnight boundaries in WP timezone (no +1)
			$start_mid = $get_midnight_ts( $start_raw );
			$end_mid   = $is_current ? $get_midnight_ts( current_time( 'Ymd' ) ) : $get_midnight_ts( $end_raw );
			// If end_mid failed (e.g. end_empty), fallback to current midnight
			if ( $end_mid === false ) {
				$end_mid = $get_midnight_ts( current_time( 'Ymd' ) );
			}
			$days = 0;
			if ( $start_mid && $end_mid && $end_mid >= $start_mid ) {
				$days = (int) floor( ( $end_mid - $start_mid ) / 86400 );
			}

			$defenses = intval( get_post_meta( $r->ID, 'wf_reign_defenses', true ) );
			if ( $defenses < 0 ) $defenses = 0;

			// create a table row that reuses wfmc classes
			$row  = '<tr class="wfmc-row">';

			// promo/champ image cell first (image is first)
			$row .= '<td class="wfmc-col wfmc-col-promo" style="vertical-align:middle;">';
			if ( $img_html ) {
				$row .= $img_html;
			}
			$row .= '</td>';

			// main content: title (no "current" appended), date range, excerpt
			$first_parts  = '<div class="wfmc-first-line">';
			$first_parts .= '<span class="wfmc-matchcard" style="display:inline-block;white-space:normal;font-weight:700;">';
			$first_parts .= '<span class="wfmc-champ" style="display:inline;white-space:nowrap;">' . esc_html( $title_name ) . '</span>';
			$first_parts .= '</span></div>';

			$meta_line = '<div class="wfmc-eventline">';
			$meta_line .= esc_html( $date_range );
			$meta_line .= '</div>';

			$notes = trim( strip_tags( $r->post_content ) );
			$excerpt = '';
			if ( $notes ) $excerpt = '<div class="wf-reign-excerpt">' . esc_html( wp_html_excerpt( $notes, 140, '...' ) ) . '</div>';

			$row .= '<td class="wfmc-col wfmc-col-main">';
			$row .= $first_parts;
			$row .= $meta_line;
			$row .= $excerpt;
			$row .= '</td>';

			// right-side stats cell: days (big number + small "days") and defenses next to it
			$row .= '<td class="wfmc-col wfmc-col-side" style="width:160px;text-align:right;vertical-align:middle;">';
			$row .= '<div class="wf-reign-side" aria-hidden="true">';
			$row .= '<div class="wf-reign-side-row">';
			$row .= '<div class="wf-reign-side-item">';
			$row .= '<span class="wf-days"><span class="wf-days-number">' . esc_html( $days ) . '</span> <span class="wf-days-label">days</span></span>';
			$row .= '</div>';
			$row .= '<div class="wf-reign-side-item">';
			$row .= '<span class="wf-defenses"><span class="wf-defenses-number">' . esc_html( number_format_i18n( $defenses ) ) . '</span> <span class="wf-defenses-label">defenses</span></span>';
			$row .= '</div>';
			$row .= '</div>'; // .wf-reign-side-row
			$row .= '</div>'; // .wf-reign-side
			$row .= '</td>';

			$row .= '</tr>';

			$out_rows .= $row;
			$i++;
		}

		// final table: reuse the wfmc-table so visuals match exactly
		$table  = '<table class="wfmc-table" cellpadding="0" cellspacing="0" border="0"><tbody>';
		$table .= $out_rows;
		$table .= '</tbody></table>';

		return '<div class="wf-superstar-reigns">' . $table . '</div>';
	}
}