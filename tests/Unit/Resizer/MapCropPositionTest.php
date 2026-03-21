<?php

declare(strict_types=1);

namespace Tests\Unit\Resizer;

use Spatie\Image\Enums\CropPosition;
use Tests\Unit\ResizerTestCase;

class MapCropPositionTest extends ResizerTestCase {

	public function test_top(): void {
		$resizer = $this->createResizer();
		$result = $this->callPrivate( $resizer, 'mapCropPosition', [ 'top' ] );
		$this->assertSame( CropPosition::Top, $result );
	}

	public function test_bottom(): void {
		$resizer = $this->createResizer();
		$result = $this->callPrivate( $resizer, 'mapCropPosition', [ 'bottom' ] );
		$this->assertSame( CropPosition::Bottom, $result );
	}

	public function test_left(): void {
		$resizer = $this->createResizer();
		$result = $this->callPrivate( $resizer, 'mapCropPosition', [ 'left' ] );
		$this->assertSame( CropPosition::Left, $result );
	}

	public function test_right(): void {
		$resizer = $this->createResizer();
		$result = $this->callPrivate( $resizer, 'mapCropPosition', [ 'right' ] );
		$this->assertSame( CropPosition::Right, $result );
	}

	public function test_center(): void {
		$resizer = $this->createResizer();
		$result = $this->callPrivate( $resizer, 'mapCropPosition', [ 'center' ] );
		$this->assertSame( CropPosition::Center, $result );
	}

	public function test_crop_maps_to_center(): void {
		$resizer = $this->createResizer();
		$result = $this->callPrivate( $resizer, 'mapCropPosition', [ 'crop' ] );
		$this->assertSame( CropPosition::Center, $result );
	}

	public function test_unknown_defaults_to_center(): void {
		$resizer = $this->createResizer();
		$result = $this->callPrivate( $resizer, 'mapCropPosition', [ 'foobar' ] );
		$this->assertSame( CropPosition::Center, $result );
	}

	public function test_uppercase_input(): void {
		$resizer = $this->createResizer();
		$result = $this->callPrivate( $resizer, 'mapCropPosition', [ 'TOP' ] );
		$this->assertSame( CropPosition::Top, $result );
	}

	public function test_mixed_case(): void {
		$resizer = $this->createResizer();
		$result = $this->callPrivate( $resizer, 'mapCropPosition', [ 'Bottom' ] );
		$this->assertSame( CropPosition::Bottom, $result );
	}
}
