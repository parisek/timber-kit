<?php

declare(strict_types=1);

namespace Tests\Unit;

use Brain\Monkey;
use Parisek\TimberKit\WpmlBlockOverride;
use PHPUnit\Framework\TestCase;

abstract class WpmlBlockOverrideTestCase extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		// The per-request source-block memo is static — reset it so memoized
		// parses from one test can't leak into the next.
		$prop = new \ReflectionProperty( WpmlBlockOverride::class, 'sourceBlocksMemo' );
		$prop->setValue( null, array() );

		Monkey\tearDown();
		parent::tearDown();
	}
}
