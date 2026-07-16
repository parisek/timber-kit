<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Parisek\TimberKit\StarterBase;
use Tests\Unit\StarterBaseTestCase;

/**
 * Covers the opt-in `$wpml_skip_empty_translation_job_fields` flag: hook wiring
 * inside registerMiscHooks() and the wpml_tm_translation_job_data callback that
 * downgrades empty translatable fields to copy-only.
 *
 * Why it matters: ATE renders empty source segments as trans-units the
 * translator cannot see or fill, exports them without a `<target>` element,
 * and WPML then rejects the whole XLIFF on delivery ("The uploaded xliff file
 * does not seem to be properly formed. Missing or wrong data: target").
 */
class WpmlSkipEmptyTranslationJobFieldsTest extends StarterBaseTestCase {

	private function invokeRegisterMiscHooks( StarterBase $instance ): void {
		$method = ( new \ReflectionClass( StarterBase::class ) )->getMethod( 'registerMiscHooks' );
		$method->invoke( $instance );
	}

	private function bareInstance( bool $flag = false ): StarterBase {
		$instance = ( new \ReflectionClass( StarterBase::class ) )->newInstanceWithoutConstructor();
		$property = ( new \ReflectionClass( StarterBase::class ) )->getProperty( 'wpml_skip_empty_translation_job_fields' );
		$property->setValue( $instance, $flag );
		return $instance;
	}

	public function test_filter_not_registered_when_flag_off(): void {
		$filters = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, ...$rest ) use ( &$filters ) {
			$filters[] = $hook;
		} );
		Functions\when( 'add_action' )->justReturn( true );

		$this->invokeRegisterMiscHooks( $this->bareInstance( false ) );

		$this->assertNotContains( 'wpml_tm_translation_job_data', $filters );
	}

	public function test_filter_registered_when_flag_on(): void {
		$callbacks = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, $callback, ...$rest ) use ( &$callbacks ) {
			$callbacks[ $hook ] = $callback;
		} );
		Functions\when( 'add_action' )->justReturn( true );

		$instance = $this->bareInstance( true );
		$this->invokeRegisterMiscHooks( $instance );

		$this->assertArrayHasKey( 'wpml_tm_translation_job_data', $callbacks );
		$this->assertSame( [ $instance, 'wpml_skip_empty_translation_job_fields' ], $callbacks['wpml_tm_translation_job_data'] );
	}

	public function test_empty_base64_translatable_field_is_downgraded_to_copy(): void {
		$package = [
			'contents' => [
				'field-info_card_link-0-target' => [
					'translate' => 1,
					'data'      => base64_encode( '' ),
					'format'    => 'base64',
				],
			],
		];

		$result = $this->bareInstance( true )->wpml_skip_empty_translation_job_fields( $package );

		$this->assertSame( 0, $result['contents']['field-info_card_link-0-target']['translate'] );
	}

	public function test_whitespace_only_field_is_downgraded_to_copy(): void {
		$package = [
			'contents' => [
				'excerpt' => [
					'translate' => 1,
					'data'      => "  \n\t ",
				],
			],
		];

		$result = $this->bareInstance( true )->wpml_skip_empty_translation_job_fields( $package );

		$this->assertSame( 0, $result['contents']['excerpt']['translate'] );
	}

	public function test_non_empty_translatable_field_is_untouched(): void {
		$package = [
			'contents' => [
				'title' => [
					'translate' => 1,
					'data'      => base64_encode( 'Single studio' ),
					'format'    => 'base64',
				],
				'body'  => [
					'translate' => 1,
					'data'      => 'Plain, non-encoded value',
				],
			],
		];

		$result = $this->bareInstance( true )->wpml_skip_empty_translation_job_fields( $package );

		$this->assertSame( $package, $result );
	}

	public function test_copy_only_field_is_untouched_even_when_empty(): void {
		$package = [
			'contents' => [
				'field-image-0' => [
					'translate' => 0,
					'data'      => '',
				],
			],
		];

		$result = $this->bareInstance( true )->wpml_skip_empty_translation_job_fields( $package );

		$this->assertSame( $package, $result );
	}

	public function test_zero_string_is_not_treated_as_empty(): void {
		$package = [
			'contents' => [
				'counter_label' => [
					'translate' => 1,
					'data'      => base64_encode( '0' ),
					'format'    => 'base64',
				],
			],
		];

		$result = $this->bareInstance( true )->wpml_skip_empty_translation_job_fields( $package );

		$this->assertSame( 1, $result['contents']['counter_label']['translate'] );
	}

	public function test_package_without_contents_is_returned_unchanged(): void {
		$this->assertSame( [], $this->bareInstance( true )->wpml_skip_empty_translation_job_fields( [] ) );
		$this->assertSame(
			[ 'contents' => 'not-an-array' ],
			$this->bareInstance( true )->wpml_skip_empty_translation_job_fields( [ 'contents' => 'not-an-array' ] )
		);
	}
}
