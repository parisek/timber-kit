<?php

declare(strict_types=1);

namespace Tests\Unit\ImageCacheRegenerator;

use Parisek\TimberKit\ImageCacheRegenerator;
use PHPUnit\Framework\TestCase;

/**
 * Decides whether the machine is too busy to encode another image.
 *
 * `sys_getloadavg()` is disabled on some platforms and returns false there.
 * The gate then opens, because a run that cannot read the load must still
 * finish rather than wait for a number that never arrives.
 */
class LoadGateTest extends TestCase {

	public function testNoLimitNeverHolds(): void {
		$this->assertFalse( ImageCacheRegenerator::loadExceeded( null, array( 9.0, 9.0, 9.0 ) ) );
	}

	public function testLoadAboveTheLimitHolds(): void {
		$this->assertTrue( ImageCacheRegenerator::loadExceeded( 2.0, array( 4.5, 1.0, 1.0 ) ) );
	}

	public function testLoadAtOrBelowTheLimitDoesNotHold(): void {
		$this->assertFalse( ImageCacheRegenerator::loadExceeded( 2.0, array( 2.0, 9.0, 9.0 ) ) );
		$this->assertFalse( ImageCacheRegenerator::loadExceeded( 2.0, array( 0.3, 9.0, 9.0 ) ) );
	}

	public function testItReadsTheOneMinuteAverageAndIgnoresTheOthers(): void {
		$this->assertFalse( ImageCacheRegenerator::loadExceeded( 2.0, array( 1.0, 8.0, 8.0 ) ) );
	}

	public function testAnUnavailableLoadAverageOpensTheGate(): void {
		$this->assertFalse( ImageCacheRegenerator::loadExceeded( 2.0, false ) );
		$this->assertFalse( ImageCacheRegenerator::loadExceeded( 2.0, array() ) );
	}
}
