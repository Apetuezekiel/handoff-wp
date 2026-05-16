<?php
/**
 * Uninstall — removes all data created by Client Handoff.
 *
 * Runs as a standalone file; MUST NOT require or depend on any class-ch-* file
 * (brief Constraint 11.6). Option names are hard-coded here for the same reason.
 *
 * Structured so each data category (options, cron, CPT) is a discrete block —
 * Phase 2 adds the ch_help_note CPT block without refactoring this file.
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
