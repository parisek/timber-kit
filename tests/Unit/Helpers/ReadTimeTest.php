<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Helpers;
use Tests\Unit\HelpersTestCase;

class ReadTimeTest extends HelpersTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'apply_filters' )->alias( function ( $filter, $value ) {
			return $value;
		} );
	}

	public function test_empty_content_returns_minimum_one_minute(): void {
		$this->assertSame( 1, Helpers::readTime( '' ) );
	}

	public function test_short_content_returns_one_minute(): void {
		$text = str_repeat( 'word ', 50 );

		$this->assertSame( 1, Helpers::readTime( $text, 220 ) );
	}

	public function test_long_content_rounds_up(): void {
		$text = str_repeat( 'word ', 500 );

		// 500 words / 220 wpm = 2.27 → ceil = 3
		$this->assertSame( 3, Helpers::readTime( $text, 220 ) );
	}

	public function test_images_add_to_reading_budget(): void {
		// 12 images * 12 seconds = 144 seconds = 2.4 minutes → ceil = 3
		$content = '<p>One word.</p>' . str_repeat( '<img src="x.jpg" />', 12 );

		$this->assertSame( 3, Helpers::readTime( $content, 220, 12 ) );
	}

	public function test_image_counting_is_case_insensitive(): void {
		// 12 mixed-case images * 12 seconds = 144 seconds = 2.4 minutes → ceil = 3
		$content = '<p>One word.</p>' . str_repeat( '<IMG src="x.jpg" />', 6 ) . str_repeat( '<Img src="y.jpg" />', 6 );

		$this->assertSame( 3, Helpers::readTime( $content, 220, 12 ) );
	}

	public function test_locale_region_language_falls_back_to_two_letter_prefix(): void {
		// Brazilian Portuguese: getLanguage() returns 'pt-br' verbatim; WPM lookup must
		// fall back to the 'pt' / Romance prefix in the default map. Default map only
		// has 'en/fr/es/it' = 220 for Romance; 'pt' isn't listed, so override via filter.
		Functions\when( 'apply_filters' )->alias( function ( $filter, $value, $post_id = null ) {
			if ( $filter === 'wpml_post_language_details' && $post_id === 11 ) {
				return [ 'language_code' => 'pt-br' ];
			}
			if ( $filter === 'timber_kit_read_time_wpm_per_language' ) {
				return [ 'pt' => 200 ];
			}
			return $value;
		} );
		Functions\when( 'get_post' )->alias( function ( $id ) {
			return $id === 11 ? new \WP_Post( [ 'ID' => 11, 'post_content' => str_repeat( 'word ', 200 ) ] ) : null;
		} );

		// 200 words / 200 wpm (matched via 'pt' prefix of 'pt-br') = 1.0 → ceil 1
		$this->assertSame( 1, Helpers::readTime( 11 ) );
	}

	public function test_explicit_wpm_bypasses_language_detection(): void {
		$text = str_repeat( 'word ', 200 );

		// Even if locale would pick 220 wpm, an explicit 100 wpm wins.
		$this->assertSame( 2, Helpers::readTime( $text, 100 ) );
	}

	public function test_unicode_word_counting_handles_czech_diacritics(): void {
		// 220 words, all with Czech diacritics. preg_match_all('/\p{L}+/u') should count them.
		$text = str_repeat( 'příliš žluťoučký kůň úpěl ďábelské ódy ', 22 );

		// 220 czech words / 220 wpm = 1.0 → ceil 1 (but Slavic auto-detection at en_US locale ≠ cs, so 220 wpm applies)
		$this->assertSame( 1, Helpers::readTime( $text, 220 ) );
	}

	public function test_auto_detects_lower_wpm_for_czech_post(): void {
		Functions\when( 'get_locale' )->justReturn( 'cs_CZ' );

		// 170 words, exactly 1 minute at the cs default (170 wpm).
		// At default 200 wpm this would still ceil to 1, so use 200 words to differentiate.
		$text = str_repeat( 'slovo ', 200 );

		// 200 / 170 = 1.176 → ceil 2  (vs. 200 / 220 = 0.91 → 1 at en defaults)
		$this->assertSame( 2, Helpers::readTime( $text ) );
	}

	public function test_filter_can_override_language_map(): void {
		Functions\when( 'get_locale' )->justReturn( 'cs_CZ' );
		Functions\when( 'apply_filters' )->alias( function ( $filter, $value ) {
			if ( $filter === 'timber_kit_read_time_wpm_per_language' ) {
				// Bump Czech to 250 wpm.
				return [ 'cs' => 250 ];
			}
			return $value;
		} );

		$text = str_repeat( 'slovo ', 250 );

		// 250 / 250 = 1.0 → 1 minute.
		$this->assertSame( 1, Helpers::readTime( $text ) );
	}

	public function test_filter_can_override_final_minutes(): void {
		Functions\when( 'apply_filters' )->alias( function ( $filter, $value ) {
			if ( $filter === 'timber_kit_read_time_minutes' ) {
				return 99;
			}
			return $value;
		} );

		$this->assertSame( 99, Helpers::readTime( 'short', 200 ) );
	}

	public function test_negative_seconds_per_image_is_clamped_to_zero(): void {
		$content = '<img src="a" /><img src="b" />';

		// Negative would otherwise subtract from the budget. Should clamp to 0 → 1 minute.
		$this->assertSame( 1, Helpers::readTime( $content, 220, -100 ) );
	}

	public function test_post_id_source_pulls_content_via_get_post(): void {
		Functions\when( 'get_post' )->alias( function ( $id ) {
			return $id === 5
				? new \WP_Post( [ 'ID' => 5, 'post_content' => str_repeat( 'word ', 440 ) ] )
				: null;
		} );

		// 440 words / 220 wpm = 2.0 → ceil 2
		$this->assertSame( 2, Helpers::readTime( 5, 220 ) );
	}
}
