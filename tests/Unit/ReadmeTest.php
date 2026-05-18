<?php
/**
 * Static-content regression tests for readme.txt (T1-T3).
 *
 * APPROACH — static-content testing
 *
 * These tests read readme.txt (and zicstack-client-handoff.php for T3) via
 * file_get_contents. Neither file is loaded or executed — this is pure
 * filesystem inspection, consistent with the UninstallTest.php pattern.
 *
 * WHAT THESE TESTS PREVENT
 *
 * T1 ensures the file exists at the repo root so it is not accidentally
 * deleted or moved. T2 ensures all required WordPress.org header fields
 * and section headers are present. T3 is the most operationally valuable:
 * wordpress.org rejects submissions where the Stable tag in readme.txt does
 * not match the Version in the main plugin file — this test makes that
 * mismatch a CI failure rather than a submission rejection.
 *
 * @package ClientHandoff
 */

use WP_Mock\Tools\TestCase;

/**
 * Class ReadmeTest
 */
class ReadmeTest extends TestCase {

	/** @var string Repo root directory. */
	private static $root;

	/** @var string Cached readme.txt contents. */
	private static $readme;

	/** @var string Cached zicstack-client-handoff.php contents. */
	private static $plugin_file;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::$root        = dirname( __DIR__, 2 );
		self::$readme      = file_get_contents( self::$root . '/readme.txt' );
		self::$plugin_file = file_get_contents( self::$root . '/zicstack-client-handoff.php' );
	}

	// =========================================================================
	// T1 — File existence
	// =========================================================================

	/**
	 * T1 — readme.txt exists at the repository root.
	 *
	 * wordpress.org reads readme.txt from the plugin root. If the file is
	 * missing the submission is invalid. This test fails fast when the file
	 * is accidentally deleted or misnamed.
	 */
	public function test_readme_txt_exists_at_repo_root() {
		$path = self::$root . '/readme.txt';
		$this->assertTrue(
			file_exists( $path ),
			"readme.txt must exist at the repo root ($path)"
		);
	}

	// =========================================================================
	// T2 — Required header fields and section headers
	// =========================================================================

	/**
	 * T2 — readme.txt contains all required wordpress.org header fields and
	 * standard section headers.
	 *
	 * The wordpress.org directory parser expects these fields in the header
	 * block and these section headers in the body. Missing any causes the
	 * submission validator to report warnings or reject the file.
	 */
	public function test_readme_txt_contains_all_required_header_fields() {
		$required = array(
			'=== Zicstack Client Handoff ===',
			'Contributors:',
			'Tags:',
			'Requires at least:',
			'Tested up to:',
			'Requires PHP:',
			'Stable tag:',
			'License:',
			'License URI:',
			'== Description ==',
			'== Installation ==',
			'== Frequently Asked Questions ==',
			'== Changelog ==',
		);

		foreach ( $required as $field ) {
			$this->assertStringContainsString(
				$field,
				self::$readme,
				"readme.txt must contain the required field or section: $field"
			);
		}
	}

	// =========================================================================
	// T3 — Stable tag matches plugin Version
	// =========================================================================

	/**
	 * T3 — The Stable tag in readme.txt matches the Version in zicstack-client-handoff.php.
	 *
	 * wordpress.org resolves which zip file to serve based on the Stable tag.
	 * A mismatch between Stable tag and the plugin file's Version header causes
	 * the submission to serve the wrong zip, or triggers a rejection. This test
	 * makes that mismatch a test-suite failure rather than a submission defect.
	 */
	public function test_readme_txt_stable_tag_matches_plugin_version() {
		$readme_match = array();
		preg_match( '/Stable tag:\s*([0-9.]+)/i', self::$readme, $readme_match );

		$plugin_match = array();
		preg_match( '/Version:\s*([0-9.]+)/i', self::$plugin_file, $plugin_match );

		$this->assertNotEmpty(
			$readme_match,
			'readme.txt must contain a Stable tag field matching the pattern /Stable tag: X.Y.Z/'
		);
		$this->assertNotEmpty(
			$plugin_match,
			'zicstack-client-handoff.php must contain a Version field matching the pattern /Version: X.Y.Z/'
		);

		$this->assertSame(
			$readme_match[1],
			$plugin_match[1],
			sprintf(
				'Stable tag in readme.txt (%s) must match Version in zicstack-client-handoff.php (%s)',
				$readme_match[1],
				$plugin_match[1]
			)
		);
	}
}
