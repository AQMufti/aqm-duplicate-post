<?php
/**
 * Plugin Name: AQM Duplicate Post
 * Description: Adds a "Duplicate" link to the Posts and Pages list tables. Copies content, taxonomies and meta into a new DRAFT. Converted from a must-use plugin on 8 Sep 2026 so it updates itself from GitHub releases like every other AQM plugin.
 * Version:     1.3.0
 * Author:      A. Q. Mufti
 * Plugin URI:  https://github.com/AQMufti/aqm-duplicate-post
 * License:     GPL-2.0-or-later
 *
 * WHY THIS EXISTS
 * ===============
 *
 * Core WordPress has no "duplicate" function - AQ is right about that. The job
 * was being done by Yoast Duplicate Post, a plugin carried year-round for
 * something used a handful of times a year.
 *
 * Under AQ's standing rule (2 Sep 2026) - avoid plugins unless investigation
 * shows there is no alternative - this is the alternative. It is roughly a
 * hundred lines, has no settings page, no update channel, and no activation
 * state to lose.
 *
 * THE DECISIONS IN HERE, AND WHY
 *
 * **The copy is always a draft.** Never inherits "publish". A duplicate that
 * goes live by accident is two near-identical pages competing for the same
 * search terms - which is exactly how /2388-eighth-line-v1/ ended up in the
 * sitemap beside the real listing.
 *
 * **The slug is not copied.** post_name is left empty so WordPress generates a
 * fresh one. Copying it produces "2388-eighth-line-2" anyway, and an inherited
 * slug is how duplicates end up looking canonical.
 *
 * **Elementor meta IS copied, except its cache.** _elementor_data holds the
 * entire page; without it an Elementor page duplicates as a blank white page,
 * which is the single most common complaint about hand-rolled duplicators.
 * _elementor_css and _elementor_element_cache are regenerated per post ID, so
 * copying them would point the new page at the old one's stylesheet.
 *
 * **AIOSEO data is deliberately NOT copied, and cannot be.** AIOSEO stores in
 * its own table (wp_aioseo_posts), not post meta - see
 * aqm-wordpress-rest-notes.md. So the copy starts with no SEO title,
 * description or keyphrase. That is the right default: duplicated SEO metadata
 * is duplicate-content signal. But it does mean **you must write the SEO
 * fields on the copy yourself.**
 *
 * **Capability is checked against the SOURCE post**, not a blanket
 * manage_options. Someone who may edit a post may copy it; someone who may not,
 * may not.
 */

defined( 'ABSPATH' ) || exit;

/*
 * THE UPDATER IS CONSTRUCTED FIRST, DELIBERATELY.
 *
 * In 1.1.0 and 1.2.0 the conversion guard ran BEFORE this block and returned
 * early, so AQM_Updater was never constructed - which removed the "Check for
 * updates" link from this plugin's row and left no way to update it except a
 * manual zip upload. A guard that disables the thing that would have fixed the
 * guard is a trap. Registering the updater first costs nothing (it only adds
 * filters) and keeps the plugin repairable however badly the rest goes wrong.
 */
define( 'AQM_DP_FILE', __FILE__ );
define( 'AQM_DP_VERSION', '1.3.0' );
define( 'AQM_DP_GITHUB_REPO', 'AQMufti/aqm-duplicate-post' );

// Shared GitHub-release updater - identical mechanism in every AQM plugin.
require_once __DIR__ . '/aqm-updater.php';
new AQM_Updater(
	__FILE__,
	AQM_DP_VERSION,
	AQM_DP_GITHUB_REPO,
	'AQM Duplicate Post',
	'Adds a Duplicate link to the Posts and Pages list tables, copying into a new draft.'
);

/*
 * CONVERSION GUARD - remove after the mu-plugin copy is gone.
 *
 * This was a must-use plugin until 8 Sep 2026. mu-plugins load BEFORE regular
 * plugins, so if the old mu-plugins/aqm-duplicate-post.php is still on the server
 * everything below would be declared twice and the site would fatal. Bail out
 * instead, and say why on the Plugins screen.
 */
if ( file_exists( ( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins' ) . '/aqm-duplicate-post.php' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p><strong>AQM Duplicate Post</strong> is not running. '
				. 'The old must-use copy at <code>wp-content/mu-plugins/aqm-duplicate-post.php</code> is still on the server '
				. 'and loads first. Delete that file, then reload this page.</p></div>';
		}
	);
	return;
}

/*
 * Belt and braces. The mu-plugin file is gone, but something else has already
 * declared our symbols - so loading on would be a fatal redeclare. Bail out
 * quietly and report WHERE it came from, using reflection, rather than blaming
 * a file that is not there.
 *
 * Version 1.1.0 tested only function_exists( 'aqm_dp_row_action' ), which is a
 * PROXY for "the old file is still present" rather than the thing itself. When
 * the four files were deleted on 8 Sep 2026 the notices kept firing, because
 * the proxy was answering a different question. Test the actual condition.
 */
if ( function_exists( 'aqm_dp_row_action' ) ) {
	add_action(
		'admin_notices',
		function () {
			$where = 'an unknown file';
			try {
				$r     = new ReflectionFunction( 'aqm_dp_row_action' );
				$where = '<code>' . esc_html( str_replace( ABSPATH, '', (string) $r->getFileName() ) ) . '</code>';
			} catch ( Exception $e ) {
				unset( $e );
			}
			echo '<div class="notice notice-warning"><p><strong>AQM Duplicate Post</strong> stood down to avoid a duplicate declaration. '
				. 'Something already defined <code>aqm_dp_row_action</code>, loaded from ' . $where . '. '
				. 'No must-use copy is present, so this is not the old file.</p></div>';
		}
	);
	return;
}

/**
 * Meta keys never carried to the copy.
 *
 * Edit locks belong to a session. Old-slug records are redirect history for a
 * different post. Elementor's CSS and element caches are keyed to a post ID and
 * would point the copy at the original's stylesheet.
 */
function aqm_dp_skipped_meta() {
	return apply_filters( 'aqm_dp_skipped_meta', array(
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_old_date',
		'_elementor_css',
		'_elementor_element_cache',
		'_elementor_page_assets',
	) );
}

/**
 * Add the "Duplicate" link to the row actions on Posts and Pages.
 */
function aqm_dp_row_action( $actions, $post ) {

	if ( ! current_user_can( 'edit_post', $post->ID ) ) {
		return $actions;
	}

	$url = wp_nonce_url(
		admin_url( 'admin-post.php?action=aqm_duplicate_post&post=' . (int) $post->ID ),
		'aqm_dp_' . $post->ID
	);

	$actions['aqm_duplicate'] = sprintf(
		'<a href="%s" title="%s">Duplicate</a>',
		esc_url( $url ),
		esc_attr__( 'Create a draft copy of this item', 'aqm' )
	);

	return $actions;
}
add_filter( 'post_row_actions', 'aqm_dp_row_action', 10, 2 );
add_filter( 'page_row_actions', 'aqm_dp_row_action', 10, 2 );

/**
 * Do the copy, then open the new draft in the editor.
 */
function aqm_dp_duplicate() {

	$id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;

	if ( ! $id ) {
		wp_die( 'No post to duplicate.', 'Duplicate', array( 'response' => 400 ) );
	}

	// Nonce is tied to this specific post id, so a link for one post cannot be
	// replayed against another.
	check_admin_referer( 'aqm_dp_' . $id );

	if ( ! current_user_can( 'edit_post', $id ) ) {
		wp_die( 'You are not allowed to duplicate this item.', 'Duplicate',
			array( 'response' => 403 ) );
	}

	$post = get_post( $id );
	if ( ! $post ) {
		wp_die( 'That item no longer exists.', 'Duplicate', array( 'response' => 404 ) );
	}

	$new_id = wp_insert_post( wp_slash( array(
		'post_title'     => $post->post_title . ' (copy)',
		'post_content'   => $post->post_content,
		'post_excerpt'   => $post->post_excerpt,
		'post_type'      => $post->post_type,
		'post_parent'    => $post->post_parent,
		'menu_order'     => $post->menu_order,
		'comment_status' => $post->comment_status,
		'ping_status'    => $post->ping_status,
		'post_password'  => $post->post_password,
		'post_author'    => get_current_user_id(),
		'post_status'    => 'draft',   // never inherit "publish"
		'post_name'      => '',        // let WordPress mint a fresh slug
	) ), true );

	if ( is_wp_error( $new_id ) ) {
		wp_die( 'Could not create the copy: ' . esc_html( $new_id->get_error_message() ),
			'Duplicate', array( 'response' => 500 ) );
	}

	// Categories, tags and any custom taxonomy, by slug.
	foreach ( get_object_taxonomies( $post->post_type ) as $tax ) {
		$terms = wp_get_object_terms( $id, $tax, array( 'fields' => 'slugs' ) );
		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			wp_set_object_terms( $new_id, $terms, $tax, false );
		}
	}

	// Meta, including the Elementor payload. get_post_meta with a single
	// argument returns every key with its raw values, so multi-value keys
	// survive intact.
	$skip = aqm_dp_skipped_meta();
	foreach ( get_post_meta( $id ) as $key => $values ) {
		if ( in_array( $key, $skip, true ) ) {
			continue;
		}
		foreach ( (array) $values as $value ) {
			// Values come back serialized-as-stored; maybe_unserialize then
			// wp_slash so add_post_meta re-serializes correctly rather than
			// storing a serialized string of a serialized string.
			add_post_meta( $new_id, $key, wp_slash( maybe_unserialize( $value ) ) );
		}
	}

	/**
	 * Fires after a duplicate is created. $new_id is a draft at this point.
	 */
	do_action( 'aqm_dp_duplicated', $new_id, $id );

	wp_safe_redirect( admin_url( 'post.php?action=edit&post=' . (int) $new_id ) );
	exit;
}
add_action( 'admin_post_aqm_duplicate_post', 'aqm_dp_duplicate' );

/**
 * A one-time reminder on the new draft that its SEO fields are empty, because
 * AIOSEO keeps them in its own table and they cannot be copied.
 */
function aqm_dp_notice() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'post' !== $screen->base ) {
		return;
	}
	$post = get_post();
	if ( ! $post || 'draft' !== $post->post_status ) {
		return;
	}
	if ( false === strpos( $post->post_title, '(copy)' ) ) {
		return;
	}
	echo '<div class="notice notice-warning"><p><strong>This is a duplicate.</strong> '
	   . 'Its SEO title, description and keyphrase are empty - AIOSEO stores those '
	   . 'in its own table, so they are not copied. Set them before publishing, and '
	   . 'change the title so this does not compete with the original in search.</p></div>';
}
add_action( 'admin_notices', 'aqm_dp_notice' );
