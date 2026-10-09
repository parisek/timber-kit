<?php

declare(strict_types=1);

namespace Tests\Unit\Twig;

use Parisek\TimberKit\Twig\UniqueIdExtension;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * `uniqueId()` shipped in `parisek/twig-common` and the component library
 * calls it (accordion, dialog, faq, footer). The kit now owns it, so these
 * tests pin the contract the templates rely on.
 */
class UniqueIdExtensionTest extends TestCase {

	public function test_id_starts_with_a_letter_so_it_is_a_valid_html_id(): void {
		$extension = new UniqueIdExtension();

		for ( $i = 0; $i < 200; $i++ ) {
			$this->assertMatchesRegularExpression( '/^[a-z][0-9a-f]{6}$/', $extension->getUniqueId() );
		}
	}

	public function test_ids_do_not_repeat_within_one_extension(): void {
		$extension = new UniqueIdExtension();
		$ids       = array();

		for ( $i = 0; $i < 2000; $i++ ) {
			$ids[] = $extension->getUniqueId();
		}

		$this->assertCount( 2000, array_unique( $ids ) );
	}

	public function test_twig_exposes_the_function_under_its_camel_case_name(): void {
		$env = new Environment( new ArrayLoader( array( 'page' => '{{ uniqueId() }}' ) ) );
		$env->addExtension( new UniqueIdExtension() );

		$this->assertMatchesRegularExpression( '/^[a-z][0-9a-f]{6}$/', $env->render( 'page' ) );
	}

	public function test_two_calls_in_one_template_differ(): void {
		$env = new Environment( new ArrayLoader( array( 'page' => '{{ uniqueId() }}|{{ uniqueId() }}' ) ) );
		$env->addExtension( new UniqueIdExtension() );

		[ $first, $second ] = explode( '|', $env->render( 'page' ) );

		$this->assertNotSame( $first, $second );
	}

	public function test_the_twig_common_class_name_still_resolves(): void {
		// `Parisek\Twig\CommonExtension` shipped in parisek/twig-common, which
		// the kit no longer requires. A theme that registers it by hand keeps
		// working through the alias in compat/aliases.php.
		$this->assertTrue( class_exists( 'Parisek\Twig\CommonExtension' ) );
		$this->assertTrue( is_a( 'Parisek\Twig\CommonExtension', UniqueIdExtension::class, true ) );
	}
}
