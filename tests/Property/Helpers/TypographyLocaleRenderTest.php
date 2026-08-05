<?php

declare(strict_types=1);

namespace Tests\Property\Helpers;

use Parisek\Twig\TypographyExtension;
use Tests\Property\Support\PropertyTestCase;

/**
 * End-to-end proof that a region-qualified locale (`de_CH`) reaches
 * `TypographyExtension`'s Swiss (`de-CH`) typographic table — Swiss
 * guillemets (`«…»`) instead of German low/high double quotes — and not
 * just the bare `de` table. This is the case
 * `Helpers::getLanguage()`'s region-subtag preservation
 * (`tests/Unit/Helpers/GetLanguageTest::test_preserves_region_subtag_from_locale_fallback`)
 * exists for: a truncating `get_locale()` fallback would make `de-CH`
 * permanently unreachable from `StarterBase::typography_locale_resolver()`.
 *
 * Deliberately example-based, not `forAll`-generated: there's no
 * meaningful invariant to sweep over a fixed locale-vs-locale divergence,
 * so this is placed in the Property suite for its bootstrap
 * (`tests/bootstrap.property.php`, no Brain\Monkey), not because it's a
 * property test in the Eris sense. That bootstrap is required here for a
 * real reason, not convenience: `PHP_Typography`'s DOM walker passes nodes
 * by reference internally, and Brain\Monkey's Patchwork interceptor rewrites
 * that call in a way that turns every traversal step into a "must be passed
 * by reference, value given" warning — for real prose this floods to
 * thousands of warnings and the test never completes under the Unit suite
 * (confirmed: killed after 90s+ there). The resolver here is a plain closure
 * returning a constant, no WordPress function is touched, so nothing needs
 * mocking in the first place — which is exactly why running under a
 * Brain\Monkey-free bootstrap makes the conflict disappear.
 */
class TypographyLocaleRenderTest extends PropertyTestCase {

	public function test_de_ch_locale_renders_swiss_guillemets_not_bare_german_quotes(): void {
		$text = 'Er sagte "Hallo"';

		$switzerland = ( new TypographyExtension( '', static fn (): string => 'de_CH' ) )->applyTypography( $text );
		$germany     = ( new TypographyExtension( '', static fn (): string => 'de_DE' ) )->applyTypography( $text );

		$this->assertStringContainsString( '«', $switzerland, 'de_CH must resolve to the Swiss guillemet quote style.' );
		$this->assertStringNotContainsString( '«', $germany, 'de_DE must not pick up the Swiss-only table.' );
		$this->assertNotSame( $switzerland, $germany );
	}
}
