<?php
/**
 * Unit tests for the import/export feature (E1–E6).
 *
 * SCOPE — sanitize_for_import only
 *
 * sanitize_for_import() is the sole new public API on CH_Admin_Settings
 * introduced by this pass. It aggregates the existing private per-tab
 * sanitizers, making it fully testable with the WP_Mock harness.
 *
 * DEFERRED — Phase 4
 *
 * CH_Import_Export::handle_export
 *   Streams a response with Content-Type / Content-Disposition headers and
 *   calls exit. Requires an integration harness; untestable under WP_Mock.
 *
 * CH_Import_Export::handle_import
 *   Involves $_FILES access, check_admin_referer(), wp_safe_redirect(), and
 *   exit. Mockable with significant effort but deferred for consistency with
 *   the handler/renderer deferral policy established in previous passes.
 *
 * CH_Admin_Settings::render_export_import_section
 *   HTML output; follows the same renderer-deferral pattern as the existing
 *   field renderers (Phase 4 sweep).
 *
 * BOOTSTRAP STUBS IN USE (no WP_Mock mocking needed)
 *   wp_kses_post, sanitize_text_field, esc_url_raw, sanitize_html_class,
 *   sanitize_key, esc_attr, esc_html, __
 *
 * @package ClientHandoff
 */

use WP_Mock\Tools\TestCase;

/**
 * Class ImportExportTest
 */
class ImportExportTest extends TestCase {

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
	 * Wire wp_roles() to return a WP_Roles stub with the given roles data.
	 *
	 * @param array<string, array<string, bool>> $roles_data
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

	// =========================================================================
	// E1 — Roles fields: valid slugs kept, unknown slugs dropped
	// =========================================================================

	/**
	 * E1 — sanitize_for_import validates protected_roles and admin_roles
	 * against the live wp_roles() registry, exactly as the Roles tab does.
	 *
	 * 'fake' is not in the registry → dropped.
	 * 'subscriber' and 'editor' are → preserved.
	 */
	public function test_sanitize_for_import_validates_roles() {
		$core     = $this->make_core();
		$settings = new CH_Admin_Settings( $core );

		$this->mock_wp_roles( array(
			'subscriber' => array( 'read' => true ),
			'editor'     => array( 'edit_posts' => true, 'read' => true ),
		) );

		$result = $settings->sanitize_for_import( array(
			'protected_roles' => array( 'subscriber', 'fake' ),
			'admin_roles'     => array( 'editor' ),
		) );

		$this->assertSame(
			array( 'subscriber' ),
			$result['protected_roles'],
			"'fake' role slug must be dropped; 'subscriber' must be kept"
		);
		$this->assertSame(
			array( 'editor' ),
			$result['admin_roles'],
			"'editor' role slug must be preserved"
		);
	}

	// =========================================================================
	// E2 — Restrictions fields: valid caps/plugins kept, unknowns dropped
	// =========================================================================

	/**
	 * E2 — sanitize_for_import validates enforcement.blocked_caps against
	 * the eleven DEFAULTS and enforcement.protected_plugins against
	 * get_plugins().
	 *
	 * 'invented_cap' is not in DEFAULTS → dropped.
	 * 'not-installed/plugin.php' is not in get_plugins() → dropped.
	 */
	public function test_sanitize_for_import_validates_restrictions() {
		$core     = $this->make_core();
		$settings = new CH_Admin_Settings( $core );

		$real_cap    = 'install_plugins'; // in DEFAULTS
		$invented    = 'invented_cap';    // not in DEFAULTS

		$real_plugin = 'real-plugin/real-plugin.php';
		$fake_plugin = 'not-installed/plugin.php';

		$this->mock_get_plugins( array(
			$real_plugin => array( 'Name' => 'Real Plugin' ),
		) );

		$result = $settings->sanitize_for_import( array(
			'enforcement' => array(
				'blocked_caps'      => array( $real_cap, $invented ),
				'protected_plugins' => array( $real_plugin, $fake_plugin ),
			),
		) );

		$this->assertSame(
			array( $real_cap ),
			$result['enforcement']['blocked_caps'],
			"'$invented' must be dropped; '$real_cap' must be kept"
		);
		$this->assertSame(
			array( $real_plugin ),
			$result['enforcement']['protected_plugins'],
			"'$fake_plugin' must be dropped; '$real_plugin' must be kept"
		);
	}

	// =========================================================================
	// E3 — Dashboard fields: script stripped, empty quick_link rows dropped
	// =========================================================================

	/**
	 * E3 — sanitize_for_import validates dashboard.welcome_message via
	 * wp_kses_post (stripping <script>) and drops quick_links rows where
	 * both label and url are empty.
	 *
	 * Uses the bootstrap wp_kses_post stub (strip_tags with allowed-tag list).
	 * Three quick_links rows submitted: two real, one blank. Result must have
	 * two entries.
	 */
	public function test_sanitize_for_import_validates_dashboard() {
		$core     = $this->make_core();
		$settings = new CH_Admin_Settings( $core );

		$result = $settings->sanitize_for_import( array(
			'dashboard' => array(
				'welcome_message' => '<p>Welcome</p><script>alert(1)</script>',
				'quick_links'     => array(
					array( 'label' => 'Posts', 'url' => '/edit.php',  'icon' => 'dashicons-edit' ),
					array( 'label' => '',      'url' => '',            'icon' => '' ),
					array( 'label' => 'Media', 'url' => '/upload.php', 'icon' => '' ),
				),
			),
		) );

		$this->assertStringContainsString(
			'<p>Welcome</p>',
			$result['dashboard']['welcome_message'],
			'Allowed HTML must survive wp_kses_post'
		);
		$this->assertStringNotContainsString(
			'<script',
			$result['dashboard']['welcome_message'],
			'<script> tag must be stripped by wp_kses_post'
		);

		$this->assertCount( 2, $result['dashboard']['quick_links'],
			'Empty quick_links row must be dropped; two populated rows must survive' );
		$this->assertSame( 'Posts', $result['dashboard']['quick_links'][0]['label'] );
		$this->assertSame( 'Media', $result['dashboard']['quick_links'][1]['label'] );
	}

	// =========================================================================
	// E4 — Top-level scalars: enabled and setup_completed preserved as bools
	// =========================================================================

	/**
	 * E4 — sanitize_for_import maps enabled and setup_completed to bool.
	 *
	 * Sub-case A: values are already boolean true → preserved.
	 * Sub-case B: values are string '1' (valid JSON number string after
	 * json_decode with assoc=true) → cast to true.
	 */
	public function test_sanitize_for_import_preserves_top_level_scalars() {
		$core     = $this->make_core();
		$settings = new CH_Admin_Settings( $core );

		// Sub-case A: native booleans.
		$result_a = $settings->sanitize_for_import( array(
			'enabled'         => true,
			'setup_completed' => true,
		) );
		$this->assertTrue( $result_a['enabled'],         'enabled=true must be preserved as true' );
		$this->assertTrue( $result_a['setup_completed'], 'setup_completed=true must be preserved as true' );

		// Sub-case B: string '1' — json_decode may produce int 1; (bool) casts it.
		$result_b = $settings->sanitize_for_import( array(
			'enabled'         => '1',
			'setup_completed' => '1',
		) );
		$this->assertTrue( $result_b['enabled'],         "enabled='1' must cast to true" );
		$this->assertTrue( $result_b['setup_completed'], "setup_completed='1' must cast to true" );
	}

	// =========================================================================
	// E5 — Unknown keys are silently dropped
	// =========================================================================

	/**
	 * E5 — sanitize_for_import drops keys it does not recognise.
	 *
	 * Only the known detection clauses (roles, enforcement, dashboard,
	 * enabled, setup_completed) write to $sanitized. Unrecognised keys at any
	 * level are never copied — they fall through all clauses silently, so a
	 * maliciously crafted JSON file cannot inject arbitrary data into the DB.
	 */
	public function test_sanitize_for_import_drops_unknown_keys() {
		$core     = $this->make_core();
		$settings = new CH_Admin_Settings( $core );

		$result = $settings->sanitize_for_import( array(
			'malicious_payload' => 'DROP TABLE wp_options;',
			'random_subarray'   => array( 'a' => 1, 'b' => 2 ),
		) );

		$this->assertArrayNotHasKey(
			'malicious_payload',
			$result,
			"'malicious_payload' key must not appear in the sanitized result"
		);
		$this->assertArrayNotHasKey(
			'random_subarray',
			$result,
			"'random_subarray' key must not appear in the sanitized result"
		);
	}

	// =========================================================================
	// E6 — Empty input returns empty array
	// =========================================================================

	/**
	 * E6 — sanitize_for_import on an empty array returns an empty array.
	 *
	 * When passed to CH_Core::update_config, an empty array merges against
	 * DEFAULTS entirely — giving the "reset to defaults" behaviour. This is
	 * the correct outcome of importing an empty JSON object ({}).
	 */
	public function test_sanitize_for_import_on_empty_input_returns_empty_array() {
		$core     = $this->make_core();
		$settings = new CH_Admin_Settings( $core );

		$result = $settings->sanitize_for_import( array() );

		$this->assertSame( array(), $result, 'Empty input must produce an empty sanitized array' );
	}
}
