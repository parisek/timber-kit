<?php

declare(strict_types=1);

namespace Tests\Unit\Seo;

use Parisek\TimberKit\Seo\BreadcrumbSchema;
use PHPUnit\Framework\TestCase;

/**
 * One BreadcrumbList per page, and it is the theme's.
 *
 * The assertions that matter here are the two the implementation exists for:
 * that the `breadcrumb` reference goes with the node it points at, and that the
 * plugin's own switch decides. Either one silently broken leaves a page that
 * still validates and still says the wrong thing.
 */
final class BreadcrumbSchemaTest extends TestCase {

	public function test_a_graph_loses_its_breadcrumb_node(): void {
		$graph = array(
			array( '@type' => 'WebSite' ),
			array( '@type' => 'BreadcrumbList', 'itemListElement' => array() ),
			array( '@type' => 'Organization' ),
		);

		$types = array_column( BreadcrumbSchema::stripGraph( $graph ), '@type' );

		$this->assertSame( array( 'WebSite', 'Organization' ), $types );
	}

	/**
	 * The whole reason this filters the finished graph. The WebPage node points
	 * at the list by `@id`, so removing only the node leaves a reference to
	 * something that is no longer there — worse than the duplicate it replaced.
	 */
	public function test_the_reference_goes_with_the_node(): void {
		$graph = array(
			array( '@type' => 'BreadcrumbList' ),
			array( '@type' => 'WebPage', 'breadcrumb' => array( '@id' => 'https://x/#breadcrumblist' ) ),
		);

		$result = BreadcrumbSchema::stripGraph( $graph );

		$this->assertCount( 1, $result );
		$this->assertArrayNotHasKey( 'breadcrumb', $result[0] );
	}

	/** The spec allows an array of types, even though these plugins emit a string. */
	public function test_a_node_typed_as_an_array_is_still_recognised(): void {
		$graph = array( array( '@type' => array( 'BreadcrumbList', 'ItemList' ) ) );

		$this->assertSame( array(), BreadcrumbSchema::stripGraph( $graph ) );
	}

	public function test_a_graph_without_breadcrumbs_is_returned_unchanged(): void {
		$graph = array( array( '@type' => 'WebSite' ), array( '@type' => 'Organization' ) );

		$this->assertSame( $graph, BreadcrumbSchema::stripGraph( $graph ) );
	}

	/** An earlier filter may hand over something that is not a graph. */
	public function test_a_non_array_graph_is_passed_through(): void {
		$this->assertSame( '', BreadcrumbSchema::stripGraph( '' ) );
		$this->assertNull( BreadcrumbSchema::stripGraph( null ) );
	}

	/**
	 * The plugin's own switch decides. On means the editor asked for the
	 * plugin's breadcrumbs, and taking the markup away would be overruling
	 * a choice they made in the admin.
	 */
	public function test_the_plugin_keeps_its_breadcrumbs_when_its_own_switch_is_on(): void {
		$this->assertFalse( BreadcrumbSchema::shouldSuppress( 'aioseo', true ) );
		$this->assertFalse( BreadcrumbSchema::shouldSuppress( 'yoast', true ) );
	}

	public function test_the_switch_being_off_is_what_suppresses(): void {
		$this->assertTrue( BreadcrumbSchema::shouldSuppress( 'aioseo', false ) );
		$this->assertTrue( BreadcrumbSchema::shouldSuppress( 'yoast', false ) );
	}

	/** No plugin, nothing to take away. */
	public function test_no_seo_plugin_means_nothing_to_suppress(): void {
		$this->assertFalse( BreadcrumbSchema::shouldSuppress( null, false ) );
		$this->assertFalse( BreadcrumbSchema::shouldSuppress( null, null ) );
	}

	/**
	 * A missing switch is not an off switch.
	 *
	 * AIOSEO's exists only on sites that had breadcrumbs off before the setting
	 * was deprecated, and it sits on a removal list. Reading absence as "off"
	 * happens to give the right answer today and would invert the moment the
	 * plugin drops the key — so absence means no signal, and the flag governs.
	 */
	public function test_a_plugin_with_no_such_switch_falls_back_to_the_flag(): void {
		$this->assertTrue( BreadcrumbSchema::shouldSuppress( 'aioseo', null ) );
		$this->assertTrue( BreadcrumbSchema::shouldSuppress( 'yoast', null ) );
	}
}
