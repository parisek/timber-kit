<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Parisek\TimberKit\DevMediaProxy;
use Parisek\TimberKit\StarterBase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

class DevMediaProxySetupStarterBaseStub extends StarterBase {

	public function __construct() {
	}

	public function run_setup_dev_media_proxy(): void {
		$this->setup_dev_media_proxy();
	}
}

class DevMediaProxySetupTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		DevMediaProxy::reset_for_tests();
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'wp_get_upload_dir' )->justReturn(
			array(
				'baseurl' => 'https://local.test/wp-content/uploads',
				'basedir' => '/tmp/wp-content/uploads',
			)
		);
	}

	protected function tearDown(): void {
		DevMediaProxy::reset_for_tests();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_disabled_by_default_does_not_register_proxy(): void {
		$filters = array();
		Functions\when( 'add_filter' )->alias(
			function ( string $tag, mixed $callback, int $priority = 10, int $accepted_args = 1 ) use ( &$filters ) {
				$filters[] = $tag;
				return true;
			}
		);

		$this->create_base()->run_setup_dev_media_proxy();

		$this->assertNotContains( 'wp_get_attachment_url', $filters );
	}

	#[PreserveGlobalState( false )]
	#[RunInSeparateProcess]
	public function test_constant_domain_origin_reuses_local_uploads_path(): void {
		define( 'TIMBERKIT_MEDIA_ORIGIN', 'https://origin.test' );

		$this->create_base()->run_setup_dev_media_proxy();

		$this->assertSame(
			'https://origin.test/wp-content/uploads/2024/01/missing.jpg',
			DevMediaProxy::filter_attachment_url( 'https://local.test/wp-content/uploads/2024/01/missing.jpg', 1 )
		);
	}

	#[PreserveGlobalState( false )]
	#[RunInSeparateProcess]
	public function test_constant_origin_registers_proxy(): void {
		define( 'TIMBERKIT_MEDIA_ORIGIN', 'https://origin.test/wp-content/uploads' );

		$filters = array();
		Functions\when( 'add_filter' )->alias(
			function ( string $tag, mixed $callback, int $priority = 10, int $accepted_args = 1 ) use ( &$filters ) {
				$filters[] = $tag;
				return true;
			}
		);
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'wp_get_upload_dir' )->justReturn(
			array(
				'baseurl' => 'https://local.test/wp-content/uploads',
				'basedir' => '/tmp/wp-content/uploads',
			)
		);

		$this->create_base()->run_setup_dev_media_proxy();

		$this->assertContains( 'wp_get_attachment_url', $filters );
	}

	#[PreserveGlobalState( false )]
	#[RunInSeparateProcess]
	public function test_constant_full_origin_is_used_verbatim(): void {
		define( 'TIMBERKIT_MEDIA_ORIGIN', 'https://constant.test/wp-content/uploads' );

		$this->create_base()->run_setup_dev_media_proxy();

		$this->assertSame(
			'https://constant.test/wp-content/uploads/2024/01/missing.jpg',
			DevMediaProxy::filter_attachment_url( 'https://local.test/wp-content/uploads/2024/01/missing.jpg', 1 )
		);
	}

	#[PreserveGlobalState( false )]
	#[RunInSeparateProcess]
	public function test_empty_constant_does_not_register_proxy(): void {
		define( 'TIMBERKIT_MEDIA_ORIGIN', ' ' );

		$filters = array();
		Functions\when( 'add_filter' )->alias(
			function ( string $tag, mixed $callback, int $priority = 10, int $accepted_args = 1 ) use ( &$filters ) {
				$filters[] = $tag;
				return true;
			}
		);

		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'wp_get_upload_dir' )->justReturn(
			array(
				'baseurl' => 'https://local.test/wp-content/uploads',
				'basedir' => '/tmp/wp-content/uploads',
			)
		);

		$this->create_base()->run_setup_dev_media_proxy();

		$this->assertNotContains( 'wp_get_attachment_url', $filters );
	}

	private function create_base(): DevMediaProxySetupStarterBaseStub {
		return new DevMediaProxySetupStarterBaseStub();
	}
}
