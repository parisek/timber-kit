<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Tests\Unit\HelpersTestCase;
use Parisek\TimberKit\Helpers;
use Brain\Monkey\Functions;

class FormatTermsTest extends HelpersTestCase {

	public function test_returns_empty_array_for_empty_input(): void {
		$result = Helpers::formatTerms( [] );

		$this->assertSame( [], $result );
	}

	public function test_returns_empty_array_for_null(): void {
		$result = Helpers::formatTerms( null );

		$this->assertSame( [], $result );
	}

	public function test_returns_empty_array_for_non_countable(): void {
		$result = Helpers::formatTerms( 'string' );

		$this->assertSame( [], $result );
	}

	public function test_skips_non_term_objects(): void {
		$items = [ new \stdClass(), 'not_a_term' ];

		$result = Helpers::formatTerms( $items );

		$this->assertSame( [], $result );
	}

	public function test_formats_timber_term(): void {
		$term = $this->createStub( \Timber\Term::class );
		$term->method( 'link' )->willReturn( '/category/test' );
		$term->ID = 5;
		$term->title = 'Test Category';
		$term->taxonomy = 'category';
		$term->count = 12;
		$term->children = false;

		$result = Helpers::formatTerms( [ $term ] );

		$this->assertCount( 1, $result );
		$this->assertSame( 5, $result[0]['id'] );
		$this->assertSame( 'Test Category', $result[0]['title'] );
		$this->assertSame( '/category/test', $result[0]['url'] );
		$this->assertSame( 12, $result[0]['count'] );
		$this->assertSame( [], $result[0]['children'] );
	}

	public function test_casts_count_to_int(): void {
		// WP_Term->count can arrive as a numeric string; the helper must expose
		// it as an int. The same `(int) $term->count` line formats parent and
		// recursive child terms, so this covers both code paths.
		$term = $this->createStub( \Timber\Term::class );
		$term->method( 'link' )->willReturn( '/category/test' );
		$term->ID = 5;
		$term->title = 'Test Category';
		$term->taxonomy = 'category';
		$term->count = '7';
		$term->children = false;

		$result = Helpers::formatTerms( [ $term ] );

		$this->assertSame( 7, $result[0]['count'] );
	}

	public function test_clears_url_with_taxonomy_query_param(): void {
		$term = $this->createStub( \Timber\Term::class );
		$term->method( 'link' )->willReturn( '/page?taxonomy=category' );
		$term->ID = 1;
		$term->title = 'Test';
		$term->taxonomy = 'category';
		$term->children = false;

		$result = Helpers::formatTerms( [ $term ] );

		$this->assertSame( '', $result[0]['url'] );
	}

	public function test_formats_multiple_terms(): void {
		$terms = [];
		for ( $i = 1; $i <= 3; $i++ ) {
			$term = $this->createStub( \Timber\Term::class );
			$term->method( 'link' )->willReturn( "/category/term-{$i}" );
			$term->ID = $i;
			$term->title = "Term {$i}";
			$term->taxonomy = 'category';
			$term->children = false;
			$terms[] = $term;
		}

		$result = Helpers::formatTerms( $terms );

		$this->assertCount( 3, $result );
		$this->assertSame( 'Term 1', $result[0]['title'] );
		$this->assertSame( 'Term 3', $result[2]['title'] );
	}
}
