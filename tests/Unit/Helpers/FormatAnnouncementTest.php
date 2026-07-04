<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Helpers;
use Tests\Unit\HelpersTestCase;

/**
 * Verifies Helpers::formatAnnouncement() — the announcement-bar formatter
 * previously copy-pasted as a private get_announcement() across projects.
 * ACF date_picker "U" timestamps (midnight UTC) are re-anchored to
 * wp_timezone() day bounds and returned as millisecond timestamps.
 */
class FormatAnnouncementTest extends HelpersTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'Europe/Prague' ) );
	}

	public function test_null_value_returns_disabled_shape(): void {
		$this->assertSame(
			[ 'text' => '', 'date_from' => 0, 'date_to' => 0 ],
			Helpers::formatAnnouncement( null )
		);
	}

	public function test_disabled_announcement_suppresses_text_and_dates(): void {
		$result = Helpers::formatAnnouncement( [
			'enabled' => false,
			'text' => 'Vánoční provoz',
			'dates' => [ 'date_from' => 1728000000, 'date_to' => 1728000000 ],
		] );

		$this->assertSame( [ 'text' => '', 'date_from' => 0, 'date_to' => 0 ], $result );
	}

	public function test_enabled_without_dates_returns_text_and_zero_bounds(): void {
		$result = Helpers::formatAnnouncement( [ 'enabled' => true, 'text' => 'Provoz omezen' ] );

		$this->assertSame( [ 'text' => 'Provoz omezen', 'date_from' => 0, 'date_to' => 0 ], $result );
	}

	public function test_dates_are_reanchored_to_wp_timezone_day_bounds_in_milliseconds(): void {
		// 1728000000 = 2024-10-04T00:00:00Z (ACF date_picker "U" — midnight UTC).
		// Europe/Prague is CEST (UTC+2) on that date:
		//   2024-10-04 00:00:00 +02:00 → 1727992800
		//   2024-10-04 23:59:59 +02:00 → 1728079199
		$result = Helpers::formatAnnouncement( [
			'enabled' => true,
			'text' => 'Akce',
			'dates' => [ 'date_from' => 1728000000, 'date_to' => 1728000000 ],
		] );

		$this->assertSame( 1727992800000, $result['date_from'], 'date_from anchors to 00:00:00 local' );
		$this->assertSame( 1728079199000, $result['date_to'], 'date_to anchors to 23:59:59 local' );
	}

	public function test_accepts_string_timestamps_as_acf_returns_them(): void {
		$result = Helpers::formatAnnouncement( [
			'enabled' => true,
			'text' => 'Akce',
			'dates' => [ 'date_from' => '1728000000', 'date_to' => '' ],
		] );

		$this->assertSame( 1727992800000, $result['date_from'] );
		$this->assertSame( 0, $result['date_to'], 'empty string timestamp collapses to 0' );
	}
}
