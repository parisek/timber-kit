<?php

declare(strict_types=1);

namespace Tests\Integration\Health;

use Tests\Integration\IntegrationTestCase;

/**
 * The kit's Site Health checks as core's own registry reports them.
 *
 * WP_Site_Health::get_tests() is what Tools > Site Health reads. A check gated
 * by a flag must appear there only when the flag is on.
 */
final class SiteHealthRegistryTest extends IntegrationTestCase {

	private const GATED   = 'timber_kit_health_package_assets_reachable';
	private const UNGATED = 'timber_kit_health_xmlrpc_disabled';

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
	}

	public function test_flag_gated_check_is_registered_when_its_flag_is_on(): void {
		$this->bootKit(
			array(
				'site_health'             => true,
				'admin_resizable_sidebar' => true,
			)
		);

		$keys = $this->directTestKeys();

		self::assertContains( self::UNGATED, $keys );
		self::assertContains( self::GATED, $keys );
	}

	public function test_flag_gated_check_is_absent_when_its_flag_is_off(): void {
		$this->bootKit(
			array(
				'site_health'             => true,
				'admin_resizable_sidebar' => false,
			)
		);

		$keys = $this->directTestKeys();

		// The ungated check proves the board is wired, so the absence below is not vacuous.
		self::assertContains( self::UNGATED, $keys );
		self::assertNotContains( self::GATED, $keys );
	}

	public function test_no_kit_check_is_registered_when_the_board_is_off(): void {
		$this->bootKit(
			array(
				'site_health'             => false,
				'admin_resizable_sidebar' => true,
			)
		);

		$kit_keys = array_filter(
			$this->directTestKeys(),
			static fn ( string $key ): bool => str_starts_with( $key, 'timber_kit_health_' )
		);

		self::assertSame( array(), array_values( $kit_keys ) );
	}

	/**
	 * @return list<string>
	 */
	private function directTestKeys(): array {
		$tests = \WP_Site_Health::get_tests();

		return array_map( 'strval', array_keys( $tests['direct'] ) );
	}
}
