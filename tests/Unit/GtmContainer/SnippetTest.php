<?php

declare(strict_types=1);

namespace Tests\Unit\GtmContainer;

use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\GtmContainer;

/**
 * The emitted loader snippet, asserted on the source string.
 *
 * Two shapes exist and the difference is load-bearing. A server-side
 * container addressed by its own path carries no container ID in the URL,
 * so the query string starts at `?l=`; the default googletagmanager loader
 * needs the ID and continues an existing query string with `&l=`.
 */
class SnippetTest extends TestCase {

	public function test_custom_path_omits_the_container_id(): void {
		$snippet = GtmContainer::snippet(
			array(
				'id'     => 'GTM-N9FNXT1',
				'domain' => 'windstream.example.com',
				'path'   => '84jp8NTuqpqDvI/',
			)
		);

		$this->assertStringContainsString( "j.src=\n'https://windstream.example.com/84jp8NTuqpqDvI/'+dl;", $snippet );
		$this->assertStringNotContainsString( 'id=', $snippet );
		$this->assertStringNotContainsString( 'GTM-N9FNXT1', $snippet );
	}

	public function test_custom_path_starts_the_query_string_with_a_question_mark(): void {
		$snippet = GtmContainer::snippet(
			array(
				'id'   => 'GTM-N9FNXT1',
				'path' => '84jp8NTuqpqDvI/',
			)
		);

		$this->assertStringContainsString( "dl=l!='dataLayer'?'?l='+l:''", $snippet );
	}

	public function test_default_loader_keeps_the_container_id(): void {
		$snippet = GtmContainer::snippet( array( 'id' => 'GTM-N9FNXT1' ) );

		$this->assertStringContainsString( "j.src=\n'https://www.googletagmanager.com/gtm.js?id='+i+dl;", $snippet );
		$this->assertStringContainsString( "'script','dataLayer','GTM-N9FNXT1');", $snippet );
		$this->assertStringContainsString( "dl=l!='dataLayer'?'&l='+l:''", $snippet );
	}

	public function test_custom_domain_without_a_path_serves_gtm_js_with_the_id(): void {
		$snippet = GtmContainer::snippet(
			array(
				'id'     => 'GTM-N9FNXT1',
				'domain' => 'windstream.example.com',
			)
		);

		$this->assertStringContainsString( "'https://windstream.example.com/gtm.js?id='+i+dl;", $snippet );
	}

	public function test_id_free_loader_drops_the_unused_iife_argument(): void {
		$snippet = GtmContainer::snippet(
			array(
				'id'   => 'GTM-N9FNXT1',
				'path' => '84jp8NTuqpqDvI/',
			)
		);

		$this->assertStringContainsString( "})(window,document,'script','dataLayer');", $snippet );
		$this->assertStringContainsString( '(function(w,d,s,l){', $snippet );
	}

	public function test_environment_parameters_are_appended_when_both_are_set(): void {
		$snippet = GtmContainer::snippet(
			array(
				'id'          => 'GTM-N9FNXT1',
				'gtm_auth'    => 'abc123',
				'gtm_preview' => 'env-5',
			)
		);

		$this->assertStringContainsString( "+'&gtm_auth=abc123&gtm_preview=env-5&gtm_cookies_win=x'", $snippet );
	}

	public function test_environment_parameters_need_both_halves(): void {
		$snippet = GtmContainer::snippet(
			array(
				'id'       => 'GTM-N9FNXT1',
				'gtm_auth' => 'abc123',
			)
		);

		$this->assertStringNotContainsString( 'gtm_auth', $snippet );
	}

	public function test_a_malformed_container_id_emits_nothing(): void {
		$this->assertSame( '', GtmContainer::snippet( array( 'id' => 'gtm-lowercase' ) ) );
		$this->assertSame( '', GtmContainer::snippet( array( 'id' => '0' ) ) );
		$this->assertSame( '', GtmContainer::snippet( array( 'id' => '' ) ) );
		$this->assertSame( '', GtmContainer::snippet( array() ) );
	}

	public function test_a_malformed_domain_falls_back_to_the_google_host(): void {
		$snippet = GtmContainer::snippet(
			array(
				'id'     => 'GTM-N9FNXT1',
				'domain' => "evil.example.com';alert(1);//",
			)
		);

		$this->assertStringContainsString( "'https://www.googletagmanager.com/gtm.js?id='", $snippet );
		$this->assertStringNotContainsString( 'alert(1)', $snippet );
	}

	/**
	 * A path carrying a query string cannot be honoured — the loader appends
	 * its own. Falling back to the plain gtm.js path keeps the container
	 * reachable instead of emitting a URL that resolves to nothing.
	 */
	public function test_a_path_outside_the_allowed_charset_falls_back_to_gtm_js(): void {
		$snippet = GtmContainer::snippet(
			array(
				'id'     => 'GTM-N9FNXT1',
				'domain' => 'windstream.example.com',
				'path'   => "84jp8NTuqpqDvI/?x='+alert(1)+'",
			)
		);

		$this->assertStringContainsString( "'https://windstream.example.com/gtm.js?id='+i+dl;", $snippet );
		$this->assertStringNotContainsString( 'alert(1)', $snippet );
	}

	public function test_the_data_layer_variable_name_reaches_the_iife(): void {
		$snippet = GtmContainer::snippet( array( 'id' => 'GTM-N9FNXT1' ), 'portaLayer' );

		$this->assertStringContainsString( "'script','portaLayer','GTM-N9FNXT1');", $snippet );
	}

	public function test_a_malformed_data_layer_name_falls_back_to_the_default(): void {
		$snippet = GtmContainer::snippet( array( 'id' => 'GTM-N9FNXT1' ), "x';alert(1);//" );

		$this->assertStringContainsString( "'script','dataLayer','GTM-N9FNXT1');", $snippet );
		$this->assertStringNotContainsString( 'alert(1)', $snippet );
	}
}
