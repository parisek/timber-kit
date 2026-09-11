<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use Parisek\TimberKit\Helpers;
use Tests\Unit\HelpersTestCase;

/**
 * Verifies Helpers::getEditorAllowedHtml() — the wp_kses() map for editor
 * richtext. It had no test of its own, which matters more than it looks:
 * StarterBase applies this list on `acf/update_value/type=wysiwyg`, so it runs
 * at SAVE time. Removing an entry does not merely hide that markup, it deletes
 * it from the database on the field's next save. A test that pins the
 * guarantees makes that consequence hard to cause by accident.
 */
class EditorAllowedHtmlTest extends HelpersTestCase {

	/** @return array<string, array<int, string>> */
	private function allowed(): array {
		return Helpers::getEditorAllowedHtml();
	}

	public function test_returns_a_kses_shaped_map(): void {
		foreach ( $this->allowed() as $tag => $attributes ) {
			$this->assertIsString( $tag );
			$this->assertIsArray( $attributes, "attributes for <$tag> must be an array" );
			foreach ( $attributes as $name => $enabled ) {
				$this->assertIsString( $name, "attribute name on <$tag> must be a string" );
				$this->assertTrue( $enabled, "wp_kses expects `true` for $name on <$tag>" );
			}
		}
	}

	/**
	 * The tags an editor actually produces. Named individually rather than
	 * counted, so dropping one fails with the tag in the message.
	 */
	public function test_permits_the_markup_a_wysiwyg_field_produces(): void {
		$expected = [ 'p', 'br', 'strong', 'em', 'ul', 'ol', 'li', 'a', 'img', 'blockquote', 'h2', 'h3' ];

		foreach ( $expected as $tag ) {
			$this->assertArrayHasKey( $tag, $this->allowed(), "<$tag> is ordinary editor output" );
		}
	}

	/**
	 * The list is a whitelist, so what it omits is the security boundary.
	 * `svg` is a scripting container, not a picture.
	 */
	public function test_omits_scripting_and_embedding_surfaces(): void {
		$forbidden = [ 'script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'svg', 'link', 'meta', 'base' ];

		foreach ( $forbidden as $tag ) {
			$this->assertArrayNotHasKey( $tag, $this->allowed(), "<$tag> must never be editor-writable" );
		}
	}

	public function test_no_tag_permits_an_event_handler_or_style(): void {
		foreach ( $this->allowed() as $tag => $attributes ) {
			foreach ( array_keys( $attributes ) as $name ) {
				$this->assertDoesNotMatchRegularExpression(
					'/^on/i', $name,
					"<$tag> must not permit the inline event handler $name"
				);
				$this->assertNotSame( 'style', strtolower( $name ), "<$tag> must not permit inline style" );
			}
		}
	}

	/**
	 * A link needs to be able to open in a new tab and to carry an accessible
	 * name. Both are presentational/assistive rather than scripting surfaces,
	 * and `rel` was already permitted — which left the list allowing the
	 * mitigation for `target="_blank"` while forbidding the attribute it
	 * mitigates.
	 */
	public function test_anchor_permits_target_and_aria_label(): void {
		$anchor = $this->allowed()['a'];

		$this->assertArrayHasKey( 'href', $anchor );
		$this->assertArrayHasKey( 'rel', $anchor );
		$this->assertArrayHasKey( 'target', $anchor, 'an announcement linking to an external form wants a new tab' );
		$this->assertArrayHasKey( 'aria-label', $anchor, 'link text like "here" needs an accessible name' );
	}

	/**
	 * Guards the direction of change. This list runs at SAVE time, so an entry
	 * removed here is content deleted from the database on the next save of
	 * every affected field, on every consuming site. Growing the list is
	 * recoverable; shrinking it is not.
	 */
	public function test_covers_every_tag_previously_guaranteed(): void {
		$guaranteed = [
			'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'sub', 'sup',
			'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
			'blockquote', 'hr', 'span', 'a', 'img', 'figure', 'figcaption',
			'code', 'pre',
		];

		$this->assertSame(
			[],
			array_diff( $guaranteed, array_keys( $this->allowed() ) ),
			'a tag may be added to this list, never removed — removal deletes stored content on next save'
		);
	}
}
