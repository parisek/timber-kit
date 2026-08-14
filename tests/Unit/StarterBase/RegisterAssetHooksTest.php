<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Parisek\TimberKit\StarterBase;
use Tests\Unit\StarterBaseTestCase;

/**
 * Verifies that registerAssetHooks() registers block assets, preload,
 * admin scripts, editor assets, and the conditional favicon filter.
 */
class RegisterAssetHooksTest extends StarterBaseTestCase {

	private function invokeRegisterAssetHooks( StarterBase $instance ): void {
		$method = ( new \ReflectionClass( StarterBase::class ) )->getMethod( 'registerAssetHooks' );
		$method->invoke( $instance );
	}

	private function bareInstance(): StarterBase {
		return ( new \ReflectionClass( StarterBase::class ) )->newInstanceWithoutConstructor();
	}

	private function setProperty( StarterBase $instance, string $name, mixed $value ): void {
		$prop = ( new \ReflectionClass( StarterBase::class ) )->getProperty( $name );
		$prop->setValue( $instance, $value );
	}

	public function test_registers_unconditional_enqueue_actions(): void {
		$actions = [];
		Functions\when( 'add_action' )->alias( function ( $hook, ...$rest ) use ( &$actions ) {
			$actions[] = $hook;
		} );
		Functions\when( 'add_filter' )->justReturn( true );
		// Point template dir to a nonexistent path so is_file() returns false without mocking.
		Functions\when( 'get_template_directory' )->justReturn( '/nonexistent/path/that/will/not/exist' );

		$this->invokeRegisterAssetHooks( $this->bareInstance() );

		$this->assertContains( 'enqueue_block_assets', $actions );
		$this->assertContains( 'wp_preload_resources', $actions );
		$this->assertContains( 'admin_enqueue_scripts', $actions );
		$this->assertContains( 'enqueue_block_editor_assets', $actions );
	}

	public function test_registers_favicon_filter_when_favicon_file_exists(): void {
		// Write a real temp file so is_file() returns true without mocking internals.
		$tmpDir = sys_get_temp_dir();
		$faviconSubPath = 'images/touch/favicon.svg';
		$faviconDir     = $tmpDir . '/static/' . dirname( $faviconSubPath );
		@mkdir( $faviconDir, 0777, true );
		$faviconFile = $tmpDir . '/static/' . $faviconSubPath;
		file_put_contents( $faviconFile, '<svg/>' );

		$filters = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'get_template_directory' )->justReturn( $tmpDir );

		$this->invokeRegisterAssetHooks( $this->bareInstance() );

		@unlink( $faviconFile );

		$this->assertContains( 'get_site_icon_url', $filters );
	}

	public function test_skips_favicon_filter_when_favicon_file_missing(): void {
		$filters = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );
		Functions\when( 'add_action' )->justReturn( true );
		// Nonexistent path → is_file() returns false naturally.
		Functions\when( 'get_template_directory' )->justReturn( '/nonexistent/path/that/will/not/exist' );

		$this->invokeRegisterAssetHooks( $this->bareInstance() );

		$this->assertNotContains( 'get_site_icon_url', $filters );
	}

	public function test_site_icon_tags_off_by_default_leaves_meta_tag_filter_unregistered(): void {
		$tmpDir = sys_get_temp_dir() . '/timber-kit-hooks-' . uniqid();
		@mkdir( $tmpDir . '/static/images/touch', 0777, true );
		file_put_contents( $tmpDir . '/static/images/touch/favicon.svg', '<svg/>' );

		$filters = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'get_template_directory' )->justReturn( $tmpDir );

		$this->invokeRegisterAssetHooks( $this->bareInstance() );

		$this->assertContains( 'get_site_icon_url', $filters, 'legacy path must stay wired' );
		$this->assertNotContains( 'site_icon_meta_tags', $filters );
	}

	public function test_site_icon_tags_on_registers_the_meta_tag_filter(): void {
		$tmpDir = sys_get_temp_dir() . '/timber-kit-hooks-' . uniqid();
		@mkdir( $tmpDir . '/static/images/touch', 0777, true );
		file_put_contents( $tmpDir . '/static/images/touch/favicon.svg', '<svg/>' );

		$filters = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'get_template_directory' )->justReturn( $tmpDir );

		$instance = $this->bareInstance();
		$this->setProperty( $instance, 'site_icon_tags', true );
		$this->invokeRegisterAssetHooks( $instance );

		$this->assertContains( 'site_icon_meta_tags', $filters );
		$this->assertContains( 'get_site_icon_url', $filters );
	}

	public function test_site_icon_tags_on_stays_unwired_when_the_theme_ships_no_favicon(): void {
		$filters = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'get_template_directory' )->justReturn( '/nonexistent/path/that/will/not/exist' );

		$instance = $this->bareInstance();
		$this->setProperty( $instance, 'site_icon_tags', true );
		$this->invokeRegisterAssetHooks( $instance );

		$this->assertNotContains( 'site_icon_meta_tags', $filters );
		$this->assertNotContains( 'get_site_icon_url', $filters );
	}
}
