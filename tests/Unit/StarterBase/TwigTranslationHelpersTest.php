<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Tests\Unit\StarterBaseTestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;

/**
 * Coverage for the typography-aware translation helpers
 * `StarterBase::twig_xt()` / `twig_t()` / `twig_nt()` / `twig_nxt()` — the Twig
 * functions `_xt` / `__t` / `_nt` / `_nxt` (parisek/timber-kit#42, mirroring
 * parisek/styleguide#21). Each calls the matching WordPress translator and
 * pipes the result through the env's `|typography` filter.
 */
class TwigTranslationHelpersTest extends StarterBaseTestCase {

	/**
	 * A Twig env whose `typography` filter wraps its input in `T(…)` so the
	 * compose order (translate THEN typography) is unambiguous in assertions —
	 * no dependency on php-typography's concrete output.
	 */
	private function envWithMarkerTypography(): Environment {
		$env = new Environment( new ArrayLoader() );
		$env->addFilter( new TwigFilter(
			'typography',
			static fn ( string $value ): string => 'T(' . $value . ')',
			[ 'is_safe' => [ 'html' ] ],
		) );

		return $env;
	}

	public function test_xt_translates_then_typographies(): void {
		Functions\when( '_x' )->alias( static fn ( string $t, string $c = '', string $d = 'default' ): string => 'X:' . $t );
		$base = $this->createStarterBase();

		$this->assertSame( 'T(X:hello)', $base->twig_xt( $this->envWithMarkerTypography(), 'hello', 'ctx', 'dom' ) );
	}

	public function test_underscore_t_translates_then_typographies(): void {
		Functions\when( '__' )->alias( static fn ( string $t, string $d = 'default' ): string => 'U:' . $t );
		$base = $this->createStarterBase();

		$this->assertSame( 'T(U:hello)', $base->twig_t( $this->envWithMarkerTypography(), 'hello', 'dom' ) );
	}

	public function test_nt_translates_plural_then_typographies(): void {
		Functions\when( '_n' )->alias( static fn ( string $s, string $p, int $n = 1, string $d = 'default' ): string => $n === 1 ? $s : $p );
		$base = $this->createStarterBase();

		$this->assertSame( 'T(one)', $base->twig_nt( $this->envWithMarkerTypography(), 'one', 'many', 1, 'dom' ) );
		$this->assertSame( 'T(many)', $base->twig_nt( $this->envWithMarkerTypography(), 'one', 'many', 4, 'dom' ) );
	}

	public function test_nxt_translates_plural_with_context_then_typographies(): void {
		Functions\when( '_nx' )->alias( static fn ( string $s, string $p, int $n, string $c = '', string $d = 'default' ): string => $n === 1 ? $s : $p );
		$base = $this->createStarterBase();

		$this->assertSame( 'T(many)', $base->twig_nxt( $this->envWithMarkerTypography(), 'one', 'many', 2, 'ctx', 'dom' ) );
	}

	public function test_typography_absent_falls_back_to_raw_translation(): void {
		Functions\when( '_x' )->alias( static fn ( string $t, string $c = '', string $d = 'default' ): string => 'X:' . $t );
		$base = $this->createStarterBase();
		$env  = new Environment( new ArrayLoader() ); // no `typography` filter registered

		$this->assertSame( 'X:hello', $base->twig_xt( $env, 'hello', 'ctx', 'dom' ) );
	}
}
