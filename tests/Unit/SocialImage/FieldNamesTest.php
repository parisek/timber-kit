<?php

declare(strict_types=1);

namespace Tests\Unit\SocialImage;

use Parisek\TimberKit\SocialImage;
use PHPUnit\Framework\TestCase;

class FieldNamesTest extends TestCase {

	public function test_a_string_entry_becomes_a_one_item_chain(): void {
		$this->assertSame( [ 'hero_image' ], SocialImage::fieldNamesFor( 'project', [ 'project' => 'hero_image' ] ) );
	}

	public function test_an_array_entry_is_kept_in_order(): void {
		$this->assertSame(
			[ 'lead_image', 'hero_image' ],
			SocialImage::fieldNamesFor( 'project', [ 'project' => [ 'lead_image', 'hero_image' ] ] )
		);
	}

	public function test_an_unmapped_post_type_has_no_fields(): void {
		$this->assertSame( [], SocialImage::fieldNamesFor( 'page', [ 'project' => 'hero_image' ] ) );
	}

	public function test_empty_and_non_string_entries_are_dropped(): void {
		$this->assertSame(
			[ 'hero_image' ],
			SocialImage::fieldNamesFor( 'project', [ 'project' => [ '', null, 42, ' hero_image ' ] ] )
		);
	}

	public function test_a_non_string_non_array_entry_yields_nothing(): void {
		$this->assertSame( [], SocialImage::fieldNamesFor( 'project', [ 'project' => 42 ] ) );
	}
}
