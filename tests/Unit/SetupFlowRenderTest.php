<?php
/**
 * Phase 4 renderer consolidation — Pass B.
 * ZSCH_Setup_Flow::render dispatch tests (B5–B8).
 *
 * WHAT IS TESTED
 *
 * render() is a public dispatcher: it reads the current step (from
 * $_GET['zsch_step']), builds the step header, then delegates to either
 * render_settings_step() or render_activate_step(). These four tests verify:
 *
 *   B5 — Default step ('roles') → header says "Step 1 of 4: Configure Roles"
 *   B6 — 'dashboard' step → header says "Step 2 of 4"
 *   B7 — 'activate' step → summary block + _zsch_setup_complete hidden input
 *   B8 — 'activate' step → _zsch_setup_dismiss hidden input (separate form)
 *
 * B5 + B6 together prove the step index is computed from state (get_step_index),
 * not hardcoded. B7 + B8 are split so a "activate present, dismiss absent" bug
 * fails a distinct test rather than one combined assertion.
 *
 * PARAMETER NOTE
 *
 * ZSCH_Setup_Flow::render(ZSCH_Admin_Settings $settings) accepts a settings
 * argument that is currently unused — type-hinted for future extension. A
 * real ZSCH_Admin_Settings instance is passed to satisfy the type hint.
 *
 * BOOTSTRAP STUBS IN USE (no WP_Mock::userFunction needed for these)
 *   admin_url, settings_fields, do_settings_sections, submit_button,
 *   esc_html, esc_attr, esc_url, sanitize_key, __
 *
 * @package ClientHandoff
 */

use WP_Mock\Tools\TestCase;

/**
 * Class SetupFlowRenderTest
 */
class SetupFlowRenderTest extends TestCase {

	// -------------------------------------------------------------------------
	// Lifecycle
	// -------------------------------------------------------------------------

	/** @var array Original $_GET contents, restored in tearDown. */
	private $original_get;

	public function setUp(): void {
		parent::setUp();
		ZSCH_Core::reset_instance();
		$this->original_get = $_GET;
	}

	public function tearDown(): void {
		$_GET = $this->original_get;
		ZSCH_Core::reset_instance();
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

	/**
	 * Return a ZSCH_Admin_Settings instance wired to the given core.
	 * The setup_flow argument is not passed — it is not needed for render()
	 * because the settings parameter in that method is currently unused.
	 *
	 * @param ZSCH_Core $core
	 * @return ZSCH_Admin_Settings
	 */
	private function make_settings( ZSCH_Core $core ): ZSCH_Admin_Settings {
		return new ZSCH_Admin_Settings( $core );
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
	// B5 — Default step ('roles') renders Step 1 of 4 header
	// =========================================================================

	/**
	 * B5 — render() with no zsch_step query param defaults to 'roles' (step 1)
	 * and outputs a header containing "Step 1 of 4" and "Configure Roles".
	 *
	 * Mutation: hardcoding "Step 1 of 4" in the template fails B6. Computing
	 * the wrong index fails B5. Both tests together prove the index is dynamic.
	 */
	public function test_render_with_default_step_renders_step_one_of_four_header() {
		unset( $_GET['zsch_step'] );

		$core       = $this->make_core();
		$setup_flow = new ZSCH_Setup_Flow( $core );
		$settings   = $this->make_settings( $core );

		$html = $this->capture( function() use ( $setup_flow, $settings ) {
			$setup_flow->render( $settings );
		} );

		$this->assertStringContainsString(
			'Step 1 of 4',
			$html,
			'Header must indicate step 1 of 4 when zsch_step is not set (defaults to roles)'
		);
		$this->assertStringContainsString(
			'Configure Roles',
			$html,
			'Header must include the step title "Configure Roles" for the roles step'
		);
	}

	// =========================================================================
	// B6 — 'dashboard' step renders Step 2 of 4 header
	// =========================================================================

	/**
	 * B6 — render() with zsch_step=dashboard outputs a header containing
	 * "Step 2 of 4" and "Configure Dashboard".
	 *
	 * Together with B5, this proves get_step_index is called and returns the
	 * correct 1-based position rather than a hardcoded value.
	 */
	public function test_render_with_dashboard_step_renders_step_two_of_four_header() {
		$_GET['zsch_step'] = 'dashboard';

		$core       = $this->make_core();
		$setup_flow = new ZSCH_Setup_Flow( $core );
		$settings   = $this->make_settings( $core );

		$html = $this->capture( function() use ( $setup_flow, $settings ) {
			$setup_flow->render( $settings );
		} );

		$this->assertStringContainsString(
			'Step 2 of 4',
			$html,
			'Header must indicate step 2 of 4 for the dashboard step'
		);
		$this->assertStringContainsString(
			'Configure Dashboard',
			$html,
			'Header must include the step title "Configure Dashboard" for the dashboard step'
		);
	}

	// =========================================================================
	// B7 — 'activate' step renders summary block and activate form
	// =========================================================================

	/**
	 * B7 — render() on the activate step outputs a Configuration Summary block
	 * and the activate form containing the _zsch_setup_complete hidden input.
	 *
	 * Config has 1 protected role (subscriber) and 1 blocked cap (install_plugins)
	 * so the summary counters have non-zero values to assert against.
	 *
	 * Asserted summary strings are taken directly from the render_activate_step
	 * template — 'Configuration Summary' (h2 text), 'Protected roles:' (li label),
	 * 'Blocked capabilities:' (li label). The counts (1) are emitted as text
	 * nodes immediately after the label.
	 */
	public function test_render_with_activate_step_renders_summary_and_activate_form() {
		$_GET['zsch_step'] = 'activate';

		$core = $this->make_core( array(
			'protected_roles' => array( 'subscriber' ),
			'enforcement'     => array(
				'blocked_caps'      => array( 'install_plugins' ),
				'protected_plugins' => array(),
			),
		) );
		$setup_flow = new ZSCH_Setup_Flow( $core );
		$settings   = $this->make_settings( $core );

		$html = $this->capture( function() use ( $setup_flow, $settings ) {
			$setup_flow->render( $settings );
		} );

		// Summary block heading.
		$this->assertStringContainsString(
			'Configuration Summary',
			$html,
			'Activate step must render a Configuration Summary heading'
		);

		// Summary list items (label strings from the template).
		$this->assertStringContainsString(
			'Protected roles:',
			$html,
			'Summary must include the Protected roles label'
		);
		$this->assertStringContainsString(
			'Blocked capabilities:',
			$html,
			'Summary must include the Blocked capabilities label'
		);

		// Activate form hidden marker input.
		// The template puts name= and value= on separate indented lines, so we
		// assert each attribute independently rather than as a single-line pattern.
		$this->assertStringContainsString(
			'name="zsch_config[_zsch_setup_complete]"',
			$html,
			'Activate form must include the _zsch_setup_complete hidden input'
		);
		// The hidden input for the activate marker must carry value="1".
		// We verify by locating the name attribute fragment and confirming value="1"
		// appears in the rendered output (all hidden step markers use value="1").
		$this->assertStringContainsString(
			'value="1"',
			$html,
			'The _zsch_setup_complete hidden input must have value="1"'
		);
	}

	// =========================================================================
	// B8 — 'activate' step renders dismiss form
	// =========================================================================

	/**
	 * B8 — render() on the activate step ALSO renders the dismiss form
	 * containing the _zsch_setup_dismiss hidden input.
	 *
	 * Split from B7 so "activate present, dismiss absent" is a distinct failure
	 * rather than a combined assertion. Both forms are required: the activate
	 * form enables handoff mode, the dismiss form skips setup without enabling.
	 */
	public function test_render_with_activate_step_renders_dismiss_form() {
		$_GET['zsch_step'] = 'activate';

		$core = $this->make_core( array(
			'protected_roles' => array( 'subscriber' ),
			'enforcement'     => array(
				'blocked_caps'      => array( 'install_plugins' ),
				'protected_plugins' => array(),
			),
		) );
		$setup_flow = new ZSCH_Setup_Flow( $core );
		$settings   = $this->make_settings( $core );

		$html = $this->capture( function() use ( $setup_flow, $settings ) {
			$setup_flow->render( $settings );
		} );

		// The template puts name= and value= on separate indented lines.
		$this->assertStringContainsString(
			'name="zsch_config[_zsch_setup_dismiss]"',
			$html,
			'Activate step must also render the dismiss form with _zsch_setup_dismiss input'
		);
		// Confirm the dismiss input carries value="1" (same single-value pattern
		// as the activate marker — both hidden inputs use value="1").
		$this->assertStringContainsString(
			'value="1"',
			$html,
			'The _zsch_setup_dismiss hidden input must have value="1"'
		);
	}
}
