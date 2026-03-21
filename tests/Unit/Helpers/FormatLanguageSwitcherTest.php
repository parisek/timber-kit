<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Tests\Unit\HelpersTestCase;
use Parisek\TimberKit\Helpers;
use Brain\Monkey\Functions;

class FormatLanguageSwitcherTest extends HelpersTestCase {

	public function test_returns_empty_when_wpml_not_active(): void {
		Functions\when( 'defined' )->justReturn( false );

		$result = Helpers::formatLanguageSwitcher();

		$this->assertSame( [], $result );
	}

	public function test_returns_empty_when_no_languages(): void {
		Functions\when( 'defined' )->justReturn( true );
		Functions\when( 'apply_filters' )->justReturn( null );

		// Mock global sitepress
		$GLOBALS['sitepress'] = new class {
			public function language_url( string $code ): string {
				return "https://example.com/{$code}/";
			}
		};

		$result = Helpers::formatLanguageSwitcher();

		$this->assertSame( [], $result );

		unset( $GLOBALS['sitepress'] );
	}

	public function test_formats_active_languages(): void {
		Functions\when( 'defined' )->justReturn( true );
		Functions\when( 'esc_url' )->alias( fn( $s ) => $s );
		Functions\when( 'esc_html' )->alias( fn( $s ) => $s );
		Functions\when( 'apply_filters' )->justReturn( [
			'en' => [
				'language_code' => 'en',
				'native_name' => 'English',
				'url' => 'https://example.com/en/',
				'active' => 1,
			],
			'cs' => [
				'language_code' => 'cs',
				'native_name' => 'Čeština',
				'url' => 'https://example.com/cs/',
				'active' => 0,
			],
		] );

		$GLOBALS['sitepress'] = new class {
			public function language_url( string $code ): string {
				return "https://example.com/{$code}/";
			}
		};

		$result = Helpers::formatLanguageSwitcher();

		$this->assertCount( 2, $result );
		$this->assertSame( 'en', $result[0]['id'] );
		$this->assertSame( 'English', $result[0]['title'] );
		$this->assertTrue( $result[0]['is_active'] );
		$this->assertSame( 'cs', $result[1]['id'] );
		$this->assertFalse( $result[1]['is_active'] );

		unset( $GLOBALS['sitepress'] );
	}

	public function test_clears_url_for_missing_translations(): void {
		Functions\when( 'defined' )->justReturn( true );
		Functions\when( 'esc_url' )->alias( fn( $s ) => $s );
		Functions\when( 'esc_html' )->alias( fn( $s ) => $s );
		Functions\when( 'apply_filters' )->justReturn( [
			'de' => [
				'language_code' => 'de',
				'native_name' => 'Deutsch',
				'url' => 'https://example.com/de/',
				'active' => 0,
				'missing' => 1,
			],
		] );

		$GLOBALS['sitepress'] = new class {
			public function language_url( string $code ): string {
				return "https://example.com/{$code}/";
			}
		};

		$result = Helpers::formatLanguageSwitcher();

		$this->assertSame( '', $result[0]['url'] );
		$this->assertSame( 'https://example.com/de/', $result[0]['home_url'] );

		unset( $GLOBALS['sitepress'] );
	}
}
