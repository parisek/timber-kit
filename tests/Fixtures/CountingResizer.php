<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Parisek\TimberKit\Resizer;

/**
 * Resizer whose backend probe is stubbed and counted.
 *
 * It overrides `probeBackendFormatsUncached()`, not `probeBackendFormats()`, so
 * the memo under test still wraps it.
 *
 * It lives in `tests/Fixtures/` rather than beside its test on purpose. A
 * `class X extends Resizer` at the top level of a `*Test.php` file loads
 * `Resizer` while PHPUnit is still collecting test files — before Patchwork
 * instruments it — and `Patchwork\redefine( 'file_exists', … )` then silently
 * stops reaching the copy of `Resizer` that the other resizer tests exercise.
 * Ten of them failed that way. Autoloaded from here, the class is built when a
 * test asks for it, like every other Resizer in the suite.
 */
class CountingResizer extends Resizer {

	public static int $probes = 0;

	protected function probeBackendFormatsUncached(): array {
		++self::$probes;
		return [ 'JPEG', 'PNG', 'GIF' ];
	}
}
