<?php
/**
 * Static-content regression tests for uninstall.php (U1–U4).
 *
 * APPROACH — static-content testing
 *
 * These tests read uninstall.php via file_get_contents and assert that specific
 * delete_ and wp_clear_scheduled_hook call strings are present. The file is NOT
 * loaded or executed — doing so would require the WP_UNINSTALL_PLUGIN constant
 * and WordPress's uninstall context, which the WP_Mock harness does not provide.
 *
 * MUTATION DETECTION
 *
 * Remove any delete_ or wp_clear_scheduled_hook line from uninstall.php and
 * the corresponding U-test fails. This makes it hard to accidentally drop
 * cleanup for a data surface without the test suite catching it.
 *
 * DATA SURFACES COVERED
 *
 *   Options:    zsch_config, zsch_activity_log,
 *               zsch_checklist
 *   Cron:       zsch_prune_log
 *   Transients: zsch_network_activation_notice
 *   Guard:      WP_UNINSTALL_PLUGIN constant check + exit
 *
 * @package ClientHandoff
 */

use WP_Mock\Tools\TestCase;

/**
 * Class UninstallTest
 */
class UninstallTest extends TestCase {

	/** @var string Absolute path to the file under test. */
	private static $file;

	/** @var string Cached file contents (read once, reused across tests). */
	private static $contents;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::$file     = dirname( __DIR__, 2 ) . '/uninstall.php';
		self::$contents = file_get_contents( self::$file );
	}

	// =========================================================================
	// U1 — Options
	// =========================================================================

	/**
	 * U1 — uninstall.php deletes all three known wp_options rows.
	 *
	 * Each option corresponds to a constant on ZSCH_Core. The values are
	 * hard-coded in uninstall.php per Constraint 11.6 (no class dependency).
	 */
	public function test_uninstall_php_deletes_all_known_options() {
		$surfaces = array(
			"delete_option( 'zsch_config' )",
			"delete_option( 'zsch_activity_log' )",
			"delete_option( 'zsch_checklist' )",
		);

		foreach ( $surfaces as $call ) {
			$this->assertStringContainsString(
				$call,
				self::$contents,
				"uninstall.php must contain: $call"
			);
		}
	}

	// =========================================================================
	// U2 — Cron
	// =========================================================================

	/**
	 * U2 — uninstall.php clears the known cron hook.
	 *
	 * ZSCH_Core::on_activation() schedules 'zsch_prune_log' via
	 * wp_schedule_event. This hook must be cleared on uninstall so WordPress
	 * does not fire a dangling cron event for a plugin that is no longer active.
	 */
	public function test_uninstall_php_clears_known_cron_hooks() {
		$this->assertStringContainsString(
			"wp_clear_scheduled_hook( 'zsch_prune_log' )",
			self::$contents,
			"uninstall.php must clear the 'zsch_prune_log' cron hook"
		);
	}

	// =========================================================================
	// U3 — Transients
	// =========================================================================

	/**
	 * U3 — uninstall.php deletes the network-activation-notice transient.
	 *
	 * ZSCH_Core::on_activation() sets 'zsch_network_activation_notice' (1-minute
	 * TTL) when network-wide activation is attempted on multisite. The transient
	 * is almost certainly expired by uninstall time, but correct-by-construction
	 * cleanup is required.
	 */
	public function test_uninstall_php_deletes_known_transients() {
		$this->assertStringContainsString(
			"delete_transient( 'zsch_network_activation_notice' )",
			self::$contents,
			"uninstall.php must delete the 'zsch_network_activation_notice' transient"
		);
	}

	// =========================================================================
	// U4 — Guard
	// =========================================================================

	/**
	 * U4 — uninstall.php guards against direct execution.
	 *
	 * The WordPress plugin handbook requires uninstall.php to begin with a
	 * WP_UNINSTALL_PLUGIN constant check followed by exit. Without this guard,
	 * a direct HTTP request to the file would run the delete_option calls
	 * without any authentication.
	 */
	public function test_uninstall_php_guards_against_direct_execution() {
		$this->assertStringContainsString(
			"if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) )",
			self::$contents,
			"uninstall.php must check WP_UNINSTALL_PLUGIN before executing"
		);

		// The exit call must follow the guard — verify it is present anywhere
		// in the file (it is on the very next line in the actual file).
		$this->assertStringContainsString(
			'exit;',
			self::$contents,
			"uninstall.php must contain 'exit;' to halt direct execution"
		);
	}
}
