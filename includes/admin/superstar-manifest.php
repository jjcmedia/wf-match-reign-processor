<?php
/**
 * Admin UI to view / generate / export plugin manifest.
 * Place: includes/admin/superstar-manifest.php
 *
 * Depends on includes/manifest-scanner.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', function() {
	add_management_page(
		'WF Manifest',
		'WF Manifest',
		'manage_options',
		'wf-manifest',
		'wf_render_manifest_admin_page'
	);
} );

function wf_get_manifest_path() {
	if ( ! defined( 'WF_MR_PLUGIN_DIR' ) ) {
		return false;
	}
	return trailingslashit( WF_MR_PLUGIN_DIR ) . 'wf-manifest.json';
}

function wf_load_manifest_file() {
	$path = wf_get_manifest_path();
	if ( ! $path || ! file_exists( $path ) ) {
		return null;
	}
	$raw = file_get_contents( $path );
	$json = json_decode( $raw, true );
	if ( json_last_error() !== JSON_ERROR_NONE ) return null;
	return $json;
}

function wf_render_manifest_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) return;

	// include scanner helper
	$scanner = WF_MR_PLUGIN_DIR . 'includes/manifest-scanner.php';
	if ( file_exists( $scanner ) ) {
		require_once $scanner;
	}

	$manifest = wf_load_manifest_file();
	$scan_now = isset( $_GET['wf_scan'] ) && intval( $_GET['wf_scan'] ) === 1;
	$export = isset( $_GET['wf_export'] ) && intval( $_GET['wf_export'] ) === 1;

	$scan_result = null;
	if ( $scan_now && function_exists( 'wf_build_plugin_manifest' ) ) {
		$scan_result = wf_build_plugin_manifest( WF_MR_PLUGIN_DIR );
		// Optionally persist to file
		$path = wf_get_manifest_path();
		if ( $path ) {
			@file_put_contents( $path, wp_json_encode( $scan_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			$manifest = $scan_result;
		}
	}

	// If export requested, deliver download (manifest from file or just-scanned)
	if ( $export ) {
		$final = $scan_result ? $scan_result : $manifest;
		if ( $final ) {
			header( 'Content-Type: application/json' );
			header( 'Content-Disposition: attachment; filename="wf-manifest.json"' );
			echo wp_json_encode( $final, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			exit;
		}
		// else fall through
	}
	?>
	<div class="wrap">
		<h1>WF Plugin Manifest</h1>

		<p>
			This page shows an index of files and discovered ACF/postmeta/taxonomy keys used across the plugin.
			Use the scan to detect fields and code references automatically; download the manifest to commit to your repo.
		</p>

		<p>
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'tools.php?page=wf-manifest&wf_scan=1' ) ); ?>">Scan plugin now</a>
			<?php if ( file_exists( wf_get_manifest_path() ) ) : ?>
				<a class="button" href="<?php echo esc_url( admin_url( 'tools.php?page=wf-manifest&wf_export=1' ) ); ?>">Download current manifest</a>
			<?php endif; ?>
		</p>

		<?php if ( $scan_now && $scan_result ) : ?>
			<h2>Scan results (fresh)</h2>
			<p>Files discovered: <strong><?php echo intval( $scan_result['file_count'] ); ?></strong> — generated at <?php echo esc_html( $scan_result['generated_at'] ); ?></p>
		<?php elseif ( $manifest ) : ?>
			<h2>Manifest file</h2>
			<p>Manifest generated at: <strong><?php echo esc_html( $manifest['generated_at'] ); ?></strong></p>
		<?php else : ?>
			<p>No manifest found yet — run a scan to generate one.</p>
		<?php endif; ?>

		<?php
		$display = $scan_result ? $scan_result : $manifest;
		if ( $display ) :
			// Summary: top fields and top files
			$fields = isset( $display['fields'] ) ? $display['fields'] : array();
			$files = isset( $display['files'] ) ? $display['files'] : array();
		?>
			<h3>Discovered meta/ACF keys (<?php echo intval( count( $fields ) ); ?>)</h3>
			<table class="widefat striped">
				<thead><tr><th>Field / Key</th><th>Referenced in (file paths)</th></tr></thead>
				<tbody>
				<?php foreach ( $fields as $key => $filelist ) : ?>
					<tr>
						<td><code><?php echo esc_html( $key ); ?></code></td>
						<td>
							<?php
							$parts = array();
							foreach ( (array) $filelist as $f ) {
								$parts[] = '<code>' . esc_html( $f ) . '</code>';
							}
							echo implode( ', ', $parts );
							?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h3 style="margin-top:18px;">Taxonomies referenced (<?php echo intval( count( $display['taxonomies'] ) ); ?>)</h3>
			<table class="widefat">
				<thead><tr><th>Taxonomy</th><th>Referenced in</th></tr></thead>
				<tbody>
				<?php foreach ( (array) $display['taxonomies'] as $tx => $filelist ) : ?>
					<tr>
						<td><code><?php echo esc_html( $tx ); ?></code></td>
						<td><?php echo implode( ', ', array_map( 'esc_html', $filelist ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h3 style="margin-top:18px;">Files (<?php echo intval( count( $files ) ); ?>)</h3>
			<table class="widefat">
				<thead><tr><th>File</th><th>Size</th><th>Modified</th><th>Meta keys found</th></tr></thead>
				<tbody>
				<?php foreach ( (array) $files as $rel => $info ) : ?>
					<tr>
						<td><code><?php echo esc_html( $rel ); ?></code></td>
						<td><?php echo esc_html( size_format( $info['size'] ) ); ?></td>
						<td><?php echo esc_html( $info['modified'] ); ?></td>
						<td><?php echo isset( $info['meta_keys'] ) && ! empty( $info['meta_keys'] ) ? esc_html( implode( ', ', $info['meta_keys'] ) ) : '&mdash;'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

	</div>
	<?php
}