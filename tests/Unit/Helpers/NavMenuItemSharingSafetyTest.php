<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Helpers;
use Tests\Fixtures\Acf\StubCoreLocationType;
use Tests\Fixtures\StubCustomLocationType;
use Tests\Unit\HelpersTestCase;

/**
 * Covers the check that decides, without configuration, whether every item of
 * one menu may share a field-group answer.
 *
 * Sharing is safe exactly while ACF's own location types are the only things
 * that see the screen: theirs read `nav_menu_item` only through `isset()`.
 * `acf_register_location_type()` is public API, so anyone can add a matcher
 * that reads the id — and the check is what notices.
 */
class NavMenuItemSharingSafetyTest extends HelpersTestCase {

	/** @var array<int, array<string, mixed>> */
	private array $seen = [];

	protected function setUp(): void {
		parent::setUp();
		$this->seen = [];

		if ( ! defined( 'ACF_PATH' ) ) {
			define( 'ACF_PATH', realpath( __DIR__ . '/../../Fixtures/Acf' ) . '/' );
		}

		Functions\when( 'apply_filters' )->alias(
			static function ( $filter, $default = null, ...$args ) {
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
	 * @param array<int, object> $types
	 */
	private function withLocationTypes( array $types ): void {
		Functions\when( 'acf_get_location_types' )->justReturn( $types );
		Helpers::flushFieldGroups();
	}

	/**
	 * @param array<string, mixed> $screen
	 */
	private function lookup( array $screen ): void {
		( new \ReflectionMethod( Helpers::class, 'fieldGroupsForScreen' ) )->invoke( null, $screen );
	}

	public function test_vanilla_acf_shares_by_default(): void {
		$this->withLocationTypes( [ new StubCoreLocationType(), new StubCoreLocationType() ] );

		$this->lookup( [ 'nav_menu_item' => 101, 'nav_menu' => 75 ] );
		$this->lookup( [ 'nav_menu_item' => 102, 'nav_menu' => 75 ] );
		$this->lookup( [ 'nav_menu_item' => 103, 'nav_menu' => 75 ] );

		$this->assertCount( 1, $this->seen, 'Nothing to configure on a site with only ACF location types.' );
	}

	public function test_one_custom_location_type_stops_sharing(): void {
		// It only takes one: that matcher receives the screen for every rule.
		$this->withLocationTypes( [ new StubCoreLocationType(), new StubCustomLocationType() ] );

		$this->lookup( [ 'nav_menu_item' => 101, 'nav_menu' => 75 ] );
		$this->lookup( [ 'nav_menu_item' => 102, 'nav_menu' => 75 ] );

		$this->assertCount( 2, $this->seen );
	}

	public function test_an_empty_registry_stops_sharing(): void {
		// Nothing to verify is not the same as verified.
		$this->withLocationTypes( [] );

		$this->lookup( [ 'nav_menu_item' => 101, 'nav_menu' => 75 ] );
		$this->lookup( [ 'nav_menu_item' => 102, 'nav_menu' => 75 ] );

		$this->assertCount( 2, $this->seen );
	}

	public function test_the_filter_can_force_sharing_off(): void {
		$this->withLocationTypes( [ new StubCoreLocationType() ] );
		Functions\when( 'apply_filters' )->alias(
			static function ( $filter, $default = null, ...$args ) {
				unset( $args );
				return 'timber_kit_share_nav_menu_item_field_groups' === $filter ? false : $default;
			}
		);
		Helpers::flushFieldGroups();

		$this->lookup( [ 'nav_menu_item' => 101, 'nav_menu' => 75 ] );
		$this->lookup( [ 'nav_menu_item' => 102, 'nav_menu' => 75 ] );

		$this->assertCount( 2, $this->seen );
	}

	public function test_the_filter_can_force_sharing_on(): void {
		$this->withLocationTypes( [ new StubCustomLocationType() ] );
		Functions\when( 'apply_filters' )->alias(
			static function ( $filter, $default = null, ...$args ) {
				unset( $args );
				return 'timber_kit_share_nav_menu_item_field_groups' === $filter ? true : $default;
			}
		);
		Helpers::flushFieldGroups();

		$this->lookup( [ 'nav_menu_item' => 101, 'nav_menu' => 75 ] );
		$this->lookup( [ 'nav_menu_item' => 102, 'nav_menu' => 75 ] );

		$this->assertCount( 1, $this->seen );
	}
}
