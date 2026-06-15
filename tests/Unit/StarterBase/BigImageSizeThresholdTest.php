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

	public function test_returns_incoming_threshold_when_disabled(): void {
		$base = $this->createStarterBase( [
			'max_upload_width'         => null,
			'max_upload_height'        => null,
			'big_image_size_threshold' => 0,
		] );

		$this->assertSame( 2560, $base->big_image_size_threshold( 2560 ) );
	}
}
