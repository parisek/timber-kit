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

	public function test_registers_wp_handle_upload_when_max_dimensions_set(): void {
		$filters = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );
		Functions\when( 'add_action' )->justReturn( true );

		$instance = $this->bareInstance();
		$this->setProperty( $instance, 'clean_image_filenames', false );
		$this->setProperty( $instance, 'max_upload_width', 2560 );
		$this->setProperty( $instance, 'max_upload_height', 2560 );

		$this->invokeRegisterMediaHooks( $instance );

		$this->assertContains( 'wp_handle_upload', $filters );
	}

	public function test_skips_wp_handle_upload_when_max_dimensions_are_zero(): void {
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

		$this->assertNotContains( 'wp_handle_upload', $filters );
	}

	/**
	 * Helper to set a protected/private property on a bare instance.
	 */
	private function setProperty( StarterBase $instance, string $name, mixed $value ): void {
		$prop = ( new \ReflectionClass( StarterBase::class ) )->getProperty( $name );
		$prop->setValue( $instance, $value );
	}
}
