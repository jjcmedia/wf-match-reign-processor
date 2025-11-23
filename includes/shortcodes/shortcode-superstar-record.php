<?php
/**
 * Shortcode handler: wf_shortcode_superstar_record
 * Wrestling-focused dashboard for a superstar — robust DOB parsing fix.
 *
 * Place at: includes/shortcodes/shortcode-superstar-record.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wf_shortcode_superstar_record' ) ) {
	function wf_shortcode_superstar_record( $atts = array(), $content = '' ) {
		$atts = shortcode_atts( array(
			'id' => 0,
			'match_id' => 0,
			// toggles controlled by block inspector
			'show_bio'    => true,
			'show_champs' => true,
			'show_feuds'  => true,
		), (array) $atts, 'wf_superstar_record' );

		$post_id = intval( $atts['id'] );
		if ( ! $post_id ) {
			$post = get_post();
			$post_id = ( $post && isset( $post->ID ) ) ? intval( $post->ID ) : 0;
		}
		if ( ! $post_id ) {
			return '';
		}

		// Meta helper
		$get_meta = function( $key, $default = '' ) use ( $post_id ) {
			$v = get_post_meta( $post_id, $key, true );
			if ( $v === '' || $v === null ) {
				return $default;
			}
			return $v;
		};

		// Robust date parser for ACF date fields / meta values
		$parse_superstar_date = function( $raw ) {
			if ( empty( $raw ) ) {
				return '';
			}

			// Prevent falsey numeric values (0, "0") causing epoch
			if ( is_string( $raw ) && trim( $raw ) === '0' ) {
				return '';
			}

			// If value is already a DateTime
			if ( $raw instanceof DateTimeInterface ) {
				$dt = new DateTimeImmutable( $raw->format( 'c' ) );
				return $dt;
			}

			// If numeric: could be a timestamp (seconds) or Ymd integer (e.g. 19700615)
			if ( is_numeric( $raw ) ) {
				$raw_str = (string) $raw;
				$len = strlen( $raw_str );

				// Unix timestamp in seconds (rough heuristic: >= 10 digits)
				if ( $len >= 10 && intval( $raw ) > 1000000000 ) {
					try {
						$dt = new DateTimeImmutable( "@".intval( $raw ) );
						// set to site's timezone
						$tz = new DateTimeZone( date_default_timezone_get() ?: 'UTC' );
						$dt = $dt->setTimezone( $tz );
					} catch ( Exception $e ) {
						$dt = false;
					}
					if ( $dt ) return $dt;
				}

				// Possibly Ymd style like 19700615
				if ( $len === 8 ) {
					$dt = DateTimeImmutable::createFromFormat( 'Ymd', $raw_str );
					if ( $dt instanceof DateTimeImmutable ) return $dt;
				}

				// otherwise avoid interpreting small ints as epoch
				return '';
			}

			// Try several known formats commonly returned by ACF or user input
			$formats = array(
				'Y-m-d',
				'Y/m/d',
				'Y.m.d',
				'Ymd',
				'm/d/Y',
				'd/m/Y',
				'd-m-Y',
				'F j, Y',
				'F j Y',
				'j F Y',
				'Y', // year only
			);

			foreach ( $formats as $fmt ) {
				$dt = DateTimeImmutable::createFromFormat( $fmt, $raw );
				if ( $dt instanceof DateTimeImmutable ) {
					// createFromFormat can return false positives for partial matches; ensure timestamp reasonable
					$ts = $dt->getTimestamp();
					if ( $ts > 0 ) return $dt;
				}
			}

			// Fallback to strtotime but ensure valid result and not epoch/1970
			$ts = strtotime( $raw );
			if ( $ts !== false && $ts > 0 ) {
				$dt = new DateTimeImmutable( "@$ts" );
				$tz = new DateTimeZone( date_default_timezone_get() ?: 'UTC' );
				$dt = $dt->setTimezone( $tz );
				// sanity check on year: not earlier than 1900 and not in the future
				$year = (int) $dt->format( 'Y' );
				$current_year = (int) date( 'Y' );
				if ( $year >= 1900 && $year <= $current_year ) {
					return $dt;
				}
			}

			return '';
		};

		// ----- ACF fields -----
		$photo = $get_meta( 'superstar_image', '' );
		if ( is_numeric( $photo ) ) {
			$photo = wp_get_attachment_url( intval( $photo ) );
		}

		$status_term = '';
		if ( taxonomy_exists( 'superstar-status' ) ) {
			$terms = get_the_terms( $post_id, 'superstar-status' );
			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
				$status_term = esc_html( $terms[0]->name );
			}
		}
		if ( ! $status_term ) {
			$status_term = $get_meta( 'superstar_status', '' );
		}

		$real_name = $get_meta( 'superstar_real_name', '' );

		$dob_raw = $get_meta( 'date_of_birth', '' );
		$dob = '';
		if ( $dob_raw !== '' ) {
			$dt_obj = $parse_superstar_date( $dob_raw );
			if ( $dt_obj instanceof DateTimeImmutable ) {
				// use date_i18n for localized formatting
				$dob = date_i18n( 'F j, Y', $dt_obj->getTimestamp() );
			} else {
				// leave as empty (avoid showing epoch)
				$dob = '';
			}
		}

		$hometown_term = '';
		if ( taxonomy_exists( 'location' ) ) {
			$terms = get_the_terms( $post_id, 'location' );
			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) $hometown_term = esc_html( $terms[0]->name );
		}
		if ( ! $hometown_term ) {
			$hometown_term = $get_meta( 'hometown', '' );
		}

		$height = $get_meta( 'height', '' );
		$weight = $get_meta( 'weight', '' );

		$company_term = '';
		if ( taxonomy_exists( 'company' ) ) {
			$terms = get_the_terms( $post_id, 'company' );
			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) $company_term = esc_html( $terms[0]->name );
		}
		if ( ! $company_term ) {
			$company_term = $get_meta( 'company', '' );
		}

		$bio = $get_meta( 'superstar_bio', '' );

		$stable_term = '';
		if ( taxonomy_exists( 'stable' ) ) {
			$terms = get_the_terms( $post_id, 'stable' );
			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) $stable_term = esc_html( $terms[0]->name );
		}
		if ( ! $stable_term ) {
			$stable_term = $get_meta( 'stable', '' );
		}

		// Counters stored by the plugin
		$total_matches    = (int) $get_meta( 'wf_total_matches', 0 );
		$tag_matches      = (int) $get_meta( 'wf_tag_matches', 0 );
		$singles_matches  = max( 0, $total_matches - $tag_matches );
		$wins             = (int) $get_meta( 'wf_wins', 0 );
		$losses           = (int) $get_meta( 'wf_losses', 0 );

		// championships (flexible format)
		$champs_raw = $get_meta( 'wf_championships', '' );
		$championships = array();
		if ( is_array( $champs_raw ) && ! empty( $champs_raw ) ) {
			$championships = $champs_raw;
		} elseif ( is_string( $champs_raw ) && $champs_raw !== '' ) {
			$try = @json_decode( $champs_raw, true );
			if ( is_array( $try ) ) $championships = $try;
			else {
				$maybe_ser = @maybe_unserialize( $champs_raw );
				if ( is_array( $maybe_ser ) ) $championships = $maybe_ser;
				else $championships = array_filter( array_map( 'trim', explode( ',', $champs_raw ) ) );
			}
		}
		$champ_count = count( $championships );

		// sparkline data (optional)
		$spark_raw = $get_meta( 'wf_last15_points', '' );
		$spark = array();
		if ( is_string( $spark_raw ) && $spark_raw !== '' ) {
			$maybe = @json_decode( $spark_raw, true );
			if ( is_array( $maybe ) ) $spark = $maybe;
		}

		// Enqueue optional chart init if spark exists (script registration expected in plugin)
		if ( ! empty( $spark ) && wp_script_is( 'wf-superstar-charts-init', 'registered' ) ) {
			wp_enqueue_script( 'chartjs' ); // plugin registers CDN if not present
			wp_enqueue_script( 'wf-superstar-charts-init' );
		}

		// Build markup
		$name = get_the_title( $post_id );

		$out = '<div class="wf-superstar-record" aria-labelledby="wf-superstar-' . esc_attr( $post_id ) . '">';
		$out .= '<div class="wf-record-dashboard" role="region" aria-label="' . esc_attr( $name ) . ' record">';

		// LEFT: profile (removed duplicate match KPIs here; stats stay in center)
		$out .= '<div class="wf-record-profile">';
		$out .= '<div class="wf-profile-photo">';
		if ( $photo ) {
			$out .= '<img src="' . esc_url( $photo ) . '" alt="' . esc_attr( $name ) . '">';
		} else {
			$out .= '<div style="width:100%;height:100%;display:grid;place-items:center;color:var(--wf-row-subtle);">No Photo</div>';
		}
		$out .= '</div>'; // photo

		$out .= '<div class="wf-profile-name">' . esc_html( $name ) . '</div>';
		if ( $real_name ) $out .= '<div class="wf-profile-role" style="font-size:0.9rem;color:var(--wf-row-subtle);">(' . esc_html( $real_name ) . ')</div>';
		if ( $status_term ) $out .= '<div style="margin-top:6px;color:var(--wf-row-subtle);font-weight:700;">' . esc_html( $status_term ) . '</div>';

		$out .= '</div>'; // left

		// CENTER: stat pills + BIO (REPLACED recent matches with superstar_bio)
		$out .= '<div class="wf-record-main">';

		$out .= '<div class="wf-record-stats">';
		$out .= '<div class="wf-pill wf-pill--accent"><div class="wf-pill__value">' . esc_html( $total_matches ) . '</div><div class="wf-pill__label">Total</div></div>';
		$out .= '<div class="wf-pill wf-pill--accent"><div class="wf-pill__value">' . esc_html( $singles_matches ) . '</div><div class="wf-pill__label">Singles</div></div>';
		$out .= '<div class="wf-pill wf-pill--accent"><div class="wf-pill__value">' . esc_html( $tag_matches ) . '</div><div class="wf-pill__label">Tag</div></div>';
		$out .= '<div class="wf-pill"><div class="wf-pill__value">' . esc_html( $wins ) . '</div><div class="wf-pill__label">Wins</div></div>';
		$out .= '<div class="wf-pill"><div class="wf-pill__value">' . esc_html( $losses ) . '</div><div class="wf-pill__label">Losses</div></div>';
		$out .= '</div>'; // stats

		// BIO in center
		if ( $bio && filter_var( $atts['show_bio'], FILTER_VALIDATE_BOOLEAN ) ) {
			$out .= '<div class="wf-record-chart">'; // reuse chart block visuals for content area
			$out .= '<div class="wf-chart-title"><h4>About</h4><div class="wf-chart-controls"></div></div>';
			$out .= '<div style="font-size:0.95rem;color:var(--wf-row-text);">' . wp_kses_post( wpautop( $bio ) ) . '</div>';
			$out .= '</div>';
		}

		// optional sparkline below bio (kept if present)
		if ( ! empty( $spark ) ) {
			$out .= '<div style="margin-top:10px">';
			$out .= '<canvas id="wf-sparkline-' . esc_attr( $post_id ) . '" class="wf-chart" width="400" height="80" data-values="' . esc_attr( wp_json_encode( $spark ) ) . '"></canvas>';
			$out .= '</div>';
		}

		$out .= '</div>'; // center

		// RIGHT: details & retained info
		$out .= '<div class="wf-record-side">';

		if ( $dob ) {
			$out .= '<div class="wf-match-meta"><div class="label">Date of Birth</div><div class="value">' . esc_html( $dob ) . '</div></div>';
		}

		if ( $height || $weight ) {
			$phys = trim( ( $height ? $height : '' ) . ( $height && $weight ? ' / ' : '' ) . ( $weight ? $weight : '' ) );
			$out .= '<div class="wf-match-meta"><div class="label">Physicals</div><div class="value">' . esc_html( $phys ) . '</div></div>';
		}

		if ( $hometown_term ) {
			$out .= '<div class="wf-match-meta"><div class="label">Hometown</div><div class="value">' . esc_html( $hometown_term ) . '</div></div>';
		}

		if ( $company_term ) {
			$out .= '<div class="wf-match-meta"><div class="label">Company</div><div class="value">' . esc_html( $company_term ) . '</div></div>';
		}

		if ( $stable_term ) {
			$out .= '<div class="wf-match-meta"><div class="label">Stable / Team</div><div class="value">' . esc_html( $stable_term ) . '</div></div>';
		}

		if ( $champ_count && filter_var( $atts['show_champs'], FILTER_VALIDATE_BOOLEAN ) ) {
			$out .= '<div class="wf-side-stats"><div class="row"><div class="k">Championships</div><div class="v">' . esc_html( $champ_count ) . '</div></div>';
			$maxc = 4; $ci = 0;
			foreach ( $championships as $c ) {
				if ( $ci++ >= $maxc ) break;
				$label = is_numeric( $c ) ? ( get_the_title( intval( $c ) ) ?: '' ) : ( is_array( $c ) && isset( $c['title'] ) ? $c['title'] : (string)$c );
				if ( $label ) $out .= '<div class="row"><div class="k" style="opacity:.85">' . esc_html( $label ) . '</div><div class="v"></div></div>';
			}
			$out .= '</div>';
		}

		$feuds = $get_meta( 'wf_notable_feuds', '' );
		if ( $feuds && filter_var( $atts['show_feuds'], FILTER_VALIDATE_BOOLEAN ) ) {
			$out .= '<div class="wf-picks"><div class="label" style="font-weight:700;margin-bottom:6px;">Notable Feuds</div><div style="font-size:0.9rem;color:var(--wf-row-subtle);">' . wp_kses_post( wpautop( $feuds ) ) . '</div></div>';
		}

		$out .= '</div>'; // right

		$out .= '</div>'; // dashboard
		$out .= '</div>'; // wrapper

		return apply_filters( 'wf_shortcode_superstar_record_output', $out, $post_id, $atts );
	}

	// register shortcodes if not already registered elsewhere
	add_shortcode( 'wf_superstar_record', 'wf_shortcode_superstar_record' );
	add_shortcode( 'superstar_record', 'wf_shortcode_superstar_record' );
}