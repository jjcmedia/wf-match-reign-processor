<?php
/**
 * Incoming Champion metabox (ACF-first, no numeric fallback)
 *
 * This metabox shows the ACF 'incoming_champion' Post Object value (superstar).
 * Editors must use the ACF Post Object field on the Match edit screen to set the incoming champion.
 * If ACF or the field is missing the metabox displays a short instruction; it does not save a numeric input.
 *
 * Place in: wp-content/plugins/wf-match-reign-processor/includes/acf-incoming-champion-metabox.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'add_meta_boxes', function() {
    // ensure a single authoritative metabox exists
    remove_meta_box( 'wf_incoming_champion', 'match', 'side' );
    add_meta_box(
        'wf_incoming_champion',
        'Incoming Champion (Title Match)',
        'wf_render_incoming_champion_metabox',
        'match',
        'side',
        'default'
    );
}, 11 );

/**
 * Render metabox:
 * - If ACF and field exist: display the ACF-selected superstar (title + link + thumbnail if available)
 * - If ACF is missing or field absent: instruct editors to add the ACF Post Object field
 */
function wf_render_incoming_champion_metabox( $post ) {
    // prefer to show ACF post object if available
    if ( function_exists( 'get_field' ) && function_exists( 'get_field_object' ) && get_field_object( 'incoming_champion', $post->ID ) ) {
        $value = get_field( 'incoming_champion', $post->ID ); // return_format expected to be 'id' (or may be WP_Post)
        if ( empty( $value ) ) {
            echo '<p><em>No incoming champion set via ACF.</em></p>';
            echo '<p class="description">Use the "Incoming Champion" ACF Post Object field in the main editor to assign a Superstar.</p>';
            return;
        }

        // Normalize to array of IDs (support single ID or array or WP_Post)
        $ids = array();
        if ( is_array( $value ) ) {
            foreach ( $value as $v ) {
                if ( is_object( $v ) && isset( $v->ID ) ) $ids[] = intval( $v->ID );
                elseif ( is_numeric( $v ) ) $ids[] = intval( $v );
            }
        } elseif ( is_object( $value ) && isset( $value->ID ) ) {
            $ids[] = intval( $value->ID );
        } elseif ( is_numeric( $value ) ) {
            $ids[] = intval( $value );
        }

        if ( empty( $ids ) ) {
            echo '<p><em>No incoming champion set.</em></p>';
            echo '<p class="description">Use the "Incoming Champion" ACF Post Object field in the main editor to assign a Superstar.</p>';
            return;
        }

        echo '<div style="font-size:13px;">';
        echo '<p class="description">Incoming champion is set via ACF Post Object — edit the ACF field in the Match editor. Displaying current value(s):</p>';
        foreach ( $ids as $id ) {
            $title = get_post_field( 'post_title', $id );
            $link = get_edit_post_link( $id );
            $thumb = '';
            if ( function_exists( 'get_the_post_thumbnail' ) ) {
                $thumb = get_the_post_thumbnail( $id, array(48,48), array( 'style' => 'margin-right:8px;vertical-align:middle;border-radius:4px;' ) );
            }
            echo '<div style="margin-bottom:8px;">';
            if ( $thumb ) echo '<span style="display:inline-block;vertical-align:middle;">' . $thumb . '</span>';
            if ( $link ) {
                echo '<a href="' . esc_url( $link ) . '" target="_blank" style="vertical-align:middle;">' . esc_html( $title ? $title : '#' . $id ) . '</a>';
            } else {
                echo '<strong>' . esc_html( $title ? $title : '#' . $id ) . '</strong>';
            }
            echo ' <span style="color:#666;font-size:12px;">(ID: ' . intval( $id ) . ')</span>';
            echo '</div>';
        }
        echo '</div>';

        return;
    }

    // If ACF not available or field not registered, show instruction to add the ACF field.
    echo '<p><em>The site expects an ACF Post Object field named <code>incoming_champion</code> on the Match edit screen.</em></p>';
    echo '<p class="description">Please add an ACF Post Object field named <strong>incoming_champion</strong> (Post Type: superstar, return format: ID). Editors will then choose the Superstar by name.</p>';
    // Do NOT render or save a numeric input here — we require ACF to be present so editors always use the Post Object UI.
}