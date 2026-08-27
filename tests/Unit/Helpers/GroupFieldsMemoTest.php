<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Helpers;
use Tests\Unit\HelpersTestCase;

/**
 * Covers the per-group memo in front of `acf_get_fields()`.
 *
 * ACF caches nothing here, and the answer is the same for every screen a group
 * matches. `getFieldObjectsByScreen()` asks once per group per screen, so a
 * 90-item menu asked 348 times for 21 distinct answers.
 *
 * Field definitions are configuration rather than content — theme JSON, not
 * anything a visitor changes — which is why this memo needs no invalidation
 * beyond the request it lives in.
 */
class GroupFieldsMemoTest extends HelpersTestCase {

	/** @var array<int, mixed> */
	private array $seen = [];

	protected function setUp(): void {
		parent::setUp();
		$this->seen = [];

		Functions\when( 'apply_filters' )->alias(
			static function ( $filter, $default = null, ...$args ) {
				unset( $filter, $args );
				return $default;
			}
		);
		Functions\when( 'acf_get_fields' )->alias(
			function ( $group ) {
				$this->seen[] = $group;
				return [ [ 'key' => 'field_1', 'name' => 'badge', 'type' => 'text' ] ];
			}
		);
	}

	/**
	 * @param array<string, mixed>|mixed $group
	 * @return array<int, mixed>
	 */
	private function lookup( $group ): array {
		$method = new \ReflectionMethod( Helpers::class, 'fieldsForGroup' );

		return $method->invoke( null, $group );
	}

	public function test_one_group_is_asked_once(): void {
		$group = [ 'key' => 'group_menu' ];

		$this->lookup( $group );
		$this->lookup( $group );
		$this->lookup( $group );

		$this->assertCount( 1, $this->seen, 'Ninety menu items share one set of field definitions.' );
	}

	public function test_the_answer_is_the_same_one(): void {
		$first  = $this->lookup( [ 'key' => 'group_menu' ] );
		$second = $this->lookup( [ 'key' => 'group_menu' ] );

		$this->assertSame( $first, $second );
		$this->assertSame( 'badge', $second[0]['name'] );
	}

	public function test_another_group_is_asked_separately(): void {
		$this->lookup( [ 'key' => 'group_menu' ] );
		$this->lookup( [ 'key' => 'group_options' ] );

		$this->assertCount( 2, $this->seen );
	}

	public function test_a_group_identified_by_id_is_memoized_too(): void {
		$this->lookup( [ 'ID' => 42 ] );
		$this->lookup( [ 'ID' => 42 ] );

		$this->assertCount( 1, $this->seen );
	}

	public function test_a_group_with_no_identity_is_asked_every_time(): void {
		// Sharing one entry between two anonymous groups would hand the second
		// the first one's fields.
		$this->lookup( [ 'title' => 'Untitled' ] );
		$this->lookup( [ 'title' => 'Untitled' ] );

		$this->assertCount( 2, $this->seen );
	}

	public function test_another_language_is_asked_separately(): void {
		// ACFML translates a field's label, instructions and choices.
		$this->lookup( [ 'key' => 'group_menu' ] );
		Functions\when( 'get_locale' )->justReturn( 'it_IT' );
		$this->lookup( [ 'key' => 'group_menu' ] );

		$this->assertCount( 2, $this->seen );
	}

	public function test_another_blog_is_asked_separately(): void {
		$this->lookup( [ 'key' => 'group_menu' ] );
		Functions\when( 'get_current_blog_id' )->justReturn( 2 );
		$this->lookup( [ 'key' => 'group_menu' ] );

		$this->assertCount( 2, $this->seen );
	}

	public function test_a_non_array_answer_is_normalized_to_empty(): void {
		Functions\when( 'acf_get_fields' )->justReturn( false );

		$this->assertSame( [], $this->lookup( [ 'key' => 'group_menu' ] ) );
	}

	public function test_flush_forces_a_fresh_read(): void {
		$this->lookup( [ 'key' => 'group_menu' ] );
		$this->lookup( [ 'key' => 'group_menu' ] );
		Helpers::flushFieldGroups();
		$this->lookup( [ 'key' => 'group_menu' ] );
		$this->lookup( [ 'key' => 'group_menu' ] );

		$this->assertCount( 2, $this->seen );
	}
}
