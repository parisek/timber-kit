<?php

declare(strict_types=1);

namespace Tests\Integration\Search;

use Tests\Integration\IntegrationTestCase;

/**
 * disable_search() against real WordPress: the main query on a `/?s=` request.
 */
class DisableSearchTest extends IntegrationTestCase {

	private int $set404Calls = 0;

	public function test_set_404_flag_resets_conditionals_and_fires_the_action(): void {
		$this->bootKit( array( 'disable_search_use_set_404' => true ) );
		add_action(
			'set_404',
			function (): void {
				++$this->set404Calls;
			}
		);

		// A date archive that is also a search: core sets is_search, is_date and is_archive.
		$this->go_to( home_url( '/?s=needle&year=2020' ) );

		self::assertTrue( is_404() );
		self::assertFalse( is_search() );
		self::assertFalse( is_archive(), 'core resets every conditional before is_404' );
		self::assertFalse( is_date() );
		self::assertSame( '', $GLOBALS['wp_query']->get( 's' ) );
		self::assertSame( 1, $this->set404Calls );
	}

	public function test_flag_off_leaves_other_conditionals_on_the_404(): void {
		$this->bootKit( array( 'disable_search_use_set_404' => false ) );
		add_action(
			'set_404',
			function (): void {
				++$this->set404Calls;
			}
		);

		$this->go_to( home_url( '/?s=needle&year=2020' ) );

		self::assertTrue( is_404() );
		self::assertFalse( is_search() );
		self::assertTrue( is_archive(), 'the hand-written flags leave is_archive on' );
		self::assertSame( 0, $this->set404Calls );
	}
}
