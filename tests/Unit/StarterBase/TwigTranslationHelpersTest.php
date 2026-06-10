<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Brain\Monkey\Functions;
use Tests\Unit\StarterBaseTestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Coverage for the typography-aware translation helpers
 * `StarterBase::twig_xt()` / `twig_t()` / `twig_nt()` / `twig_nxt()` — the Twig
 * functions `_xt` / `__t` / `_nt` / `_nxt` (parisek/timber-kit#42, mirroring
 * parisek/styleguide#21). Each calls the matching WordPress translator and
 * pipes the result through the env's `|typography` filter.
 *
 * The mocked translators fold every argument they receive into the returned
 * marker (`X:text|context|domain`, …) so a dropped or swapped `$context` /
 * `$number` / `$domain` would change the assertion — i.e. full argument
 * forwarding is proven, not just that the right translator ran.
 */
class TwigTranslationHelpersTest extends StarterBaseTestCase {

	/**
	 * A Twig env whose `typography` filter wraps its input in `T(…)` so the
	 * compose order (translate THEN typography) is unambiguous — no dependency
	 * on php-typography's concrete output.
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

	public function test_xt_forwards_all_args_then_typographies(): void {
		Functions\when( '_x' )->alias( static fn ( string $t, string $c, string $d = 'default' ): string => "X:$t|$c|$d" );
		$base = $this->createStarterBase();

		$this->assertSame( 'T(X:hello|ctx|dom)', $base->twig_xt( $this->envWithMarkerTypography(), 'hello', 'ctx', 'dom' ) );
	}

	public function test_underscore_t_forwards_all_args_then_typographies(): void {
		Functions\when( '__' )->alias( static fn ( string $t, string $d = 'default' ): string => "U:$t|$d" );
		$base = $this->createStarterBase();

		$this->assertSame( 'T(U:hello|dom)', $base->twig_t( $this->envWithMarkerTypography(), 'hello', 'dom' ) );
	}

	public function test_nt_selects_plural_and_forwards_then_typographies(): void {
		Functions\when( '_n' )->alias( static fn ( string $s, string $p, int $n, string $d = 'default' ): string => 'N:' . ( $n === 1 ? $s : $p ) . "|$d" );
		$base = $this->createStarterBase();

		$this->assertSame( 'T(N:one|dom)', $base->twig_nt( $this->envWithMarkerTypography(), 'one', 'many', 1, 'dom' ) );
		$this->assertSame( 'T(N:many|dom)', $base->twig_nt( $this->envWithMarkerTypography(), 'one', 'many', 4, 'dom' ) );
	}

	public function test_nxt_selects_plural_with_context_and_forwards_then_typographies(): void {
		Functions\when( '_nx' )->alias( static fn ( string $s, string $p, int $n, string $c, string $d = 'default' ): string => 'NX:' . ( $n === 1 ? $s : $p ) . "|$c|$d" );
		$base = $this->createStarterBase();

		$this->assertSame( 'T(NX:many|ctx|dom)', $base->twig_nxt( $this->envWithMarkerTypography(), 'one', 'many', 2, 'ctx', 'dom' ) );
	}

	public function test_typography_absent_falls_back_to_raw_translation(): void {
		Functions\when( '_x' )->alias( static fn ( string $t, string $c, string $d = 'default' ): string => "X:$t|$c|$d" );
		$base = $this->createStarterBase();
		$env  = new Environment( new ArrayLoader() ); // no `typography` filter registered

		$this->assertSame( 'X:hello|ctx|dom', $base->twig_xt( $env, 'hello', 'ctx', 'dom' ) );
	}

	/**
	 * Render through Twig with the functions registered exactly as
	 * `timber_twig()` registers them, so the public names `_xt`/`__t`/`_nt`/
	 * `_nxt`, the `needs_environment` injection and the `is_safe: ['html']`
	 * contract are all exercised — the HTML-emitting marker filter (`<b>…</b>`)
	 * is NOT autoescaped, proving the safety flag.
	 */
	public function test_functions_render_through_twig_with_environment_and_html_safety(): void {
		Functions\when( '_x' )->alias( static fn ( string $t, string $c, string $d = 'default' ): string => "X:$t|$c|$d" );
		Functions\when( '__' )->alias( static fn ( string $t, string $d = 'default' ): string => "U:$t|$d" );
		Functions\when( '_n' )->alias( static fn ( string $s, string $p, int $n, string $d = 'default' ): string => 'N:' . ( $n === 1 ? $s : $p ) . "|$d" );
		Functions\when( '_nx' )->alias( static fn ( string $s, string $p, int $n, string $c, string $d = 'default' ): string => 'NX:' . ( $n === 1 ? $s : $p ) . "|$c|$d" );

		$base = $this->createStarterBase();

		$twig = new Environment( new ArrayLoader() );
		$twig->addFilter( new TwigFilter( 'typography', static fn ( string $v ): string => '<b>' . $v . '</b>', [ 'is_safe' => [ 'html' ] ] ) );
		foreach ( [ '_xt' => 'twig_xt', '__t' => 'twig_t', '_nt' => 'twig_nt', '_nxt' => 'twig_nxt' ] as $name => $method ) {
			$twig->addFunction( new TwigFunction( $name, [ $base, $method ], [ 'needs_environment' => true, 'is_safe' => [ 'html' ] ] ) );
		}

		$out = $twig->createTemplate(
			'{{ _xt("hello", "ctx", "dom") }}|{{ __t("hi", "dom") }}|{{ _nt("one", "many", 2, "dom") }}|{{ _nxt("a", "b", 2, "ctx", "dom") }}',
		)->render();

		$this->assertSame(
			'<b>X:hello|ctx|dom</b>|<b>U:hi|dom</b>|<b>N:many|dom</b>|<b>NX:b|ctx|dom</b>',
			$out,
		);
	}
}
