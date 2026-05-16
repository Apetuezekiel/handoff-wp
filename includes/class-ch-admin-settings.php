<?php
/**
 * Admin settings page: top-level menu, nav-tab framework, settings registration.
 *
 * PAGE STRUCTURE
 *
 * A single top-level menu page is registered at the 'client-handoff' slug. The
 * page is rendered with a standard nav-tab wrapper. Six tabs are declared in
 * the TABS constant; Roles and Restrictions are fully wired — the other four
 * render a "coming soon" placeholder.
 *
 * SETTINGS API FLOW
 *
 * register_settings() ties the 'client_handoff_config' settings group to the
 * 'client_handoff_config' option with this class's sanitize() method as the
 * sanitize_callback. Forms submit to options.php. WordPress calls sanitize(),
 * which dispatches to per-tab helpers:
 *
 *   sanitize_roles()        → protected_roles, admin_roles
 *   sanitize_restrictions() → enforcement.blocked_caps, enforcement.protected_plugins
 *
 * Each helper returns a partial array. sanitize() merges all partials and
 * calls merge_into_current(), which overlays them onto the full saved option —
 * preserving fields from tabs not represented in the current POST.
 *
 * SCREEN ID
 *
 * add_menu_page() with slug 'client-handoff' produces the screen ID
 * 'toplevel_page_client-handoff', which must match
 * CH_Enforcer::SETTINGS_SCREEN_ID so developers are never blocked from this
 * page by the screen guard.
 *
 * @package ClientHandoff
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CH_Admin_Settings
 */
class CH_Admin_Settings {

	/** @var string[] Ordered list of nav-tab slugs. */
	const TABS = array( 'roles', 'dashboard', 'restrictions', 'help-notes', 'checklist', 'logging' );

	/** @var CH_Core */
	private $core;

	/**
	 * @var CH_Setup_Flow|null  Null when the setup flow class is unavailable
	 *                           or when constructing without a flow instance
	 *                           (e.g. in unit tests that pre-date the setup
	 *                           flow). Optional to avoid requiring test updates
	 *                           across 30+ existing test setups.
	 */
	private $setup_flow;

	/**
	 * @param CH_Core           $core
	 * @param CH_Setup_Flow|null $setup_flow  Optional; null → tabbed page always shown.
	 */
	public function __construct( $core, $setup_flow = null ) {
		$this->core       = $core;
		$this->setup_flow = $setup_flow;
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	// -------------------------------------------------------------------------
	// Page and settings registration
	// -------------------------------------------------------------------------

	/**
	 * Register the top-level admin menu page.
	 *
	 * Slug 'client-handoff' → screen ID 'toplevel_page_client-handoff'.
	 */
	public function register_page() {
		add_menu_page(
			__( 'Client Handoff', 'client-handoff' ),
			__( 'Client Handoff', 'client-handoff' ),
			'manage_options',
			'client-handoff',
			array( $this, 'render_page' ),
			'dashicons-businessman',
			80
		);
	}

	/**
	 * Register settings group, section, and fields.
	 *
	 * register_setting() is unconditional — the option registration is global,
	 * not tab-specific. Section and field registrations are tab-gated so each
	 * tab only registers its own fields.
	 */
	public function register_settings() {
		register_setting(
			CH_Core::OPTION_CONFIG,
			CH_Core::OPTION_CONFIG,
			array( 'sanitize_callback' => array( $this, 'sanitize' ) )
		);

		if ( 'roles' === $this->get_active_tab() ) {
			add_settings_section(
				'ch_roles_section',
				__( 'Role Configuration', 'client-handoff' ),
				null,
				'client-handoff-roles'
			);

			add_settings_field(
				'ch_protected_roles',
				__( 'Protected Roles', 'client-handoff' ),
				array( $this, 'render_protected_roles_field' ),
				'client-handoff-roles',
				'ch_roles_section'
			);

			add_settings_field(
				'ch_admin_roles',
				__( 'Admin Roles', 'client-handoff' ),
				array( $this, 'render_admin_roles_field' ),
				'client-handoff-roles',
				'ch_roles_section'
			);
		}

		if ( 'restrictions' === $this->get_active_tab() ) {
			add_settings_section(
				'ch_restrictions_section',
				__( 'Restrictions', 'client-handoff' ),
				null,
				'client-handoff-restrictions'
			);

			add_settings_field(
				'ch_blocked_caps',
				__( 'Blocked Capabilities', 'client-handoff' ),
				array( $this, 'render_blocked_caps_field' ),
				'client-handoff-restrictions',
				'ch_restrictions_section'
			);

			add_settings_field(
				'ch_protected_plugins',
				__( 'Protected Plugins', 'client-handoff' ),
				array( $this, 'render_protected_plugins_field' ),
				'client-handoff-restrictions',
				'ch_restrictions_section'
			);
		}

		if ( 'dashboard' === $this->get_active_tab() ) {
			add_settings_section(
				'ch_dashboard_section',
				__( 'Client Dashboard', 'client-handoff' ),
				null,
				'client-handoff-dashboard'
			);

			add_settings_field(
				'ch_dashboard_enabled',
				__( 'Enable Dashboard', 'client-handoff' ),
				array( $this, 'render_dashboard_enabled_field' ),
				'client-handoff-dashboard',
				'ch_dashboard_section'
			);

			add_settings_field(
				'ch_welcome_message',
				__( 'Welcome Message', 'client-handoff' ),
				array( $this, 'render_welcome_message_field' ),
				'client-handoff-dashboard',
				'ch_dashboard_section'
			);

			add_settings_field(
				'ch_quick_links',
				__( 'Quick Links', 'client-handoff' ),
				array( $this, 'render_quick_links_field' ),
				'client-handoff-dashboard',
				'ch_dashboard_section'
			);

			add_settings_field(
				'ch_developer_contact',
				__( 'Developer Contact', 'client-handoff' ),
				array( $this, 'render_developer_contact_field' ),
				'client-handoff-dashboard',
				'ch_dashboard_section'
			);

			add_settings_field(
				'ch_show_site_status',
				__( 'Show Site Status', 'client-handoff' ),
				array( $this, 'render_show_site_status_field' ),
				'client-handoff-dashboard',
				'ch_dashboard_section'
			);
		}
	}

	// -------------------------------------------------------------------------
	// Tab resolution
	// -------------------------------------------------------------------------

	/**
	 * Return the active tab slug, defaulting to 'roles'.
	 *
	 * When the setup flow is active, ch_step takes priority over the tab
	 * parameter for the three configurable steps (roles, dashboard,
	 * restrictions). This ensures register_settings()'s tab-gated section
	 * and field registrations fire for the correct step during the flow.
	 *
	 * 'activate' is intentionally excluded: it has no Settings API sections
	 * or fields — its form uses custom hidden inputs only.
	 *
	 * sanitize_key() strips anything outside [a-z0-9_-]; the allowlist check
	 * then rejects anything not in TABS (or the ch_step allowlist).
	 *
	 * @return string
	 */
	public function get_active_tab() {
		if ( isset( $_GET['ch_step'] ) ) {
			$step = sanitize_key( $_GET['ch_step'] );
			if ( in_array( $step, array( 'roles', 'dashboard', 'restrictions' ), true ) ) {
				return $step;
			}
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : '';
		return in_array( $tab, self::TABS, true ) ? $tab : 'roles';
	}

	// -------------------------------------------------------------------------
	// Rendering
	// -------------------------------------------------------------------------

	/**
	 * Render the full settings page (nav-tab wrapper + active-tab content).
	 *
	 * The capability check here is belt-and-braces: add_menu_page() already
	 * enforces 'manage_options' at the menu registration level, but direct URL
	 * access warrants an explicit guard. The return after wp_die() is dead code
	 * in production (wp_die halts) but keeps the method unit-testable.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'client-handoff' ) );
			return;
		}

		// Delegate to setup flow when first-run experience is active.
		if ( null !== $this->setup_flow && $this->setup_flow->should_show() ) {
			$this->setup_flow->render( $this );
			return;
		}

		$active = $this->get_active_tab();
		?>
		<div class="wrap">
			<h1><?php echo esc_html( __( 'Client Handoff', 'client-handoff' ) ); ?></h1>
			<nav class="nav-tab-wrapper">
				<?php foreach ( self::TABS as $tab ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=client-handoff&tab=' . $tab ) ); ?>"
					   class="nav-tab<?php echo $active === $tab ? ' nav-tab-active' : ''; ?>">
						<?php echo esc_html( ucfirst( str_replace( '-', ' ', $tab ) ) ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<form method="post" action="options.php">
				<?php
				if ( 'roles' === $active ) {
					settings_fields( CH_Core::OPTION_CONFIG );
					do_settings_sections( 'client-handoff-roles' );
					submit_button();
				} elseif ( 'restrictions' === $active ) {
					settings_fields( CH_Core::OPTION_CONFIG );
					do_settings_sections( 'client-handoff-restrictions' );
					submit_button();
				} elseif ( 'dashboard' === $active ) {
					settings_fields( CH_Core::OPTION_CONFIG );
					do_settings_sections( 'client-handoff-dashboard' );
					submit_button();
				} else {
					echo '<p>' . esc_html( __( 'This tab is coming soon.', 'client-handoff' ) ) . '</p>';
				}
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render checkboxes for protected_roles.
	 */
	public function render_protected_roles_field() {
		$saved = (array) $this->core->get( 'protected_roles' );
		$roles = wp_roles()->get_names();
		foreach ( $roles as $slug => $name ) {
			printf(
				'<label><input type="checkbox" name="%s[protected_roles][]" value="%s"%s> %s</label><br>',
				esc_attr( CH_Core::OPTION_CONFIG ),
				esc_attr( $slug ),
				in_array( $slug, $saved, true ) ? ' checked' : '',
				esc_html( $name )
			);
		}
	}

	/**
	 * Render checkboxes for admin_roles.
	 */
	public function render_admin_roles_field() {
		$saved = (array) $this->core->get( 'admin_roles' );
		$roles = wp_roles()->get_names();
		foreach ( $roles as $slug => $name ) {
			printf(
				'<label><input type="checkbox" name="%s[admin_roles][]" value="%s"%s> %s</label><br>',
				esc_attr( CH_Core::OPTION_CONFIG ),
				esc_attr( $slug ),
				in_array( $slug, $saved, true ) ? ' checked' : '',
				esc_html( $name )
			);
		}
	}

	/**
	 * Render checkboxes for enforcement.blocked_caps.
	 *
	 * Only the eleven capabilities in CH_Core::DEFAULTS['enforcement']['blocked_caps']
	 * are offered. Developers may untick any default; they cannot add custom caps
	 * via this UI (post-MVP).
	 */
	public function render_blocked_caps_field() {
		$enforcement = $this->core->get( 'enforcement' );
		$saved       = isset( $enforcement['blocked_caps'] ) ? (array) $enforcement['blocked_caps'] : array();
		$defaults    = CH_Core::DEFAULTS['enforcement']['blocked_caps'];

		foreach ( $defaults as $cap ) {
			printf(
				'<label><input type="checkbox" name="%s[enforcement][blocked_caps][]" value="%s"%s> <code>%s</code></label><br>',
				esc_attr( CH_Core::OPTION_CONFIG ),
				esc_attr( $cap ),
				in_array( $cap, $saved, true ) ? ' checked' : '',
				esc_html( $cap )
			);
		}
	}

	/**
	 * Render checkboxes for enforcement.protected_plugins.
	 *
	 * Iterates the live get_plugins() result; basenames not installed are
	 * never shown (and would be dropped by sanitize_restrictions anyway).
	 */
	public function render_protected_plugins_field() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$enforcement = $this->core->get( 'enforcement' );
		$saved       = isset( $enforcement['protected_plugins'] )
			? (array) $enforcement['protected_plugins'] : array();
		$plugins     = get_plugins();

		if ( empty( $plugins ) ) {
			echo '<p>' . esc_html( __( 'No plugins installed.', 'client-handoff' ) ) . '</p>';
			return;
		}

		foreach ( $plugins as $basename => $data ) {
			$name = isset( $data['Name'] ) ? $data['Name'] : $basename;
			printf(
				'<label><input type="checkbox" name="%s[enforcement][protected_plugins][]" value="%s"%s> %s</label><br>',
				esc_attr( CH_Core::OPTION_CONFIG ),
				esc_attr( $basename ),
				in_array( $basename, $saved, true ) ? ' checked' : '',
				esc_html( $name )
			);
		}
	}

	// -------------------------------------------------------------------------
	// Sanitize callback + per-tab helpers
	// -------------------------------------------------------------------------

	/**
	 * Sanitize and merge any tab's form submission.
	 *
	 * Detects which tab's fields are present in $input and dispatches to the
	 * appropriate per-tab helper. Each helper returns a partial that is merged
	 * into the running $partial before merge_into_current() is called.
	 *
	 * This dispatch approach means adding a new tab requires only a new private
	 * sanitize_X() method and a detection clause here — not a growing monolith.
	 *
	 * @param mixed $input Raw $_POST['client_handoff_config'] value.
	 * @return array Full merged config to be saved.
	 */
	public function sanitize( $input ) {
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$partial = array();

		// Roles tab fields.
		if ( isset( $input['protected_roles'] ) || isset( $input['admin_roles'] ) ) {
			$partial = array_merge( $partial, $this->sanitize_roles( $input ) );
		}

		// Restrictions tab fields.
		if ( isset( $input['enforcement'] ) && is_array( $input['enforcement'] ) ) {
			$partial['enforcement'] = $this->sanitize_restrictions( $input['enforcement'] );
		}

		// Dashboard tab fields.
		if ( isset( $input['dashboard'] ) && is_array( $input['dashboard'] ) ) {
			$partial['dashboard'] = $this->sanitize_dashboard( $input['dashboard'] );
		}

		// Activate-step marker: enable handoff AND mark setup complete.
		// This clause is mutually exclusive with field-data clauses — the
		// activate form carries no field data, only the control marker.
		if ( ! empty( $input['_ch_setup_complete'] ) ) {
			$partial['enabled']         = true;
			$partial['setup_completed'] = true;
		}

		// Dismiss marker: mark setup complete without enabling handoff.
		// 'enabled' is intentionally omitted — leaving it to merge from the
		// saved value preserves whatever the developer set. Matches the same
		// no-enabled rule the Roles tab follows (see sanitize_roles docblock).
		if ( ! empty( $input['_ch_setup_dismiss'] ) ) {
			$partial['setup_completed'] = true;
		}

		return $this->core->merge_into_current( $partial );
	}

	/**
	 * Sanitize Roles-tab fields: protected_roles and admin_roles.
	 *
	 * Returns ['protected_roles' => [...], 'admin_roles' => [...]].
	 * Role slugs not present in the live wp_roles() registry are dropped.
	 *
	 * 'enabled' is intentionally absent — the Roles form has no enabled
	 * checkbox; including it would silently deactivate the plugin on every save.
	 *
	 * @param array $input Raw form input.
	 * @return array Partial with protected_roles and admin_roles.
	 */
	private function sanitize_roles( array $input ): array {
		$valid_roles   = array_keys( wp_roles()->get_names() );
		$protected_raw = isset( $input['protected_roles'] ) && is_array( $input['protected_roles'] )
			? $input['protected_roles'] : array();
		$admin_raw     = isset( $input['admin_roles'] ) && is_array( $input['admin_roles'] )
			? $input['admin_roles'] : array();

		return array(
			'protected_roles' => array_values( array_filter(
				array_map( 'sanitize_key', $protected_raw ),
				static function ( $slug ) use ( $valid_roles ) {
					return in_array( $slug, $valid_roles, true );
				}
			) ),
			'admin_roles'     => array_values( array_filter(
				array_map( 'sanitize_key', $admin_raw ),
				static function ( $slug ) use ( $valid_roles ) {
					return in_array( $slug, $valid_roles, true );
				}
			) ),
		);
	}

	/**
	 * Sanitize Restrictions-tab fields: blocked_caps and protected_plugins.
	 *
	 * Returns ['blocked_caps' => [...], 'protected_plugins' => [...]] WITHOUT
	 * the outer 'enforcement' wrapper — sanitize() wraps it.
	 *
	 * BLOCKED CAPS VALIDATION
	 * Source of truth: CH_Core::DEFAULTS['enforcement']['blocked_caps'] (the
	 * eleven core capabilities). Any submitted cap not in that list is dropped.
	 * Developers can untick defaults; they cannot add caps via this UI (post-MVP).
	 * This prevents arbitrary capability names being written to the DB via a
	 * crafted POST.
	 *
	 * PROTECTED PLUGINS VALIDATION
	 * Source of truth: get_plugins() at runtime. Basenames absent from the live
	 * result are silently dropped (guards against stale/crafted POSTs naming
	 * plugins that are no longer installed).
	 * get_plugins() is not autoloaded — a require_once guard is applied before
	 * the call. sanitize_text_field() is used (not sanitize_key()) because
	 * basenames contain slashes and dots that sanitize_key() would strip.
	 *
	 * @param array $enforcement_input Raw enforcement sub-array from form input.
	 * @return array Partial enforcement subarray (no 'enforcement' wrapper).
	 */
	private function sanitize_restrictions( array $enforcement_input ): array {
		// ---- blocked_caps -------------------------------------------------------
		$allowed_caps = CH_Core::DEFAULTS['enforcement']['blocked_caps'];
		$caps_raw     = isset( $enforcement_input['blocked_caps'] ) && is_array( $enforcement_input['blocked_caps'] )
			? $enforcement_input['blocked_caps'] : array();

		$blocked_caps = array_values( array_filter(
			array_map( 'sanitize_key', $caps_raw ),
			static function ( $cap ) use ( $allowed_caps ) {
				return in_array( $cap, $allowed_caps, true );
			}
		) );

		// ---- protected_plugins --------------------------------------------------
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed_basenames  = array_keys( get_plugins() );
		$plugins_raw          = isset( $enforcement_input['protected_plugins'] ) && is_array( $enforcement_input['protected_plugins'] )
			? $enforcement_input['protected_plugins'] : array();

		$protected_plugins = array_values( array_filter(
			array_map( 'sanitize_text_field', $plugins_raw ),
			static function ( $basename ) use ( $installed_basenames ) {
				return in_array( $basename, $installed_basenames, true );
			}
		) );

		return array(
			'blocked_caps'      => $blocked_caps,
			'protected_plugins' => $protected_plugins,
		);
	}

	/**
	 * Sanitize Dashboard-tab fields.
	 *
	 * Returns the full 'dashboard' sub-array without any outer wrapper —
	 * sanitize() assigns it to $partial['dashboard'] directly.
	 *
	 * ENABLED / SHOW_SITE_STATUS
	 * Both are checkbox booleans. Unchecked checkboxes are absent from the
	 * POST — ! empty() correctly maps presence to true and absence to false.
	 *
	 * WELCOME_MESSAGE
	 * wp_kses_post() is used deliberately instead of sanitize_text_field().
	 * The field is intended to hold basic HTML (headings, links, emphasis).
	 * sanitize_text_field() strips all tags, which would destroy that HTML.
	 *
	 * QUICK_LINKS — FIXED-SLOT REPEATER
	 * The form exposes five fixed rows. On save, rows where BOTH label AND
	 * url are empty strings are dropped. Rows where only one of label/url is
	 * set are kept — the developer controls which fields matter. Order is
	 * preserved from the row index. Dynamic add/remove is post-MVP: the
	 * fixed-slot model is simpler to implement without JavaScript and
	 * sufficient for the five typical quick actions a client dashboard needs.
	 *
	 * DEVELOPER_CONTACT
	 * sanitize_email() rejects malformed email addresses by returning ''.
	 * esc_url_raw() is used (not esc_url) because esc_url adds additional
	 * HTML-encoding unsuitable for database storage.
	 * All three keys are always returned (empty-string default) so the saved
	 * shape never has missing keys.
	 *
	 * @param array $dashboard_input Raw dashboard sub-array from form input.
	 * @return array Sanitized dashboard sub-array (no outer 'dashboard' key).
	 */
	private function sanitize_dashboard( array $dashboard_input ): array {
		// ---- enabled ------------------------------------------------------------
		$enabled = ! empty( $dashboard_input['enabled'] );

		// ---- welcome_message ----------------------------------------------------
		$welcome_raw     = isset( $dashboard_input['welcome_message'] )
			? (string) $dashboard_input['welcome_message'] : '';
		$welcome_message = wp_kses_post( $welcome_raw );

		// ---- quick_links --------------------------------------------------------
		$links_raw  = isset( $dashboard_input['quick_links'] ) && is_array( $dashboard_input['quick_links'] )
			? $dashboard_input['quick_links'] : array();
		$quick_links = array();

		foreach ( $links_raw as $row ) {
			$label = sanitize_text_field( isset( $row['label'] ) ? (string) $row['label'] : '' );
			$url   = esc_url_raw( isset( $row['url'] )   ? (string) $row['url']   : '' );
			$icon  = sanitize_html_class( isset( $row['icon'] )  ? (string) $row['icon']  : '' );

			// Drop rows where both label and url are empty; icon alone is not
			// enough to constitute an actionable quick link.
			if ( '' === $label && '' === $url ) {
				continue;
			}

			$quick_links[] = array(
				'label' => $label,
				'url'   => $url,
				'icon'  => $icon,
			);
		}

		$quick_links = array_values( $quick_links );

		// ---- developer_contact --------------------------------------------------
		$contact_raw = isset( $dashboard_input['developer_contact'] ) && is_array( $dashboard_input['developer_contact'] )
			? $dashboard_input['developer_contact'] : array();

		$developer_contact = array(
			'name'  => sanitize_text_field( isset( $contact_raw['name'] )  ? (string) $contact_raw['name']  : '' ),
			'email' => sanitize_email(      isset( $contact_raw['email'] ) ? (string) $contact_raw['email'] : '' ),
			'url'   => esc_url_raw(         isset( $contact_raw['url'] )   ? (string) $contact_raw['url']   : '' ),
		);

		// ---- show_site_status ---------------------------------------------------
		$show_site_status = ! empty( $dashboard_input['show_site_status'] );

		return array(
			'enabled'           => $enabled,
			'welcome_message'   => $welcome_message,
			'quick_links'       => $quick_links,
			'developer_contact' => $developer_contact,
			'show_site_status'  => $show_site_status,
		);
	}

	// -------------------------------------------------------------------------
	// Dashboard tab field renderers
	// -------------------------------------------------------------------------

	/**
	 * Render the dashboard.enabled checkbox.
	 */
	public function render_dashboard_enabled_field() {
		$dashboard = $this->core->get( 'dashboard' );
		$checked   = ! empty( $dashboard['enabled'] );
		printf(
			'<label><input type="checkbox" name="%s[dashboard][enabled]" value="1"%s> %s</label>',
			esc_attr( CH_Core::OPTION_CONFIG ),
			$checked ? ' checked' : '',
			esc_html( __( 'Replace the WordPress dashboard with the client dashboard widget', 'client-handoff' ) )
		);
	}

	/**
	 * Render the dashboard.welcome_message textarea.
	 *
	 * Basic HTML is intentional — the textarea is rendered as-is; the user
	 * composes HTML and the sanitize callback applies wp_kses_post on save.
	 */
	public function render_welcome_message_field() {
		$dashboard = $this->core->get( 'dashboard' );
		$value     = isset( $dashboard['welcome_message'] ) ? $dashboard['welcome_message'] : '';
		printf(
			'<textarea name="%s[dashboard][welcome_message]" rows="5" cols="50">%s</textarea>
			<p class="description">%s</p>',
			esc_attr( CH_Core::OPTION_CONFIG ),
			esc_textarea( $value ),
			esc_html( __( 'Basic HTML allowed (paragraphs, links, emphasis).', 'client-handoff' ) )
		);
	}

	/**
	 * Render five fixed-slot rows for dashboard.quick_links.
	 *
	 * Fixed-slot repeater (five rows) — dynamic add/remove is post-MVP.
	 * Each row has label, URL, and icon (Dashicons class) inputs. Saved values
	 * populate rows in order; remaining rows show empty inputs.
	 */
	public function render_quick_links_field() {
		$dashboard   = $this->core->get( 'dashboard' );
		$saved_links = isset( $dashboard['quick_links'] ) && is_array( $dashboard['quick_links'] )
			? $dashboard['quick_links'] : array();

		echo '<table class="widefat" style="max-width:600px">';
		echo '<thead><tr>';
		echo '<th>' . esc_html( __( 'Label', 'client-handoff' ) ) . '</th>';
		echo '<th>' . esc_html( __( 'URL', 'client-handoff' ) ) . '</th>';
		echo '<th>' . esc_html( __( 'Icon (Dashicons class)', 'client-handoff' ) ) . '</th>';
		echo '</tr></thead><tbody>';

		for ( $i = 0; $i < 5; $i++ ) {
			$row   = isset( $saved_links[ $i ] ) ? $saved_links[ $i ] : array();
			$label = isset( $row['label'] ) ? $row['label'] : '';
			$url   = isset( $row['url'] )   ? $row['url']   : '';
			$icon  = isset( $row['icon'] )  ? $row['icon']  : '';
			printf(
				'<tr>
					<td><input type="text" name="%1$s[dashboard][quick_links][%2$d][label]" value="%3$s" class="regular-text"></td>
					<td><input type="text" name="%1$s[dashboard][quick_links][%2$d][url]"   value="%4$s" class="regular-text"></td>
					<td><input type="text" name="%1$s[dashboard][quick_links][%2$d][icon]"  value="%5$s" class="regular-text" placeholder="dashicons-admin-generic"></td>
				</tr>',
				esc_attr( CH_Core::OPTION_CONFIG ),
				$i,
				esc_attr( $label ),
				esc_attr( $url ),
				esc_attr( $icon )
			);
		}

		echo '</tbody></table>';
		echo '<p class="description">' . esc_html( __( 'Leave label and URL blank to omit a row. Dynamic add/remove is post-MVP.', 'client-handoff' ) ) . '</p>';
	}

	/**
	 * Render the dashboard.developer_contact group of three text inputs.
	 */
	public function render_developer_contact_field() {
		$dashboard = $this->core->get( 'dashboard' );
		$contact   = isset( $dashboard['developer_contact'] ) && is_array( $dashboard['developer_contact'] )
			? $dashboard['developer_contact'] : array();

		$name  = isset( $contact['name'] )  ? $contact['name']  : '';
		$email = isset( $contact['email'] ) ? $contact['email'] : '';
		$url   = isset( $contact['url'] )   ? $contact['url']   : '';

		printf(
			'<p><label>%s<br><input type="text"  name="%s[dashboard][developer_contact][name]"  value="%s" class="regular-text"></label></p>',
			esc_html( __( 'Name', 'client-handoff' ) ),
			esc_attr( CH_Core::OPTION_CONFIG ),
			esc_attr( $name )
		);
		printf(
			'<p><label>%s<br><input type="email" name="%s[dashboard][developer_contact][email]" value="%s" class="regular-text"></label></p>',
			esc_html( __( 'Email', 'client-handoff' ) ),
			esc_attr( CH_Core::OPTION_CONFIG ),
			esc_attr( $email )
		);
		printf(
			'<p><label>%s<br><input type="url"   name="%s[dashboard][developer_contact][url]"   value="%s" class="regular-text"></label></p>',
			esc_html( __( 'Website URL', 'client-handoff' ) ),
			esc_attr( CH_Core::OPTION_CONFIG ),
			esc_attr( $url )
		);
	}

	/**
	 * Render the dashboard.show_site_status checkbox.
	 */
	public function render_show_site_status_field() {
		$dashboard = $this->core->get( 'dashboard' );
		$checked   = ! empty( $dashboard['show_site_status'] );
		printf(
			'<label><input type="checkbox" name="%s[dashboard][show_site_status]" value="1"%s> %s</label>',
			esc_attr( CH_Core::OPTION_CONFIG ),
			$checked ? ' checked' : '',
			esc_html( __( 'Show WordPress version, SSL status, and pending plugin updates', 'client-handoff' ) )
		);
	}
}
