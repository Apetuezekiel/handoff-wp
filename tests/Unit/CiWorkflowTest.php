<?php
/**
 * Static existence test for the GitHub Actions CI workflow (C1).
 *
 * APPROACH — file existence check
 *
 * A content-level test (asserting specific matrix versions or the phpunit
 * command) would require updating the test on every matrix change — two edits
 * instead of one. That maintenance cost is not justified here: the running
 * workflow on GitHub Actions is the behavioral verification. This test guards
 * only against the file being accidentally deleted or moved.
 *
 * @package ClientHandoff
 */

use WP_Mock\Tools\TestCase;

/**
 * Class CiWorkflowTest
 */
class CiWorkflowTest extends TestCase {

	// =========================================================================
	// C1 — Workflow file existence
	// =========================================================================

	/**
	 * C1 — .github/workflows/ci.yml exists at the repository root.
	 *
	 * Deleting or moving the workflow file silently disables CI. This test
	 * makes that accidental deletion a test-suite failure rather than a silent
	 * gap in coverage.
	 */
	public function test_ci_workflow_file_exists() {
		$path = dirname( __DIR__, 2 ) . '/.github/workflows/ci.yml';
		$this->assertTrue(
			file_exists( $path ),
			".github/workflows/ci.yml must exist at the repo root ($path)"
		);
	}
}
