<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Tests\Unit\HelpersTestCase;
use Parisek\TimberKit\Helpers;
use Brain\Monkey\Functions;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Renders MenuData through a real Twig environment.
 *
 * The conclusion that `|merge` and `|slice` accept a Traversable came from
 * reading Twig's CoreExtension, not from running it. This class executes it.
 * Every template below is lifted from a real call site found in the audit:
 * keypers (|merge), anyever (|slice), eprukaz + oekoplan (|length), mairateam
 * (truthiness guard + iteration).
 */
class FormatMenuTwigTest extends HelpersTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_field_objects' )->justReturn( [] );
		// Brain\Monkey function mocks are process-wide; stub defensively so this
		// class passes standalone and under the full suite alike (see sibling
		// FormatMenuTest / FormatMenuEquivalenceTest for the same pattern).
		Functions\when( 'is_plugin_active' )->justReturn( false );
		Functions\when( 'acf_get_field_groups' )->justReturn( [] );
	}

	private function makeMenu( int $count ): object {
		$items = [];
		for ( $i = 1; $i <= $count; $i++ ) {
			$item = new \stdClass();
			$item->ID = $i;
			$item->name = 'Item ' . $i;
			$item->url = '/item-' . $i . '/';
			$item->description = '';
			$item->target = '';
			$item->classes = [];
			$item->current_item_ancestor = false;
			$item->current = false;
			$item->children = [];
			$items[] = $item;
		}

		$menu = new \stdClass();
		$menu->id = 7;
		$menu->name = 'Channels';
		$menu->slug = 'channels';
		$menu->description = '';
		$menu->items = $items;

		return $menu;
	}

	private function render( string $template, array $context ): string {
		$env = new Environment( new ArrayLoader( [ 't' => $template ] ) );

		return $env->render( 't', $context );
	}

	public function test_iterates_like_an_array(): void {
		$menu = Helpers::formatMenu( $this->makeMenu( 2 ) );

		$out = $this->render( '{% for item in menu %}{{ item.title }};{% endfor %}', [ 'menu' => $menu ] );

		$this->assertSame( 'Item 1;Item 2;', $out );
	}

	public function test_truthiness_guard_renders_for_a_non_empty_menu(): void {
		$menu = Helpers::formatMenu( $this->makeMenu( 1 ) );

		$out = $this->render( '{% if menu %}yes{% else %}no{% endif %}', [ 'menu' => $menu ] );

		$this->assertSame( 'yes', $out );
	}

	public function test_truthiness_guard_skips_an_empty_menu(): void {
		// mairateam footer.twig:43 — `{% if content.menu_secondary %}`
		$menu = Helpers::formatMenu( $this->makeMenu( 0 ) );

		$out = $this->render( '{% if menu %}yes{% else %}no{% endif %}', [ 'menu' => $menu ] );

		$this->assertSame( 'no', $out );
	}

	public function test_title_is_reachable_as_a_heading(): void {
		$menu = Helpers::formatMenu( $this->makeMenu( 1 ) );

		$out = $this->render( '<h3>{{ menu.title }}</h3>', [ 'menu' => $menu ] );

		$this->assertSame( '<h3>Channels</h3>', $out );
	}

	public function test_length_filter(): void {
		// eprukaz / oekoplan header.twig — `{% if content.menu_secondary|length > 0 %}`
		$menu = Helpers::formatMenu( $this->makeMenu( 3 ) );

		$out = $this->render( '{% if menu|length > 0 %}{{ menu|length }}{% endif %}', [ 'menu' => $menu ] );

		$this->assertSame( '3', $out );
	}

	public function test_merge_filter(): void {
		// keypers header.twig:82 — `content.menu_left|merge(content.menu_right)`
		$left = Helpers::formatMenu( $this->makeMenu( 2 ) );
		$right = Helpers::formatMenu( $this->makeMenu( 1 ) );

		$out = $this->render(
			'{% for item in left|merge(right) %}{{ item.title }};{% endfor %}',
			[ 'left' => $left, 'right' => $right ]
		);

		$this->assertSame( 'Item 1;Item 2;Item 1;', $out );
	}

	public function test_slice_filter(): void {
		// anyever header.twig:39,65 — `content.menu|slice(0, 3)` and `|slice(3)`
		$menu = Helpers::formatMenu( $this->makeMenu( 5 ) );

		$head = $this->render( '{% for item in menu|slice(0, 3) %}{{ item.title }};{% endfor %}', [ 'menu' => $menu ] );
		$tail = $this->render( '{% for item in menu|slice(3) %}{{ item.title }};{% endfor %}', [ 'menu' => $menu ] );

		$this->assertSame( 'Item 1;Item 2;Item 3;', $head );
		$this->assertSame( 'Item 4;Item 5;', $tail );
	}

	public function test_items_property_iterates_too(): void {
		$menu = Helpers::formatMenu( $this->makeMenu( 2 ) );

		$out = $this->render( '{% for item in menu.items %}{{ item.title }};{% endfor %}', [ 'menu' => $menu ] );

		$this->assertSame( 'Item 1;Item 2;', $out );
	}
}
