<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Parisek\TimberKit\StarterBase;
use Tests\Unit\HelpersTestCase;

/**
 * Covers the opt-in that skips ACFML's field-definition translation.
 *
 * The switch itself is one boolean. What needs holding is the context test:
 * three of the four contexts that must keep the translation are not
 * `is_admin()`, and getting any of them wrong shows an editor untranslated
 * labels — a failure nobody reports as a bug in a performance flag.
 */
class AcfmlFieldTranslationTest extends HelpersTestCase {

	private function subject(): object {
		return new class extends StarterBase {
			// The constructor wires a whole theme; this test needs the callback.
			public function __construct() {}
		};
	}

	private function contexts( bool $admin, bool $ajax ): void {
		Functions\when( 'is_admin' )->justReturn( $admin );
		Functions\when( 'wp_doing_ajax' )->justReturn( $ajax );
	}

	public function test_a_plain_front_end_view_skips_the_translation(): void {
		$this->contexts( false, false );

		$this->assertFalse( $this->subject()->acfml_should_translate_acf_entity( true ) );
	}

	public function test_the_admin_keeps_it(): void {
		$this->contexts( true, false );

		$this->assertTrue( $this->subject()->acfml_should_translate_acf_entity( true ) );
	}

	public function test_ajax_keeps_it(): void {
		// ACF talks to admin-ajax from inside the block editor, and
		// wp_doing_ajax() is not is_admin().
		$this->contexts( false, true );

		$this->assertTrue( $this->subject()->acfml_should_translate_acf_entity( true ) );
	}

	#[RunInSeparateProcess]
	public function test_rest_keeps_it(): void {
		// Gutenberg loads field groups over REST. This is the context an
		// is_admin()-only guard would get wrong, and the one whose breakage
		// looks like a translation bug rather than a caching one.
		//
		// Its own process: a constant cannot be undefined, and REST_REQUEST
		// left standing makes every later test in this run look like REST.
		$this->contexts( false, false );
		define( 'REST_REQUEST', true );

		$this->assertTrue( $this->subject()->acfml_should_translate_acf_entity( true ) );
	}

	public function test_it_passes_a_refusal_through_rather_than_forcing_true(): void {
		// Another plugin may already have said no. Returning the incoming value
		// rather than true keeps this a veto, not an override.
		$this->contexts( true, false );

		$this->assertFalse( $this->subject()->acfml_should_translate_acf_entity( false ) );
	}
}
