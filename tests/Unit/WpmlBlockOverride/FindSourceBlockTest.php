<?php

declare(strict_types=1);

namespace Tests\Unit\WpmlBlockOverride;

use Parisek\TimberKit\WpmlBlockOverride;
use Tests\Unit\WpmlBlockOverrideTestCase;

class FindSourceBlockTest extends WpmlBlockOverrideTestCase {

	public function test_matches_by_attrs_id(): void {
		$block   = array( 'attrs' => array( 'id' => 'block_abc' ) );
		$sources = array(
			array( 'attrs' => array( 'id' => 'block_zzz', 'data' => array( 'x' => 1 ) ) ),
			array( 'attrs' => array( 'id' => 'block_abc', 'data' => array( 'x' => 2 ) ) ),
		);

		$found = WpmlBlockOverride::findSourceBlock( $block, $sources );

		$this->assertSame( 2, $found['attrs']['data']['x'] );
	}

	public function test_recurses_into_inner_blocks(): void {
		$block   = array( 'attrs' => array( 'id' => 'block_nested' ) );
		$sources = array(
			array(
				'attrs'       => array( 'id' => 'block_columns' ),
				'innerBlocks' => array(
					array( 'attrs' => array( 'id' => 'block_nested', 'data' => array( 'hit' => true ) ) ),
				),
			),
		);

		$found = WpmlBlockOverride::findSourceBlock( $block, $sources );

		$this->assertNotNull( $found );
		$this->assertTrue( $found['attrs']['data']['hit'] );
	}

	public function test_returns_null_when_block_has_no_id(): void {
		$block   = array( 'attrs' => array( 'data' => array() ) );
		$sources = array( array( 'attrs' => array( 'id' => 'block_abc' ) ) );

		$this->assertNull( WpmlBlockOverride::findSourceBlock( $block, $sources ) );
	}

	public function test_returns_null_when_no_match(): void {
		$block   = array( 'attrs' => array( 'id' => 'block_missing' ) );
		$sources = array( array( 'attrs' => array( 'id' => 'block_abc' ) ) );

		$this->assertNull( WpmlBlockOverride::findSourceBlock( $block, $sources ) );
	}
}
