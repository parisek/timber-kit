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
 * or not the screen repeats.
 *
 * These tests drive the nav-menu-item sharing explicitly through its filter,
 * both ways. Whether it is on by default on a given site is decided by
 * {@see NavMenuItemSharingSafetyTest}, which covers the detection itself.
 */
class FieldGroupsMemoTest extends HelpersTestCase {

	/** @var array<int, array<string, mixed>> */
	private array $seen = [];

	private bool $shareMenuItems = false;

	protected function setUp(): void {
		parent::setUp();
		$this->seen           = [];
		$this->shareMenuItems = false;

		Functions\when( 'apply_filters' )->alias(
			function ( $filter, $default = null, ...$args ) {
				unset( $args );
				return 'timber_kit_share_nav_menu_item_field_groups' === $filter
					? $this->shareMenuItems
					: $default;
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

	private function shareMenuItems(): void {
		$this->shareMenuItems = true;
		Helpers::flushFieldGroups();
	}

	public function test_menu_items_are_asked_separately_when_sharing_is_off(): void {
		$this->lookup( [ 'nav_menu_item' => 101, 'nav_menu' => 75 ] );
		$this->lookup( [ 'nav_menu_item' => 102, 'nav_menu' => 75 ] );

		$this->assertCount( 2, $this->seen );
	}

	public function test_items_of_one_menu_share_an_answer_when_sharing_is_on(): void {
		$this->shareMenuItems();

		$this->lookup( [ 'nav_menu_item' => 101, 'nav_menu' => 75 ] );
		$this->lookup( [ 'nav_menu_item' => 102, 'nav_menu' => 75 ] );
		$this->lookup( [ 'nav_menu_item' => 103, 'nav_menu' => 75 ] );

		$this->assertCount( 1, $this->seen );
	}

	public function test_the_real_item_id_still_reaches_acf_when_sharing_is_on(): void {
		// The normalization is in the cache key only. ACF's own location type
		// checks that the key is set, so a placeholder would change the answer.
		$this->shareMenuItems();

		$this->lookup( [ 'nav_menu_item' => 101, 'nav_menu' => 75 ] );

		$this->assertSame( 101, $this->seen[0]['nav_menu_item'] );
	}

	public function test_a_different_menu_is_asked_separately_when_sharing_is_on(): void {
		$this->shareMenuItems();

		$this->lookup( [ 'nav_menu_item' => 101, 'nav_menu' => 75 ] );
		$this->lookup( [ 'nav_menu_item' => 102, 'nav_menu' => 76 ] );

		$this->assertCount( 2, $this->seen );
	}

	public function test_one_options_page_is_asked_once(): void {
		// Never gated: the options-page screen carries no per-caller id.
		$this->lookup( [ 'options_page' => 'site-settings' ] );
		$this->lookup( [ 'options_page' => 'site-settings' ] );

		$this->assertCount( 1, $this->seen );
	}

	public function test_key_order_does_not_split_the_memo(): void {
		$this->lookup( [ 'nav_menu' => 75, 'post_type' => 'page' ] );
		$this->lookup( [ 'post_type' => 'page', 'nav_menu' => 75 ] );

		$this->assertCount( 1, $this->seen );
	}

	public function test_another_blog_is_asked_separately(): void {
		$this->lookup( [ 'options_page' => 'site-settings' ] );
		Functions\when( 'get_current_blog_id' )->justReturn( 2 );
		$this->lookup( [ 'options_page' => 'site-settings' ] );

		$this->assertCount( 2, $this->seen );
	}

	public function test_another_language_is_asked_separately(): void {
		$this->lookup( [ 'options_page' => 'site-settings' ] );
		Functions\when( 'get_locale' )->justReturn( 'it_IT' );
		$this->lookup( [ 'options_page' => 'site-settings' ] );

		$this->assertCount( 2, $this->seen );
	}

	public function test_an_unencodable_screen_is_never_memoized(): void {
		// `wp_json_encode()` answers false for invalid UTF-8. Casting that to a
		// string would collapse every such screen onto one key, so the memo is
		// skipped instead — twice the work, never the wrong answer.
		Functions\when( 'wp_json_encode' )->justReturn( false );

		$this->lookup( [ 'options_page' => "bad\xB1utf8" ] );
		$this->lookup( [ 'options_page' => "bad\xB1utf8" ] );
		$this->lookup( [ 'options_page' => 'another' ] );

		$this->assertCount( 3, $this->seen );
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
