<?php

declare(strict_types=1);

namespace Tests\Unit\Resizer;

use Tests\Unit\ResizerTestCase;

class FindOptimalSubgridTest extends ResizerTestCase {

	public function test_uniform_grid(): void {
		$resizer = $this->createResizer();

		// 4x4 grid, all cells = 1.0, cell size = 100px
		$grid = array_fill( 0, 4, array_fill( 0, 4, 1.0 ) );

		$result = $this->callPrivate( $resizer, 'findOptimalSubgrid', [
			$grid,    // entropyGrid
			4,        // gridRows
			4,        // gridCols
			100.0,    // cellWidth
			100.0,    // cellHeight
			200,      // cropWidth
			200,      // cropHeight
			2,        // subRows
			2,        // subCols
		] );

		$this->assertArrayHasKey( 'x', $result );
		$this->assertArrayHasKey( 'y', $result );
		$this->assertSame( 200, $result['width'] );
		$this->assertSame( 200, $result['height'] );
		// With uniform entropy, first match wins → top-left area
		$this->assertSame( 0, $result['x'] );
		$this->assertSame( 0, $result['y'] );
	}

	public function test_high_entropy_corner(): void {
		$resizer = $this->createResizer();

		// 4x4 grid, top-right corner (row 0, col 3) has high entropy
		$grid = array_fill( 0, 4, array_fill( 0, 4, 0.0 ) );
		$grid[0][3] = 8.0;

		$result = $this->callPrivate( $resizer, 'findOptimalSubgrid', [
			$grid,
			4,        // gridRows
			4,        // gridCols
			100.0,    // cellWidth
			100.0,    // cellHeight
			100,      // cropWidth
			100,      // cropHeight
			2,        // subRows
			2,        // subCols
		] );

		// Should center near the high-entropy cell (col 3, row 0)
		$this->assertGreaterThan( 100, $result['x'] );
		$this->assertSame( 100, $result['width'] );
		$this->assertSame( 100, $result['height'] );
	}

	public function test_high_entropy_center(): void {
		$resizer = $this->createResizer();

		// 5x5 grid, center cell (row 2, col 2) has high entropy
		$grid = array_fill( 0, 5, array_fill( 0, 5, 0.0 ) );
		$grid[2][2] = 8.0;

		$result = $this->callPrivate( $resizer, 'findOptimalSubgrid', [
			$grid,
			5,        // gridRows
			5,        // gridCols
			100.0,    // cellWidth
			100.0,    // cellHeight
			200,      // cropWidth
			200,      // cropHeight
			3,        // subRows
			3,        // subCols
		] );

		// Should center on the high-entropy cell area
		$this->assertGreaterThanOrEqual( 0, $result['x'] );
		$this->assertGreaterThanOrEqual( 0, $result['y'] );
		$this->assertSame( 200, $result['width'] );
		$this->assertSame( 200, $result['height'] );
	}

	public function test_single_cell_grid(): void {
		$resizer = $this->createResizer();

		$grid = [ [ 5.0 ] ];

		$result = $this->callPrivate( $resizer, 'findOptimalSubgrid', [
			$grid,
			1,        // gridRows
			1,        // gridCols
			200.0,    // cellWidth
			200.0,    // cellHeight
			100,      // cropWidth
			100,      // cropHeight
			1,        // subRows
			1,        // subCols
		] );

		// Center of 1x1 grid (200px cell) = 100px; crop of 100px centered = x:50, y:50
		$this->assertSame( 50, $result['x'] );
		$this->assertSame( 50, $result['y'] );
		$this->assertSame( 100, $result['width'] );
		$this->assertSame( 100, $result['height'] );
	}

	public function test_bottom_right_entropy(): void {
		$resizer = $this->createResizer();

		// 4x4 grid, bottom-right corner (row 3, col 3) has high entropy
		$grid = array_fill( 0, 4, array_fill( 0, 4, 0.0 ) );
		$grid[3][3] = 10.0;

		$result = $this->callPrivate( $resizer, 'findOptimalSubgrid', [
			$grid,
			4,        // gridRows
			4,        // gridCols
			100.0,    // cellWidth
			100.0,    // cellHeight
			100,      // cropWidth
			100,      // cropHeight
			2,        // subRows
			2,        // subCols
		] );

		// Should position near the bottom-right
		$this->assertGreaterThan( 100, $result['x'] );
		$this->assertGreaterThan( 100, $result['y'] );
		$this->assertSame( 100, $result['width'] );
		$this->assertSame( 100, $result['height'] );
	}

	public function test_all_zero_entropy(): void {
		$resizer = $this->createResizer();

		// All cells zero — no entropy anywhere
		$grid = array_fill( 0, 3, array_fill( 0, 3, 0.0 ) );

		$result = $this->callPrivate( $resizer, 'findOptimalSubgrid', [
			$grid,
			3,        // gridRows
			3,        // gridCols
			100.0,    // cellWidth
			100.0,    // cellHeight
			100,      // cropWidth
			100,      // cropHeight
			2,        // subRows
			2,        // subCols
		] );

		// No winner, first position wins → top-left subgrid (rows 0-1, cols 0-1)
		// Subgrid center = (1*100, 1*100) = (100, 100), crop centered = (50, 50)
		$this->assertSame( 50, $result['x'] );
		$this->assertSame( 50, $result['y'] );
	}

	public function test_non_square_grid(): void {
		$resizer = $this->createResizer();

		// 2 rows x 6 cols, high entropy at col 5
		$grid = array_fill( 0, 2, array_fill( 0, 6, 0.0 ) );
		$grid[0][5] = 7.0;
		$grid[1][5] = 7.0;

		$result = $this->callPrivate( $resizer, 'findOptimalSubgrid', [
			$grid,
			2,        // gridRows
			6,        // gridCols
			50.0,     // cellWidth
			100.0,    // cellHeight
			100,      // cropWidth
			200,      // cropHeight
			2,        // subRows
			2,        // subCols
		] );

		// Should shift toward the right side (cols 4-5)
		$this->assertGreaterThan( 100, $result['x'] );
		$this->assertSame( 100, $result['width'] );
		$this->assertSame( 200, $result['height'] );
	}

	public function test_adjacent_high_entropy_cluster(): void {
		$resizer = $this->createResizer();

		// 5x5 grid, cluster of high entropy at rows 1-2, cols 1-2
		$grid = array_fill( 0, 5, array_fill( 0, 5, 0.0 ) );
		$grid[1][1] = 5.0;
		$grid[1][2] = 5.0;
		$grid[2][1] = 5.0;
		$grid[2][2] = 5.0;

		// Also a single very high entropy cell at (4,4)
		$grid[4][4] = 15.0;

		$result = $this->callPrivate( $resizer, 'findOptimalSubgrid', [
			$grid,
			5,        // gridRows
			5,        // gridCols
			100.0,    // cellWidth
			100.0,    // cellHeight
			200,      // cropWidth
			200,      // cropHeight
			2,        // subRows
			2,        // subCols
		] );

		// Cluster (4x5.0=20.0) beats single (15.0 + 3x0.0 = 15.0)
		// Best subgrid is rows 1-2, cols 1-2 → center at (200, 200)
		$this->assertSame( 200, $result['width'] );
		$this->assertSame( 200, $result['height'] );
		$this->assertLessThanOrEqual( 200, $result['x'] );
		$this->assertLessThanOrEqual( 200, $result['y'] );
	}
}
