<?php

declare(strict_types=1);

namespace Tests\Unit\WpmlBlockOverride;

use Tests\Unit\WpmlBlockOverrideTestCase;

class NormalizeCopyFieldsTest extends WpmlBlockOverrideTestCase {

	public function test_keeps_well_formed_entries(): void {
		$entries = [
			[ 'field' => [ 'name' => 'img', 'type' => 'image' ], 'path' => [] ],
			[ 'field' => [ 'name' => 'price', 'type' => 'text' ], 'path' => [ [ 'name' => 'items', 'type' => 'repeater' ] ] ],
		];

		$result = self::callPrivate( 'normalizeCopyFields', [ $entries ] );

		$this->assertCount( 2, $result, 'both well-formed entries kept' );
	}

	public function test_drops_non_array_entries(): void {
		$entries = [
			'string entry',
			42,
			null,
			[ 'field' => [ 'name' => 'img' ], 'path' => [] ],
		];

		$result = self::callPrivate( 'normalizeCopyFields', [ $entries ] );

		$this->assertCount( 1, $result, 'only the array entry survives' );
	}

	public function test_drops_entries_missing_field_array(): void {
		$entries = [
			[ 'path' => [] ],                              // no field key
			[ 'field' => 'not-an-array', 'path' => [] ],   // field is scalar
			[ 'field' => [ 'name' => 'img' ], 'path' => [] ],
		];

		$result = self::callPrivate( 'normalizeCopyFields', [ $entries ] );

		$this->assertCount( 1, $result );
		$this->assertSame( 'img', $result[0]['field']['name'] );
	}

	public function test_drops_entries_with_empty_or_invalid_name(): void {
		$entries = [
			[ 'field' => [ 'name' => '' ], 'path' => [] ],
			[ 'field' => [ 'name' => null ], 'path' => [] ],
			[ 'field' => [ 'name' => 123 ], 'path' => [] ],
			[ 'field' => [ 'type' => 'image' ], 'path' => [] ],  // no name at all
			[ 'field' => [ 'name' => 'ok' ], 'path' => [] ],
		];

		$result = self::callPrivate( 'normalizeCopyFields', [ $entries ] );

		$this->assertCount( 1, $result );
		$this->assertSame( 'ok', $result[0]['field']['name'] );
	}

	public function test_defaults_missing_path_to_empty_array(): void {
		$entries = [
			[ 'field' => [ 'name' => 'img' ] ],                      // no path key
			[ 'field' => [ 'name' => 'img2' ], 'path' => 'broken' ], // path scalar
		];

		$result = self::callPrivate( 'normalizeCopyFields', [ $entries ] );

		$this->assertCount( 2, $result );
		$this->assertSame( [], $result[0]['path'], 'missing path defaults to []' );
		$this->assertSame( [], $result[1]['path'], 'scalar path replaced with []' );
	}
}
