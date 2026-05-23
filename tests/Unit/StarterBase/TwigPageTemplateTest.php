<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Tests\Unit\StarterBaseTestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Coverage for `StarterBase::twig_page_template()` — sibling of
 * {@see twig_component_template()} for the `@page/` Twig namespace.
 * Verifies path resolution, slug normalisation, and the page-labelled
 * fallback chain.
 */
class TwigPageTemplateTest extends StarterBaseTestCase {

	/**
	 * @param array<string,string> $templates
	 */
	private function makeEnv( array $templates ): Environment {
		return new Environment( new ArrayLoader( $templates ) );
	}

	public function test_resolves_page_path_and_merges_content_into_context(): void {
		$env = $this->makeEnv( [
			'@page/about/about.twig' => 'site={{ site }};body={{ content.body }}',
		] );
		$base = $this->createStarterBase();

		$result = $base->twig_page_template( $env, [ 'site' => 'Y' ], 'about', [ 'body' => 'hello' ] );

		$this->assertSame( 'site=Y;body=hello', $result );
	}

	public function test_underscore_in_slug_normalised_to_dash(): void {
		$env = $this->makeEnv( [
			'@page/contact-us/contact-us.twig' => 'cu-rendered',
		] );
		$base = $this->createStarterBase();

		$result = $base->twig_page_template( $env, [], 'contact_us' );

		$this->assertSame( 'cu-rendered', $result );
	}

	public function test_falls_back_to_alert_template_when_page_missing(): void {
		// First load throws → catch tries `@component/alert/alert.twig` (not
		// `@page/alert/alert.twig` — the alert fallback is intentionally rooted
		// in `@component/` so themes don't need to duplicate it per namespace).
		$env = $this->makeEnv( [
			'@component/alert/alert.twig' => 'TYPE={{ content.type }};MSG={{ content.message|raw }}',
		] );
		$base = $this->createStarterBase();

		$result = $base->twig_page_template( $env, [], 'gone' );

		$this->assertStringContainsString( 'TYPE=error', $result );
		$this->assertStringContainsString( 'Page template', $result );
		$this->assertStringContainsString( 'gone.twig', $result );
	}

	public function test_falls_back_to_bare_div_when_alert_template_also_missing(): void {
		$env  = $this->makeEnv( [] );
		$base = $this->createStarterBase();

		$result = $base->twig_page_template( $env, [], 'gone' );

		$this->assertStringContainsString( '<div>', $result );
		$this->assertStringContainsString( 'Page template', $result );
		$this->assertStringContainsString( 'gone.twig', $result );
	}

	public function test_uses_page_label_not_component_label_in_fallback(): void {
		// Guard that the shared helper passes the right label. Page method
		// should NEVER emit "Component template".
		$env  = $this->makeEnv( [] );
		$base = $this->createStarterBase();

		$result = $base->twig_page_template( $env, [], 'gone' );

		$this->assertStringContainsString( 'Page template', $result );
		$this->assertStringNotContainsString( 'Component template', $result );
	}
}
