<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Parisek\TimberKit\Helpers;
use Tests\Unit\HelpersTestCase;

class IsAssocTest extends HelpersTestCase {

	public function test_sequential_array_returns_false(): void {
		$this->assertFalse( Helpers::isAssoc( [ 'a', 'b', 'c' ] ) );
	}

	public function test_associative_array_returns_true(): void {
		$this->assertTrue( Helpers::isAssoc( [ 'key' => 'value', 'foo' => 'bar' ] ) );
	}

	public function test_empty_array_returns_false(): void {
		$this->assertFalse( Helpers::isAssoc( [] ) );
	}

	public function test_numeric_non_sequential_keys_returns_true(): void {
		$this->assertTrue( Helpers::isAssoc( [ 0 => 'a', 2 => 'b', 5 => 'c' ] ) );
	}

	public function test_mixed_keys_returns_true(): void {
		$this->assertTrue( Helpers::isAssoc( [ 0 => 'a', 'key' => 'b' ] ) );
	}

	public function test_single_element_sequential_returns_false(): void {
		$this->assertFalse( Helpers::isAssoc( [ 'only' ] ) );
	}
}
