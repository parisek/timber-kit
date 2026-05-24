<?php
declare(strict_types=1);

/**
 * Stub global \Breadcrumb class.
 *
 * Loaded only by tests that exercise the legacy-class guard in
 * StarterBase::timber_context() — see tests/Unit/Breadcrumb/StarterBaseBreadcrumbTest.php.
 *
 * Always require this file inside an `if ( ! class_exists( '\Breadcrumb' ) )`
 * guard to keep it idempotent across multi-test runs.
 */
if ( ! class_exists( '\Breadcrumb' ) ) {
	class Breadcrumb {
		public function get(): array {
			return [];
		}
	}
}
