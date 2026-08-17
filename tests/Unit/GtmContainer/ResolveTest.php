<?php

declare(strict_types=1);

namespace Tests\Unit\GtmContainer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Parisek\TimberKit\GtmContainer;

/**
 * Language resolution over the configured container map.
 */
class ResolveTest extends TestCase {

	/** @var array<string, array<string, string>> */
	private const MAP = array(
		'default' => array(
			'id'     => 'GTM-DEFAULT1',
			'domain' => 'windstream.example.com',
			'path'   => 'aBcDeF/',
		),
		'de'      => array(
			'id' => 'GTM-GERMAN1',
		),
		'sk'      => array(
			'id'     => 'GTM-SLOVAK1',
			'domain' => 'sk.example.com',
		),
	);

	public function test_unknown_language_falls_back_to_default(): void {
		$this->assertSame(
			self::MAP['default'],
			GtmContainer::resolve( self::MAP, 'fr' )
		);
	}

	public function test_absent_language_falls_back_to_default(): void {
		$this->assertSame(
			self::MAP['default'],
			GtmContainer::resolve( self::MAP, NULL )
		);
	}

	public function test_language_entry_inherits_unset_keys_from_default(): void {
		$this->assertSame(
			array(
				'id'     => 'GTM-GERMAN1',
				'domain' => 'windstream.example.com',
				'path'   => 'aBcDeF/',
			),
			GtmContainer::resolve( self::MAP, 'de' )
		);
	}

	public function test_language_entry_overrides_only_the_keys_it_states(): void {
		$this->assertSame(
			array(
				'id'     => 'GTM-SLOVAK1',
				'domain' => 'sk.example.com',
				'path'   => 'aBcDeF/',
			),
			GtmContainer::resolve( self::MAP, 'sk' )
		);
	}

	public function test_empty_map_resolves_to_nothing(): void {
		$this->assertSame( array(), GtmContainer::resolve( array(), 'cs' ) );
	}

	public function test_map_without_default_still_serves_a_stated_language(): void {
		$map = array( 'cs' => array( 'id' => 'GTM-CZECH11' ) );

		$this->assertSame( array( 'id' => 'GTM-CZECH11' ), GtmContainer::resolve( $map, 'cs' ) );
	}

	public function test_map_without_default_resolves_unknown_language_to_nothing(): void {
		$map = array( 'cs' => array( 'id' => 'GTM-CZECH11' ) );

		$this->assertSame( array(), GtmContainer::resolve( $map, 'de' ) );
	}

	/**
	 * A shorthand string value is the container ID, so a single-language
	 * project never writes a one-key array.
	 */
	#[DataProvider('shorthand_provider')]
	public function test_string_shorthand_is_read_as_the_container_id( mixed $entry, array $expected ): void {
		$this->assertSame( $expected, GtmContainer::resolve( array( 'default' => $entry ), 'cs' ) );
	}

	/** @return array<string, array{mixed, array<string, string>}> */
	public static function shorthand_provider(): array {
		return array(
			'plain string' => array( 'GTM-SHORT11', array( 'id' => 'GTM-SHORT11' ) ),
			'array form'   => array( array( 'id' => 'GTM-SHORT11' ), array( 'id' => 'GTM-SHORT11' ) ),
		);
	}
}
