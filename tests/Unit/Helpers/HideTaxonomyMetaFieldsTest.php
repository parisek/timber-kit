<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Helpers;
use Tests\Unit\HelpersTestCase;

/**
 * Verifies Helpers::hideTaxonomyMetaFields() — hiding the description/slug/
 * parent fields on taxonomy add/edit screens and dropping the matching list
 * table columns (previously copy-pasted hook trios in project controllers).
 */
class HideTaxonomyMetaFieldsTest extends HelpersTestCase {

	/** @var array<int, array{hook: string, callback: callable}> */
	private array $filters = [];

	/** @var array<int, array{hook: string, callback: callable}> */
	private array $actions = [];

	protected function setUp(): void {
		parent::setUp();
		$this->filters = [];
		$this->actions = [];
		Functions\when( 'add_filter' )->alias( function ( $hook, $callback ) {
			$this->filters[] = [ 'hook' => $hook, 'callback' => $callback ];
		} );
		Functions\when( 'add_action' )->alias( function ( $hook, $callback ) {
			$this->actions[] = [ 'hook' => $hook, 'callback' => $callback ];
		} );
	}

	private function hooksFor( array $registry, string $hook ): array {
		return array_values( array_filter( $registry, fn( $r ) => $r['hook'] === $hook ) );
	}

	public function test_registers_column_filter_and_form_css_hooks_for_default_taxonomy(): void {
		Helpers::hideTaxonomyMetaFields();

		$this->assertCount( 1, $this->hooksFor( $this->filters, 'manage_edit-category_columns' ) );
		$this->assertCount( 1, $this->hooksFor( $this->actions, 'category_edit_form' ) );
		$this->assertCount( 1, $this->hooksFor( $this->actions, 'category_add_form' ) );
	}

	public function test_column_filter_drops_description_and_slug_but_keeps_the_rest(): void {
		Helpers::hideTaxonomyMetaFields();

		$callback = $this->hooksFor( $this->filters, 'manage_edit-category_columns' )[0]['callback'];
		$columns = $callback( [
			'cb' => '<input type="checkbox" />',
			'name' => 'Name',
			'description' => 'Description',
			'slug' => 'Slug',
			'posts' => 'Count',
		] );

		$this->assertSame( [ 'cb', 'name', 'posts' ], array_keys( $columns ) );
	}

	public function test_form_hook_emits_css_hiding_the_field_wrappers(): void {
		Helpers::hideTaxonomyMetaFields();

		$callback = $this->hooksFor( $this->actions, 'category_edit_form' )[0]['callback'];
		ob_start();
		$callback();
		$css = ob_get_clean();

		$this->assertStringContainsString( '.term-description-wrap', $css );
		$this->assertStringContainsString( '.term-slug-wrap', $css );
		$this->assertStringContainsString( '.term-parent-wrap', $css );
		$this->assertStringContainsString( 'display: none', $css );
	}

	public function test_custom_taxonomy_and_field_subset(): void {
		Helpers::hideTaxonomyMetaFields( 'project_tag', [ 'description' ] );

		$this->assertCount( 1, $this->hooksFor( $this->filters, 'manage_edit-project_tag_columns' ) );

		$css_callback = $this->hooksFor( $this->actions, 'project_tag_edit_form' )[0]['callback'];
		ob_start();
		$css_callback();
		$css = ob_get_clean();
		$this->assertStringContainsString( '.term-description-wrap', $css );
		$this->assertStringNotContainsString( '.term-slug-wrap', $css );

		$columns_callback = $this->hooksFor( $this->filters, 'manage_edit-project_tag_columns' )[0]['callback'];
		$columns = $columns_callback( [ 'name' => 'Name', 'description' => 'D', 'slug' => 'S' ] );
		$this->assertSame( [ 'name', 'slug' ], array_keys( $columns ), 'only the requested fields are dropped from columns' );
	}

	public function test_columns_can_be_kept_while_hiding_form_fields(): void {
		Helpers::hideTaxonomyMetaFields( 'category', [ 'description', 'slug' ], false );

		$this->assertCount( 0, $this->hooksFor( $this->filters, 'manage_edit-category_columns' ) );
		$this->assertCount( 1, $this->hooksFor( $this->actions, 'category_edit_form' ) );
	}
}
