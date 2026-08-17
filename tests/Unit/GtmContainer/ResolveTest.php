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
	 * Stating a language and leaving it blank is the only way to say "do
	 * not measure here". Inheritance would otherwise make that
	 * unsayable — every spelling of "nothing" would resolve back to
	 * `default` and measure anyway.
	 *
	 * @param mixed $blank A written-out empty value.
	 */
	#[DataProvider('blank_entry_provider')]
	public function test_a_blank_language_entry_turns_measurement_off_for_it( mixed $blank ): void {
		$map        = self::MAP;
		$map['de']  = $blank;

		$this->assertSame( array(), GtmContainer::resolve( $map, 'de' ) );
	}

	/** @return array<string, array{mixed}> */
	public static function blank_entry_provider(): array {
		return array(
			'empty string' => array( '' ),
			'null'         => array( NULL ),
			'false'        => array( FALSE ),
			'empty array'  => array( array() ),
			'blank id'     => array( array( 'id' => '' ) ),
			'blank id, endpoint stated' => array( array( 'id' => '', 'domain' => 'sk.example.com' ) ),
		);
	}

	public function test_a_blank_entry_stops_the_walk_to_the_base_language(): void {
		$map          = self::MAP;
		$map['de-at'] = '';

		$this->assertSame( array(), GtmContainer::resolve( $map, 'de-at' ) );
	}

	public function test_a_blank_default_leaves_stated_languages_measuring(): void {
		$map            = self::MAP;
		$map['default'] = '';

		$this->assertSame( array(), GtmContainer::resolve( $map, 'cs' ) );
		$this->assertSame( array( 'id' => 'GTM-GERMAN1' ), GtmContainer::resolve( $map, 'de' ) );
	}

	/**
	 * A regional variant belongs to its language before it belongs to the
	 * site default: Austria reports with Germany's container until someone
	 * says otherwise, which is the answer that costs nothing when it is
	 * wrong and saves a silent misattribution when it is right.
	 */
	public function test_a_regional_variant_inherits_from_its_base_language(): void {
		$this->assertSame(
			array(
				'id'     => 'GTM-GERMAN1',
				'domain' => 'windstream.example.com',
				'path'   => 'aBcDeF/',
			),
			GtmContainer::resolve( self::MAP, 'de-at' )
		);
	}

	public function test_a_regional_variant_can_state_its_own_container(): void {
		$map = self::MAP;
		$map['de-at'] = array( 'id' => 'GTM-AUSTRIA1' );

		$this->assertSame( 'GTM-AUSTRIA1', GtmContainer::resolve( $map, 'de-at' )['id'] );
	}

	/**
	 * WPML lets an editor type the language code, so the same variant
	 * reaches us as `de-at`, `de_AT` or `de-AT` depending on who set the
	 * site up. Matching has to survive that.
	 */
	#[DataProvider('variant_spelling_provider')]
	public function test_separator_and_case_do_not_change_the_match( string $configured, string $current ): void {
		$map = array(
			'default' => array( 'id' => 'GTM-DEFAULT1' ),
			$configured => array( 'id' => 'GTM-AUSTRIA1' ),
		);

		$this->assertSame( 'GTM-AUSTRIA1', GtmContainer::resolve( $map, $current )['id'] );
	}

	/** @return array<string, array{string, string}> */
	public static function variant_spelling_provider(): array {
		return array(
			'hyphen both sides'      => array( 'de-at', 'de-at' ),
			'underscore config'      => array( 'de_AT', 'de-at' ),
			'underscore runtime'     => array( 'de-at', 'de_AT' ),
			'uppercase config'       => array( 'DE-AT', 'de-at' ),
			'locale-style both'      => array( 'de_AT', 'de_AT' ),
		);
	}

	public function test_an_unrelated_variant_still_falls_back_to_default(): void {
		$this->assertSame( self::MAP['default'], GtmContainer::resolve( self::MAP, 'fr-ca' ) );
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
