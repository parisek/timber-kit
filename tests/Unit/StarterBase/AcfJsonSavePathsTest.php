<?php

declare(strict_types=1);

namespace Tests\Unit\StarterBase;

use Tests\Unit\StarterBaseTestCase;
use Brain\Monkey\Functions;

class AcfJsonSavePathsTest extends StarterBaseTestCase {

	private \Parisek\TimberKit\StarterBase $base;

	protected function setUp(): void {
		parent::setUp();
		$this->base = $this->createStarterBase();
	}

	public function test_returns_user_path_for_user_form_location(): void {
		$tmp_dir = sys_get_temp_dir() . '/timber_kit_test_' . uniqid();
		Functions\when( 'get_template_directory' )->justReturn( $tmp_dir );

		$user_dir = $tmp_dir . '/templates/user';
		if ( ! is_dir( $user_dir ) ) {
			mkdir( $user_dir, 0777, true );
		}

		$post = [
			'location' => [
				[
					[ 'param' => 'user_form', 'value' => 'all' ],
				],
			],
		];

		$result = $this->base->acf_json_save_paths( [ '/default/path' ], $post );

		$this->assertSame( [ $user_dir ], $result );

		// cleanup
		rmdir( $user_dir );
		rmdir( $tmp_dir . '/templates' );
		rmdir( $tmp_dir );
	}

	public function test_returns_default_paths_when_user_dir_does_not_exist(): void {
		Functions\when( 'get_template_directory' )->justReturn( '/tmp/nonexistent-theme' );

		$post = [
			'location' => [
				[
					[ 'param' => 'user_form', 'value' => 'all' ],
				],
			],
		];

		$result = $this->base->acf_json_save_paths( [ '/default/path' ], $post );

		$this->assertSame( [ '/default/path' ], $result );
	}
}
