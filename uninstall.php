<?php
/**
 * Uninstall — removes all data created by Client Handoff.
 *
 * Runs as a standalone file; MUST NOT require or depend on any class-ch-* file
 * (brief Constraint 11.6). Option names are hard-coded here for the same reason.
 *
 * Structured so each data category (options, cron, transients, CPT) is a
 * discrete block — Phase 2 adds the ch_help_note CPT block without refactoring
 * this file.
 *
 * MULTISITE BEHAVIOUR
 *
 * uninstall.php runs in the context of whichever site triggered the uninstall.
 * On a multisite install with the plugin per-site activated, only that site's
 * options and transients are removed by this file. Network-wide activation is
 * unsupported at MVP (Constraint 11.11), so this single-site cleanup correctly
 * matches the supported activation model.
 *
 * @package ClientHandoff
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// ---- Options ----------------------------------------------------------------

delete_option( 'client_handoff_config' );
delete_option( 'client_handoff_activity_log' );
delete_option( 'client_handoff_checklist' );

// ---- Cron -------------------------------------------------------------------

wp_clear_scheduled_hook( 'client_handoff_prune_log' );

// ---- Transients -------------------------------------------------------------
// ch_network_activation_notice is set by CH_Core::on_activation() when a
// network-wide activation is attempted on multisite (see class-ch-core.php).
// It has a 1-minute expiry and is almost certainly gone by uninstall time, but
// cleanup is correct-by-construction.

delete_transient( 'ch_network_activation_notice' );

// ---- CPT posts + meta (ch_help_note) ----------------------------------------
// Phase 2 registers the ch_help_note CPT. When that ships, replace this block
// with the real removal loop:
//
//   $post_ids = get_posts( array(
//       'post_type'      => 'ch_help_note',
//       'posts_per_page' => -1,
//       'post_status'    => 'any',
//       'fields'         => 'ids',
//   ) );
//   foreach ( $post_ids as $post_id ) {
//       wp_delete_post( (int) $post_id, true ); // force-delete, bypass trash.
//   }
