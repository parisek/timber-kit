<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Helpers;
use Tests\Unit\HelpersTestCase;

/**
 * Covers the per-screen memo in front of `acf_get_field_groups()`.
 *
 * ACF caches nothing here: every call walks each registered field group and
 * evaluates its location rules, measured at 8-10 ms against 96 groups whether
 * or not the screen repeats. `formatFields()` asks once per nav menu item, so a
 * 90-item menu paid for 90 identical answers.
 */
class FieldGroupsMemoTest extends HelpersTestCase {

	/** @var array<int, array<string, mixed>> */
	private array $seen = [];

	protected function setUp(): void {
		parent::setUp();
		$this->seen = [];

		Functions\when( 'apply_filters' )->alias(
			function ( $filter, $default = null, ...$args ) {
				unset( $filter, $args );
				return $default;
			}
		);
		Functions\when( 'get_locale' )->justReturn( 'cs_CZ' );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'wp_json_encode' )->alias(
			static fn ( $value ) => json_encode( $value )
		);
		Functions\when( 'acf_get_field_groups' )->alias(
			function ( $screen ) {
				$this->seen[] = $screen;
				return [ [ 'key' => 'group_1' ] ];
			}
		);
	}

	/**
	 * @param array<string, mixed> $screen
	 * @return array<int, mixed>
	 */
	private function lookup( array $screen ): array {
		$method = new \ReflectionMethod( Helpers::class, 'fieldGroupsForScreen' );
		return $method->invoke( null, $screen );
	}

	public function test_items_of_one_menu_share_an_answer(): void {
		// ACF_Location_Nav_Menu_Item::match() reads `nav_menu_item` only to
		// confirm it is set, then matches on `nav_menu` — so the item id cannot
		// change which groups match.
		$this->lookup( [ 'nav_menu_item' => 101, 'nav_menu' => 75 ] );
		$this->lookup( [ 'nav_menu_item' => 102, 'nav_menu' => 75 ] );
		$this->lookup( [ 'nav_menu_item' => 103, 'nav_menu' => 75 ] );

		$this->assertCount( 1, $this->seen, 'Three items of one menu must ask ACF once.' );
	}

	public function test_the_first_item_id_still_reaches_acf(): void {
		// The normalization is in the cache key only. ACF must still be asked
		// with a real id, because its location type checks that the key is set.
		$this->lookup( [ 'nav_menu_item' => 101, 'nav_menu' => 75 ] );

		$this->assertSame( 101, $this->seen[0]['nav_menu_item'] );
	}

	public function test_a_different_menu_is_asked_separately(): void {
		$this->lookup( [ 'nav_menu_item' => 101, 'nav_menu' => 75 ] );
		$this->lookup( [ 'nav_menu_item' => 102, 'nav_menu' => 76 ] );

		$this->assertCount( 2, $this->seen );
	}

	public function test_one_options_page_is_asked_once(): void {
		$this->lookup( [ 'options_page' => 'site-settings' ] );
		$this->lookup( [ 'options_page' => 'site-settings' ] );

		$this->assertCount( 1, $this->seen );
	}

	public function test_key_order_does_not_split_the_memo(): void {
		$this->lookup( [ 'nav_menu' => 75, 'nav_menu_item' => 101 ] );
		$this->lookup( [ 'nav_menu_item' => 102, 'nav_menu' => 75 ] );

		$this->assertCount( 1, $this->seen );
	}

	public function test_flush_forces_a_fresh_read(): void {
		$this->lookup( [ 'options_page' => 'site-settings' ] );
		$this->lookup( [ 'options_page' => 'site-settings' ] );
		Helpers::flushFieldGroups();
		$this->lookup( [ 'options_page' => 'site-settings' ] );
		$this->lookup( [ 'options_page' => 'site-settings' ] );

		$this->assertCount( 2, $this->seen );
	}

	public function test_a_non_array_answer_is_normalized_to_empty(): void {
		Functions\when( 'acf_get_field_groups' )->justReturn( false );

		$this->assertSame( [], $this->lookup( [ 'options_page' => 'x' ] ) );
	}
}
