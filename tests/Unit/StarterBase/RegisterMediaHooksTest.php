<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Parisek\TimberKit\StarterBase;
use Tests\Unit\StarterBaseTestCase;

/**
 * Verifies that registerMediaHooks() registers image-processing filters
 * and the conditional filename sanitization / upload-resize hooks.
 */
class RegisterMediaHooksTest extends StarterBaseTestCase {

	private function invokeRegisterMediaHooks( StarterBase $instance ): void {
		$method = ( new \ReflectionClass( StarterBase::class ) )->getMethod( 'registerMediaHooks' );
		$method->invoke( $instance );
	}

	private function bareInstance(): StarterBase {
		return ( new \ReflectionClass( StarterBase::class ) )->newInstanceWithoutConstructor();
	}

	public function test_registers_unconditional_media_filters(): void {
		$filters = [];
		$actions = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );
		Functions\when( 'add_action' )->alias( function ( $hook, ...$rest ) use ( &$actions ) {
			$actions[] = $hook;
		} );

		$instance = $this->bareInstance();
		// Disable conditional blocks so we only test unconditional hooks.
		$this->setProperty( $instance, 'clean_image_filenames', false );
		$this->setProperty( $instance, 'max_upload_width', 0 );
		$this->setProperty( $instance, 'max_upload_height', 0 );

		$this->invokeRegisterMediaHooks( $instance );

		$this->assertContains( 'wp_get_attachment_image_attributes', $filters );
		$this->assertContains( 'jpeg_quality', $filters );
		$this->assertContains( 'wp_editor_set_quality', $filters );
		$this->assertContains( 'wp_handle_upload_prefilter', $filters );
		$this->assertContains( 'init', $actions );
		$this->assertContains( 'delete_attachment', $actions );
	}

	public function test_registers_sanitize_file_name_when_clean_image_filenames_is_true(): void {
		$filters = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );
		Functions\when( 'add_action' )->justReturn( true );

		$instance = $this->bareInstance();
		$this->setProperty( $instance, 'clean_image_filenames', true );
		$this->setProperty( $instance, 'max_upload_width', 0 );
		$this->setProperty( $instance, 'max_upload_height', 0 );

		$this->invokeRegisterMediaHooks( $instance );

		$this->assertContains( 'sanitize_file_name', $filters );
	}

	public function test_skips_sanitize_file_name_when_clean_image_filenames_is_false(): void {
		$filters = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );
		Functions\when( 'add_action' )->justReturn( true );

		$instance = $this->bareInstance();
		$this->setProperty( $instance, 'clean_image_filenames', false );
		$this->setProperty( $instance, 'max_upload_width', 0 );
		$this->setProperty( $instance, 'max_upload_height', 0 );

		$this->invokeRegisterMediaHooks( $instance );

		$this->assertNotContains( 'sanitize_file_name', $filters );
	}

	public function test_registers_big_image_size_threshold_unconditionally(): void {
		$filters = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );
		Functions\when( 'add_action' )->justReturn( true );

		$instance = $this->bareInstance();
		$this->setProperty( $instance, 'clean_image_filenames', false );
		$this->setProperty( $instance, 'max_upload_width', null );
		$this->setProperty( $instance, 'max_upload_height', null );
		$this->setProperty( $instance, 'big_image_size_threshold', 2560 );

		$this->invokeRegisterMediaHooks( $instance );

		// Registered always — timber-kit is authoritative over the threshold; the
		// callback returns 0 to disable scaling rather than the hook being skipped.
		$this->assertContains( 'big_image_size_threshold', $filters );
	}

	public function test_never_registers_on_upload_metadata_deletion(): void {
		// Original deletion is a deferred WP-CLI sweep, never an on-upload hook —
		// deleting on upload would degrade later thumbnail regeneration.
		$filters = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );
		Functions\when( 'add_action' )->justReturn( true );

		$instance = $this->bareInstance();
		$this->setProperty( $instance, 'clean_image_filenames', false );
		$this->setProperty( $instance, 'big_image_size_threshold', 2560 );

		$this->invokeRegisterMediaHooks( $instance );

		$this->assertNotContains( 'wp_generate_attachment_metadata', $filters );
	}

	public function test_registers_intermediate_image_sizes_when_allowlist_set(): void {
		$filters = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );
		Functions\when( 'add_action' )->justReturn( true );

		$instance = $this->bareInstance();
		$this->setProperty( $instance, 'clean_image_filenames', false );
		$this->setProperty( $instance, 'big_image_size_threshold', 2560 );
		$this->setProperty( $instance, 'enabled_image_sizes', [ 'thumbnail', 'medium', 'large' ] );

		$this->invokeRegisterMediaHooks( $instance );

		$this->assertContains( 'intermediate_image_sizes_advanced', $filters );
	}

	public function test_skips_intermediate_image_sizes_when_allowlist_is_null(): void {
		$filters = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );
		Functions\when( 'add_action' )->justReturn( true );

		$instance = $this->bareInstance();
		$this->setProperty( $instance, 'clean_image_filenames', false );
		$this->setProperty( $instance, 'big_image_size_threshold', 2560 );
		$this->setProperty( $instance, 'enabled_image_sizes', null );

		$this->invokeRegisterMediaHooks( $instance );

		$this->assertNotContains( 'intermediate_image_sizes_advanced', $filters );
	}

	/**
	 * Helper to set a protected/private property on a bare instance.
	 */
	private function setProperty( StarterBase $instance, string $name, mixed $value ): void {
		$prop = ( new \ReflectionClass( StarterBase::class ) )->getProperty( $name );
		$prop->setValue( $instance, $value );
	}
}
