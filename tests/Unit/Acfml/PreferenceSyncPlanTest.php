<?php

declare(strict_types=1);

namespace Tests\Unit\Acfml;

use Parisek\TimberKit\Acfml\PreferenceSyncPlan;
use PHPUnit\Framework\TestCase;

class PreferenceSyncPlanTest extends TestCase {

	/**
	 * @param array<string, array<string, mixed>> $fields
	 */
	private function plan( array $fields = [] ): PreferenceSyncPlan {
		return new PreferenceSyncPlan( static fn ( string $field_key ): ?array => $fields[ $field_key ] ?? null );
	}

	public function test_registers_exact_key_with_field_preference_and_companion_as_copy(): void {
		$plan = $this->plan( [
			'field_abc' => [ 'name' => 'title', 'wpml_cf_preferences' => 2 ],
		] );

		$plan->collect( [
			'flat_detail_blocks_0_title'  => [ 'Hello' ],
			'_flat_detail_blocks_0_title' => [ 'field_abc' ],
		] );

		$patch = $plan->patch( [] );

		$this->assertSame( 2, $patch['flat_detail_blocks_0_title'] );
		$this->assertSame( PreferenceSyncPlan::PREF_COPY, $patch['_flat_detail_blocks_0_title'] );
	}

	public function test_ignores_meta_without_acf_field_key_companion(): void {
		$plan = $this->plan();

		$plan->collect( [
			'_edit_lock'    => [ '123:1' ],
			'plain_meta'    => [ 'value' ],
			'_plain_meta'   => [ 'not-a-field-key' ],
			'seo_title'     => [ 'x' ],
		] );

		$this->assertSame( [], $plan->patch( [] ) );
		$this->assertSame( [], $plan->summary()['unresolvable'] );
	}

	public function test_counts_unresolvable_fields_without_registering(): void {
		$plan = $this->plan( [
			'field_nopref' => [ 'name' => 'perex' ],
		] );

		$plan->collect( [
			'gone'    => [ 'v' ],
			'_gone'   => [ 'field_missing' ],
			'perex'   => [ 'v' ],
			'_perex'  => [ 'field_nopref' ],
		] );

		$this->assertSame( [], $plan->patch( [] ) );
		$this->assertSame( [ 'gone', 'perex' ], $plan->summary()['unresolvable'] );
	}

	public function test_patch_is_idempotent_against_current_dictionary(): void {
		$plan = $this->plan( [
			'field_abc' => [ 'name' => 'title', 'wpml_cf_preferences' => 2 ],
		] );

		$plan->collect( [
			'title'  => [ 'Hello' ],
			'_title' => [ 'field_abc' ],
		] );

		$current = [
			'title'  => 2,
			'_title' => PreferenceSyncPlan::PREF_COPY,
		];

		$this->assertSame( [], $plan->patch( $current ) );
	}

	public function test_patch_updates_key_whose_current_preference_differs(): void {
		$plan = $this->plan( [
			'field_abc' => [ 'name' => 'title', 'wpml_cf_preferences' => 2 ],
		] );

		$plan->collect( [
			'title'  => [ 'Hello' ],
			'_title' => [ 'field_abc' ],
		] );

		$patch = $plan->patch( [ 'title' => 0, '_title' => PreferenceSyncPlan::PREF_COPY ] );

		$this->assertSame( [ 'title' => 2 ], $patch );
	}

	public function test_never_overwrites_existing_companion_entry(): void {
		$plan = $this->plan( [
			'field_abc' => [ 'name' => 'title', 'wpml_cf_preferences' => 2 ],
		] );

		$plan->collect( [
			'title'  => [ 'Hello' ],
			'_title' => [ 'field_abc' ],
		] );

		$patch = $plan->patch( [ '_title' => PreferenceSyncPlan::PREF_COPY_ONCE ] );

		$this->assertArrayNotHasKey( '_title', $patch );
		$this->assertSame( 2, $patch['title'] );
	}

	public function test_conflicting_preferences_for_same_key_are_excluded_and_reported(): void {
		$plan = $this->plan( [
			'field_a' => [ 'name' => 'title', 'wpml_cf_preferences' => 2 ],
			'field_b' => [ 'name' => 'title', 'wpml_cf_preferences' => 1 ],
		] );

		$plan->collect( [
			'title'  => [ 'Hello' ],
			'_title' => [ 'field_a' ],
		] );
		$plan->collect( [
			'title'  => [ 'Jiny objekt, jina definice' ],
			'_title' => [ 'field_b' ],
		] );

		$patch = $plan->patch( [] );

		$this->assertArrayNotHasKey( 'title', $patch );
		$this->assertArrayNotHasKey( '_title', $patch );
		$this->assertSame( [ 'title' => [ 2, 1 ] ], $plan->summary()['conflicts'] );
	}

	public function test_same_key_same_preference_across_objects_is_not_a_conflict(): void {
		$plan = $this->plan( [
			'field_a' => [ 'name' => 'title', 'wpml_cf_preferences' => 2 ],
		] );

		$plan->collect( [ 'title' => [ 'A' ], '_title' => [ 'field_a' ] ] );
		$plan->collect( [ 'title' => [ 'B' ], '_title' => [ 'field_a' ] ] );

		$this->assertSame( [], $plan->summary()['conflicts'] );
		$this->assertSame( 2, $plan->patch( [] )['title'] );
	}

	public function test_summary_reports_registered_counts_by_preference(): void {
		$plan = $this->plan( [
			'field_t' => [ 'name' => 'title', 'wpml_cf_preferences' => 2 ],
			'field_c' => [ 'name' => 'image', 'wpml_cf_preferences' => 1 ],
		] );

		$plan->collect( [
			'title'  => [ 'x' ],
			'_title' => [ 'field_t' ],
			'image'  => [ '7' ],
			'_image' => [ 'field_c' ],
		] );

		$summary = $plan->summary();

		$this->assertSame( [ 2 => 1, 1 => 1 ], $summary['registered_by_preference'] );
	}

	public function test_resolver_is_consulted_once_per_field_key(): void {
		$calls = 0;
		$plan  = new PreferenceSyncPlan( function ( string $field_key ) use ( &$calls ): ?array {
			$calls++;
			return [ 'name' => 'title', 'wpml_cf_preferences' => 2 ];
		} );

		$plan->collect( [ 'title' => [ 'A' ], '_title' => [ 'field_a' ] ] );
		$plan->collect( [ 'title' => [ 'B' ], '_title' => [ 'field_a' ] ] );

		$this->assertSame( 1, $calls );
	}
}
