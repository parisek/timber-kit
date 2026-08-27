<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/**
 * Stands in for a location type a project registered itself.
 *
 * It lives one directory above `tests/Fixtures/Acf/`, which the tests use as
 * `ACF_PATH`, so the detection must classify it as foreign.
 */
class StubCustomLocationType {

	public string $name = 'stub_custom';
}
