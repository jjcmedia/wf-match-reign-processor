<?php
/**
 * Admin: Manual reign creation UI (integrated into the main plugin)
 * - Creates the Reign post and delegates processing to wf_apply_reign()
 *
 * Path:
 *   wp-content/plugins/wf-match-reign-processor/includes/admin-manual-reigns.php
 *
 * Notes:
 * - This file creates a Reign post and sets initial canonical meta, then calls wf_apply_reign($rid, array('manual'=>true)).
 * - Keep this UI in place for bulk/manual entries; wf_apply_reign does the bookkeeping.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AJAX: search superstars / posts for Select2 picker
 * Returns JSON: { results: [ { id: ID, text: "Post title (ID)" }, ... ] }
 */
add_action( 'wp_ajax_wf_search_superstars', 'wf_ajax_search_superstars' );
function wf_ajax_search_superstars() {
    // permission & nonce
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'forbidden', 403 );
    }
    check_ajax_referer( 'wf_manual_reign_nonce', 'nonce' );

    $q = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
    $page = isset( $_GET['page'] ) ? intval( $_GET['page'] ) : 1;
    $per_page = 20;

    $post_types = array( 'superstar', 'person', 'people', 'post', 'page' );

    $args = array(
        's' => $q,
        'post_type' => $post_types,
        'post_status' => 'publish',
        'posts_per_page' => $per_page,
        'paged' => $page,
        'fields' => 'ids',
    );

    // Use get_posts to search; for very large sites this could be further optimized
    $posts = get_posts( $args );

    $results = array();
    if ( ! empty( $posts ) ) {
        foreach ( $posts as $pid ) {
            $title = get_the_title( $pid );
            $results[] = array(
                'id' => intval( $pid ),
                'text' => sprintf( '%s (ID:%d)', $title, $pid ),
            );
        }
    }

    wp_send_json( array( 'results' => $results ) );
}

/**
 * Add management page
 */
add_action( 'admin_menu', function() {
    add_management_page(
        'WF Manual Reigns',
        'WF Manual Reigns',
        'manage_options',
        'wf-manual-reigns',
        'wf_manual_reigns_page'
    );
} );

/**
 * Enqueue Select2 assets only on our page
 */
add_action( 'admin_enqueue_scripts', function( $hook ) {
    // Only load on our page
    if ( $hook !== 'tools_page_wf-manual-reigns' ) return;

    // Select2 (CDN) - v4
    wp_enqueue_style( 'select2-css', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css', array(), '4.0.13' );
    wp_enqueue_script( 'select2-js', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.full.min.js', array( 'jquery' ), '4.0.13', true );

    // Our inline init script depends on select2-js; we'll localize ajax nonce and url
    $params = array(
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'wf_manual_reign_nonce' ),
    );
    wp_localize_script( 'select2-js', 'wf_manual_reign_params', $params );

    // Small inline init script (only when select2 is loaded)
    add_action( 'admin_print_footer_scripts', function() {
        ?>
        <script type="text/javascript">
        (function($){
            $(document).ready(function(){
                if ( typeof $.fn.select2 === 'undefined' ) return;

                $('#champions_select').select2({
                    placeholder: 'Search champions by name or ID',
                    allowClear: true,
                    ajax: {
                        url: wf_manual_reign_params.ajax_url,
                        dataType: 'json',
                        delay: 250,
                        data: function(params) {
                            return {
                                action: 'wf_search_superstars',
                                q: params.term || '',
                                page: params.page || 1,
                                nonce: wf_manual_reign_params.nonce
                            };
                        },
                        processResults: function(data, params) {
                            params.page = params.page || 1;
                            // Select2 expects results array
                            return {
                                results: data.results || []
                            };
                        },
                        cache: true
                    },
                    minimumInputLength: 1,
                    width: '60%',
                    templateResult: function(item) {
                        if (!item.id) return item.text;
                        return $('<span>').text(item.text);
                    },
                    templateSelection: function(item) {
                        return item.text || item.id;
                    }
                });

                // When form is submitted, ensure selected values are properly placed into hidden input(s)
                $('#wf_manual_reign_form').on('submit', function(){
                    // nothing special required as select name is champions[] and will submit values
                    return true;
                });
            });
        })(jQuery);
        </script>
        <?php
    } );
} );

/**
 * Render the manual reign admin page and handle form submissions.
 */
function wf_manual_reigns_page() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions' );

    $errors = array();
    $success = '';

    if ( isset( $_POST['wf_manual_reign_submit'] ) ) {
        check_admin_referer( 'wf_manual_reign_action', 'wf_manual_reign_nonce' );

        $championship = isset( $_POST['championship'] ) ? intval( $_POST['championship'] ) : 0;

        // Champions can come in as champions[] (Select2) or legacy comma-separated champions string
        $champions = array();
        if ( ! empty( $_POST['champions'] ) ) {
            // If posted as array (Select2)
            if ( is_array( $_POST['champions'] ) ) {
                foreach ( $_POST['champions'] as $c ) {
                    if ( is_numeric( $c ) ) $champions[] = intval( $c );
                }
            } else {
                // legacy comma separated
                $champions_raw = sanitize_text_field( $_POST['champions'] );
                if ( $champions_raw ) {
                    $parts = preg_split( '/[,\s]+/', $champions_raw );
                    foreach ( $parts as $p ) if ( is_numeric( trim( $p ) ) ) $champions[] = intval( $p );
                }
            }
        }

        $start_date_raw = isset( $_POST['start_date'] ) ? sanitize_text_field( $_POST['start_date'] ) : '';
        $end_date_raw   = isset( $_POST['end_date'] ) ? sanitize_text_field( $_POST['end_date'] ) : '';
        $is_current     = ! empty( $_POST['is_current'] ) ? 1 : 0;
        $notes          = isset( $_POST['notes'] ) ? sanitize_textarea_field( $_POST['notes'] ) : '';

        if ( ! $championship ) $errors[] = 'Please choose a championship post ID.';
        if ( empty( $champions ) ) $errors[] = 'Please provide at least one champion ID.';

        // normalize start date
        $start_date_norm = '';
        if ( $start_date_raw ) {
            try {
                $dt = new DateTime( $start_date_raw );
                $start_date_norm = $dt->format( 'Y-m-d' );
            } catch ( Exception $e ) {
                if ( ctype_digit( $start_date_raw ) && strlen( $start_date_raw ) === 8 ) {
                    $dt = DateTime::createFromFormat( 'Ymd', $start_date_raw );
                    if ( $dt ) $start_date_norm = $dt->format( 'Y-m-d' );
                }
            }
        }
        if ( ! $start_date_norm ) $start_date_norm = current_time( 'Y-m-d' );

        // normalize end date (optional)
        $end_date_norm = '';
        if ( $end_date_raw ) {
            try {
                $dt = new DateTime( $end_date_raw );
                $end_date_norm = $dt->format( 'Y-m-d' );
            } catch ( Exception $e ) {
                if ( ctype_digit( $end_date_raw ) && strlen( $end_date_raw ) === 8 ) {
                    $dt = DateTime::createFromFormat( 'Ymd', $end_date_raw );
                    if ( $dt ) $end_date_norm = $dt->format( 'Y-m-d' );
                }
            }
        }

        // If marked current, we ignore any provided end date
        if ( $is_current ) {
            $end_date_norm = '';
        }

        // Validate logical date order if end date present
        if ( $end_date_norm ) {
            $s_ts = strtotime( $start_date_norm );
            $e_ts = strtotime( $end_date_norm );
            if ( $e_ts === false || $s_ts === false ) {
                $errors[] = 'Invalid start or end date.';
            } elseif ( $e_ts < $s_ts ) {
                $errors[] = 'End date must be the same as or after the start date.';
            }
        }

        if ( empty( $errors ) ) {
            // Create the Reign post (minimal)
            $champion_names = array();
            foreach ( $champions as $cid ) {
                $p = get_post( $cid );
                if ( $p ) $champion_names[] = $p->post_title;
            }
            $title_name = get_post_field( 'post_title', $championship );
            $display_start = $start_date_norm ? $start_date_norm : current_time( 'Y-m-d' );
            $display_end = $end_date_norm ? $end_date_norm : '';
            $range = $display_start . ( $display_end ? ' — ' . $display_end : '' );
            $reign_title = wp_strip_all_tags( trim( $title_name . ' — ' . ( ! empty( $champion_names ) ? implode( ', ', $champion_names ) : 'Unknown' ) . ' ( ' . $range . ' )' ) );

            $reign_post = array(
                'post_title' => $reign_title,
                'post_type' => 'reign',
                'post_status' => 'publish',
                'post_content' => $notes,
            );

            $rid = wp_insert_post( $reign_post );

            if ( is_wp_error( $rid ) || ! $rid ) {
                $errors[] = 'Failed to create reign post.';
            } else {
                // Write initial canonical meta so wf_apply_reign reads consistent values
                update_post_meta( $rid, 'wf_reign_title', $championship );
                update_post_meta( $rid, 'wf_reign_champions', $champions );
                update_post_meta( $rid, 'wf_reign_start_date', date_i18n( 'Ymd', strtotime( $start_date_norm ) ) );
                update_post_meta( $rid, 'wf_reign_end_date', $end_date_norm ? date_i18n( 'Ymd', strtotime( $end_date_norm ) ) : '' );
                update_post_meta( $rid, 'wf_reign_won_match', 0 );
                update_post_meta( $rid, 'wf_reign_ended_by_match', 0 );
                // If an end date is provided, this is not current; otherwise use is_current flag
                $is_current_meta = $is_current && empty( $end_date_norm ) ? 1 : 0;
                update_post_meta( $rid, 'wf_reign_is_current', $is_current_meta );
                update_post_meta( $rid, 'wf_reign_notes', $notes );
                update_post_meta( $rid, 'wf_reign_manual', 1 );
                update_post_meta( $rid, 'wf_reign_defenses', 0 );

                // ACF writes when available
                if ( function_exists( 'update_field' ) ) {
                    update_field( 'wf_reign_title', $championship, $rid );
                    update_field( 'wf_reign_champions', $champions, $rid );
                    update_field( 'wf_reign_start_date', date_i18n( 'Ymd', strtotime( $start_date_norm ) ), $rid );
                    update_field( 'wf_reign_end_date', $end_date_norm ? date_i18n( 'Ymd', strtotime( $end_date_norm ) ) : '', $rid );
                    update_field( 'wf_reign_is_current', $is_current_meta, $rid );
                    update_field( 'wf_reign_notes', $notes, $rid );
                    update_field( 'wf_reign_manual', 1, $rid );
                }

                // Delegate to centralized processor to apply bookkeeping
                if ( function_exists( 'wf_apply_reign' ) ) {
                    $res = wf_apply_reign( $rid, array( 'manual' => true ) );
                    // wf_apply_reign will close prior current reigns if wf_reign_is_current was set
                } else {
                    $res = array( 'status' => 'error', 'message' => 'Processor not available' );
                }

                if ( isset( $res['status'] ) && $res['status'] === 'ok' ) {
                    $success = "Created Reign #{$rid}.";
                } else {
                    $errors[] = 'Reign created but processing failed: ' . ( isset( $res['message'] ) ? esc_html( $res['message'] ) : 'unknown' );
                }
            }
        }
    }

    // Prepare values for form rendering / prepopulation
    $posted_champions = array();
    if ( isset( $_POST['champions'] ) ) {
        if ( is_array( $_POST['champions'] ) ) {
            foreach ( $_POST['champions'] as $c ) if ( is_numeric( $c ) ) $posted_champions[] = intval( $c );
        } elseif ( is_string( $_POST['champions'] ) && $_POST['champions'] !== '' ) {
            $parts = preg_split( '/[,\s]+/', sanitize_text_field( $_POST['champions'] ) );
            foreach ( $parts as $p ) if ( is_numeric( trim( $p ) ) ) $posted_champions[] = intval( $p );
        }
    }

    ?>
    <div class="wrap">
        <h1>WF Manual Reigns</h1>

        <?php if ( ! empty( $errors ) ) : ?>
            <div class="notice notice-error"><p><strong>Errors:</strong></p><ul><?php foreach ( $errors as $e ) echo '<li>' . esc_html( $e ) . '</li>'; ?></ul></div>
        <?php endif; ?>

        <?php if ( $success ) : ?>
            <div class="notice notice-success"><p><?php echo esc_html( $success ); ?></p></div>
        <?php endif; ?>

        <form id="wf_manual_reign_form" method="post" style="max-width:900px;">
            <?php wp_nonce_field( 'wf_manual_reign_action', 'wf_manual_reign_nonce' ); ?>

            <table class="form-table">
                <tr>
                    <th scope="row"><label for="championship">Championship (post)</label></th>
                    <td>
                        <input type="number" name="championship" id="championship" style="width:160px;" placeholder="Post ID of championship" value="<?php echo isset( $_POST['championship'] ) ? esc_attr( intval( $_POST['championship'] ) ) : ''; ?>" />
                        <p class="description">Enter the post ID of the championship.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="champions_select">Champion(s)</label></th>
                    <td>
                        <select id="champions_select" name="champions[]" multiple="multiple" style="width:60%;"></select>
                        <p class="description">Start typing a name or ID to search and add champions. Selected IDs are submitted automatically.</p>

                        <?php
                        // Pre-populate selected options if we have posted values
                        if ( ! empty( $posted_champions ) ) {
                            foreach ( $posted_champions as $pc ) {
                                $p = get_post( $pc );
                                if ( $p ) {
                                    printf(
                                        '<script>jQuery(function($){ var opt = new Option(%s, %d, true, true); $("#champions_select").append(opt).trigger("change"); });</script>',
                                        json_encode( get_the_title( $pc ) . ' (ID:' . $pc . ')' ),
                                        intval( $pc )
                                    );
                                }
                            }
                        }
                        ?>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="start_date">Start date</label></th>
                    <td>
                        <input type="date" name="start_date" id="start_date" value="<?php echo isset( $_POST['start_date'] ) ? esc_attr( $_POST['start_date'] ) : ''; ?>" />
                        <p class="description">Format: YYYY-MM-DD. If left empty, today's date will be used.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="end_date">End date (optional)</label></th>
                    <td>
                        <input type="date" name="end_date" id="end_date" value="<?php echo isset( $_POST['end_date'] ) ? esc_attr( $_POST['end_date'] ) : ''; ?>" />
                        <p class="description">If this is a past reign, provide the end date (YYYY-MM-DD). Leave empty for ongoing reigns.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Is current?</th>
                    <td>
                        <label><input type="checkbox" name="is_current" value="1" <?php echo ! empty( $_POST['is_current'] ) ? 'checked' : ''; ?> /> Mark this reign as current (will close existing current reigns for the championship). If checked, any provided end date will be ignored.</label>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="notes">Notes (optional)</label></th>
                    <td>
                        <textarea name="notes" id="notes" rows="4" cols="60"><?php echo isset( $_POST['notes'] ) ? esc_textarea( $_POST['notes'] ) : ''; ?></textarea>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <input type="submit" name="wf_manual_reign_submit" id="submit" class="button button-primary" value="Create manual reign" />
            </p>
        </form>

        <h2>Quick usage tips</h2>
        <ul>
            <li>After creating a manual reign it will be processed automatically and superstar counters will be updated.</li>
            <li>For historical records, provide both start and end dates and leave "Is current?" unchecked.</li>
            <li>If you prefer a nicer UX (search by name) we can add a Select2 picker instead of numeric IDs.</li>
        </ul>
    </div>
    <?php
}
?>