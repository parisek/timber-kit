<?php

declare(strict_types=1);

namespace Tests\Fixtures\Acf;

/**
 * Stands in for a location type ACF ships.
 *
 * The detection under test asks only one question of a location type: does its
 * class file sit inside `ACF_PATH`. The tests point `ACF_PATH` at this
 * directory, so this class is "ACF's own" and anything in the parent directory
 * is not — the same mechanism the production check uses, not a parallel one.
 */
class StubCoreLocationType {

	public string $name = 'stub_core';
}
