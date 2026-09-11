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

	/** @var array<int, array{0: string, 1: array}> Every wp_kses() call, in order. */
	private array $kses_calls = [];

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'Europe/Prague' ) );

		// Record the call and model the tag filtering. Attribute filtering is
		// wp_kses()'s own job and WordPress's to get right — what belongs to
		// THIS class is which sanitiser it calls and which list it passes.
		$this->kses_calls = [];
		Functions\when( 'wp_kses' )->alias( function ( $string, $allowed ) {
			$this->kses_calls[] = [ $string, $allowed ];
			$tags = implode( '', array_map( static fn ( $t ) => "<$t>", array_keys( $allowed ) ) );
			return strip_tags( $string, $tags );
		} );
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

	/**
	 * The bar renders this through Alpine's `x-html`, so the stored value
	 * reaches the DOM as markup. Every other editor-content path in Helpers
	 * already goes through wp_kses(); this one used to be the exception.
	 */
	public function test_text_is_sanitised_with_the_editor_allowed_list(): void {
		Helpers::formatAnnouncement( [
			'enabled' => true,
			'text' => '<p>Zveme vás na <strong>akci</strong>.</p>',
		] );

		$this->assertCount( 1, $this->kses_calls, 'text must be sanitised exactly once' );
		$this->assertSame(
			Helpers::getEditorAllowedHtml(),
			$this->kses_calls[0][1],
			'the announcement is editor richtext, so it takes the editor list this class already defines'
		);
	}

	public function test_editor_markup_survives_unchanged(): void {
		// The shape a real announcement actually has: a paragraph, emphasis and
		// links. Sanitising must be a no-op for it, or the change would rewrite
		// live copy on every consuming site.
		$text = '<p>Zveme vás na <strong>přednášku</strong>. '
			. '<a href="https://example.test/prihlaska">Registrovat</a>.</p>';

		$result = Helpers::formatAnnouncement( [ 'enabled' => true, 'text' => $text ] );

		$this->assertSame( $text, $result['text'] );
	}

	public function test_markup_outside_the_editor_list_is_removed(): void {
		$result = Helpers::formatAnnouncement( [
			'enabled' => true,
			'text' => 'Pozor<script>alert(1)</script><iframe src="evil"></iframe> na termín.',
		] );

		$this->assertStringNotContainsString( '<script', $result['text'] );
		$this->assertStringNotContainsString( '<iframe', $result['text'] );
		$this->assertStringContainsString( 'Pozor', $result['text'] );
		$this->assertStringContainsString( 'na termín.', $result['text'] );
	}

	public function test_disabled_announcement_returns_empty_text_without_sanitising(): void {
		$result = Helpers::formatAnnouncement( [
			'enabled' => false,
			'text' => '<script>alert(1)</script>',
		] );

		$this->assertSame( '', $result['text'] );
		$this->assertSame( [], $this->kses_calls, 'a disabled bar has no text to sanitise' );
	}

	public function test_missing_text_key_sanitises_an_empty_string(): void {
		$result = Helpers::formatAnnouncement( [ 'enabled' => true ] );

		$this->assertSame( '', $result['text'] );
	}
}
