<?php

declare(strict_types=1);

namespace Tests\Unit\Breadcrumb;

use Parisek\TimberKit\Breadcrumb;
use ReflectionClass;

final class BreadcrumbTest extends BreadcrumbTestCase {

	// -------------------------------------------------------------------
	// Constructor & config
	// -------------------------------------------------------------------

	public function test_constructor_accepts_no_arguments(): void {
		$bc = new Breadcrumb();
		$this->assertSame( [], $bc->get() );
	}

	public function test_constructor_applies_config_to_known_properties(): void {
		$bc = new Breadcrumb([
			'menu_name'     => 'custom-menu',
			'list_page_map' => [ 'project' => 'projects_list' ],
		]);

		$reflection = new ReflectionClass( $bc );
		$menuProp = $reflection->getProperty( 'menu_name' );
		$mapProp = $reflection->getProperty( 'list_page_map' );

		$this->assertSame( 'custom-menu', $menuProp->getValue( $bc ) );
		$this->assertSame( [ 'project' => 'projects_list' ], $mapProp->getValue( $bc ) );
	}

	public function test_constructor_ignores_unknown_config_keys(): void {
		$bc = new Breadcrumb([ 'nonexistent_key' => 'whatever' ]);
		$this->assertSame( [], $bc->get() );
	}
}
