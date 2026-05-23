<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Tests\Unit\StarterBaseTestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Coverage for `StarterBase::twig_component_template()` — the `component_*`
 * Twig function. Verifies the resolved Twig path, the `_` → `-` slug
 * normalisation, the fallback chain (alert template → bare `<div>`), and the
 * shape of the merged context.
 *
 * Uses a real Twig {@see Environment} with an {@see ArrayLoader} carrying the
 * templates the test needs. Real-environment routing is simpler and less
 * brittle than mocking — PHPUnit's `createMock(Environment::class)` chokes on
 * the `load()` return type in PHPUnit 11.
 */
class TwigComponentTemplateTest extends StarterBaseTestCase {

	/**
	 * Build a Twig environment populated with the named templates.
	 *
	 * @param array<string,string> $templates Path → template source. Sources may use
	 *                                        Twig syntax to echo context values back
	 *                                        out for assertion purposes.
	 */
	private function makeEnv( array $templates ): Environment {
		return new Environment( new ArrayLoader( $templates ) );
	}

	public function test_resolves_component_path_and_merges_content_into_context(): void {
		$env = $this->makeEnv( [
			'@component/hero/hero.twig' => 'site={{ site }};title={{ content.title }}',
		] );
		$base = $this->createStarterBase();

		$result = $base->twig_component_template( $env, [ 'site' => 'X' ], 'hero', [ 'title' => 'Hi' ] );

		// Confirms both the path resolution and the `content` key merge into context.
		$this->assertSame( 'site=X;title=Hi', $result );
	}

	public function test_underscore_in_slug_normalised_to_dash(): void {
		// `component_article_hero` is the conventional Twig call shape — the
		// callback strips the leading prefix, so the function receives
		// `article_hero`, which must become `article-hero` in the resolved path.
		$env = $this->makeEnv( [
			'@component/article-hero/article-hero.twig' => 'ah-rendered',
		] );
		$base = $this->createStarterBase();

		$result = $base->twig_component_template( $env, [], 'article_hero' );

		$this->assertSame( 'ah-rendered', $result );
	}

	public function test_falls_back_to_alert_template_when_component_missing(): void {
		// First load throws (template not in loader) → catch tries
		// `@component/alert/alert.twig` and renders it with an error context.
		$env = $this->makeEnv( [
			// `missing/missing.twig` deliberately absent
			'@component/alert/alert.twig' => 'TYPE={{ content.type }};MSG={{ content.message|raw }};SITE={{ site }}',
		] );
		$base = $this->createStarterBase();

		$result = $base->twig_component_template( $env, [ 'site' => 'X' ], 'missing' );

		$this->assertStringContainsString( 'TYPE=error', $result );
		$this->assertStringContainsString( 'SITE=X', $result );
		$this->assertStringContainsString( 'Component template', $result );
		$this->assertStringContainsString( 'missing.twig', $result );
	}

	public function test_falls_back_to_bare_div_when_alert_template_also_missing(): void {
		// Both component AND alert templates missing → method returns the
		// hard-coded `<div>` so the page doesn't fatal in production.
		$env  = $this->makeEnv( [] );
		$base = $this->createStarterBase();

		$result = $base->twig_component_template( $env, [], 'missing' );

		$this->assertStringContainsString( '<div>', $result );
		$this->assertStringContainsString( 'Component template', $result );
		$this->assertStringContainsString( 'missing.twig', $result );
	}

	public function test_template_name_in_fallback_message_uses_normalised_slug(): void {
		// The slug shown in the alert message must reflect the post-normalisation
		// form (dashes) — that's what an editor would see in the file system,
		// which is the actionable hint.
		$env  = $this->makeEnv( [] );
		$base = $this->createStarterBase();

		$result = $base->twig_component_template( $env, [], 'no_such_thing' );

		$this->assertStringContainsString( 'no-such-thing.twig', $result );
		$this->assertStringNotContainsString( 'no_such_thing.twig', $result );
	}

	public function test_uses_page_label_only_for_page_template_sibling(): void {
		// Sanity guard that the shared helper distinguishes component-vs-page
		// label correctly — component method should NEVER emit "Page template".
		$env  = $this->makeEnv( [] );
		$base = $this->createStarterBase();

		$result = $base->twig_component_template( $env, [], 'missing' );

		$this->assertStringNotContainsString( 'Page template', $result );
	}
}
