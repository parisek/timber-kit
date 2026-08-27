<?php
/**
 * A value-load callback defined in a file whose directory a test vouches for.
 *
 * It exists as a real file because the gate judges a callback by
 * `ReflectionFunction::getFileName()`. A closure declared inside the test would
 * report the test file, and an internal function like `strtolower` reports no
 * file at all — neither can stand in for "defined under a trusted root".
 */

declare(strict_types=1);

namespace Tests\Fixtures\Acf;

function trustedValueLoad( mixed $value ): mixed {
	return $value;
}
