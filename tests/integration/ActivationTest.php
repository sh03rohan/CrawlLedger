<?php
/**
 * Activation, capability, file locations, migrations.
 *
 * @package CrawlLedger
 */

namespace CrawlLedger\Tests\Integration;

use CrawlLedger\Activation;
use CrawlLedger\Migrations;
use CrawlLedger\Plugin;
use WP_UnitTestCase;

/**
 * Runs inside wp-env against the core test suite.
 */
final class ActivationTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Activation::activate( false );
	}

	public function test_tables_exist_after_activation(): void {
		$this->assertTrue( Plugin::instance()->repository()->tables_exist() );
		$this->assertSame( Migrations::SCHEMA_VERSION, (int) get_option( Migrations::OPTION ) );
	}

	public function test_administrator_receives_capability(): void {
		$this->assertTrue( get_role( 'administrator' )->has_cap( Activation::CAPABILITY ) );
	}

	public function test_pattern_is_compiled_into_the_autoloaded_option(): void {
		$pattern = Plugin::instance()->options()->get( 'ua_pattern' );
		$this->assertNotEmpty( $pattern );
		$this->assertSame( 1, preg_match( $pattern, 'Mozilla/5.0 (compatible; GPTBot/1.0)' ) );
	}

	public function test_every_option_read_on_plugins_loaded_is_autoloaded(): void {
		// Migrations::maybe_run() reads these on every request; a non-autoloaded option would cost a query per visitor.
		wp_cache_delete( 'alloptions', 'options' );
		$all = wp_load_alloptions();
		$this->assertArrayHasKey( Migrations::OPTION, $all );
		$this->assertArrayHasKey( Migrations::OPTION_PLUGIN, $all );
		$this->assertArrayHasKey( \CrawlLedger\Support\Options::OPTION, $all );
	}

	public function test_upgrade_repairs_non_autoloaded_version_options(): void {
		update_option( Migrations::OPTION_PLUGIN, '0.0.1', false );
		wp_set_option_autoload_values( array( Migrations::OPTION => false ) );
		Migrations::maybe_run();
		wp_cache_delete( 'alloptions', 'options' );
		$all = wp_load_alloptions();
		$this->assertSame( CRAWLLEDGER_VERSION, $all[ Migrations::OPTION_PLUGIN ] ?? null );
		$this->assertArrayHasKey( Migrations::OPTION, $all );
	}

	public function test_activation_writes_nothing_outside_uploads(): void {
		// The reviewable contract: no files in mu-plugins, wp-content root or the plugin folder.
		$this->assertFileDoesNotExist( WPMU_PLUGIN_DIR . '/crawlledger-drop-in.php' );
		$this->assertDirectoryExists( Plugin::instance()->queue()->dir() );
		$this->assertStringStartsWith( wp_upload_dir()['basedir'], Plugin::instance()->queue()->dir() );
	}

	public function test_coverage_report_names_page_caches_only(): void {
		$report = Plugin::instance()->coverage()->report();
		$this->assertArrayHasKey( 'complete', $report );
		$this->assertArrayHasKey( 'misses', $report );
		$this->assertTrue( $report['complete'] ); // A bare test install has no page cache.
		$this->assertSame( array(), $report['misses'] );
	}

	/**
	 * Guideline 7: activation must not contact anyone. Verification is opt-in, so the weekly
	 * vendor fetch is not even scheduled until an administrator asks for it.
	 */
	public function test_activation_makes_no_outbound_request(): void {
		$seen = array();
		$spy  = static function ( $pre, $args, $url ) use ( &$seen ) {
			$seen[] = $url;
			return new \WP_Error( 'blocked', 'blocked by test' );
		};
		add_filter( 'pre_http_request', $spy, 1, 3 );
		try {
			delete_option( \CrawlLedger\Support\Options::OPTION );
			Plugin::instance()->options()->reset();
			Activation::activate( false );
		} finally {
			remove_filter( 'pre_http_request', $spy, 1 );
		}
		$this->assertSame( array(), $seen );
		$this->assertFalse( (bool) Plugin::instance()->options()->get( 'verification_enabled' ) );
		$this->assertFalse( (bool) wp_next_scheduled( \CrawlLedger\Support\Cron::RANGES ) );
	}

	public function test_range_fetch_is_scheduled_only_after_opting_in(): void {
		$cron = Plugin::instance()->cron();
		$cron->sync_verification( false );
		$this->assertFalse( (bool) wp_next_scheduled( \CrawlLedger\Support\Cron::RANGES ) );

		Plugin::instance()->options()->set( 'verification_enabled', true );
		$cron->sync_verification( true );
		$this->assertTrue( (bool) wp_next_scheduled( \CrawlLedger\Support\Cron::RANGES ) );

		Plugin::instance()->options()->set( 'verification_enabled', false );
		$cron->sync_verification( false );
		$this->assertFalse( (bool) wp_next_scheduled( \CrawlLedger\Support\Cron::RANGES ) );
	}

	/**
	 * Migrations run from plugins_loaded, never from the activation hook.
	 */
	public function test_migration_runs_when_version_differs(): void {
		update_option( Migrations::OPTION_PLUGIN, '0.0.1' );
		update_option( Migrations::OPTION, 0 );
		Migrations::maybe_run();
		$this->assertSame( CRAWLLEDGER_VERSION, get_option( Migrations::OPTION_PLUGIN ) );
		$this->assertSame( Migrations::SCHEMA_VERSION, (int) get_option( Migrations::OPTION ) );
	}
}
