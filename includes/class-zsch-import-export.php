<?php
/**
 * JSON export / import for multi-site deployment (brief § 3.4).
 *
 * SEMANTICS — OVERWRITE, NOT MERGE
 *
 * Import uses ZSCH_Core::update_config, which merges the incoming array against
 * DEFAULTS and persists the result. Fields absent from the imported JSON fall
 * back to DEFAULTS, not to the previous saved value. This is the brief's
 * intended behaviour: importing from site A to site B replaces B's config
 * entirely, without leaking B's prior state through keys the JSON doesn't
 * include.
 *
 * Do NOT use merge_into_current here — that overlay helper is for tab-save
 * partial submissions where unsubmitted tabs must be preserved.
 *
 * UNSERIALIZE PROHIBITION
 *
 * The import path uses json_decode($content, true) exclusively. Never use
 * unserialize() on untrusted file content — doing so allows arbitrary PHP
 * object instantiation (Constraint 11.7).
 *
 * DEFERRED TESTS — Phase 4
 *
 * handle_export: streams a response with Content-Type / Content-Disposition
 * headers and calls exit. Untestable without a full integration harness.
 *
 * handle_import: involves $_FILES, check_admin_referer, wp_safe_redirect,
 * and exit. Mockable with significant effort but deferred for consistency
 * with the renderer/handler deferral policy established in previous passes.
 *
 * render_export_import_section: HTML output; follows the same renderer-
 * deferral pattern as ZSCH_Admin_Settings field renderers (Phase 4).
 *
 * All six pure-logic tests (E1–E6) target sanitize_for_import on
 * ZSCH_Admin_Settings and live in ImportExportTest.php.
 *
 * @package ClientHandoff
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ZSCH_Import_Export
 */
class ZSCH_Import_Export {

	/** @var ZSCH_Core */
	private $core;

	/** @var ZSCH_Admin_Settings */
	private $settings;

	/**
	 * @param ZSCH_Core           $core
	 * @param ZSCH_Admin_Settings $settings
	 */
	public function __construct( $core, $settings ) {
		$this->core     = $core;
		$this->settings = $settings;
	}

	/**
	 * Register admin-post.php hooks.
	 */
	public function register_hooks() {
		add_action( 'admin_post_zsch_export_config', array( $this, 'handle_export' ) );
		add_action( 'admin_post_zsch_import_config', array( $this, 'handle_import' ) );
	}

	// -------------------------------------------------------------------------
	// Handlers
	// -------------------------------------------------------------------------

	/**
	 * Stream the current config as a JSON file download.
	 *
	 * DEFERRED UNIT TEST — Phase 4
	 * Streams a response with Content-Type / Content-Disposition headers and
	 * calls exit. Requires an integration harness to test.
	 */
	public function handle_export() {
		check_admin_referer( 'zsch_export_config' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'zicstack-client-handoff' ) );
		}

		$config   = $this->core->get_config();
		$json     = json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$filename = 'zicstack-client-handoff-config-' . gmdate( 'Y-m-d' ) . '.json';

		header( 'Content-Type: application/json' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );

		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary download, not HTML
		exit;
	}

	/**
	 * Accept a JSON file upload, validate, sanitize, and overwrite the config.
	 *
	 * OVERWRITE SEMANTICS: update_config is used, not merge_into_current.
	 * Fields absent from the uploaded JSON fall back to DEFAULTS.
	 *
	 * DEFERRED UNIT TEST — Phase 4
	 * Involves $_FILES, check_admin_referer, wp_safe_redirect, and exit.
	 */
	public function handle_import() {
		check_admin_referer( 'zsch_import_config' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'zicstack-client-handoff' ) );
		}

		$settings_url = admin_url( 'admin.php?page=zicstack-client-handoff' );

		// ---- File validation ----------------------------------------------------
		$file = isset( $_FILES['zsch_config_file'] ) ? $_FILES['zsch_config_file'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_FILES is server-side upload metadata; individual field access is structurally bounded.

		if (
			null === $file ||
			! isset( $file['error'] ) ||
			UPLOAD_ERR_OK !== (int) $file['error'] ||
			empty( $file['tmp_name'] ) ||
			! is_uploaded_file( $file['tmp_name'] ) ||
			(int) $file['size'] >= 1024 * 1024
		) {
			wp_safe_redirect( add_query_arg( 'zsch_import_error', 'upload', $settings_url ) );
			exit;
		}

		// ---- Parse JSON ---------------------------------------------------------
		$contents = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- tmp file, no remote
		$json     = json_decode( $contents, true ); // strict array — never unserialize

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $json ) ) {
			wp_safe_redirect( add_query_arg( 'zsch_import_error', 'parse', $settings_url ) );
			exit;
		}

		// ---- Sanitize + persist (OVERWRITE) -------------------------------------
		$sanitized = $this->settings->sanitize_for_import( $json );
		$this->core->update_config( $sanitized ); // OVERWRITE — not merge_into_current

		wp_safe_redirect( add_query_arg( 'zsch_import_success', '1', $settings_url ) );
		exit;
	}
}
