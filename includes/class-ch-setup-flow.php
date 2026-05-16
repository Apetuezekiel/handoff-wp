<?php
/**
 * First-run guided setup flow (brief § 3.0).
 *
 * A thin sequential wrapper over the existing settings tabs. No new data
 * model — reads and writes the same 'client_handoff_config' option that the
 * standard settings page uses. The flow is shown whenever
 * config.setup_completed is false; it is hidden once the developer activates
 * handoff mode or explicitly dismisses the flow.
 *
 * STEPS
 *
 *   roles        → protected_roles + admin_roles (existing settings tab)
 *   dashboard    → dashboard.* fields             (existing settings tab)
 *   restrictions → enforcement.*                  (existing settings tab)
 *   activate     → summary + enable toggle        (custom form, no tab)
 *
 * DEFERRED STEPS (Phase 2)
 *
 *   help-notes  — no CH feature class yet; deferred until the help-notes
 *                 feature lands. When it does, its own pass adds a step.
 *   checklist   — same deferral.
 *
 * RENDERING MODEL
 *
 * For the three settings steps (roles, dashboard, restrictions), render()
 * outputs a standard Settings API form: settings_fields(), an overridden
 * _wp_http_referer that redirects options.php to the NEXT step instead of
 * the current page, do_settings_sections(), and submit_button(). A "Skip"
 * link navigates directly to the next step without saving.
 *
 * For the activate step, render() outputs a config summary (role counts,
 * dashboard status, blocked caps count, protected plugins count) and two
 * forms:
 *   1. Activate form — posts _ch_setup_complete=1 → sanitize() in
 *      CH_Admin_Settings sets enabled=true and setup_completed=true.
 *   2. Dismiss form  — posts _ch_setup_dismiss=1  → sets
 *      setup_completed=true without enabling handoff mode.
 *
 * RE-RUN SETUP — DEFERRED
 * A "Re-run Setup" link is out of scope for this pass. The Skip control on
 * each step provides sufficient bypass for MVP. Re-run support is a small
 * follow-on that adds a query-param override to should_show().
 *
 * UNIT TESTS
 * Only structural/state methods are unit-tested: should_show(),
 * get_current_step(), get_step_index(), get_next_step(). render() and its
 * private sub-methods use too many WordPress output functions to test
 * meaningfully without a full integration harness; deferred to Phase 4
 * (renderer tests, consistent with the approach taken for field renderers in
 * CH_Admin_Settings).
 *
 * @package ClientHandoff
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CH_Setup_Flow
 */
class CH_Setup_Flow {

	/** @var string[] Ordered setup step slugs. */
	const STEPS = array( 'roles', 'dashboard', 'restrictions', 'activate' );

	/** @var CH_Core */
	private $core;

	/**
	 * @param CH_Core $core
	 */
	public function __construct( $core ) {
		$this->core = $core;
	}

	// -------------------------------------------------------------------------
	// State
	// -------------------------------------------------------------------------

	/**
	 * Return true if the setup flow should be displayed.
	 *
	 * The flow is shown while config.setup_completed is false (or absent —
	 * fresh installs hydrate it from DEFAULTS which defaults to false).
	 *
	 * No query-param override exists yet — bypass is via the Skip control
	 * or by dismissing on the activate step. Re-run support is Phase 2.
	 *
	 * @return bool
	 */
	public function should_show(): bool {
		return ! $this->core->get( 'setup_completed' );
	}

	// -------------------------------------------------------------------------
	// Step navigation
	// -------------------------------------------------------------------------

	/**
	 * Return the active step slug.
	 *
	 * Reads $_GET['ch_step'], sanitize_key, validates against STEPS.
	 * Defaults to 'roles' when absent or invalid.
	 *
	 * @return string
	 */
	public function get_current_step(): string {
		$step = isset( $_GET['ch_step'] ) ? sanitize_key( $_GET['ch_step'] ) : '';
		return in_array( $step, self::STEPS, true ) ? $step : 'roles';
	}

	/**
	 * Return the 1-based position of a step in STEPS.
	 *
	 * Returns 1 for an unknown slug (graceful fallback).
	 *
	 * @param string $step
	 * @return int
	 */
	public function get_step_index( string $step ): int {
		$idx = array_search( $step, self::STEPS, true );
		return ( false !== $idx ) ? (int) $idx + 1 : 1;
	}

	/**
	 * Return the next step slug, or null when at the last step.
	 *
	 * @param string $step
	 * @return string|null
	 */
	public function get_next_step( string $step ): ?string {
		$idx = array_search( $step, self::STEPS, true );
		if ( false === $idx ) {
			return null;
		}
		return isset( self::STEPS[ $idx + 1 ] ) ? self::STEPS[ $idx + 1 ] : null;
	}

	// -------------------------------------------------------------------------
	// Rendering
	// -------------------------------------------------------------------------

	/**
	 * Render the full setup flow page for the current step.
	 *
	 * Called from CH_Admin_Settings::render_page() when should_show() is true.
	 * The $settings parameter is required so render() can re-use the field
	 * renderers already defined on CH_Admin_Settings without duplicating them.
	 *
	 * @param CH_Admin_Settings $settings
	 */
	public function render( CH_Admin_Settings $settings ): void {
		$step       = $this->get_current_step();
		$step_index = $this->get_step_index( $step );
		$step_count = count( self::STEPS );
		$next       = $this->get_next_step( $step );

		// After the last step, redirect to the standard tabbed page.
		$next_url = $next
			? admin_url( 'admin.php?page=client-handoff&ch_step=' . $next )
			: admin_url( 'admin.php?page=client-handoff' );

		$done_url = admin_url( 'admin.php?page=client-handoff' );

		$titles = array(
			'roles'        => __( 'Configure Roles', 'client-handoff' ),
			'dashboard'    => __( 'Configure Dashboard', 'client-handoff' ),
			'restrictions' => __( 'Configure Restrictions', 'client-handoff' ),
			'activate'     => __( 'Activate Handoff Mode', 'client-handoff' ),
		);
		$title = isset( $titles[ $step ] ) ? $titles[ $step ] : $step;
		?>
		<div class="wrap ch-admin-page ch-setup-flow">

			<!-- Gradient page header -->
			<div class="ch-page-header">
				<span class="dashicons dashicons-businessman"></span>
				<div class="ch-page-header__text">
					<h1><?php echo esc_html( sprintf(
						/* translators: 1: current step number, 2: total steps */
						__( 'Client Handoff Setup — Step %1$d of %2$d: %3$s', 'client-handoff' ),
						$step_index,
						$step_count,
						$title
					) ); ?></h1>
					<p><?php echo esc_html( $title ); ?></p>
				</div>
			</div>

			<!-- Progress stepper -->
			<div class="ch-stepper">
				<?php foreach ( self::STEPS as $s ) :
					$s_index  = $this->get_step_index( $s );
					$is_done   = $s_index < $step_index;
					$is_active = $s === $step;
					$s_titles  = array(
						'roles'        => __( 'Roles', 'client-handoff' ),
						'dashboard'    => __( 'Dashboard', 'client-handoff' ),
						'restrictions' => __( 'Restrictions', 'client-handoff' ),
						'activate'     => __( 'Activate', 'client-handoff' ),
					);
					$s_label = isset( $s_titles[ $s ] ) ? $s_titles[ $s ] : ucfirst( $s );
					$css_class = 'ch-stepper__step';
					if ( $is_done )   { $css_class .= ' ch-stepper__step--done'; }
					if ( $is_active ) { $css_class .= ' ch-stepper__step--active'; }
					?>
					<div class="<?php echo esc_attr( $css_class ); ?>">
						<div class="ch-stepper__dot">
							<?php if ( $is_done ) : ?>
								<span class="dashicons dashicons-yes" style="font-size:14px;width:14px;height:14px;"></span>
							<?php else : ?>
								<?php echo esc_html( $s_index ); ?>
							<?php endif; ?>
						</div>
						<span class="ch-stepper__label"><?php echo esc_html( $s_label ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>

			<!-- Step content card -->
			<?php if ( 'activate' === $step ) : ?>
				<?php $this->render_activate_step( $done_url ); ?>
			<?php else : ?>
				<?php $this->render_settings_step( $step, $next_url ); ?>
			<?php endif; ?>

		</div>
		<?php
	}

	/**
	 * Render a settings step (roles, dashboard, restrictions).
	 *
	 * Outputs a Settings API form. The _wp_http_referer hidden field is
	 * overridden (by outputting a second input AFTER settings_fields()) so
	 * options.php redirects to $next_url rather than the current page.
	 *
	 * @param string $step     One of 'roles', 'dashboard', 'restrictions'.
	 * @param string $next_url URL of the next step (or standard page).
	 */
	private function render_settings_step( string $step, string $next_url ): void {
		$page_slug = 'client-handoff-' . $step;
		?>
		<div class="ch-card ch-setup-form-card">
			<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
				<?php settings_fields( CH_Core::OPTION_CONFIG ); ?>
				<input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( $next_url ); ?>">
				<?php do_settings_sections( $page_slug ); ?>
				<?php submit_button( __( 'Save &amp; Continue', 'client-handoff' ) ); ?>
			</form>
			<a class="ch-skip-link" href="<?php echo esc_url( $next_url ); ?>">
				<span class="dashicons dashicons-arrow-right-alt2" style="font-size:14px;width:14px;height:14px;margin-top:2px;"></span>
				<?php echo esc_html( __( 'Skip this step', 'client-handoff' ) ); ?>
			</a>
		</div>
		<?php
	}

	/**
	 * Render the activate step.
	 *
	 * Outputs a config summary followed by two forms:
	 *   1. Activate form: posts _ch_setup_complete=1 → enables handoff +
	 *      marks setup complete.
	 *   2. Dismiss form: posts _ch_setup_dismiss=1 → marks setup complete
	 *      without enabling handoff mode.
	 *
	 * @param string $done_url URL to redirect to after either form submit.
	 */
	private function render_activate_step( string $done_url ): void {
		$protected_roles   = (array) $this->core->get( 'protected_roles' );
		$admin_roles       = (array) $this->core->get( 'admin_roles' );
		$dashboard_config  = $this->core->get( 'dashboard' );
		$enforcement       = $this->core->get( 'enforcement' );
		$dashboard_enabled = ! empty( $dashboard_config['enabled'] );
		$blocked_caps      = isset( $enforcement['blocked_caps'] )
			? (array) $enforcement['blocked_caps'] : array();
		$protected_plugins = isset( $enforcement['protected_plugins'] )
			? (array) $enforcement['protected_plugins'] : array();
		?>
		<div class="ch-card">
			<h2 class="ch-card__title">
				<span class="dashicons dashicons-chart-bar"></span>
				<?php echo esc_html( __( 'Configuration Summary', 'client-handoff' ) ); ?>
			</h2>
			<p class="ch-card__description"><?php echo esc_html( __( 'Review your settings before activating handoff mode.', 'client-handoff' ) ); ?></p>

			<!-- Stat grid cards (counts) -->
			<div class="ch-stat-grid">
				<div class="ch-stat-card">
					<span class="ch-stat-card__value"><?php echo esc_html( count( $protected_roles ) ); ?></span>
					<span class="ch-stat-card__label"><?php echo esc_html( __( 'Protected roles:', 'client-handoff' ) ); ?></span>
				</div>
				<div class="ch-stat-card">
					<span class="ch-stat-card__value"><?php echo esc_html( count( $admin_roles ) ); ?></span>
					<span class="ch-stat-card__label"><?php echo esc_html( __( 'Admin roles:', 'client-handoff' ) ); ?></span>
				</div>
				<div class="ch-stat-card">
					<span class="ch-stat-card__value"><?php echo esc_html( count( $blocked_caps ) ); ?></span>
					<span class="ch-stat-card__label"><?php echo esc_html( __( 'Blocked capabilities:', 'client-handoff' ) ); ?></span>
				</div>
				<div class="ch-stat-card">
					<span class="ch-stat-card__value"><?php echo esc_html( count( $protected_plugins ) ); ?></span>
					<span class="ch-stat-card__label"><?php echo esc_html( __( 'Protected plugins:', 'client-handoff' ) ); ?></span>
				</div>
				<div class="ch-stat-card">
					<span class="ch-stat-card__value"><?php echo $dashboard_enabled ? '✓' : '—'; ?></span>
					<span class="ch-stat-card__label"><?php echo esc_html( __( 'Client dashboard:', 'client-handoff' ) ); ?></span>
				</div>
			</div>

			<hr class="ch-card__divider">

			<!-- Activate form -->
			<div class="ch-activate-actions">
				<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
					<?php settings_fields( CH_Core::OPTION_CONFIG ); ?>
					<input type="hidden"
					       name="<?php echo esc_attr( CH_Core::OPTION_CONFIG ); ?>[_ch_setup_complete]"
					       value="1">
					<input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( $done_url ); ?>">
					<?php submit_button( __( 'Activate Handoff Mode', 'client-handoff' ), 'primary large', 'submit', false ); ?>
				</form>

				<!-- Dismiss form (mark complete without enabling) -->
				<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
					<?php settings_fields( CH_Core::OPTION_CONFIG ); ?>
					<input type="hidden"
					       name="<?php echo esc_attr( CH_Core::OPTION_CONFIG ); ?>[_ch_setup_dismiss]"
					       value="1">
					<input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( $done_url ); ?>">
					<?php submit_button( __( 'Skip without activating', 'client-handoff' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>
		</div>
		<?php
	}
}
