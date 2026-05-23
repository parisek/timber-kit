<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Tests\Unit\StarterBaseTestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Coverage for `StarterBase::twig_template_exists()` — Twig function that
 * returns true when `$env->load($name)` resolves and false when it throws.
 */
class TwigTemplateExistsTest extends StarterBaseTestCase {

	public function test_returns_true_when_template_resolves(): void {
		$env  = new Environment( new ArrayLoader( [
			'@component/hero/hero.twig' => 'x',
		] ) );
		$base = $this->createStarterBase();

		$this->assertTrue( $base->twig_template_exists( $env, [], '@component/hero/hero.twig' ) );
	}

	public function test_returns_false_when_template_missing(): void {
		$env  = new Environment( new ArrayLoader( [] ) );
		$base = $this->createStarterBase();

		$this->assertFalse( $base->twig_template_exists( $env, [], '@component/nope/nope.twig' ) );
	}

	public function test_context_arg_is_ignored(): void {
		// `needs_context` is true on the Twig registration so the callable
		// signature matches Twig's calling convention — but the method itself
		// must not act on it. Passing arbitrary nonsense as context should not
		// change the outcome.
		$env  = new Environment( new ArrayLoader( [ '@a/b.twig' => 'x' ] ) );
		$base = $this->createStarterBase();

		$this->assertTrue( $base->twig_template_exists( $env, [ 'arbitrary' => 'junk' ], '@a/b.twig' ) );
		$this->assertFalse( $base->twig_template_exists( $env, [ 'something' => 'else' ], '@a/missing.twig' ) );
	}
}
