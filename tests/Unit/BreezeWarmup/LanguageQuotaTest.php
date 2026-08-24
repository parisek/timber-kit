<?php

declare(strict_types=1);

namespace Tests\Unit\BreezeWarmup;

use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\BreezeWarmup\LanguageQuota;

/**
 * Covers the per-language budget split.
 *
 * The cap is divided between languages, never multiplied by them — otherwise
 * going trilingual would silently triple origin load. Guarantees (every
 * language's homepage and menu items) win over the cap, so the cap is soft.
 */
class LanguageQuotaTest extends TestCase {

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function record( string $url, array $overrides = array() ): array {
		return array_merge(
			array(
				'url'        => $url,
				'key'        => $url,
				'lastmod'    => null,
				'type'       => '',
				'lang'       => 'cs',
				'menu'       => false,
				'front_page' => false,
				'manual'     => false,
				'score'      => 0,
			),
			$overrides
		);
	}

	public function test_single_language_is_a_plain_cap(): void {
		$records = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$records[] = $this->record( 'https://example.test/' . $i . '/', array( 'score' => 100 - $i ) );
		}

		$result = LanguageQuota::apply( $records, 4 );

		$this->assertCount( 4, $result );
		$this->assertSame( 'https://example.test/0/', $result[0]['url'] );
	}

	public function test_budget_is_split_proportionally_to_url_count(): void {
		// 8 Czech URLs, 2 Slovak, cap 5 -> Czech gets 4, Slovak gets 1.
		$records = array();
		for ( $i = 0; $i < 8; $i++ ) {
			$records[] = $this->record( 'https://example.test/cs' . $i . '/', array( 'lang' => 'cs', 'score' => 10 ) );
		}
		for ( $i = 0; $i < 2; $i++ ) {
			$records[] = $this->record( 'https://example.test/sk' . $i . '/', array( 'lang' => 'sk', 'score' => 10 ) );
		}

		$result = LanguageQuota::apply( $records, 5 );
		$byLang = array_count_values( array_column( $result, 'lang' ) );

		$this->assertSame( 4, $byLang['cs'] );
		$this->assertSame( 1, $byLang['sk'] );
	}

	public function test_front_page_and_menu_are_always_kept(): void {
		$records = array(
			$this->record( 'https://example.test/', array( 'lang' => 'cs', 'front_page' => true, 'score' => 1000 ) ),
			$this->record( 'https://example.test/sk/', array( 'lang' => 'sk', 'front_page' => true, 'score' => 1000 ) ),
			$this->record( 'https://example.test/kontakt/', array( 'lang' => 'cs', 'menu' => true, 'score' => 500 ) ),
			$this->record( 'https://example.test/filler/', array( 'lang' => 'cs', 'score' => 1 ) ),
		);

		$result = LanguageQuota::apply( $records, 1 );
		$urls   = array_column( $result, 'url' );

		$this->assertContains( 'https://example.test/', $urls );
		$this->assertContains( 'https://example.test/sk/', $urls );
		$this->assertContains( 'https://example.test/kontakt/', $urls );
		$this->assertNotContains( 'https://example.test/filler/', $urls );
	}

	public function test_guarantees_may_overflow_the_cap(): void {
		// Cap 2, but four guaranteed records. Guarantees win; the cap is soft.
		$records = array();
		foreach ( array( 'cs', 'sk', 'en', 'de' ) as $lang ) {
			$records[] = $this->record(
				'https://example.test/' . $lang . '/',
				array( 'lang' => $lang, 'front_page' => true, 'score' => 1000 )
			);
		}

		$result = LanguageQuota::apply( $records, 2 );

		$this->assertCount( 4, $result );
	}

	public function test_zero_cap_still_keeps_guarantees(): void {
		$records = array(
			$this->record( 'https://example.test/', array( 'front_page' => true, 'score' => 1000 ) ),
			$this->record( 'https://example.test/x/', array( 'score' => 1 ) ),
		);

		$result = LanguageQuota::apply( $records, 0 );

		$this->assertCount( 1, $result );
		$this->assertSame( 'https://example.test/', $result[0]['url'] );
	}

	public function test_preserves_input_order(): void {
		// apply() selects; it must not reorder. Sorting happens after.
		$records = array(
			$this->record( 'https://example.test/a/', array( 'score' => 5 ) ),
			$this->record( 'https://example.test/b/', array( 'score' => 9 ) ),
		);

		$result = LanguageQuota::apply( $records, 2 );

		$this->assertSame(
			array( 'https://example.test/a/', 'https://example.test/b/' ),
			array_column( $result, 'url' )
		);
	}
}
