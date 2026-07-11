<?php

declare(strict_types=1);

namespace Tests\Unit\Health;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

abstract class HealthTestCase extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
