<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Unit\StarterBaseTestCase;

/**
 * Coverage for the ACF ⇄ Alpine.js block-preview compatibility shim emitted by
 * StarterBase::acf_input_admin_footer().
 *
 * Background — the bug this guards against:
 *   ACF Pro's parseJSX (acf-pro-blocks.min.js) runs JSON.parse() on any block-
 *   preview attribute whose VALUE starts with `[` or `{`, assuming a JSON prop.
 *   That throws — crashing the preview and making the post unsavable
 *   ("Response is not valid JSON") — for attributes that legitimately start
 *   with those chars:
 *     - x-data="{ … }"   (Alpine inline object)
 *     - :class="{ … }"   (Alpine bind, object syntax)
 *     - pattern="[ … ]"  (HTML regex char class, e.g. a phone field)
 *   The shim registers ACF's `acf_blocks_parse_node_attr` escape-hatch filter
 *   so those attributes are passed through untouched and ACF skips JSON.parse.
 *
 * Regression origin — the shim originally covered ONLY `x-` attributes, so
 * `:class` and `pattern` still crashed. These tests pin coverage for each
 * affected attribute family so the filter can't silently narrow again.
 *
 * The filter is browser JS embedded in the emitted admin-footer markup; this
 * suite has no JS runtime, so (as with the sibling
 * `test_acf_input_admin_footer_prints_tinymce_sanitization_script`) we assert
 * against the emitted source.
 */
class AcfBlocksParseNodeAttrCompatTest extends StarterBaseTestCase {

	private function emittedFooterScript(): string {
		$base = $this->createStarterBase();
		ob_start();
		$base->acf_input_admin_footer();
		return (string) ob_get_clean();
	}

	public function test_registers_acf_blocks_parse_node_attr_filter(): void {
		$this->assertStringContainsString(
			"acf.addFilter('acf_blocks_parse_node_attr'",
			$this->emittedFooterScript(),
			'The Alpine/Gutenberg compatibility filter must be registered, otherwise ACF JSON.parses Alpine attributes and crashes block previews.'
		);
	}

	/**
	 * Each attribute family that legitimately renders a value starting with `[`
	 * or `{` must be covered by the pass-through predicate, or ACF's JSON.parse
	 * crashes the block preview.
	 *
	 */
	#[DataProvider( 'provideRequiredCoverage' )]
	public function test_filter_covers_attribute_family( string $predicateClause, string $exampleAttribute ): void {
		$this->assertStringContainsString(
			$predicateClause,
			$this->emittedFooterScript(),
			sprintf(
				'`%s` is not covered — ACF would JSON.parse it and crash the preview (e.g. %s).',
				$predicateClause,
				$exampleAttribute
			)
		);
	}

	/**
	 * @return array<string,array{string,string}> label => [ predicate clause, example attribute ]
	 */
	public static function provideRequiredCoverage(): array {
		return [
			'Alpine directives (x-data, x-show, x-model)' => [ "name.startsWith('x-')", 'x-data="{ open: false }"' ],
			'Alpine binds (:class, :style)'               => [ "name.startsWith(':')", ':class="{ active: open }"' ],
			'Alpine events (@click, @submit)'             => [ "name.startsWith('@')", '@click="open = true"' ],
			'HTML regex pattern'                          => [ "name === 'pattern'", 'pattern="[\\d\\s+()-]{9,20}"' ],
		];
	}
}
