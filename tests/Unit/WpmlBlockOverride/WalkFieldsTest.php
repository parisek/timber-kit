<?php

declare(strict_types=1);

namespace Tests\Unit\WpmlBlockOverride;

use Brain\Monkey\Functions;
use Tests\Unit\WpmlBlockOverrideTestCase;

class WalkFieldsTest extends WpmlBlockOverrideTestCase {

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'apply_filters' )->alias(
			static fn( string $tag, mixed $value, mixed ...$rest ) => $value
		);
	}

	public function test_walk_skips_underscore_system_fields(): void {
		$fields = [
			[ 'name' => '_hidden', 'type' => 'text', 'wpml_cf_preferences' => 1 ],
			[ 'name' => 'visible', 'type' => 'text', 'wpml_cf_preferences' => 1 ],
		];

		$result = self::callPrivate( 'walkFields', [ $fields, [] ] );

		$this->assertCount( 1, $result, 'underscore field skipped' );
		$this->assertSame( 'visible', $result[0]['field']['name'], 'visible field collected' );
	}

	public function test_walk_descends_into_repeater(): void {
		$fields = [
			[
				'name'       => 'items',
				'type'       => 'repeater',
				'sub_fields' => [
					[ 'name' => 'image', 'type' => 'image', 'wpml_cf_preferences' => 1 ],
					[ 'name' => 'title', 'type' => 'text', 'wpml_cf_preferences' => 2 ],
				],
			],
		];

		$result = self::callPrivate( 'walkFields', [ $fields, [] ] );

		$this->assertCount( 1, $result, 'one nested Copy field' );
		$this->assertSame( 'image', $result[0]['field']['name'] );
		$this->assertSame( [ [ 'name' => 'items', 'type' => 'repeater' ] ], $result[0]['path'] );
	}

	public function test_walk_descends_two_levels(): void {
		$fields = [
			[
				'name'       => 'outer',
				'type'       => 'repeater',
				'sub_fields' => [
					[
						'name'       => 'inner',
						'type'       => 'repeater',
						'sub_fields' => [
							[ 'name' => 'leaf', 'type' => 'text', 'wpml_cf_preferences' => 1 ],
						],
					],
				],
			],
		];

		$result = self::callPrivate( 'walkFields', [ $fields, [] ] );

		$this->assertCount( 1, $result );
		$this->assertSame( 'leaf', $result[0]['field']['name'] );
		$this->assertCount( 2, $result[0]['path'], 'two-level path' );
		$this->assertSame( 'outer', $result[0]['path'][0]['name'] );
		$this->assertSame( 'inner', $result[0]['path'][1]['name'] );
	}

	public function test_walk_skips_flexible_content(): void {
		$fields = [
			[
				'name'    => 'flex',
				'type'    => 'flexible_content',
				'layouts' => [
					[
						'sub_fields' => [
							[ 'name' => 'image', 'type' => 'image', 'wpml_cf_preferences' => 1 ],
						],
					],
				],
			],
		];

		$result = self::callPrivate( 'walkFields', [ $fields, [] ] );

		$this->assertCount( 0, $result, 'flexible_content fields not yet supported — skipped' );
	}

	public function test_walk_descends_into_group(): void {
		$fields = [
			[
				'name' => 'contact',
				'type' => 'group',
				'sub_fields' => [
					[ 'name' => 'email', 'type' => 'email', 'wpml_cf_preferences' => 1 ],
					[ 'name' => 'label', 'type' => 'text',  'wpml_cf_preferences' => 2 ],
				],
			],
		];

		$result = self::callPrivate( 'walkFields', [ $fields, [] ] );

		$this->assertCount( 1, $result, 'one Copy sub-field collected from group' );
		$this->assertSame( 'email', $result[0]['field']['name'] );
		$this->assertSame(
			[ [ 'name' => 'contact', 'type' => 'group' ] ],
			$result[0]['path'],
			'path records group container'
		);
	}

	public function test_walk_descends_group_inside_repeater(): void {
		$fields = [
			[
				'name' => 'items',
				'type' => 'repeater',
				'sub_fields' => [
					[
						'name' => 'meta',
						'type' => 'group',
						'sub_fields' => [
							[ 'name' => 'email', 'type' => 'email', 'wpml_cf_preferences' => 1 ],
						],
					],
				],
			],
		];

		$result = self::callPrivate( 'walkFields', [ $fields, [] ] );

		$this->assertCount( 1, $result );
		$this->assertSame( 'email', $result[0]['field']['name'] );
		$this->assertCount( 2, $result[0]['path'], 'path records both repeater + group' );
		$this->assertSame( 'repeater', $result[0]['path'][0]['type'] );
		$this->assertSame( 'group',    $result[0]['path'][1]['type'] );
	}
}
