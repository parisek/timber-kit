<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Tests\Unit\StarterBaseTestCase;

/**
 * Verifies big_image_size_threshold() resolves the upload size cap from the
 * canonical $big_image_size_threshold property, honouring the deprecated
 * $max_upload_width / $max_upload_height pair for backward compatibility.
 */
class BigImageSizeThresholdTest extends StarterBaseTestCase {

	public function test_returns_canonical_property_value(): void {
		$base = $this->createStarterBase( [
			'max_upload_width'         => null,
			'max_upload_height'        => null,
			'big_image_size_threshold' => 4000,
		] );

		$this->assertSame( 4000, $base->big_image_size_threshold( 2560 ) );
	}

	public function test_legacy_dimensions_take_precedence_when_set(): void {
		$base = $this->createStarterBase( [
			'max_upload_width'         => 4000,
			'max_upload_height'        => 4000,
			'big_image_size_threshold' => 2560,
		] );

		$this->assertSame( 4000, $base->big_image_size_threshold( 2560 ) );
	}

	public function test_legacy_uses_larger_of_width_and_height(): void {
		$base = $this->createStarterBase( [
			'max_upload_width'         => 4000,
			'max_upload_height'        => 2000,
			'big_image_size_threshold' => 0,
		] );

		$this->assertSame( 4000, $base->big_image_size_threshold( 2560 ) );
	}

	public function test_returns_zero_to_disable_when_threshold_is_zero(): void {
		// 0 disables core scaling — the callback returns 0 (ignoring the incoming
		// core value) rather than falling back to it.
		$base = $this->createStarterBase( [
			'max_upload_width'         => null,
			'max_upload_height'        => null,
			'big_image_size_threshold' => 0,
		] );

		$this->assertSame( 0, $base->big_image_size_threshold( 2560 ) );
	}

	public function test_legacy_zero_dimensions_disable_scaling(): void {
		// Backward-compat: the legacy "both 0 disables resizing" contract — explicit
		// 0 (non-null) takes the legacy branch and returns 0.
		$base = $this->createStarterBase( [
			'max_upload_width'         => 0,
			'max_upload_height'        => 0,
			'big_image_size_threshold' => 2560,
		] );

		$this->assertSame( 0, $base->big_image_size_threshold( 2560 ) );
	}

	public function test_ignores_incoming_value_when_canonical_set(): void {
		// Authoritative: an upstream plugin filter (incoming 4000) does not win.
		$base = $this->createStarterBase( [
			'max_upload_width'         => null,
			'max_upload_height'        => null,
			'big_image_size_threshold' => 2560,
		] );

		$this->assertSame( 2560, $base->big_image_size_threshold( 4000 ) );
	}
}
