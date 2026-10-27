<?php
/**
 * Unit tests for GSlider\Classes\RegistrationBlocks.
 *
 * Verifies that:
 * 1. RegistrationBlocks implements Registrable interface and SingletonTrait.
 * 2. register() hooks into WordPress 'init'.
 * 3. Plugin services array contains RegistrationBlocks::class.
 * 4. Registered block viewScript handle has 'defer' strategy and Swiper dependency.
 * 5. Swiper dependency is not added if Swiper script is not registered.
 * 6. Swiper dependency is not duplicated if already present.
 *
 * @package GSlider\Tests\Classes
 */

require_once dirname( __DIR__ ) . '/bootstrap.php';

// Require Core Classes Under Test.
require_once ABSPATH . 'includes/Interfaces/Registrable.php';
require_once ABSPATH . 'includes/Traits/SingletonTrait.php';
require_once ABSPATH . 'includes/Classes/RegistrationBlocks.php';
require_once ABSPATH . 'includes/Assets/FrontendAssets.php';
require_once ABSPATH . 'includes/Plugin.php';

use GSlider\Classes\RegistrationBlocks;
use GSlider\Assets\FrontendAssets;
use GSlider\Interfaces\Registrable;
use GSlider\Plugin;

/**
 * Test class for GSlider\Classes\RegistrationBlocks.
 *
 * @package GSlider\Tests\Classes
 */
class RegistrationBlocksTest {

	/**
	 * Run all test methods in class.
	 *
	 * @return void
	 */
	public function run_all_tests(): void {
		echo "=== GSlider RegistrationBlocks Unit Tests ===\n\n";

		$this->test_implements_registrable_and_singleton();
		$this->test_plugin_services_contains_class();
		$this->test_register_hooks();
		$this->test_view_script_defer_and_swiper_dependency();
		$this->test_swiper_dependency_not_added_when_unregistered();
		$this->test_swiper_dependency_not_duplicated();

		echo "\nAll RegistrationBlocks unit tests passed successfully!\n";
	}

	/**
	 * Test 1: Verify RegistrationBlocks implements Registrable and follows Singleton pattern.
	 *
	 * @return void
	 */
	public function test_implements_registrable_and_singleton(): void {
		$instance = RegistrationBlocks::getInstance();
		if ( ! ( $instance instanceof Registrable ) ) {
			fwrite( STDERR, "FAIL: RegistrationBlocks does not implement Registrable interface\n" );
			exit( 1 );
		}
		echo "OK: RegistrationBlocks implements Registrable interface\n";

		$instance_again = RegistrationBlocks::getInstance();
		if ( $instance !== $instance_again ) {
			fwrite( STDERR, "FAIL: RegistrationBlocks does not maintain singleton identity\n" );
			exit( 1 );
		}
		echo "OK: RegistrationBlocks implements SingletonTrait correctly\n";
	}

	/**
	 * Test 2: Verify Plugin::$services array contains RegistrationBlocks::class.
	 *
	 * @return void
	 */
	public function test_plugin_services_contains_class(): void {
		$ref = new ReflectionProperty( Plugin::class, 'services' );
		if ( PHP_VERSION_ID < 80100 ) {
			$ref->setAccessible( true );
		}
		$services = $ref->getValue( Plugin::getInstance() );

		if ( ! in_array( RegistrationBlocks::class, $services, true ) ) {
			fwrite( STDERR, "FAIL: Plugin::\$services array does not contain RegistrationBlocks::class\n" );
			exit( 1 );
		}
		echo "OK: Plugin::\$services contains RegistrationBlocks::class\n";
	}

	/**
	 * Test 3: Verify register() hooks register_blocks into 'init'.
	 *
	 * @return void
	 */
	public function test_register_hooks(): void {
		global $wp_actions;

		$instance = RegistrationBlocks::getInstance();
		$instance->register();

		$has_hook = false;
		if ( ! empty( $wp_actions['init'] ) ) {
			foreach ( $wp_actions['init'] as $action ) {
				if ( is_array( $action['callback'] ) && $action['callback'][0] instanceof RegistrationBlocks && 'register_blocks' === $action['callback'][1] ) {
					$has_hook = true;
					break;
				}
			}
		}

		if ( ! $has_hook ) {
			fwrite( STDERR, "FAIL: RegistrationBlocks does not hook register_blocks into init\n" );
			exit( 1 );
		}
		echo "OK: RegistrationBlocks hooks into init action\n";
	}

	/**
	 * Test 4: Verify registered block view-script handle has 'defer' and has Swiper in deps.
	 *
	 * @return void
	 */
	public function test_view_script_defer_and_swiper_dependency(): void {
		global $wp_scripts;

		// 1. Ensure Swiper script is registered.
		FrontendAssets::getInstance()->enqueue_assets();

		if ( ! wp_script_is( 'gslider-blocks-swiper-script', 'registered' ) ) {
			fwrite( STDERR, "FAIL: Swiper script is not registered before block registration\n" );
			exit( 1 );
		}

		// 2. Trigger block registration.
		$instance = RegistrationBlocks::getInstance();
		$instance->register_blocks();

		$scripts = wp_scripts();
		$slugs   = [ 'base', 'flexi-content', 'image-slider', 'logo-carousel', 'testimonial' ];

		foreach ( $slugs as $slug ) {
			$handle = "gslider-blocks-{$slug}-view-script";

			if ( ! isset( $scripts->registered[ $handle ] ) ) {
				fwrite( STDERR, "FAIL: Block view script '{$handle}' is not registered\n" );
				exit( 1 );
			}

			$script_obj = $scripts->registered[ $handle ];

			// Check defer strategy.
			$strategy = $script_obj->extra['strategy'] ?? ( $script_obj->args['strategy'] ?? null );
			if ( 'defer' !== $strategy ) {
				fwrite( STDERR, "FAIL: Block view script '{$handle}' does not have strategy => defer\n" );
				exit( 1 );
			}

			// Check Swiper dependency.
			if ( ! in_array( 'gslider-blocks-swiper-script', $script_obj->deps, true ) ) {
				fwrite( STDERR, "FAIL: Block view script '{$handle}' does not declare dependency on gslider-blocks-swiper-script\n" );
				exit( 1 );
			}

			echo "OK: View script '{$handle}' has strategy => defer and Swiper in deps\n";
		}
	}

	/**
	 * Test 5: Verify Swiper dependency is NOT added if Swiper script is not registered.
	 *
	 * @return void
	 */
	public function test_swiper_dependency_not_added_when_unregistered(): void {
		global $wp_scripts, $wp_scripts_obj;

		// Reset scripts.
		$wp_scripts     = [];
		$wp_scripts_obj = new \WP_Scripts();

		// Register block view script without Swiper script registered.
		$handle = 'gslider-blocks-test-mock-view-script';
		wp_register_script( $handle, 'mock-view.js', [ 'dummy-dep' ] );

		// Simulate RegistrationBlocks logic via reflection.
		$instance = RegistrationBlocks::getInstance();
		$method   = new \ReflectionMethod( RegistrationBlocks::class, 'register_single_block' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		// Use base block directory.
		$plugin_dir = defined( 'GSLIDER_DIR_PATH' ) ? GSLIDER_DIR_PATH : dirname( __DIR__, 2 ) . '/';
		$build_dir  = trailingslashit( $plugin_dir ) . 'build/blocks/';

		$method->invoke( $instance, $build_dir, 'base' );

		$base_handle = 'gslider-blocks-base-view-script';
		$scripts     = wp_scripts();

		if ( in_array( 'gslider-blocks-swiper-script', $scripts->registered[ $base_handle ]->deps, true ) ) {
			fwrite( STDERR, "FAIL: Swiper was added to deps even though Swiper script was not registered\n" );
			exit( 1 );
		}
		echo "OK: Swiper dependency is not added when Swiper is not registered\n";
	}

	/**
	 * Test 6: Verify Swiper dependency is not duplicated if already present in deps.
	 *
	 * @return void
	 */
	public function test_swiper_dependency_not_duplicated(): void {
		// Register Swiper script.
		FrontendAssets::getInstance()->enqueue_assets();

		$instance = RegistrationBlocks::getInstance();
		$method   = new \ReflectionMethod( RegistrationBlocks::class, 'register_single_block' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$plugin_dir = defined( 'GSLIDER_DIR_PATH' ) ? GSLIDER_DIR_PATH : dirname( __DIR__, 2 ) . '/';
		$build_dir  = trailingslashit( $plugin_dir ) . 'build/blocks/';

		// Invoke multiple times.
		$method->invoke( $instance, $build_dir, 'base' );
		$method->invoke( $instance, $build_dir, 'base' );

		$base_handle = 'gslider-blocks-base-view-script';
		$scripts     = wp_scripts();

		$occurrences = array_count_values( $scripts->registered[ $base_handle ]->deps );
		if ( ( $occurrences['gslider-blocks-swiper-script'] ?? 0 ) !== 1 ) {
			fwrite( STDERR, "FAIL: Swiper dependency was duplicated in deps\n" );
			exit( 1 );
		}
		echo "OK: Swiper dependency is not duplicated when already present\n";
	}
}

// Standalone execution entrypoint.
( new RegistrationBlocksTest() )->run_all_tests();
