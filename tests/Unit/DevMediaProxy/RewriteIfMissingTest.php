<?php

declare(strict_types=1);

namespace Tests\Unit\DevMediaProxy;

use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\DevMediaProxy;

class RewriteIfMissingTest extends TestCase {

	private string $uploads_base_url = 'https://local.test/wp-content/uploads';
	private string $uploads_base_dir = '/tmp/wp-content/uploads';
	private string $origin_base_url = 'https://origin.test/wp-content/uploads';

	protected function setUp(): void {
		parent::setUp();
		@mkdir( $this->uploads_base_dir . '/2024/01', 0777, true );
	}

	public function test_non_uploads_url_is_unchanged(): void {
		$url = 'https://local.test/about/';

		$result = DevMediaProxy::rewriteIfMissing(
			$url,
			$this->uploads_base_url,
			$this->uploads_base_dir,
			$this->origin_base_url
		);

		$this->assertSame( $url, $result );
	}

	public function test_existing_upload_file_keeps_local_url(): void {
		$file = $this->uploads_base_dir . '/2024/01/foo.jpg';
		file_put_contents( $file, 'ok' );
		$url = $this->uploads_base_url . '/2024/01/foo.jpg';

		$result = DevMediaProxy::rewriteIfMissing(
			$url,
			$this->uploads_base_url,
			$this->uploads_base_dir,
			$this->origin_base_url
		);

		$this->assertSame( $url, $result );
	}

	public function test_missing_upload_file_is_rewritten_to_origin(): void {
		$url = $this->uploads_base_url . '/2024/01/missing.jpg';

		$result = DevMediaProxy::rewriteIfMissing(
			$url,
			$this->uploads_base_url,
			$this->uploads_base_dir,
			$this->origin_base_url
		);

		$this->assertSame( $this->origin_base_url . '/2024/01/missing.jpg', $result );
	}

	public function test_domain_only_origin_reuses_local_uploads_path(): void {
		$url = $this->uploads_base_url . '/2024/01/missing.jpg';

		$result = DevMediaProxy::rewriteIfMissing(
			$url,
			$this->uploads_base_url,
			$this->uploads_base_dir,
			'https://origin.test'
		);

		$this->assertSame( 'https://origin.test/wp-content/uploads/2024/01/missing.jpg', $result );
	}

	public function test_query_string_and_fragment_are_preserved(): void {
		$url = $this->uploads_base_url . '/2024/01/missing.jpg?ver=123#anchor';

		$result = DevMediaProxy::rewriteIfMissing(
			$url,
			$this->uploads_base_url,
			$this->uploads_base_dir,
			$this->origin_base_url
		);

		$this->assertSame( $this->origin_base_url . '/2024/01/missing.jpg?ver=123#anchor', $result );
	}

	public function test_nested_path_is_rewritten_correctly(): void {
		$url = $this->uploads_base_url . '/2020/07/nested/bar.png';

		$result = DevMediaProxy::rewriteIfMissing(
			$url,
			$this->uploads_base_url,
			$this->uploads_base_dir,
			$this->origin_base_url
		);

		$this->assertSame( $this->origin_base_url . '/2020/07/nested/bar.png', $result );
	}

	public function test_relative_path_traversal_is_rejected(): void {
		$url = $this->uploads_base_url . '/2024/01/../../secrets.txt';

		$result = DevMediaProxy::rewriteIfMissing(
			$url,
			$this->uploads_base_url,
			$this->uploads_base_dir,
			$this->origin_base_url
		);

		$this->assertSame( $url, $result );
	}

	public function test_malformed_url_is_unchanged(): void {
		$url = 'not-a-url';

		$result = DevMediaProxy::rewriteIfMissing(
			$url,
			$this->uploads_base_url,
			$this->uploads_base_dir,
			$this->origin_base_url
		);

		$this->assertSame( $url, $result );
	}

	public function test_origin_with_trailing_slash_is_normalized(): void {
		$url = $this->uploads_base_url . '/2024/01/missing.jpg';

		$result = DevMediaProxy::rewriteIfMissing(
			$url,
			$this->uploads_base_url,
			$this->uploads_base_dir,
			$this->origin_base_url . '/'
		);

		$this->assertSame( $this->origin_base_url . '/2024/01/missing.jpg', $result );
	}

	public function test_origin_credentials_are_stripped_from_rewritten_url(): void {
		$url = $this->uploads_base_url . '/2024/01/missing.jpg';

		$result = DevMediaProxy::rewriteIfMissing(
			$url,
			$this->uploads_base_url,
			$this->uploads_base_dir,
			'https://user:pass@origin.test/wp-content/uploads'
		);

		$this->assertSame( 'https://origin.test/wp-content/uploads/2024/01/missing.jpg', $result );
	}
}
