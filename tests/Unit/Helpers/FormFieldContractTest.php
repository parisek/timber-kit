<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use Parisek\TimberKit\Helpers;
use Tests\Unit\HelpersTestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Pins the form-field contract that a deferred render must keep (issue #92).
 *
 * A `post_object` field that points at a WPForms or Contact Form 7 form
 * reaches the template through `formatFields()`. Today the form renders at
 * format time. Issue #92 moves the render to template print time.
 *
 * These tests describe what a template sees, not when the render runs. They
 * pass before and after that change. They do not assume the value is a
 * string, and they count renders with `>= 1` or `=== 0`.
 *
 * One case stays unpinned on purpose: a plain `{% if content.form %}` on a
 * form whose render is empty. #92 changes that answer. Only
 * `{% if content.form is not empty %}` and `{{ content.form|raw }}` are pinned.
 */
class FormFieldContractTest extends HelpersTestCase {

	private int $render_calls = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->render_calls = 0;
		Functions\when( 'apply_filters' )->alias( function ( $filter, ...$args ) {
			return $args[0] ?? null;
		} );
		Functions\when( 'shortcode_exists' )->alias(
			static fn ( $tag ) => in_array( $tag, [ 'wpforms', 'contact-form-7' ], true )
		);
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function form_plugin_provider(): array {
		return [
			'wpforms'        => [ 'wpforms', 'wpforms', '[wpforms id="55"]' ],
			'contact form 7' => [ 'wpcf7_contact_form', 'contact-form-7', '[contact-form-7 id="55" title=""]' ],
		];
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function form_post_type_provider(): array {
		return [
			'wpforms'        => [ 'wpforms' ],
			'contact form 7' => [ 'wpcf7_contact_form' ],
		];
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
	 */
	public static function form_status_provider(): array {
		$cases = [];
		foreach ( self::form_plugin_provider() as $name => [ $post_type, $tag, $shortcode ] ) {
			foreach ( [ 'trash', 'draft' ] as $status ) {
				$cases[ $name . ', ' . $status ] = [ $post_type, $tag, $shortcode, $status ];
			}
		}
		return $cases;
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function missing_value_provider(): array {
		return [
			'false' => [ false ],
			'null'  => [ null ],
		];
	}

	private function form_post( string $post_type, string $status = 'publish' ): \WP_Post {
		// The shared WP_Post stub has no post_status property. A subclass declares
		// it, so the stub in tests/bootstrap.php stays untouched.
		$post = new class extends \WP_Post {
			public string $post_status = 'publish';
		};
		$post->ID          = 55;
		$post->post_type   = $post_type;
		$post->post_status = $status;

		return $post;
	}

	/**
	 * Mock one ACF `post_object` field named `form` and run formatFields().
	 *
	 * @param mixed $value Raw ACF value.
	 * @return array<string, mixed>
	 */
	private function format( $value, bool $is_preview = false ): array {
		Functions\when( 'get_field_objects' )->justReturn( [
			'form' => [
				'type'  => 'post_object',
				'name'  => 'form',
				'value' => $value,
			],
		] );

		return Helpers::formatFields( (object) [ 'ID' => 42 ], $is_preview );
	}

	/**
	 * Mock do_shortcode to return fixed markup and count the calls.
	 */
	private function mock_render( string $markup ): void {
		Functions\when( 'do_shortcode' )->alias( function ( $shortcode ) use ( $markup ) {
			++$this->render_calls;
			return $markup;
		} );
	}

	private function twig( string $template, array $content, string $autoescape = 'html' ): string {
		$twig = new Environment(
			new ArrayLoader( [ 't' => $template ] ),
			[ 'autoescape' => $autoescape ]
		);
		return $twig->render( 't', [ 'content' => $content ] );
	}

	// --- Missing form ---

	#[DataProvider( 'missing_value_provider' )]
	public function test_missing_form_is_falsy_in_twig_and_renders_nothing( $value ): void {
		Functions\expect( 'do_shortcode' )->never();

		$content = $this->format( $value );

		$this->assertSame( 'no', $this->twig( '{% if content.form %}yes{% else %}no{% endif %}', $content ) );
		$this->assertSame( '', $this->twig( '{% if content.form %}{{ content.form|raw }}{% endif %}', $content ) );
	}

	#[DataProvider( 'missing_value_provider' )]
	public function test_missing_form_does_not_raise_dynamic_counter( $value ): void {
		Functions\expect( 'do_shortcode' )->never();
		$before = Helpers::dynamicFormatCount();

		$this->format( $value );

		$this->assertSame( $before, Helpers::dynamicFormatCount() );
	}

	// --- Trashed and draft forms ---
	// The post_object branch does not read post_status. Main renders a trashed
	// or draft form like a published one.

	#[DataProvider( 'form_status_provider' )]
	public function test_non_published_form_renders_like_a_published_one( string $post_type, string $tag, string $shortcode, string $status ): void {
		$this->mock_render( '<form class="f">x</form>' );

		$content = $this->format( $this->form_post( $post_type, $status ) );

		$this->assertGreaterThanOrEqual( 1, $this->render_calls );
		$this->assertSame( '<form class="f">x</form>', $this->twig( '{{ content.form|raw }}', $content ) );
	}

	#[DataProvider( 'form_status_provider' )]
	public function test_non_published_form_raises_dynamic_counter( string $post_type, string $tag, string $shortcode, string $status ): void {
		$this->mock_render( '<form>x</form>' );
		$before = Helpers::dynamicFormatCount();

		$this->format( $this->form_post( $post_type, $status ) );

		$this->assertSame( $before + 1, Helpers::dynamicFormatCount() );
	}

	// --- Preview ---

	#[DataProvider( 'form_plugin_provider' )]
	public function test_preview_returns_shortcode_text_without_rendering( string $post_type, string $tag, string $shortcode ): void {
		Functions\expect( 'do_shortcode' )->never();

		$content = $this->format( $this->form_post( $post_type ), true );

		$this->assertSame( $shortcode, $content['form'] );
		$this->assertSame( $shortcode, $this->twig( '{{ content.form|raw }}', $content ) );
	}

	#[DataProvider( 'form_post_type_provider' )]
	public function test_preview_does_not_raise_dynamic_counter( string $post_type ): void {
		Functions\expect( 'do_shortcode' )->never();
		$before = Helpers::dynamicFormatCount();

		$this->format( $this->form_post( $post_type ), true );

		$this->assertSame( $before, Helpers::dynamicFormatCount() );
	}

	// --- Published form with an empty render ---

	#[DataProvider( 'form_post_type_provider' )]
	public function test_empty_render_is_empty_in_twig_and_prints_nothing( string $post_type ): void {
		$this->mock_render( '' );

		$content = $this->format( $this->form_post( $post_type ) );

		$this->assertSame( 'empty', $this->twig( '{% if content.form is not empty %}full{% else %}empty{% endif %}', $content ) );
		$this->assertSame( '', $this->twig( '{{ content.form|raw }}', $content ) );
	}

	// --- Published form with markup ---

	#[DataProvider( 'form_post_type_provider' )]
	public function test_markup_prints_raw( string $post_type ): void {
		$markup = '<form class="f"><input name="a" value="Čau &amp; ahoj"></form>';
		$this->mock_render( $markup );

		$content = $this->format( $this->form_post( $post_type ) );

		$this->assertGreaterThanOrEqual( 1, $this->render_calls );
		$this->assertSame( $markup, $this->twig( '{{ content.form|raw }}', $content ) );
		$this->assertSame( 'yes', $this->twig( '{% if content.form is not empty %}yes{% endif %}', $content ) );
	}

	#[DataProvider( 'form_post_type_provider' )]
	public function test_markup_is_escaped_under_html_autoescape( string $post_type ): void {
		$markup = '<form class="f">a & b</form>';
		$this->mock_render( $markup );

		$content = $this->format( $this->form_post( $post_type ) );

		$this->assertSame(
			'&lt;form class=&quot;f&quot;&gt;a &amp; b&lt;/form&gt;',
			$this->twig( '{{ content.form }}', $content )
		);
	}

	#[DataProvider( 'form_post_type_provider' )]
	public function test_length_filter_counts_characters_of_the_markup( string $post_type ): void {
		$markup = '<form>Čau, světe</form>';
		$this->mock_render( $markup );

		$content = $this->format( $this->form_post( $post_type ) );

		$this->assertSame(
			(string) mb_strlen( $markup ),
			$this->twig( '{{ content.form|length }}', $content )
		);
	}

	#[DataProvider( 'form_post_type_provider' )]
	public function test_markup_encodes_to_json_as_a_string( string $post_type ): void {
		$markup = '<form class="f">Čau</form>';
		$this->mock_render( $markup );

		$content = $this->format( $this->form_post( $post_type ) );

		$this->assertSame( json_encode( $markup ), json_encode( $content['form'] ) );
		$this->assertSame( json_encode( [ 'form' => $markup ] ), json_encode( $content ) );
		$this->assertSame(
			json_encode( $markup ),
			$this->twig( '{{ content.form|json_encode|raw }}', $content )
		);
	}

	// --- Dynamic counter ---

	#[DataProvider( 'form_post_type_provider' )]
	public function test_formatting_a_published_form_raises_dynamic_counter_by_one( string $post_type ): void {
		$this->mock_render( '<form>x</form>' );
		$before = Helpers::dynamicFormatCount();

		$this->format( $this->form_post( $post_type ) );

		$this->assertSame( $before + 1, Helpers::dynamicFormatCount() );
	}
}
