<?php
/**
 * Enhanced Manifest scanner helper
 * Path: includes/manifest-scanner.php
 *
 * Scans the plugin directory for files and extracts:
 *  - referenced meta keys (get_post_meta, get_field, the_field, acf_get_field)
 *  - referenced taxonomies (get_the_terms)
 *  - file metadata
 *  - occurrences with line numbers + short snippet for each match
 *
 * This enhanced scanner replaces the earlier simple scanner and gives
 * precise file:line locations so you (and I) can quickly find where fields
 * are referenced in code.
 *
 * Usage:
 *  - wf_build_plugin_manifest( WF_MR_PLUGIN_DIR );
 *  - Admin UI or WP-CLI will call this when you click "Scan plugin now".
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recursively list plugin files (exclude vendor / node_modules / .git)
 *
 * @param string $base_dir
 * @return array list of absolute file paths
 */
function wf_scan_list_files( $base_dir ) {
	$files = array();
	$exclude_dirs = array( '/node_modules/', '/.git/', '/vendor/', '/tests/' );

	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $base_dir, RecursiveDirectoryIterator::SKIP_DOTS )
	);

	foreach ( $it as $file ) {
		$path = $file->getPathname();
		// skip directories we don't want to scan
		$skip = false;
		foreach ( $exclude_dirs as $d ) {
			// normalize separators for matching
			$norm = str_replace( array( '\\', '/' ), DIRECTORY_SEPARATOR, $d );
			if ( strpos( $path, DIRECTORY_SEPARATOR . trim( $norm, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR ) !== false ) {
				$skip = true;
				break;
			}
		}
		if ( $skip ) continue;
		if ( $file->isFile() ) {
			$files[] = $path;
		}
	}

	return $files;
}

/**
 * Given file contents and a byte offset, compute the 1-based line number.
 *
 * @param string $contents
 * @param int $offset
 * @return int
 */
function wf_offset_to_line( $contents, $offset ) {
	// Clamp
	$offset = max( 0, min( strlen( $contents ), intval( $offset ) ) );
	// Count newlines before offset
	$before = substr( $contents, 0, $offset );
	return substr_count( $before, "\n" ) + 1;
}

/**
 * Extract a short snippet (single line) given contents and a line number.
 *
 * @param string $contents
 * @param int $line_number 1-based
 * @return string
 */
function wf_get_line_snippet( $contents, $line_number ) {
	$lines = preg_split( '/\r\n|\n|\r/', $contents );
	$idx = max( 0, $line_number - 1 );
	if ( isset( $lines[ $idx ] ) ) {
		return trim( $lines[ $idx ] );
	}
	return '';
}

/**
 * Enhanced scan for meta keys, acf keys, taxonomies and occurrences
 *
 * @param string $file_path
 * @return array {
 *   'meta_keys' => array( key => occurrences[] ),
 *   'acf_keys' => same as meta_keys,
 *   'taxonomies' => array( taxonomy => occurrences[] ),
 *   'other_matches' => array
 * }
 */
function wf_scan_file_for_keys( $file_path ) {
	$contents = @file_get_contents( $file_path );
	if ( $contents === false ) {
		return array(
			'meta_keys' => array(),
			'acf_keys' => array(),
			'taxonomies' => array(),
			'other_matches' => array(),
		);
	}

	$meta_keys = array();
	$taxonomies = array();
	$other_matches = array();

	// Patterns to find literal string usage for common functions
	$patterns = array(
		// get_post_meta( ..., 'key', ... ) or get_post_meta('key', ...)
		'get_post_meta' => '/get_post_meta\(\s*(?:[^\),]+,\s*)?[\'"]([^\'"]+)[\'"]\s*,/i',
		// get_field('key')
		'get_field' => '/get_field\(\s*[\'"]([^\'"]+)[\'"]\s*[\),]/i',
		// the_field('key')
		'the_field' => '/the_field\(\s*[\'"]([^\'"]+)[\'"]\s*[\),]/i',
		// acf_get_field('key')
		'acf_get_field' => '/acf_get_field\(\s*[\'"]([^\'"]+)[\'"]\s*[\),]/i',
		// update_post_meta($id, 'key', ...)
		'update_post_meta' => '/update_post_meta\(\s*[^\),]*,\s*[\'"]([^\'"]+)[\'"]\s*,/i',
		// get_post_meta($id, 'key')
		'get_post_meta_alt' => '/get_post_meta\(\s*[^,]+,\s*[\'"]([^\'"]+)[\'"]\s*\)/i',
	);

	foreach ( $patterns as $label => $pat ) {
		if ( preg_match_all( $pat, $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[1] as $m ) {
				$key = trim( $m[0] );
				$offset = intval( $m[1] );
				$line = wf_offset_to_line( $contents, $offset );
				$snippet = wf_get_line_snippet( $contents, $line );

				if ( ! isset( $meta_keys[ $key ] ) ) $meta_keys[ $key ] = array();
				$meta_keys[ $key ][] = array(
					'line' => $line,
					'snippet' => $snippet,
				);
			}
		}
	}

	// find taxonomies referenced in get_the_terms( $post_id, 'taxonomy' )
	if ( preg_match_all( '/get_the_terms\(\s*[^\),]*,\s*[\'"]([^\'"]+)[\'"]\s*\)/i', $contents, $mt, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $mt[1] as $m ) {
			$tx = trim( $m[0] );
			$offset = intval( $m[1] );
			$line = wf_offset_to_line( $contents, $offset );
			$snippet = wf_get_line_snippet( $contents, $line );
			if ( ! isset( $taxonomies[ $tx ] ) ) $taxonomies[ $tx ] = array();
			$taxonomies[ $tx ][] = array(
				'line' => $line,
				'snippet' => $snippet,
			);
		}
	}

	// find register_taxonomy usages
	if ( preg_match_all( '/register_taxonomy\(\s*[\'"]([^\'"]+)[\'"]/i', $contents, $rt, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $rt[1] as $m ) {
			$tx = trim( $m[0] );
			$offset = intval( $m[1] );
			$line = wf_offset_to_line( $contents, $offset );
			$snippet = wf_get_line_snippet( $contents, $line );
			if ( ! isset( $taxonomies[ $tx ] ) ) $taxonomies[ $tx ] = array();
			$taxonomies[ $tx ][] = array(
				'line' => $line,
				'snippet' => $snippet,
			);
		}
	}

	// find register_post_type usages (other matches)
	if ( preg_match_all( '/register_post_type\(\s*[\'"]([^\'"]+)[\'"]/i', $contents, $rp, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $rp[1] as $m ) {
			$pt = trim( $m[0] );
			$offset = intval( $m[1] );
			$line = wf_offset_to_line( $contents, $offset );
			$snippet = wf_get_line_snippet( $contents, $line );
			$other_matches[] = array(
				'type' => 'post_type',
				'name' => $pt,
				'line' => $line,
				'snippet' => $snippet,
			);
		}
	}

	return array(
		'meta_keys' => $meta_keys,
		'taxonomies' => $taxonomies,
		'other_matches' => $other_matches,
	);
}

/**
 * Look for ACF local JSON files in plugin acf-json folder and return parsed fields.
 *
 * @param string $plugin_dir
 * @return array list of field group arrays (decoded JSON)
 */
function wf_scan_acf_local_json( $plugin_dir ) {
	$groups = array();
	$acf_dir = trailingslashit( $plugin_dir ) . 'acf-json';
	if ( ! is_dir( $acf_dir ) ) {
		return $groups;
	}
	$it = new DirectoryIterator( $acf_dir );
	foreach ( $it as $file ) {
		if ( $file->isFile() && strtolower( $file->getExtension() ) === 'json' ) {
			$contents = @file_get_contents( $file->getPathname() );
			if ( $contents ) {
				$decoded = json_decode( $contents, true );
				if ( json_last_error() === JSON_ERROR_NONE ) {
					$groups[] = $decoded;
				}
			}
		}
	}
	return $groups;
}

/**
 * Build a full manifest for the plugin folder (enhanced).
 *
 * @param string $plugin_dir absolute path
 * @return array manifest structure
 */
function wf_build_plugin_manifest( $plugin_dir ) {
	$files = wf_scan_list_files( $plugin_dir );

	$file_details = array();
	$field_index = array(); // key => array(files with occurrences)
	$tax_index = array();

	foreach ( $files as $f ) {
		$stat = @stat( $f );
		$rel = str_replace( rtrim( $plugin_dir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR, '', $f );

		$scan = wf_scan_file_for_keys( $f );

		// Normalize meta_keys and taxonomies to map of occurrences
		$meta_occ = array();
		foreach ( $scan['meta_keys'] as $k => $occ ) {
			$meta_occ[ $k ] = $occ;
			if ( ! isset( $field_index[ $k ] ) ) $field_index[ $k ] = array();
			$field_index[ $k ][] = array( 'file' => $rel, 'occurrences' => $occ );
		}

		$tax_occ = array();
		foreach ( $scan['taxonomies'] as $tx => $occ ) {
			$tax_occ[ $tx ] = $occ;
			if ( ! isset( $tax_index[ $tx ] ) ) $tax_index[ $tx ] = array();
			$tax_index[ $tx ][] = array( 'file' => $rel, 'occurrences' => $occ );
		}

		$file_details[ $rel ] = array(
			'path' => $rel,
			'size' => $stat ? intval( $stat['size'] ) : 0,
			'modified' => $stat ? date( 'c', $stat['mtime'] ) : '',
			'meta_keys' => $meta_occ,
			'taxonomies' => $tax_occ,
			'other' => $scan['other_matches'],
		);
	}

	// Include ACF local JSON definitions (if any) to provide exact field keys & labels
	$acf_groups = wf_scan_acf_local_json( $plugin_dir );
	$acf_summary = array();
	if ( ! empty( $acf_groups ) ) {
		foreach ( $acf_groups as $g ) {
			if ( isset( $g['key'] ) || isset( $g['title'] ) ) {
				$acf_summary[] = array(
					'title' => isset( $g['title'] ) ? $g['title'] : '',
					'key' => isset( $g['key'] ) ? $g['key'] : '',
					'fields' => isset( $g['fields'] ) ? $g['fields'] : array(),
				);
			}
		}
	}

	ksort( $file_details );
	ksort( $field_index );
	ksort( $tax_index );

	$manifest = array(
		'generated_at' => date( 'c' ),
		'plugin_path' => $plugin_dir,
		'file_count' => count( $file_details ),
		'files' => $file_details,
		'fields' => $field_index,
		'taxonomies' => $tax_index,
		'acf_local_json' => $acf_summary,
	);

	return $manifest;
}