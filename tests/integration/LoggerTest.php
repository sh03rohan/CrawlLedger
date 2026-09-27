<?php
/**
 * End-to-end logger: queue -> ingest -> tables, plus the spoof fixture and DISABLE_WP_CRON.
 *
 * @package CrawlLedger
 */

namespace CrawlLedger\Tests\Integration;

use CrawlLedger\Activation;
use CrawlLedger\Logger\Ingest;
use CrawlLedger\Logger\Ranges;
use CrawlLedger\Logger\Record;
use CrawlLedger\Logger\Verifier;
use CrawlLedger\Plugin;
use CrawlLedger\Support\Cron;
use WP_UnitTestCase;

/**
 * Rows land, spoofed hits are unverified, cron warnings surface.
 */
final class LoggerTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Activation::activate( false );
		// Verification ships off; these cases are about the verification pipeline, so opt in.
		Plugin::instance()->options()->set( 'verification_enabled', true );
		update_option( Ranges::OPTION, array( 1 => array( 'cidrs' => array( '20.171.207.0/24' ), 'fetched' => time(), 'error' => '' ) ), false );
	}

	private function ingest(): array {
		$plugin = Plugin::instance();
		$ingest = new Ingest( $plugin->options(), $plugin->repository(), $plugin->queue(), new Verifier( new Ranges() ) );
		return $ingest->run();
	}

	public function test_real_and_spoofed_hits_land_with_correct_verified_flag(): void {
		$queue = Plugin::instance()->queue();
		$queue->append( Record::hit( 'GPTBot', '20.171.207.10', 'GET', '/real/', 'example.org', 200, 0, 50 ) );
		$queue->append( Record::hit( 'GPTBot', '203.0.113.5', 'GET', '/spoof/', 'example.org', 200, 0, 50 ) );

		$summary = $this->ingest();
		$this->assertSame( 2, $summary['rows'] );

		global $wpdb;
		$table = Plugin::instance()->repository()->hits_table();
		$rows  = $wpdb->get_results( "SELECT url, verified FROM {$table} ORDER BY id", ARRAY_A ); // phpcs:ignore
		$this->assertSame( array( array( 'url' => '/real/', 'verified' => '1' ), array( 'url' => '/spoof/', 'verified' => '0' ) ), $rows );

		$series = Plugin::instance()->repository()->series( 7, true );
		$this->assertSame( 1, array_sum( array_column( $series, 'hits' ) ) );
	}

	public function test_rate_cap_keeps_counting_but_stops_storing_rows(): void {
		Plugin::instance()->options()->set( 'rate_cap_per_minute', 10 );
		$queue = Plugin::instance()->queue();
		for ( $i = 0; $i < 25; $i++ ) {
			$queue->append( Record::hit( 'GPTBot', '20.171.207.10', 'GET', '/p' . $i, 'example.org', 200, 0, 50 ) );
		}
		$summary = $this->ingest();
		$this->assertSame( 10, $summary['rows'] );
		$this->assertSame( 15, $summary['capped'] );
		$series = Plugin::instance()->repository()->series( 7, true );
		$this->assertSame( 25, array_sum( array_column( $series, 'hits' ) ) );
	}

	public function test_verification_off_records_the_hit_without_looking_anything_up(): void {
		Plugin::instance()->options()->set( 'verification_enabled', false );
		$seen = array();
		$spy  = static function ( $pre, $args, $url ) use ( &$seen ) {
			$seen[] = $url;
			return new \WP_Error( 'blocked', 'blocked by test' );
		};
		add_filter( 'pre_http_request', $spy, 1, 3 );
		try {
			Plugin::instance()->queue()->append( Record::hit( 'GPTBot', '20.171.207.10', 'GET', '/real/', 'example.org', 200, 0, 50 ) );
			$summary = $this->ingest();
		} finally {
			remove_filter( 'pre_http_request', $spy, 1 );
		}

		$this->assertSame( 1, $summary['rows'] );
		$this->assertSame( array(), $seen );

		global $wpdb;
		$table = Plugin::instance()->repository()->hits_table();
		$this->assertSame( '0', $wpdb->get_var( "SELECT verified FROM {$table} ORDER BY id" ) ); // phpcs:ignore
	}

	public function test_disable_wp_cron_is_surfaced(): void {
		if ( ! defined( 'DISABLE_WP_CRON' ) ) {
			define( 'DISABLE_WP_CRON', true );
		}
		$cron = new Cron();
		if ( function_exists( 'as_schedule_recurring_action' ) ) {
			$this->assertSame( 'action-scheduler', $cron->backend() );
		} else {
			$this->assertSame( 'wp-cron-disabled', $cron->backend() );
		}
	}
}
