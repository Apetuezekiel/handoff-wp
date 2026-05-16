<?php
/**
 * Phase 4 renderer consolidation — Pass A.
 * Field renderer tests for CH_Admin_Settings (RF1–RF9).
 *
 * SCOPE — Pass A: non-trivial field renderers only
 *
 * Covered (nine tests):
 *   render_protected_roles_field  — RF1, RF2
 *   render_admin_roles_field      — RF3
 *   render_blocked_caps_field     — RF4, RF5
 *   render_protected_plugins_field — RF6, RF7
 *   render_quick_links_field      — RF8, RF9
 *
 * Skipped (low mutation-detection value, deferred to Pass A sweep review):
 *   render_dashboard_enabled_field — single checkbox, no branching logic
 *   render_welcome_message_field   — single textarea, no branching logic
 *   render_developer_contact_field — three text inputs, no branching; same
 *                                    pattern as roles/contact already covered
 *   render_show_site_status_field  — single checkbox, no branching logic
 *
 * TECHNIQUE
 *
 * Output is captured via ob_start() / ob_get_clean(). Assertions use
 * assertStringContainsString and assertStringNotContainsString on the
 * captured string. This approach tests what the renderer actually echoes
 * without depending on a running WordPress install.
 *
 * BOOTSTRAP STUBS IN USE (no WP_Mock::userFunction needed for these)
 *   esc_attr, esc_html — identity htmlspecialchars wrappers
 *   wp_roles           — mocked per-test via WP_Mock::userFunction
 *   get_plugins        — mocked per-test via WP_Mock::userFunction
 *
 * @package ClientHandoff
 */

use WP_Mock\Tools\TestCase;

/**
 * Class AdminSettingsRenderTest
 */
class AdminSettingsRenderTest extends TestCase {

	// -------------------------------------------------------------------------
	// Lifecycle
	// -------------------------------------------------------------------------

	public function setUp(): void {
		parent::setUp();
		CH_Core::reset_instance();
	}

	public function tearDown(): void {
		CH_Core::reset_instance();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a CH_Core instance backed by the given config array.
	 *
	 * @param array $config
	 * @return CH_Core
	 */
	private function make_core( array $config = array() ): CH_Core {
		WP_Mock::userFunction( 'get_option', array( 'return' => $config ) );
		return CH_Core::get_instance();
	}

	/**
	 * Wire wp_roles() to return a WP_Roles stub with the given roles.
	 *
	 * @param array<string, array<string, bool>> $roles_data  slug => caps map
	 */
	private function mock_wp_roles( array $roles_data ): void {
		$wp_roles = new WP_Roles( $roles_data );
		WP_Mock::userFunction( 'wp_roles', array( 'return' => $wp_roles ) );
	}

	/**
	 * Wire get_plugins() to return the given basename => data map.
	 *
	 * @param array<string, array<string, string>> $plugins
	 */
	private function mock_get_plugins( array $plugins ): void {
		WP_Mock::userFunction( 'get_plugins', array( 'return' => $plugins ) );
	}

	/**
	 * Capture output of a callable.
	 *
	 * @param callable $fn
	 * @return string
	 */
	private function capture( callable $fn ): string {
		ob_start();
		$fn();
		return ob_get_clean();
	}

	// =========================================================================
	// RF1 — render_protected_roles_field: renders a checkbox per role
	// =========================================================================

	/**
	 * RF1 — render_protected_roles_field renders one checkbox per registered role
	 * and marks none as checked when saved protected_roles is empty.
	 *
	 * Three roles in the registry → three checkboxes, all unchecked.
	 */
	public function test_render_protected_roles_field_renders_checkbox_for_each_role() {
		$core     = $this->make_core( array( 'protected_roles' => array() ) );
		$settings = new CH_Admin_Settings( $core );

		$this->mock_wp_roles( array(
			'subscriber'  => array( 'read' => true ),
			'editor'      => array( 'edit_posts' => true, 'read' => true ),
			'custom_role' => array( 'read' => true ),
		) );

		$html = $this->capture( array( $settings, 'render_protected_roles_field' ) );

		// All three slugs appear as checkbox values.
		$this->assertStringContainsString(
			'name="client_handoff_config[protected_roles][]" value="subscriber"',
			$html,
			'subscriber checkbox must be present'
		);
		$this->assertStringContainsString(
			'name="client_handoff_config[protected_roles][]" value="editor"',
			$html,
			'editor checkbox must be present'
		);
		$this->assertStringContainsString(
			'name="client_handoff_config[protected_roles][]" value="custom_role"',
			$html,
			'custom_role checkbox must be present'
		);

		// No checkbox should be checked — saved list is empty.
		$this->assertStringNotContainsString(
			' checked',
			$html,
			'no checkbox should be checked when protected_roles is empty'
		);
	}

	// =========================================================================
	// RF2 — render_protected_roles_field: saved roles are marked checked
	// =========================================================================

	/**
	 * RF2 — render_protected_roles_field marks only the saved role as checked.
	 *
	 * 'subscriber' is saved → its input has ' checked'; 'editor' does not.
	 * Mutation: inverting or removing the in_array check breaks this test.
	 */
	public function test_render_protected_roles_field_marks_saved_roles_as_checked() {
		$core     = $this->make_core( array( 'protected_roles' => array( 'subscriber' ) ) );
		$settings = new CH_Admin_Settings( $core );

		$this->mock_wp_roles( array(
			'subscriber'  => array( 'read' => true ),
			'editor'      => array( 'edit_posts' => true, 'read' => true ),
			'custom_role' => array( 'read' => true ),
		) );

		$html = $this->capture( array( $settings, 'render_protected_roles_field' ) );

		// The subscriber checkbox must include ' checked'.
		$this->assertStringContainsString(
			'value="subscriber" checked',
			$html,
			'subscriber must be marked checked when it is in the saved list'
		);

		// The editor checkbox must NOT include ' checked'.
		// We verify by checking that the editor value line does not have ' checked'
		// immediately adjacent. Split on the editor value and check the fragment.
		$editor_pos   = strpos( $html, 'value="editor"' );
		$fragment_end = strpos( $html, '<br>', $editor_pos );
		$fragment     = substr( $html, $editor_pos, $fragment_end - $editor_pos );

		$this->assertStringNotContainsString(
			' checked',
			$fragment,
			'editor must NOT be marked checked when it is not in the saved list'
		);
	}

	// =========================================================================
	// RF3 — render_admin_roles_field: renders a checkbox per role
	// =========================================================================

	/**
	 * RF3 — render_admin_roles_field renders one checkbox per registered role
	 * using the admin_roles[] name attribute.
	 *
	 * The saved-state check logic is shared with protected_roles (RF1+RF2 cover
	 * it); this test verifies only that the correct field name is used.
	 */
	public function test_render_admin_roles_field_renders_checkbox_for_each_role() {
		$core     = $this->make_core( array( 'admin_roles' => array( 'editor' ) ) );
		$settings = new CH_Admin_Settings( $core );

		$this->mock_wp_roles( array(
			'subscriber' => array( 'read' => true ),
			'editor'     => array( 'edit_posts' => true, 'read' => true ),
			'custom_role' => array( 'read' => true ),
		) );

		$html = $this->capture( array( $settings, 'render_admin_roles_field' ) );

		$this->assertStringContainsString(
			'name="client_handoff_config[admin_roles][]" value="subscriber"',
			$html,
			'subscriber checkbox must appear under admin_roles name'
		);
		$this->assertStringContainsString(
			'name="client_handoff_config[admin_roles][]" value="editor"',
			$html,
			'editor checkbox must appear under admin_roles name'
		);
		$this->assertStringContainsString(
			'value="editor" checked',
			$html,
			'editor must be checked because it is in the saved admin_roles list'
		);
		// Protected_roles name must NOT appear — wrong renderer.
		$this->assertStringNotContainsString(
			'[protected_roles]',
			$html,
			'admin_roles renderer must not emit protected_roles input names'
		);
	}

	// =========================================================================
	// RF4 — render_blocked_caps_field: renders a checkbox for each default cap
	// =========================================================================

	/**
	 * RF4 — render_blocked_caps_field renders one checkbox for every capability
	 * in CH_Core::DEFAULTS['enforcement']['blocked_caps'].
	 *
	 * Assertions loop over the DEFAULTS list so a future addition to DEFAULTS
	 * is automatically covered without editing this test.
	 */
	public function test_render_blocked_caps_field_renders_a_checkbox_for_each_default_cap() {
		$core     = $this->make_core();
		$settings = new CH_Admin_Settings( $core );

		$html = $this->capture( array( $settings, 'render_blocked_caps_field' ) );

		$default_caps = CH_Core::DEFAULTS['enforcement']['blocked_caps'];

		foreach ( $default_caps as $cap ) {
			$this->assertStringContainsString(
				'name="client_handoff_config[enforcement][blocked_caps][]" value="' . $cap . '"',
				$html,
				"A checkbox for cap '$cap' must be present in the blocked_caps field output"
			);
		}
	}

	// =========================================================================
	// RF5 — render_blocked_caps_field: saved caps are marked checked
	// =========================================================================

	/**
	 * RF5 — render_blocked_caps_field marks only the saved caps as checked.
	 *
	 * 'install_plugins' and 'activate_plugins' are saved → checked.
	 * 'edit_plugins' is a default cap but not saved → must not be checked.
	 */
	public function test_render_blocked_caps_field_marks_saved_caps_as_checked() {
		$core = $this->make_core( array(
			'enforcement' => array(
				'blocked_caps' => array( 'install_plugins', 'activate_plugins' ),
			),
		) );
		$settings = new CH_Admin_Settings( $core );

		$html = $this->capture( array( $settings, 'render_blocked_caps_field' ) );

		$this->assertStringContainsString(
			'value="install_plugins" checked',
			$html,
			'install_plugins must be marked checked'
		);
		$this->assertStringContainsString(
			'value="activate_plugins" checked',
			$html,
			'activate_plugins must be marked checked'
		);

		// edit_plugins is in DEFAULTS but not in the saved list → not checked.
		$edit_pos     = strpos( $html, 'value="edit_plugins"' );
		$fragment_end = strpos( $html, '<br>', $edit_pos );
		$fragment     = substr( $html, $edit_pos, $fragment_end - $edit_pos );

		$this->assertStringNotContainsString(
			' checked',
			$fragment,
			'edit_plugins must NOT be marked checked when absent from saved list'
		);
	}

	// =========================================================================
	// RF6 — render_protected_plugins_field: renders a checkbox per installed plugin
	// =========================================================================

	/**
	 * RF6 — render_protected_plugins_field renders one checkbox per entry returned
	 * by get_plugins() and includes each plugin's Name in the label.
	 */
	public function test_render_protected_plugins_field_renders_a_checkbox_for_each_installed_plugin() {
		$core     = $this->make_core();
		$settings = new CH_Admin_Settings( $core );

		$this->mock_get_plugins( array(
			'akismet/akismet.php'       => array( 'Name' => 'Akismet Anti-Spam' ),
			'real-plugin/real.php'      => array( 'Name' => 'Real Plugin' ),
			'another/another.php'       => array( 'Name' => 'Another Plugin' ),
		) );

		$html = $this->capture( array( $settings, 'render_protected_plugins_field' ) );

		$basenames = array( 'akismet/akismet.php', 'real-plugin/real.php', 'another/another.php' );
		foreach ( $basenames as $basename ) {
			$this->assertStringContainsString(
				'name="client_handoff_config[enforcement][protected_plugins][]" value="' . $basename . '"',
				$html,
				"A checkbox for '$basename' must be present"
			);
		}

		// Plugin names must appear in label text.
		$this->assertStringContainsString( 'Akismet Anti-Spam', $html, 'Plugin Name must appear in label' );
		$this->assertStringContainsString( 'Real Plugin',       $html, 'Plugin Name must appear in label' );
		$this->assertStringContainsString( 'Another Plugin',    $html, 'Plugin Name must appear in label' );
	}

	// =========================================================================
	// RF7 — render_protected_plugins_field: empty state when no plugins installed
	// =========================================================================

	/**
	 * RF7 — render_protected_plugins_field shows an empty-state message and
	 * emits no checkbox inputs when get_plugins() returns an empty array.
	 *
	 * Mutation: removing the empty() guard would cause a loop over zero items
	 * and skip the message — this test would still fail because it checks the
	 * message is present.
	 */
	public function test_render_protected_plugins_field_shows_empty_state_when_no_plugins() {
		$core     = $this->make_core();
		$settings = new CH_Admin_Settings( $core );

		$this->mock_get_plugins( array() );

		$html = $this->capture( array( $settings, 'render_protected_plugins_field' ) );

		$this->assertStringContainsString(
			'No plugins installed.',
			$html,
			'Empty-state message must appear when get_plugins returns an empty array'
		);
		$this->assertStringNotContainsString(
			'name="client_handoff_config[enforcement][protected_plugins][]"',
			$html,
			'No checkbox inputs must be rendered when there are no plugins'
		);
	}

	// =========================================================================
	// RF8 — render_quick_links_field: renders exactly five fixed-slot rows
	// =========================================================================

	/**
	 * RF8 — render_quick_links_field renders five rows (indices 0–4) regardless
	 * of how many saved links exist.
	 *
	 * With no saved links, all five rows render with empty values. The five-slot
	 * fixed model is the load-bearing detail — the renderer must never emit
	 * fewer rows because the sanitizer expects exactly those five indices on submit.
	 */
	public function test_render_quick_links_field_renders_five_rows() {
		$core     = $this->make_core( array( 'dashboard' => array( 'quick_links' => array() ) ) );
		$settings = new CH_Admin_Settings( $core );

		$html = $this->capture( array( $settings, 'render_quick_links_field' ) );

		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertStringContainsString(
				'name="client_handoff_config[dashboard][quick_links][' . $i . '][label]"',
				$html,
				"Row $i label input must be present"
			);
			$this->assertStringContainsString(
				'name="client_handoff_config[dashboard][quick_links][' . $i . '][url]"',
				$html,
				"Row $i url input must be present"
			);
			$this->assertStringContainsString(
				'name="client_handoff_config[dashboard][quick_links][' . $i . '][icon]"',
				$html,
				"Row $i icon input must be present"
			);
		}
	}

	// =========================================================================
	// RF9 — render_quick_links_field: saved rows populate the correct indices
	// =========================================================================

	/**
	 * RF9 — render_quick_links_field populates saved rows into their correct
	 * fixed-slot indices and leaves trailing rows empty.
	 *
	 * Two saved rows → rows 0 and 1 have their values; rows 2, 3, 4 have
	 * empty value="" for the label. Mutation: if the renderer truncates at
	 * the last saved row, the row 2–4 assertions fail.
	 */
	public function test_render_quick_links_field_populates_saved_rows_into_correct_indices() {
		$saved_links = array(
			array( 'label' => 'Posts', 'url' => '/edit.php',   'icon' => 'dashicons-edit' ),
			array( 'label' => 'Media', 'url' => '/upload.php', 'icon' => 'dashicons-media-default' ),
		);
		$core     = $this->make_core( array( 'dashboard' => array( 'quick_links' => $saved_links ) ) );
		$settings = new CH_Admin_Settings( $core );

		$html = $this->capture( array( $settings, 'render_quick_links_field' ) );

		// Row 0 must have value="Posts".
		$this->assertStringContainsString(
			'quick_links][0][label]" value="Posts"',
			$html,
			'Row 0 label must be populated with the saved value'
		);
		// Row 1 must have value="Media".
		$this->assertStringContainsString(
			'quick_links][1][label]" value="Media"',
			$html,
			'Row 1 label must be populated with the saved value'
		);

		// Rows 2, 3, 4 must render with empty value="".
		for ( $i = 2; $i < 5; $i++ ) {
			$this->assertStringContainsString(
				'quick_links][' . $i . '][label]" value=""',
				$html,
				"Row $i label must be empty when there is no saved value for that slot"
			);
		}
	}

	// =========================================================================
	// B1 — render_export_import_section: export form action and nonce
	// =========================================================================

	/**
	 * B1 — render_export_import_section emits an export form with the correct
	 * admin-post action value and matching nonce field.
	 *
	 * Mutation: changing 'ch_export_config' in the renderer (either the hidden
	 * action input or the wp_nonce_field call) causes this test to fail.
	 */
	public function test_render_export_import_section_outputs_export_form_with_correct_action() {
		// Ensure notice branches are not triggered.
		unset( $_GET['ch_import_success'], $_GET['ch_import_error'] );

		$core     = $this->make_core();
		$settings = new CH_Admin_Settings( $core );

		$html = $this->capture( array( $settings, 'render_export_import_section' ) );

		$this->assertStringContainsString(
			'<input type="hidden" name="action" value="ch_export_config">',
			$html,
			'Export form must post action=ch_export_config to admin-post.php'
		);
		$this->assertStringContainsString(
			'<!-- wp_nonce_field:ch_export_config -->',
			$html,
			'Export form must request a nonce for the ch_export_config action'
		);
	}

	// =========================================================================
	// B2 — render_export_import_section: import form upload field and nonce
	// =========================================================================

	/**
	 * B2 — render_export_import_section emits an import form with enctype,
	 * file input, the correct admin-post action, and the matching nonce field.
	 *
	 * Mutation: removing the file input, dropping the enctype attribute, or
	 * changing the action name fails this test.
	 */
	public function test_render_export_import_section_outputs_import_form_with_upload_field() {
		unset( $_GET['ch_import_success'], $_GET['ch_import_error'] );

		$core     = $this->make_core();
		$settings = new CH_Admin_Settings( $core );

		$html = $this->capture( array( $settings, 'render_export_import_section' ) );

		$this->assertStringContainsString(
			'enctype="multipart/form-data"',
			$html,
			'Import form must declare multipart/form-data enctype for file upload'
		);
		$this->assertStringContainsString(
			'type="file" name="ch_config_file"',
			$html,
			'Import form must include a file input named ch_config_file'
		);
		$this->assertStringContainsString(
			'<input type="hidden" name="action" value="ch_import_config">',
			$html,
			'Import form must post action=ch_import_config to admin-post.php'
		);
		$this->assertStringContainsString(
			'<!-- wp_nonce_field:ch_import_config -->',
			$html,
			'Import form must request a nonce for the ch_import_config action'
		);
	}

	// =========================================================================
	// B3 — render_rerun_setup_section: null guard emits nothing
	// =========================================================================

	/**
	 * B3 — render_rerun_setup_section emits no output when CH_Admin_Settings
	 * was constructed without a setup flow (null default).
	 *
	 * This is the regression test for the null-check guard introduced in the
	 * Re-run Setup pass. Without the guard the section would always render,
	 * potentially on sites where no setup flow instance exists.
	 */
	public function test_render_rerun_setup_section_outputs_nothing_when_setup_flow_is_null() {
		$core     = $this->make_core();
		// Explicitly no setup_flow argument — null is the default.
		$settings = new CH_Admin_Settings( $core );

		$html = $this->capture( array( $settings, 'render_rerun_setup_section' ) );

		$this->assertStringNotContainsString(
			'<form',
			$html,
			'No form must be rendered when setup_flow is null'
		);
	}

	// =========================================================================
	// B4 — render_rerun_setup_section: emits rerun marker when flow is present
	// =========================================================================

	/**
	 * B4 — render_rerun_setup_section emits the _ch_setup_rerun hidden input
	 * and the settings_fields nonce marker when a setup flow is present.
	 *
	 * Mutation: removing the hidden marker input or the settings_fields call
	 * fails this test. The hidden input is the load-bearing element — without
	 * it sanitize() never receives the rerun signal.
	 */
	public function test_render_rerun_setup_section_outputs_rerun_marker_when_setup_flow_present() {
		$core       = $this->make_core();
		$setup_flow = new CH_Setup_Flow( $core );
		$settings   = new CH_Admin_Settings( $core, $setup_flow );

		$html = $this->capture( array( $settings, 'render_rerun_setup_section' ) );

		$this->assertStringContainsString(
			'name="client_handoff_config[_ch_setup_rerun]"',
			$html,
			'Re-run section must include a hidden input for the _ch_setup_rerun marker'
		);
		$this->assertStringContainsString(
			'value="1"',
			$html,
			'The _ch_setup_rerun hidden input must have value="1"'
		);
		$this->assertStringContainsString(
			'<!-- settings_fields:client_handoff_config -->',
			$html,
			'Re-run section must call settings_fields with the plugin option group'
		);
	}
}
