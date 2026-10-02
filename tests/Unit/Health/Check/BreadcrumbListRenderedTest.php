<?php

declare(strict_types=1);

namespace Tests\Unit\Health\Check;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Health\Check\BreadcrumbListRendered;
use Parisek\TimberKit\Health\HealthCheck;
use Parisek\TimberKit\Health\Result;
use Tests\Unit\Health\HealthTestCase;

class BreadcrumbListRenderedTest extends HealthTestCase {

	private const URL = 'https://example.com/news/newest-post/';

	private const JSON_LD = '<html><head><script type="application/ld+json">{"@context":"https://schema.org","@type":"BreadcrumbList","itemListElement":[]}</script></head><body></body></html>';

	private const MICRODATA = '<html><body><nav><ol itemscope itemtype="https://schema.org/BreadcrumbList"><li>Home</li></ol></nav></body></html>';

	private const NONE = '<html><head><script type="application/ld+json">{"@type":"WebPage"}</script></head><body><h1>Newest post</h1></body></html>';

	/** @var list<array{url: string, args: array<string, mixed>}> */
	private array $requests = [];

	/** @var list<array<string, mixed>> */
	private array $queries = [];

	protected function setUp(): void {
		parent::setUp();
		$this->requests = [];
		$this->queries  = [];
		Functions\when( '__' )->returnArg();
		Functions\when( 'is_wp_error' )->alias( fn ( $thing ): bool => 'wp-error' === $thing );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			fn ( $response ) => is_array( $response ) ? $response['response']['code'] : ''
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			fn ( $response ): string => is_array( $response ) ? $response['body'] : ''
		);
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\when( 'get_permalink' )->justReturn( self::URL );
	}

	/**
	 * @param array<string, list<int>> $postsByType Newest published post IDs per public post type.
	 */
	private function stubPosts( array $postsByType ): void {
		Functions\when( 'get_post_types' )->justReturn( array_combine( array_keys( $postsByType ), array_keys( $postsByType ) ) );
		Functions\when( 'get_posts' )->alias( function ( array $args ) use ( $postsByType ): array {
			$this->queries[] = $args;
			return $postsByType[ $args['post_type'] ] ?? [];
		} );
	}

	/**
	 * @param array{0: int, 1?: string}|string $response [code, body] or 'wp-error'.
	 */
	private function stubResponse( array|string $response ): void {
		Functions\when( 'wp_remote_get' )->alias( function ( string $url, array $args = [] ) use ( $response ) {
			$this->requests[] = [ 'url' => $url, 'args' => $args ];
			return is_array( $response )
				? [ 'response' => [ 'code' => $response[0] ], 'body' => $response[1] ?? '' ]
				: $response;
		} );
	}

	private function run_check(): Result {
		return ( new BreadcrumbListRendered() )->run();
	}

	public function test_identity(): void {
		$check = new BreadcrumbListRendered();

		$this->assertSame( 'breadcrumb_list_rendered', $check->id() );
		$this->assertSame( 'seo', $check->category() );
		$this->assertSame( HealthCheck::METHOD_EFFECT, $check->method() );
	}

	public function test_good_when_the_list_is_in_json_ld(): void {
		$this->stubPosts( [ 'post' => [ 42 ] ] );
		$this->stubResponse( [ 200, self::JSON_LD ] );

		$result = $this->run_check();

		$this->assertSame( 'good', $result->status() );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( self::URL, $this->requests[0]['url'] );
		$this->assertSame( 5, $this->requests[0]['args']['timeout'] );
	}

	public function test_good_when_the_list_is_in_microdata(): void {
		$this->stubPosts( [ 'post' => [ 42 ] ] );
		$this->stubResponse( [ 200, self::MICRODATA ] );

		$this->assertSame( 'good', $this->run_check()->status() );
	}

	public function test_microdata_with_single_quotes_and_http_is_found(): void {
		$this->stubPosts( [ 'post' => [ 42 ] ] );
		$this->stubResponse( [ 200, "<ol itemscope itemtype='http://schema.org/BreadcrumbList'></ol>" ] );

		$this->assertSame( 'good', $this->run_check()->status() );
	}

	public function test_recommended_with_both_fixes_when_the_list_is_absent(): void {
		$this->stubPosts( [ 'post' => [ 42 ] ] );
		$this->stubResponse( [ 200, self::NONE ] );

		$result = $this->run_check();

		$this->assertSame( 'recommended', $result->status() );
		$this->assertStringContainsString( self::URL, $result->summary() );
		$this->assertStringContainsString( '$seo_suppress_plugin_breadcrumb = false', $result->summary() );
		$this->assertStringContainsString( 'render a breadcrumb', $result->summary() );
	}

	/**
	 * The word alone is not markup. A page that mentions BreadcrumbList in its
	 * text, or in a non-JSON-LD script, carries no list a crawler can read, and
	 * reporting `good` there is the silent loss this check exists to catch.
	 */
	public function test_the_word_outside_json_ld_or_microdata_does_not_count(): void {
		$this->stubPosts( [ 'post' => [ 42 ] ] );
		$this->stubResponse( [ 200, '<p>We removed the BreadcrumbList.</p><script>var t = "BreadcrumbList";</script>' ] );

		$this->assertSame( 'recommended', $this->run_check()->status() );
	}

	/**
	 * Body shapes the detection must read as markup, not as text.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function bodies(): array {
		$ld = static fn ( string $json ): string => '<script type="application/ld+json">' . $json . '</script>';

		return [
			'JSON-LD: a description that mentions the word' => [
				$ld( '{"@context":"https://schema.org","@type":"WebPage","description":"Our BreadcrumbList is gone."}' ),
				'recommended',
			],
			'JSON-LD: a BreadcrumbList node in @graph' => [
				$ld( '{"@context":"https://schema.org","@graph":[{"@type":"WebPage"},{"@type":"BreadcrumbList","itemListElement":[]}]}' ),
				'good',
			],
			'JSON-LD: @type as an array that contains it' => [
				$ld( '{"@type":["ItemList","BreadcrumbList"]}' ),
				'good',
			],
			'JSON-LD: a top-level list of nodes' => [
				$ld( '[{"@type":"WebSite"},{"@type":"BreadcrumbList"}]' ),
				'good',
			],
			'JSON-LD: an invalid block next to a valid one' => [
				$ld( '{"@type":"BreadcrumbList",' ) . $ld( '{"@type":"BreadcrumbList"}' ),
				'good',
			],
			'JSON-LD: only an invalid block that names the type' => [
				$ld( '{"@type":"BreadcrumbList",' ),
				'recommended',
			],
			'microdata: several itemtype tokens' => [
				'<ol itemscope itemtype="https://schema.org/ItemList https://schema.org/BreadcrumbList"></ol>',
				'good',
			],
			'microdata: itemtype without itemscope' => [
				'<ol itemtype="https://schema.org/BreadcrumbList"></ol>',
				'recommended',
			],
			'microdata: single quotes' => [
				"<ol itemscope itemtype='https://schema.org/BreadcrumbList'></ol>",
				'good',
			],
			'microdata: itemtype before itemscope, more attributes' => [
				'<nav aria-label="Breadcrumb"><ol class="crumbs" itemtype="https://schema.org/BreadcrumbList" id="x" itemscope=""></ol></nav>',
				'good',
			],
			'microdata: a > inside a quoted value before itemtype' => [
				'<ol title="Home > News" itemscope itemtype="https://schema.org/BreadcrumbList"></ol>',
				'good',
			],
			'microdata: a type on another vocabulary' => [
				'<ol itemscope itemtype="https://example.com/BreadcrumbList"></ol>',
				'recommended',
			],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'bodies' )]
	public function test_detection_reads_markup_not_text( string $body, string $status ): void {
		$this->stubPosts( [ 'post' => [ 42 ] ] );
		$this->stubResponse( [ 200, '<html><head></head><body>' . $body . '</body></html>' ] );

		$this->assertSame( $status, $this->run_check()->status() );
	}

	public function test_a_failed_loopback_is_recommended_could_not_verify(): void {
		$this->stubPosts( [ 'post' => [ 42 ] ] );
		$this->stubResponse( 'wp-error' );

		$result = $this->run_check();

		$this->assertSame( 'recommended', $result->status() );
		$this->assertStringContainsString( 'Could not verify', $result->summary() );
	}

	public function test_a_non_200_answer_is_recommended_could_not_verify(): void {
		$this->stubPosts( [ 'post' => [ 42 ] ] );
		$this->stubResponse( [ 500, self::JSON_LD ] );

		$result = $this->run_check();

		$this->assertSame( 'recommended', $result->status() );
		$this->assertStringContainsString( 'Could not verify', $result->summary() );
		$this->assertStringContainsString( '500', $result->summary() );
	}

	/**
	 * A redirect to a login page or a soft-404 handler would end in HTTP 200
	 * on a page that is not the post. The request must not follow it.
	 */
	public function test_redirects_are_not_followed(): void {
		$this->stubPosts( [ 'post' => [ 42 ] ] );
		$this->stubResponse( [ 302 ] );

		$result = $this->run_check();

		$this->assertSame( 'recommended', $result->status() );
		$this->assertStringContainsString( 'Could not verify', $result->summary() );
		$this->assertSame( 0, $this->requests[0]['args']['redirection'] ?? null );
	}

	public function test_no_published_post_sends_no_request(): void {
		$this->stubPosts( [ 'post' => [], 'page' => [] ] );
		$this->stubResponse( [ 200, self::JSON_LD ] );

		$result = $this->run_check();

		$this->assertSame( 'good', $result->status() );
		$this->assertStringContainsString( 'No published', $result->summary() );
		$this->assertSame( [], $this->requests );
	}

	public function test_the_first_post_type_with_a_post_is_used(): void {
		$this->stubPosts( [ 'post' => [], 'page' => [ 7 ] ] );
		$this->stubResponse( [ 200, self::JSON_LD ] );

		$this->run_check();

		$this->assertSame( [ 'post', 'page' ], array_column( $this->queries, 'post_type' ) );
		$this->assertSame( 'publish', $this->queries[1]['post_status'] );
		$this->assertSame( 'date', $this->queries[1]['orderby'] );
		$this->assertSame( 'DESC', $this->queries[1]['order'] );
		$this->assertCount( 1, $this->requests );
	}

	/**
	 * A home page can carry no breadcrumb by design, so the newest page must
	 * never be the static front page or the posts page.
	 */
	public function test_the_front_page_and_the_posts_page_are_excluded(): void {
		Functions\when( 'get_option' )->alias(
			fn ( string $name ) => [ 'page_on_front' => '11', 'page_for_posts' => '12' ][ $name ] ?? 0
		);
		$this->stubPosts( [ 'page' => [ 7 ] ] );
		$this->stubResponse( [ 200, self::JSON_LD ] );

		$this->run_check();

		$this->assertSame( [ 11, 12 ], $this->queries[0]['post__not_in'] );
	}

	public function test_a_post_without_a_permalink_sends_no_request(): void {
		Functions\when( 'get_permalink' )->justReturn( false );
		$this->stubPosts( [ 'post' => [ 42 ] ] );
		$this->stubResponse( [ 200, self::JSON_LD ] );

		$this->assertSame( 'good', $this->run_check()->status() );
		$this->assertSame( [], $this->requests );
	}
}
