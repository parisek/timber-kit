<?php

declare(strict_types=1);

namespace Tests\Integration\Health;

use Tests\Integration\IntegrationTestCase;
use WpOrg\Requests\Utility\CaseInsensitiveDictionary;

/**
 * The duplicate-security-headers detector against the headers object WordPress
 * really hands back.
 *
 * `wp_remote_retrieve_headers()` returns a `CaseInsensitiveDictionary`, an
 * ArrayAccess with no `getValues()`. The detector once asked for `getValues()`,
 * so on real WordPress it ended as "could not verify" every time, and the unit
 * tests could not see it: they built the response from a stub that had the
 * method. This test builds the response the way core does.
 */
final class DuplicateSecurityHeadersTest extends IntegrationTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_transient( 'timber_kit_duplicate_security_headers' );
	}

	public function test_a_header_on_two_lines_is_reported(): void {
		$result = $this->checkWith( array( 'x-content-type-options' => array( 'nosniff', 'nosniff' ) ) );

		self::assertSame( 'recommended', $result['status'] );
		self::assertStringContainsString( 'x-content-type-options', $result['description'] );
	}

	public function test_a_value_repeated_in_one_folded_line_is_reported(): void {
		$result = $this->checkWith( array( 'X-Frame-Options' => 'SAMEORIGIN, SAMEORIGIN' ) );

		self::assertSame( 'recommended', $result['status'] );
		self::assertStringContainsString( 'x-frame-options', $result['description'] );
	}

	public function test_single_headers_are_clean_and_the_check_says_it_verified(): void {
		$result = $this->checkWith(
			array(
				'x-frame-options'        => 'SAMEORIGIN',
				'referrer-policy'        => 'no-referrer, strict-origin-when-cross-origin',
				'x-content-type-options' => 'nosniff',
			)
		);

		self::assertSame( 'good', $result['status'] );
		// "Could not verify" also has status good. Only a verified answer says "exactly once".
		self::assertStringContainsString( 'exactly once', $result['label'] );
	}

	/**
	 * @param array<string, string|list<string>> $headers
	 * @return array<string, mixed>
	 */
	private function checkWith( array $headers ): array {
		add_filter(
			'pre_http_request',
			static fn () => array(
				'headers'  => new CaseInsensitiveDictionary( $headers ),
				'body'     => '',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			)
		);

		return $this->bootKit(
			array(
				'site_health'      => true,
				'security_headers' => true,
			)
		)->site_health_test_duplicate_security_headers();
	}
}
