<?php
/**
 * Unit tests for CH_Admin_Settings (settings page, tab routing, sanitize callback).
 *
 * WP_Mock limitation: add_action() is an expectation assertion only — the hook
 * pipeline is never fired. All tab and sanitize tests call the methods directly.
 *
 * $_GET isolation: setUp saves and resets $_GET; tearDown restores it. Each
 * test sets only the key it needs and relies on the clean state from setUp.
 *
 * Sanitize flow: sanitize() calls wp_roles()->get_names() to build the valid-role
 * allowlist, then calls merge_into_current() which re-reads get_option(). Tests
 * use make_core() to set up the get_option mock (allowing multiple calls) and
 * mock wp_roles() explicitly per test.
 *
 * sanitize_key is pre-defined in bootstrap as a PHP userspace function — it does
 * not need WP_Mock mocking in these tests.
 *
 * Registration-tracking pattern: WP_Mock::userFunction('fn', ['return' => Closure])
 * where the closure appends func_get_args() to a reference array. Assertions
 * inspect the recorded calls — no assertTrue(true) no-ops.
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
	 * Standard base config used by sanitize tests.
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
	// Tests A–F: register_hooks, register_page, register_settings, render_page
	// =========================================================================

	/**
	 * Test A — register_hooks() wires admin_menu and admin_init.
	 *
	 * WP_Mock::expectActionAdded() registers an expectation verified by
	 * WP_Mock::tearDown() (called via parent::tearDown()). PHPUnit would flag
	 * the test as risky for having no $this->assert*() calls; telling it to
	 * expect no assertions keeps the test passing while WP_Mock does the real
	 * verification in tearDown.
	 */
	public function test_register_hooks_adds_admin_menu_and_admin_init_actions() {
		$this->expectNotToPerformAssertions();

		$core     = $this->make_core();
		$settings = new CH_Admin_Settings( $core );

		WP_Mock::expectActionAdded( 'admin_menu', array( $settings, 'register_page' ) );
		WP_Mock::expectActionAdded( 'admin_init', array( $settings, 'register_settings' ) );

		$settings->register_hooks();
	}

	/**
	 * Test B — register_page() calls add_menu_page() with the required args.
	 */
	public function test_register_page_calls_add_menu_page_with_expected_args() {
		$core     = $this->make_core();
		$settings = new CH_Admin_Settings( $core );

		$calls = array();
		WP_Mock::userFunction( 'add_menu_page', array(
			'return' => static function () use ( &$calls ) {
				$calls[] = func_get_args();
			},
		) );

		$settings->register_page();

		$this->assertCount( 1, $calls, 'add_menu_page must be called exactly once' );
		$args = $calls[0];
		// [0]=page_title [1]=menu_title [2]=cap [3]=slug [4]=cb [5]=icon [6]=pos
		$this->assertSame( 'manage_options',        $args[2], 'capability must be manage_options' );
		$this->assertSame( 'client-handoff',        $args[3], 'menu slug must be client-handoff' );
		$this->assertEquals( array( $settings, 'render_page' ), $args[4], 'render callback must be render_page' );
		$this->assertSame( 'dashicons-businessman', $args[5], 'icon must be dashicons-businessman' );
		$this->assertSame( 80,                      $args[6], 'position must be 80' );
	}

	/**
	 * Test C — register_settings() calls register_setting() with the correct args.
	 */
	public function test_register_settings_calls_register_setting_with_correct_args() {
		$_GET['tab'] = 'roles'; // activate gate so section/field mocks are needed.

		$core     = $this->make_core();
		$settings = new CH_Admin_Settings( $core );

		$reg_calls = array();
		WP_Mock::userFunction( 'register_setting', array(
			'return' => static function () use ( &$reg_calls ) {
				$reg_calls[] = func_get_args();
			},
		) );
		WP_Mock::userFunction( 'add_settings_section' );
		WP_Mock::userFunction( 'add_settings_field' );

		$settings->register_settings();

		$this->assertCount( 1, $reg_calls, 'register_setting must be called exactly once' );
		$this->assertSame( 'client_handoff_config', $reg_calls[0][0], 'option_group must be client_handoff_config' );
		$this->assertSame( 'client_handoff_config', $reg_calls[0][1], 'option_name must be client_handoff_config' );
		$this->assertArrayHasKey( 'sanitize_callback', $reg_calls[0][2], 'args must contain sanitize_callback' );
		$this->assertEquals(
			array( $settings, 'sanitize' ),
			$reg_calls[0][2]['sanitize_callback'],
			'sanitize_callback must be this->sanitize'
		);
	}

	/**
	 * Test D — on the roles tab, section and both fields are registered.
	 */
	public function test_register_settings_with_roles_tab_registers_section_and_fields() {
		$_GET['tab'] = 'roles';

		$core     = $this->make_core();
		$settings = new CH_Admin_Settings( $core );

		$section_calls = array();
		$field_calls   = array();
		WP_Mock::userFunction( 'register_setting' );
		WP_Mock::userFunction( 'add_settings_section', array(
			'return' => static function () use ( &$section_calls ) {
				$section_calls[] = func_get_args();
			},
		) );
		WP_Mock::userFunction( 'add_settings_field', array(
			'return' => static function () use ( &$field_calls ) {
				$field_calls[] = func_get_args();
			},
		) );

		$settings->register_settings();

		$this->assertCount( 1, $section_calls, 'add_settings_section must be called once on roles tab' );
		$this->assertSame( 'client-handoff-roles', $section_calls[0][3],
			'section must be registered to page client-handoff-roles' );

		$this->assertCount( 2, $field_calls, 'add_settings_field must be called twice on roles tab' );
		foreach ( $field_calls as $i => $field_args ) {
			$this->assertSame( 'client-handoff-roles', $field_args[3],
				"field call $i must target page client-handoff-roles" );
		}
		// Verify the two field IDs.
		$field_ids = array_column( $field_calls, 0 );
		$this->assertContains( 'ch_protected_roles', $field_ids );
		$this->assertContains( 'ch_admin_roles', $field_ids );
	}

	/**
	 * Test E — on a non-roles tab, register_setting fires but section/fields do not.
	 */
	public function test_register_settings_with_non_roles_tab_skips_section_and_fields() {
		$_GET['tab'] = 'dashboard';

		$core     = $this->make_core();
		$settings = new CH_Admin_Settings( $core );

		$reg_calls     = array();
		$section_calls = array();
		$field_calls   = array();
		WP_Mock::userFunction( 'register_setting', array(
			'return' => static function () use ( &$reg_calls ) {
				$reg_calls[] = func_get_args();
			},
		) );
		WP_Mock::userFunction( 'add_settings_section', array(
			'return' => static function () use ( &$section_calls ) {
				$section_calls[] = func_get_args();
			},
		) );
		WP_Mock::userFunction( 'add_settings_field', array(
			'return' => static function () use ( &$field_calls ) {
				$field_calls[] = func_get_args();
			},
		) );

		$settings->register_settings();

		$this->assertCount( 1, $reg_calls, 'register_setting must fire regardless of active tab' );
		$this->assertEmpty( $section_calls, 'add_settings_section must not fire on non-roles tab' );
		$this->assertEmpty( $field_calls,   'add_settings_field must not fire on non-roles tab' );
	}

	/**
	 * Test F — render_page() wp_dies when user lacks manage_options.
	 */
	public function test_render_page_wp_dies_when_user_lacks_manage_options() {
		$core     = $this->make_core();
		$settings = new CH_Admin_Settings( $core );

		WP_Mock::userFunction( 'current_user_can', array(
			'args'   => array( 'manage_options' ),
			'return' => false,
		) );

		$wp_die_called = false;
		WP_Mock::userFunction( 'wp_die', array(
			'return' => static function () use ( &$wp_die_called ) {
				$wp_die_called = true;
			},
		) );

		$settings->render_page();

		$this->assertTrue( $wp_die_called, 'wp_die must be called when user lacks manage_options' );
	}

	// =========================================================================
	// Tests 1–5: get_active_tab() — tab routing and allowlist enforcement
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
	// Tests 6–10: sanitize() — role validation and enabled-preservation
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
			'protected_roles' => array(),
			'admin_roles'     => array( 'editor', 'fake_admin_role' ),
		) );

		$this->assertSame( array( 'editor' ), $result['admin_roles'] );
		$this->assertNotContains( 'fake_admin_role', $result['admin_roles'] );
	}

	/**
	 * The Roles-tab sanitize pass must NOT alter the saved enabled flag.
	 *
	 * The Roles form has no enabled checkbox. If sanitize() included 'enabled'
	 * in its partial, merge_into_current() would overlay false on every save.
	 * Two sub-assertions cover both directions to catch any regression.
	 *
	 * get_option is mocked with a closure that routes by call count so that both
	 * sub-cases share a single mock without WP_Mock expectation-stacking problems.
	 * Each sub-case makes exactly 2 calls to get_option: one in CH_Core::__construct
	 * (load_config) and one inside merge_into_current. floor(call/2) routes pairs.
	 */
	public function test_sanitize_preserves_saved_enabled_through_roles_tab_save() {
		$call_count = 0;
		$configs    = array(
			array( 'enabled' => true ),  // sub-case 1: both calls return enabled=true.
			array( 'enabled' => false ), // sub-case 2: both calls return enabled=false.
		);
		WP_Mock::userFunction( 'get_option', array(
			'return' => static function () use ( &$call_count, $configs ) {
				$pair = (int) floor( $call_count / 2 );
				++$call_count;
				return isset( $configs[ $pair ] ) ? $configs[ $pair ] : array();
			},
		) );

		// Sub-case 1: saved enabled=true must survive a Roles-tab save (calls 0–1).
		$core1    = CH_Core::get_instance();
		$settings = new CH_Admin_Settings( $core1 );
		WP_Mock::userFunction( 'wp_roles', array( 'return' => new WP_Roles( array() ) ) );
		$result1 = $settings->sanitize( array( 'protected_roles' => array(), 'admin_roles' => array() ) );
		$this->assertTrue(
			$result1['enabled'],
			'saved enabled=true must not be overwritten by a Roles-tab sanitize pass'
		);

		// Sub-case 2: saved enabled=false must also survive (calls 2–3).
		CH_Core::reset_instance();
		$core2    = CH_Core::get_instance();
		$settings = new CH_Admin_Settings( $core2 );
		WP_Mock::userFunction( 'wp_roles', array( 'return' => new WP_Roles( array() ) ) );
		$result2 = $settings->sanitize( array( 'protected_roles' => array(), 'admin_roles' => array() ) );
		$this->assertFalse(
			$result2['enabled'],
			'saved enabled=false must not be overwritten by a Roles-tab sanitize pass'
		);
	}

	// =========================================================================
	// Tests R1–R8: Restrictions tab — sanitize + registration isolation
	// =========================================================================

	/**
	 * Helper: build a saved config that includes an enforcement subarray.
	 *
	 * @param array $enforcement_overrides
	 * @return array
	 */
	private function enforcement_config( array $enforcement_overrides = array() ) {
		return $this->base_config( array(
			'enforcement' => array_merge(
				array(
					'blocked_caps'      => array( 'install_plugins' ),
					'protected_plugins' => array(),
					'screen_blocklist'  => array(),
				),
				$enforcement_overrides
			),
		) );
	}

	/**
	 * Helper: mock get_plugins() to return the given map.
	 *
	 * @param array<string, array> $plugins basename => plugin data array.
	 */
	private function mock_get_plugins( array $plugins ) {
		WP_Mock::userFunction( 'get_plugins', array( 'return' => $plugins ) );
	}

	// ---- R1 -------------------------------------------------------------------

	/**
	 * R1 — Valid blocked_caps (from the eleven defaults) are preserved in order.
	 */
	public function test_sanitize_restrictions_keeps_valid_blocked_caps() {
		$core     = $this->make_core( $this->enforcement_config() );
		$settings = new CH_Admin_Settings( $core );

		$this->mock_get_plugins( array() );

		$result = $settings->sanitize( array(
			'enforcement' => array(
				'blocked_caps'      => array( 'install_plugins', 'activate_plugins' ),
				'protected_plugins' => array(),
			),
		) );

		$this->assertSame(
			array( 'install_plugins', 'activate_plugins' ),
			$result['enforcement']['blocked_caps'],
			'Valid blocked_caps must be preserved in submission order'
		);
	}

	// ---- R2 -------------------------------------------------------------------

	/**
	 * R2 — Caps absent from the eleven defaults are silently dropped.
	 */
	public function test_sanitize_restrictions_drops_unknown_blocked_caps() {
		$core     = $this->make_core( $this->enforcement_config() );
		$settings = new CH_Admin_Settings( $core );

		$this->mock_get_plugins( array() );

		$result = $settings->sanitize( array(
			'enforcement' => array(
				'blocked_caps'      => array( 'install_plugins', 'manage_options', 'invented_cap' ),
				'protected_plugins' => array(),
			),
		) );

		$this->assertContains( 'install_plugins', $result['enforcement']['blocked_caps'] );
		$this->assertNotContains( 'manage_options', $result['enforcement']['blocked_caps'],
			'manage_options is not in the eleven defaults and must be dropped' );
		$this->assertNotContains( 'invented_cap', $result['enforcement']['blocked_caps'],
			'Invented caps must be dropped' );
		$this->assertCount( 1, $result['enforcement']['blocked_caps'] );
	}

	// ---- R3 -------------------------------------------------------------------

	/**
	 * R3 — Installed plugin basenames are preserved.
	 */
	public function test_sanitize_restrictions_keeps_installed_protected_plugins() {
		$core     = $this->make_core( $this->enforcement_config() );
		$settings = new CH_Admin_Settings( $core );

		$this->mock_get_plugins( array(
			'real/real.php'         => array( 'Name' => 'Real Plugin' ),
			'akismet/akismet.php'   => array( 'Name' => 'Akismet' ),
		) );

		$result = $settings->sanitize( array(
			'enforcement' => array(
				'blocked_caps'      => array(),
				'protected_plugins' => array( 'real/real.php', 'akismet/akismet.php' ),
			),
		) );

		$this->assertContains( 'real/real.php',       $result['enforcement']['protected_plugins'] );
		$this->assertContains( 'akismet/akismet.php', $result['enforcement']['protected_plugins'] );
		$this->assertCount( 2, $result['enforcement']['protected_plugins'] );
	}

	// ---- R4 -------------------------------------------------------------------

	/**
	 * R4 — Plugin basenames absent from get_plugins() are silently dropped.
	 */
	public function test_sanitize_restrictions_drops_uninstalled_protected_plugins() {
		$core     = $this->make_core( $this->enforcement_config() );
		$settings = new CH_Admin_Settings( $core );

		$this->mock_get_plugins( array(
			'real/real.php' => array( 'Name' => 'Real Plugin' ),
		) );

		$result = $settings->sanitize( array(
			'enforcement' => array(
				'blocked_caps'      => array(),
				'protected_plugins' => array( 'real/real.php', 'fake/fake.php' ),
			),
		) );

		$this->assertSame( array( 'real/real.php' ), $result['enforcement']['protected_plugins'] );
		$this->assertNotContains( 'fake/fake.php', $result['enforcement']['protected_plugins'] );
	}

	// ---- R5 -------------------------------------------------------------------

	/**
	 * R5 — Empty submissions produce empty arrays, not nulls or missing keys.
	 */
	public function test_sanitize_restrictions_empty_arrays_produce_empty_arrays() {
		$core     = $this->make_core( $this->enforcement_config() );
		$settings = new CH_Admin_Settings( $core );

		$this->mock_get_plugins( array() );

		$result = $settings->sanitize( array(
			'enforcement' => array(
				'blocked_caps'      => array(),
				'protected_plugins' => array(),
			),
		) );

		$this->assertArrayHasKey( 'blocked_caps',      $result['enforcement'] );
		$this->assertArrayHasKey( 'protected_plugins', $result['enforcement'] );
		$this->assertSame( array(), $result['enforcement']['blocked_caps'] );
		$this->assertSame( array(), $result['enforcement']['protected_plugins'] );
	}

	// ---- R6 -------------------------------------------------------------------

	/**
	 * R6 — Subkeys not in the Restrictions partial (screen_blocklist) and
	 * top-level keys not in the Restrictions partial (protected_roles) are
	 * both preserved from the saved config.
	 */
	public function test_sanitize_restrictions_preserves_other_config_subkeys() {
		$saved = $this->base_config( array(
			'protected_roles' => array( 'subscriber' ),
			'enforcement'     => array(
				'blocked_caps'      => array( 'install_plugins' ),
				'protected_plugins' => array(),
				'screen_blocklist'  => array( 'subscriber' => array( 'some-screen.php' ) ),
			),
		) );

		// Single saved config — same value on every get_option call.
		$core     = $this->make_core( $saved );
		$settings = new CH_Admin_Settings( $core );

		$this->mock_get_plugins( array() );

		$result = $settings->sanitize( array(
			'enforcement' => array(
				'blocked_caps'      => array( 'install_plugins' ),
				'protected_plugins' => array(),
			),
		) );

		// screen_blocklist (enforcement subkey not in partial) must be preserved.
		$this->assertSame(
			array( 'subscriber' => array( 'some-screen.php' ) ),
			$result['enforcement']['screen_blocklist'],
			'screen_blocklist must be preserved when not in the Restrictions partial'
		);

		// protected_roles (top-level key not in partial) must be preserved.
		$this->assertSame(
			array( 'subscriber' ),
			$result['protected_roles'],
			'protected_roles must be preserved when not in the Restrictions partial'
		);
	}

	// ---- R7 -------------------------------------------------------------------

	/**
	 * R7 — On the restrictions tab, section and both fields are registered.
	 */
	public function test_register_settings_with_restrictions_tab_registers_section_and_fields() {
		$_GET['tab'] = 'restrictions';

		$core     = $this->make_core();
		$settings = new CH_Admin_Settings( $core );

		$section_calls = array();
		$field_calls   = array();
		WP_Mock::userFunction( 'register_setting' );
		WP_Mock::userFunction( 'add_settings_section', array(
			'return' => static function () use ( &$section_calls ) {
				$section_calls[] = func_get_args();
			},
		) );
		WP_Mock::userFunction( 'add_settings_field', array(
			'return' => static function () use ( &$field_calls ) {
				$field_calls[] = func_get_args();
			},
		) );

		$settings->register_settings();

		$this->assertCount( 1, $section_calls,
			'add_settings_section must be called once on restrictions tab' );
		$this->assertSame( 'client-handoff-restrictions', $section_calls[0][3],
			'Section must target page client-handoff-restrictions' );

		$this->assertCount( 2, $field_calls,
			'add_settings_field must be called twice on restrictions tab' );
		foreach ( $field_calls as $i => $args ) {
			$this->assertSame( 'client-handoff-restrictions', $args[3],
				"Field call $i must target page client-handoff-restrictions" );
		}

		$field_ids = array_column( $field_calls, 0 );
		$this->assertContains( 'ch_blocked_caps',      $field_ids );
		$this->assertContains( 'ch_protected_plugins',  $field_ids );
	}

	// ---- R8 -------------------------------------------------------------------

	/**
	 * R8 — On the roles tab, restrictions section and fields are NOT registered.
	 *
	 * Proves the two tabs' registrations are isolated — no cross-contamination.
	 */
	public function test_register_settings_with_roles_tab_does_not_register_restrictions_fields() {
		$_GET['tab'] = 'roles';

		$core     = $this->make_core();
		$settings = new CH_Admin_Settings( $core );

		$section_calls = array();
		$field_calls   = array();
		WP_Mock::userFunction( 'register_setting' );
		WP_Mock::userFunction( 'add_settings_section', array(
			'return' => static function () use ( &$section_calls ) {
				$section_calls[] = func_get_args();
			},
		) );
		WP_Mock::userFunction( 'add_settings_field', array(
			'return' => static function () use ( &$field_calls ) {
				$field_calls[] = func_get_args();
			},
		) );

		$settings->register_settings();

		// Exactly one section, and it targets roles not restrictions.
		$this->assertCount( 1, $section_calls );
		$this->assertSame( 'client-handoff-roles', $section_calls[0][3],
			'Section must target client-handoff-roles, not client-handoff-restrictions' );

		// Exactly two fields, both targeting the roles page.
		$this->assertCount( 2, $field_calls );
		foreach ( $field_calls as $i => $args ) {
			$this->assertSame( 'client-handoff-roles', $args[3],
				"Field call $i must target client-handoff-roles, not client-handoff-restrictions" );
		}

		// Restrictions field IDs must not appear.
		$field_ids = array_column( $field_calls, 0 );
		$this->assertNotContains( 'ch_blocked_caps',     $field_ids );
		$this->assertNotContains( 'ch_protected_plugins', $field_ids );
	}
}
