<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Brain\Monkey\Functions;
use Parisek\TimberKit\Helpers;
use Parisek\TimberKit\LazyFormEmbed;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Unit\HelpersTestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * A form `post_object` field renders when a template prints it (issue #92).
 *
 * `formatFields( 'option' )` formats every options field on every request.
 * Rendering the form there registered it with the plugin on every page, so
 * WPForms loaded its styles on pages that print no form. These tests pin the
 * deferred value and every place where it must behave like the old string.
 */
class FormEmbedTest extends HelpersTestCase {

	/** @var int do_shortcode() calls in the current test. */
	private int $renders = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->renders = 0;
		Functions\when( 'apply_filters' )->alias( static fn ( $filter, ...$args ) => $args[0] ?? null );
		Functions\when( 'shortcode_exists' )->alias( static fn ( string $tag ) => in_array( $tag, [ 'wpforms', 'contact-form-7' ], true ) );
		Functions\when( 'do_shortcode' )->alias( function ( string $content ): string {
			++$this->renders;
			return '<form class="rendered" data-source=\'' . $content . '\'>' . $this->renders . '</form>';
		} );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function formTypeProvider(): array {
		return [
			'WPForms'        => [ 'wpforms', '[wpforms id="12"]' ],
			'Contact Form 7' => [ 'wpcf7_contact_form', '[contact-form-7 id="12" title=""]' ],
		];
	}

	private function formField( string $post_type, string $status = 'publish' ): array {
		return [
			'type'  => 'post_object',
			'name'  => 'form',
			'value' => new \WP_Post( [ 'ID' => 12, 'post_type' => $post_type, 'post_status' => $status ] ),
		];
	}

	#[DataProvider( 'formTypeProvider' )]
	public function test_formatting_a_published_form_does_not_render_it( string $post_type, string $shortcode ): void {
		$value = Helpers::fieldFormatter( $this->formField( $post_type ) );

		$this->assertInstanceOf( LazyFormEmbed::class, $value );
		$this->assertSame( $shortcode, $value->shortcode() );
		$this->assertSame( 0, $this->renders, 'the form rendered while it was formatted' );
		$this->assertFalse( $value->isRendered() );
	}

	#[DataProvider( 'formTypeProvider' )]
	public function test_printing_renders_once_however_often_it_prints( string $post_type, string $shortcode ): void {
		$value = Helpers::fieldFormatter( $this->formField( $post_type ) );

		$first  = (string) $value;
		$second = (string) $value;

		$this->assertSame( 1, $this->renders );
		$this->assertSame( $first, $second );
		$this->assertStringContainsString( 'class="rendered"', $first );
		$this->assertStringContainsString( $shortcode, $first );
	}

	#[DataProvider( 'formTypeProvider' )]
	public function test_preview_still_returns_the_shortcode_string( string $post_type, string $shortcode ): void {
		$value = Helpers::fieldFormatter( $this->formField( $post_type ), null, true );

		$this->assertSame( $shortcode, $value );
		$this->assertSame( 0, $this->renders );
	}

	#[DataProvider( 'formTypeProvider' )]
	public function test_an_unpublished_form_keeps_the_eager_string( string $post_type, string $shortcode ): void {
		$value = Helpers::fieldFormatter( $this->formField( $post_type, 'draft' ) );

		$this->assertIsString( $value );
		$this->assertStringContainsString( $shortcode, $value );
		$this->assertSame( 1, $this->renders );
	}

	#[DataProvider( 'formTypeProvider' )]
	public function test_an_inactive_plugin_keeps_the_eager_string( string $post_type, string $shortcode ): void {
		Functions\when( 'shortcode_exists' )->justReturn( false );

		$value = Helpers::fieldFormatter( $this->formField( $post_type ) );

		$this->assertIsString( $value );
		$this->assertStringContainsString( $shortcode, $value );
		$this->assertSame( 1, $this->renders );
	}

	public function test_another_post_type_passes_through(): void {
		$field = [
			'type'  => 'post_object',
			'name'  => 'related',
			'value' => new \WP_Post( [ 'ID' => 5, 'post_type' => 'page', 'post_status' => 'publish' ] ),
		];

		$value = Helpers::fieldFormatter( $field );

		$this->assertInstanceOf( \WP_Post::class, $value );
		$this->assertSame( 0, $this->renders );
	}

	public function test_the_deferred_form_still_counts_as_dynamic(): void {
		$before = Helpers::dynamicFormatCount();

		Helpers::fieldFormatter( $this->formField( 'wpforms' ) );

		$this->assertSame( $before + 1, Helpers::dynamicFormatCount(), 'a block or menu holding the form would be cached with its nonce' );
	}

	public function test_formatfields_keeps_the_deferred_form(): void {
		Functions\when( 'get_field_objects' )->justReturn( [ 'form' => $this->formField( 'wpforms' ) ] );
		Functions\when( 'get_queried_object_id' )->justReturn( 7 );

		$content = Helpers::formatFields( 7 );

		$this->assertArrayHasKey( 'form', $content );
		$this->assertInstanceOf( LazyFormEmbed::class, $content['form'] );
		$this->assertSame( 0, $this->renders );
	}

	public function test_json_encode_renders_the_markup(): void {
		$value = Helpers::fieldFormatter( $this->formField( 'wpforms' ) );

		$this->assertSame( json_encode( (string) $value ), json_encode( $value ) );
		$this->assertSame( 1, $this->renders );
	}

	public function test_a_serialised_copy_keeps_the_shortcode_not_the_markup(): void {
		$value = Helpers::fieldFormatter( $this->formField( 'wpforms' ) );
		(string) $value;

		$serialised = serialize( $value );
		$this->assertStringNotContainsString( 'rendered', $serialised, 'markup with a nonce must not cross requests' );

		$copy = unserialize( $serialised );
		$this->assertInstanceOf( LazyFormEmbed::class, $copy );
		$this->assertFalse( $copy->isRendered() );
		$this->assertSame( '[wpforms id="12"]', $copy->shortcode() );
	}

	// --- Twig: the templates in the field use `{% if %}` and `|raw` ---

	private function twig( string $template, array $context ): string {
		$env = new Environment( new ArrayLoader( [ 't' => $template ] ), [ 'autoescape' => 'html' ] );
		return $env->render( 't', $context );
	}

	public function test_twig_raw_prints_the_form(): void {
		$value = Helpers::fieldFormatter( $this->formField( 'wpforms' ) );

		$this->assertStringContainsString( '<form class="rendered"', $this->twig( '{{ form|raw }}', [ 'form' => $value ] ) );
	}

	public function test_twig_autoescape_escapes_it_like_the_old_string(): void {
		$value = Helpers::fieldFormatter( $this->formField( 'wpforms' ) );

		$html = $this->twig( '{{ form }}', [ 'form' => $value ] );

		$this->assertSame( $this->twig( '{{ form }}', [ 'form' => (string) $value ] ), $html );
		$this->assertStringStartsWith( '&lt;form', $html );
	}

	public function test_twig_if_wraps_the_printed_form_and_renders_once(): void {
		$value = Helpers::fieldFormatter( $this->formField( 'wpforms' ) );

		$html = $this->twig( '{% if form %}<div>{{ form|raw }}</div><aside>{{ form|raw }}</aside>{% endif %}', [ 'form' => $value ] );

		$this->assertStringStartsWith( '<div><form', $html );
		$this->assertSame( 1, $this->renders );
	}

	public function test_twig_if_skips_a_form_that_is_not_there(): void {
		Functions\when( 'get_field_objects' )->justReturn(
			[
				'form' => [
					'type'  => 'post_object',
					'name'  => 'form',
					'value' => false,
				],
			]
		);
		Functions\when( 'get_queried_object_id' )->justReturn( 7 );

		$content = Helpers::formatFields( 7 );

		$this->assertSame( 'none', $this->twig( '{% if content.form %}form{% else %}none{% endif %}', [ 'content' => $content ] ) );
	}

	public function test_a_template_that_never_prints_the_form_never_renders_it(): void {
		$value = Helpers::fieldFormatter( $this->formField( 'wpforms' ) );

		$this->twig( '<p>no form here</p>', [ 'content' => [ 'form' => $value ] ] );

		$this->assertSame( 0, $this->renders );
	}

	public function test_twig_length_and_empty_render_and_read_the_markup(): void {
		$value = Helpers::fieldFormatter( $this->formField( 'wpforms' ) );

		$this->assertSame( 'no', $this->twig( '{{ form is empty ? "yes" : "no" }}', [ 'form' => $value ] ) );
		$this->assertSame( (string) mb_strlen( (string) $value ), $this->twig( '{{ form|length }}', [ 'form' => $value ] ) );
	}
}
