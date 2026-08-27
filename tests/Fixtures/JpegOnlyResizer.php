<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Parisek\TimberKit\Resizer;

/**
 * Second stubbed Resizer, reporting a different backend than
 * {@see CountingResizer}.
 *
 * Its whole job is to prove the memo is keyed per concrete class. Two
 * subclasses that stub the probe differently must not answer each other.
 */
class JpegOnlyResizer extends Resizer {

	public static int $probes = 0;

	protected function probeBackendFormatsUncached(): array {
		++self::$probes;
		return [ 'JPEG' ];
	}
}
