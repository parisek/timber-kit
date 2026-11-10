<?php
/**
 * A form embed that renders when a template prints it.
 *
 * @package Parisek\TimberKit
 */

declare(strict_types=1);

namespace Parisek\TimberKit;

/**
 * The formatted value of a WPForms `post_object` field.
 *
 * `fieldFormatter()` used to run the form shortcode while it formatted the
 * field. `formatFields( 'option' )` formats every field of the options page on
 * every request, so every page rendered the form, and the render registered
 * the form with the plugin. WPForms then enqueued its styles in `wp_footer`,
 * and WordPress (6.9 and later) hoisted them into `<head>`: render-blocking
 * styles on pages that print no form (issue #92). Contact Form 7 enqueues on
 * every page anyway, so its field keeps the eager string.
 *
 * This value defers the render to the first time it is turned into a string:
 * a Twig print, `|raw`, `|length`, `is empty`, `json_encode()` or a string
 * cast. A page that never prints it never renders the form. The render runs
 * once per object; a second print returns the same markup.
 *
 * Templates print it the way they printed the old string, `{{ content.form|raw }}`.
 * Autoescape treats it like the old string too: `{{ content.form }}` without
 * `|raw` escapes it. It does not extend `Twig\Markup`, which Twig marks
 * `@final`. Twig tests that require a real string (`in`, `starts with`,
 * `ends with`, `same as`) answer false without rendering it; cast it with
 * `~ ''` first.
 *
 * Twig and PHP treat an object as true, so {@see Helpers::fieldFormatter()}
 * creates this value only for a published form with decodable form data and
 * an active plugin. Any other case keeps the old string result, and an empty
 * one stays falsy. A form the plugin still refuses to render (a filter, an
 * access rule) is true here and prints `''`; `is not empty` gives the exact
 * answer.
 */
final class LazyFormEmbed implements \Stringable, \JsonSerializable {

	/** @var string|null Rendered markup, set on the first render. */
	private ?string $rendered = null;

	/**
	 * @param string $shortcode The shortcode that renders the form.
	 */
	public function __construct( private readonly string $shortcode ) {
	}

	/**
	 * The shortcode this value renders, for code that needs the source.
	 */
	public function shortcode(): string {
		return $this->shortcode;
	}

	/**
	 * Whether the form has rendered in this request.
	 */
	public function isRendered(): bool {
		return null !== $this->rendered;
	}

	/**
	 * The rendered form. Renders on the first call only.
	 */
	public function render(): string {
		if ( null === $this->rendered ) {
			$this->rendered = (string) do_shortcode( $this->shortcode );
		}

		return $this->rendered;
	}

	public function __toString(): string {
		return $this->render();
	}

	public function jsonSerialize(): string {
		return $this->render();
	}

	/**
	 * A serialised copy keeps the shortcode, never the markup: the markup
	 * carries a nonce and must not cross requests.
	 *
	 * @return array{shortcode: string}
	 */
	public function __serialize(): array {
		return [ 'shortcode' => $this->shortcode ];
	}

	/**
	 * @param array{shortcode: string} $data Serialised data.
	 */
	public function __unserialize( array $data ): void {
		$this->__construct( (string) ( $data['shortcode'] ?? '' ) );
	}
}
