<?php

declare(strict_types=1);

namespace Tests\Unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

abstract class HelpersTestCase extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
