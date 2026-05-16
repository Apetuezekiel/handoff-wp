<?php
/**
 * Unit tests for CH_Admin_Settings (settings page, tab routing, sanitize callback).
 *
 * WP_Mock limitation: add_action() is an expectation assertion only — the hook
 * pipeline is never fired. All tab and sanitize tests call the methods directly.
 *
 * $_GET isolation: setUp saves and resets $_GET; tearDown restores it.  Each
 * get_active_tab test sets only the key it needs and relies on the clean state
 * from setUp.
 *
 * Sanitize flow: sanitize() calls wp_roles()->get_names() to build the valid-role
 * allowlist, then calls merge_into_current() which re-reads get_option().  Tests
 * use make_core() to set up the get_option mock (allowing multiple calls) and
 * mock wp_roles() explicitly per test.
 *
 * sanitize_key is pre-defined in bootstrap as a PHP userspace function — it does
 * not need WP_Mock mocking in these tests.
 *
 * @package ClientHandoff
 */

use WP_Mock\Tools\TestCase;

/**
 * Class AdminSettingsTest
 */
class AdminSettingsTest extends TestCase {

	// -------------------------------------------------------------------------
	// Lifecycle
	// -------------------------------------------------------------------------

	/** @var array Original $_GET, restored in tearDown. */
	private $original_get;

	public function setUp(): void {
		parent::setUp();
		CH_Core::reset_instance();
		$this->original_get = $_GET;
		$_GET               = array();
	}

	public function tearDown(): void {
		CH_Core::reset_instance();
		$_GET = $this->original_get;
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * @param array $config
	 * @return CH_Core
	 */
	private function make_core( array $config = array() ) {
		WP_Mock::userFunction( 'get_option', array( 'return' => $config ) );
		return CH_Core::get_instance();
	}

	/**
	 * Standard enabled config with empty role lists — used by sanitize tests.
	 *
	 * @param array $overrides
	 * @return array
	 */
	private function base_config( array $overrides = array() ) {
		return array_merge(
			array(
				'enabled'         => false,
				'protected_roles' => array(),
				'admin_roles'     => array(),
			),
			$overrides
		);
	}

	/**
	 * Build a WP_Roles stub and wire it into wp_roles().
	 *
	 * @param array<string, array<string, bool>> $roles_data
	 */
	private function mock_wp_roles( array $roles_data ) {
		$wp_roles = new WP_Roles( $roles_data );
		WP_Mock::userFunction( 'wp_roles', array( 'return' => $wp_roles ) );
	}

	// =========================================================================
	// Test 1–6: get_active_tab() — tab routing and allowlist enforcement
	// =========================================================================

	/**
	 * When $_GET['tab'] is absent, the method defaults to 'roles'.
	 */
	public function test_get_active_tab_defaults_to_roles_when_tab_absent() {
		$core     = $this->make_core();
		$settings = new CH_Admin_Settings( $core );

		$this->assertSame( 'roles', $settings->get_active_tab() );
	}

	/**
	 * A valid tab slug from TABS is returned unchanged.
	 */
	public function test_get_active_tab_accepts_dashboard_tab() {
		$core     = $this->make_core();
		$settings = new CH_Admin_Settings( $core );

		$_GET['tab'] = 'dashboard';

		$this->assertSame( 'dashboard', $settings->get_active_tab() );
	}

	/**
	 * All six declared tabs are accepted.
	 */
	public function test_get_active_tab_accepts_every_declared_tab() {
		$core     = $this->make_core();
		$settings = new CH_Admin_Settings( $core );

		foreach ( CH_Admin_Settings::TABS as $tab ) {
			$_GET['tab'] = $tab;
			$this->assertSame( $tab, $settings->get_active_tab(),
				"Tab '$tab' should be accepted by get_active_tab()" );
		}
	}

	/**
	 * An unknown tab slug is rejected; 'roles' is returned.
	 */
	public function test_get_active_tab_rejects_unknown_tab() {
		$core     = $this->make_core();
		$settings = new CH_Admin_Settings( $core );

		$_GET['tab'] = 'hax0r';

		$this->assertSame( 'roles', $settings->get_active_tab() );
	}

	/**
	 * An empty string is rejected; 'roles' is returned.
	 */
	public function test_get_active_tab_rejects_empty_string() {
		$core     = $this->make_core();
		$settings = new CH_Admin_Settings( $core );

		$_GET['tab'] = '';

		$this->assertSame( 'roles', $settings->get_active_tab() );
	}

	// =========================================================================
	// Test 7–11: sanitize() — role validation and enabled flag
	// =========================================================================

	/**
	 * Valid protected_roles slugs (present in wp_roles registry) are preserved.
	 */
	public function test_sanitize_keeps_valid_protected_roles() {
		$core     = $this->make_core( $this->base_config() );
		$settings = new CH_Admin_Settings( $core );

		$this->mock_wp_roles( array(
			'subscriber' => array( 'read' => true ),
			'editor'     => array( 'edit_posts' => true ),
		) );

		$result = $settings->sanitize( array(
			'enabled'         => '1',
			'protected_roles' => array( 'subscriber', 'editor' ),
			'admin_roles'     => array(),
		) );

		$this->assertSame( array( 'subscriber', 'editor' ), $result['protected_roles'] );
	}

	/**
	 * Role slugs absent from the wp_roles registry are silently dropped.
	 */
	public function test_sanitize_drops_invalid_protected_roles() {
		$core     = $this->make_core( $this->base_config() );
		$settings = new CH_Admin_Settings( $core );

		$this->mock_wp_roles( array(
			'subscriber' => array( 'read' => true ),
		) );

		$result = $settings->sanitize( array(
			'enabled'         => false,
			'protected_roles' => array( 'subscriber', 'nonexistent_role' ),
			'admin_roles'     => array(),
		) );

		$this->assertSame( array( 'subscriber' ), $result['protected_roles'] );
		$this->assertNotContains( 'nonexistent_role', $result['protected_roles'] );
	}

	/**
	 * Valid admin_roles slugs are preserved.
	 */
	public function test_sanitize_keeps_valid_admin_roles() {
		$core     = $this->make_core( $this->base_config() );
		$settings = new CH_Admin_Settings( $core );

		$this->mock_wp_roles( array(
			'editor' => array( 'edit_posts' => true ),
		) );

		$result = $settings->sanitize( array(
			'enabled'         => false,
			'protected_roles' => array(),
			'admin_roles'     => array( 'editor' ),
		) );

		$this->assertSame( array( 'editor' ), $result['admin_roles'] );
	}

	/**
	 * Invalid admin_roles slugs are silently dropped.
	 */
	public function test_sanitize_drops_invalid_admin_roles() {
		$core     = $this->make_core( $this->base_config() );
		$settings = new CH_Admin_Settings( $core );

		$this->mock_wp_roles( array(
			'editor' => array( 'edit_posts' => true ),
		) );

		$result = $settings->sanitize( array(
			'enabled'         => false,
			'protected_roles' => array(),
			'admin_roles'     => array( 'editor', 'fake_admin_role' ),
		) );

		$this->assertSame( array( 'editor' ), $result['admin_roles'] );
		$this->assertNotContains( 'fake_admin_role', $result['admin_roles'] );
	}

	/**
	 * A truthy 'enabled' value in input sets enabled=true in the merged config.
	 */
	public function test_sanitize_sets_enabled_true_from_input() {
		$core     = $this->make_core( $this->base_config() );
		$settings = new CH_Admin_Settings( $core );

		$this->mock_wp_roles( array() );

		$result = $settings->sanitize( array(
			'enabled'         => '1', // checkbox value when checked.
			'protected_roles' => array(),
			'admin_roles'     => array(),
		) );

		$this->assertTrue( $result['enabled'] );
	}

	/**
	 * When 'enabled' is absent (unchecked checkbox), enabled=false is returned.
	 */
	public function test_sanitize_enabled_defaults_to_false_when_key_absent() {
		$core     = $this->make_core( $this->base_config() );
		$settings = new CH_Admin_Settings( $core );

		$this->mock_wp_roles( array() );

		$result = $settings->sanitize( array(
			// 'enabled' intentionally absent — unchecked checkboxes are not submitted.
			'protected_roles' => array(),
			'admin_roles'     => array(),
		) );

		$this->assertFalse( $result['enabled'] );
	}
}
