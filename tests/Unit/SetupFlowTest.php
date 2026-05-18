<?php
/**
 * Unit tests for ZSCH_Setup_Flow (state methods only).
 *
 * SCOPE
 * Tests S1–S8 cover should_show(), get_current_step(), get_step_index(), and
 * get_next_step() — the four pure-PHP state/navigation methods that have no
 * WordPress function dependencies and are therefore fully testable under
 * WP_Mock.
 *
 * render() AND ITS PRIVATE HELPERS — DEFERRED (Phase 4)
 * render() calls settings_fields(), do_settings_sections(), submit_button(),
 * admin_url(), and several escaping functions across two code paths (settings
 * step and activate step). Testing it meaningfully requires capturing the
 * full output stream and mocking every WP output function — the same
 * rationale used to defer renderer tests for ZSCH_Admin_Settings field
 * renderers. Deferred to Phase 4 alongside those renderer tests.
 *
 * $_GET ISOLATION
 * setUp() saves and resets $_GET; tearDown() restores it. Tests set only the
 * key they need and rely on the clean state provided by setUp().
 *
 * make_core() PATTERN
 * Identical to the pattern in AdminSettingsTest: one WP_Mock::userFunction()
 * call for get_option (which ZSCH_Core reads in its constructor) plus
 * ZSCH_Core::get_instance().
 *
 * @package ClientHandoff
 */

use WP_Mock\Tools\TestCase;

/**
 * Class SetupFlowTest
 */
class SetupFlowTest extends TestCase {

	// -------------------------------------------------------------------------
	// Lifecycle
	// -------------------------------------------------------------------------

	/** @var array Original $_GET, restored in tearDown. */
	private $original_get;

	public function setUp(): void {
		parent::setUp();
		ZSCH_Core::reset_instance();
		$this->original_get = $_GET;
		$_GET               = array();
	}

	public function tearDown(): void {
		ZSCH_Core::reset_instance();
		$_GET = $this->original_get;
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a ZSCH_Core instance backed by the given config array.
	 *
	 * @param array $config
	 * @return ZSCH_Core
	 */
	private function make_core( array $config = array() ): ZSCH_Core {
		WP_Mock::userFunction( 'get_option', array( 'return' => $config ) );
		return ZSCH_Core::get_instance();
	}

	// =========================================================================
	// S1 — should_show() returns true when setup_completed=false
	// =========================================================================

	/**
	 * S1 — should_show() is true when config.setup_completed is explicitly false.
	 */
	public function test_should_show_returns_true_when_setup_not_completed() {
		$core = $this->make_core( array( 'setup_completed' => false ) );
		$flow = new ZSCH_Setup_Flow( $core );

		$this->assertTrue( $flow->should_show() );
	}

	// =========================================================================
	// S2 — should_show() returns false when setup_completed=true
	// =========================================================================

	/**
	 * S2 — should_show() is false when config.setup_completed is true (flow done).
	 */
	public function test_should_show_returns_false_when_setup_completed() {
		$core = $this->make_core( array( 'setup_completed' => true ) );
		$flow = new ZSCH_Setup_Flow( $core );

		$this->assertFalse( $flow->should_show() );
	}

	// =========================================================================
	// S3 — should_show() returns true on a fresh install (key absent)
	// =========================================================================

	/**
	 * S3 — should_show() is true when the saved config is empty (fresh install).
	 *
	 * ZSCH_Core hydrates the saved config against DEFAULTS on load. DEFAULTS has
	 * setup_completed=false, so an empty saved array resolves to false — the
	 * setup flow must be displayed.
	 */
	public function test_should_show_returns_true_when_setup_completed_key_absent() {
		$core = $this->make_core( array() );
		$flow = new ZSCH_Setup_Flow( $core );

		$this->assertTrue(
			$flow->should_show(),
			'Fresh install with empty saved config must show the setup flow'
		);
	}

	// =========================================================================
	// S4 — get_current_step() defaults to 'roles'
	// =========================================================================

	/**
	 * S4 — get_current_step() returns 'roles' when zsch_step is absent.
	 */
	public function test_get_current_step_defaults_to_roles() {
		$core = $this->make_core();
		$flow = new ZSCH_Setup_Flow( $core );

		// $_GET is clean from setUp — zsch_step absent.
		$this->assertSame( 'roles', $flow->get_current_step() );
	}

	// =========================================================================
	// S5 — get_current_step() accepts each valid step
	// =========================================================================

	/**
	 * S5 — get_current_step() returns the correct slug for each valid step.
	 */
	public function test_get_current_step_accepts_each_valid_step() {
		$core = $this->make_core();
		$flow = new ZSCH_Setup_Flow( $core );

		foreach ( ZSCH_Setup_Flow::STEPS as $step ) {
			$_GET['zsch_step'] = $step;
			$this->assertSame(
				$step,
				$flow->get_current_step(),
				"get_current_step() must return '$step' when zsch_step='$step'"
			);
		}
	}

	// =========================================================================
	// S6 — get_current_step() rejects unknown step slug
	// =========================================================================

	/**
	 * S6 — get_current_step() defaults to 'roles' for an invalid slug.
	 */
	public function test_get_current_step_rejects_unknown_step() {
		$core = $this->make_core();
		$flow = new ZSCH_Setup_Flow( $core );

		$_GET['zsch_step'] = 'hax0r';

		$this->assertSame(
			'roles',
			$flow->get_current_step(),
			"Unknown zsch_step value must fall back to 'roles'"
		);
	}

	// =========================================================================
	// S7 — get_next_step() returns the subsequent step or null at end
	// =========================================================================

	/**
	 * S7 — get_next_step() returns each step's successor; null after the last.
	 */
	public function test_get_next_step_returns_subsequent_step() {
		$core = $this->make_core();
		$flow = new ZSCH_Setup_Flow( $core );

		$this->assertSame( 'dashboard',    $flow->get_next_step( 'roles' ) );
		$this->assertSame( 'restrictions', $flow->get_next_step( 'dashboard' ) );
		$this->assertSame( 'activate',     $flow->get_next_step( 'restrictions' ) );
		$this->assertNull(                 $flow->get_next_step( 'activate' ),
			'get_next_step() must return null after the final step'
		);
	}

	// =========================================================================
	// S8 — get_step_index() is 1-based
	// =========================================================================

	/**
	 * S8 — get_step_index() returns a 1-based position for each step.
	 */
	public function test_get_step_index_is_one_based() {
		$core = $this->make_core();
		$flow = new ZSCH_Setup_Flow( $core );

		$this->assertSame( 1, $flow->get_step_index( 'roles' ),    "roles must be step 1" );
		$this->assertSame( 2, $flow->get_step_index( 'dashboard' ), "dashboard must be step 2" );
		$this->assertSame( 3, $flow->get_step_index( 'restrictions' ), "restrictions must be step 3" );
		$this->assertSame( 4, $flow->get_step_index( 'activate' ), "activate must be step 4" );
	}
}
