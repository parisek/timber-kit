<?php

declare(strict_types=1);

namespace Tests\Unit\Seo;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Parisek\TimberKit\Seo\Aioseo;
use PHPUnit\Framework\TestCase;

/**
 * Reading AIOSEO's "Enable Breadcrumbs" switch.
 *
 * This exists for one line. AIOSEO resolves options through `__get()`, and its
 * `__isset()` is not an existence check — it walks and resets the traversal
 * state. A null-coalescing read calls `__isset()` first, so it takes a
 * different path through the chain and answers differently. Written that way
 * the method returned false while the switch read true, and the suppression
 * could not be turned off from the admin, which is the whole point of binding
 * to that switch.
 *
 * No unit test could have caught it upstream: the decision function takes a
 * boolean, so it was handed the wrong answer and agreed with it. Only a render
 * showed it. The stub below reproduces the exact shape — magic getter, no
 * `__isset` — so the trap cannot come back.
 */
final class AioseoBreadcrumbsEnabledTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_the_switch_being_on_is_read_through_a_magic_getter(): void {
		Functions\when( 'aioseo' )->justReturn( new FakeAioseo( true, array( 'breadcrumbsEnable' ) ) );

		$this->assertTrue( Aioseo::breadcrumbsEnabled() );
	}

	public function test_the_switch_being_off_reads_as_off(): void {
		Functions\when( 'aioseo' )->justReturn( new FakeAioseo( false, array( 'breadcrumbsEnable' ) ) );

		$this->assertFalse( Aioseo::breadcrumbsEnabled() );
	}

	/**
	 * The switch only exists on a site that had breadcrumbs off before AIOSEO
	 * deprecated the setting, and it sits on that plugin's removal list. Where
	 * it is absent the value must not be read at all — the plugin's own code
	 * checks membership before every read, and so does this.
	 */
	public function test_an_absent_switch_reads_as_no_answer_rather_than_off(): void {
		Functions\when( 'aioseo' )->justReturn( new FakeAioseo( false, array() ) );

		$this->assertNull( Aioseo::breadcrumbsEnabled() );
	}
}

/**
 * AIOSEO's option chain in miniature.
 *
 * `__get()` answers truthfully; `__isset()` answers false, standing in for the
 * real one's stateful walk. That asymmetry is the whole trap: a `??` read
 * consults `__isset()` and so never sees what `__get()` would have returned.
 */
class FakeAioseo {

	/** @param array<int, string> $deprecated */
	public function __construct( private bool $enable, private array $deprecated ) {}

	public function __get( string $name ): mixed {
		if ( 'enable' === $name ) {
			return $this->enable;
		}

		if ( 'deprecatedOptions' === $name ) {
			return $this->deprecated;
		}

		return $this;
	}

	public function __isset( string $name ): bool {
		return false;
	}
}
