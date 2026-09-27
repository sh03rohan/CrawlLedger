<?php
/**
 * Scheduling.
 *
 * @package CrawlLedger
 */

namespace CrawlLedger\Support;

use CrawlLedger\Module;

/**
 * WP-Cron cannot be relied on alone, so prefer Action Scheduler when a plugin (WooCommerce) bundles it.
 */
final class Cron implements Module {

	const INGEST = 'crawlledger_ingest';
	const ROLLUP = 'crawlledger_rollup';
	const RANGES = 'crawlledger_ranges';
	const GROUP  = 'crawlledger';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'cron_schedules', array( $this, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- five-minute ingest is the documented design.
		add_action( 'init', array( $this, 'ensure_scheduled' ), 20 );
	}

	/**
	 * Custom intervals.
	 *
	 * @param array<mixed> $schedules Existing schedules.
	 * @return array<mixed>
	 */
	public function schedules( array $schedules ): array {
		if ( ! isset( $schedules['crawlledger_5min'] ) ) {
			$schedules['crawlledger_5min'] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every five minutes (CrawlLedger)', 'crawlledger-ai-crawler-log' ),
			);
		}
		return $schedules;
	}

	/**
	 * Whether Action Scheduler is available.
	 *
	 * @return bool
	 */
	public function has_action_scheduler(): bool {
		return function_exists( 'as_schedule_recurring_action' ) && function_exists( 'as_has_scheduled_action' );
	}

	/**
	 * Events this plugin owns: hook => interval seconds.
	 *
	 * @return array<mixed>
	 */
	private function events(): array {
		$events = array(
			self::INGEST => array( 5 * MINUTE_IN_SECONDS, 'crawlledger_5min' ),
			self::ROLLUP => array( DAY_IN_SECONDS, 'daily' ),
		);
		if ( self::verification_enabled() ) {
			$events[ self::RANGES ] = array( WEEK_IN_SECONDS, 'weekly' );
		}
		return $events;
	}

	/**
	 * Whether the user has opted in to crawler verification.
	 *
	 * @return bool
	 */
	private static function verification_enabled(): bool {
		$settings = get_option( Options::OPTION, array() );
		return ! empty( $settings['verification_enabled'] );
	}

	/**
	 * Schedule or drop the weekly range fetch after the setting changes, and fetch once straight
	 * away when it has just been turned on so the user sees the result of their own click.
	 *
	 * @param bool $enabled New value.
	 * @return void
	 */
	public function sync_verification( bool $enabled ): void {
		if ( ! $enabled ) {
			wp_clear_scheduled_hook( self::RANGES );
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( self::RANGES, array(), self::GROUP );
			}
			return;
		}
		$this->ensure_scheduled();
		if ( $this->has_action_scheduler() ) {
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + 10, self::RANGES, array(), self::GROUP );
			}
		} elseif ( ! wp_next_scheduled( self::RANGES ) ) {
			wp_schedule_single_event( time() + 10, self::RANGES );
		}
	}

	/**
	 * Make sure every event is scheduled with whichever backend is available.
	 *
	 * On multisite the ingest and rollup run from the main site and iterate the network.
	 *
	 * @return void
	 */
	public function ensure_scheduled(): void {
		if ( is_multisite() && ! is_main_site() ) {
			return;
		}
		foreach ( $this->events() as $hook => $spec ) {
			list( $interval, $schedule ) = $spec;
			if ( $this->has_action_scheduler() ) {
				if ( ! as_has_scheduled_action( $hook, array(), self::GROUP ) ) {
					as_schedule_recurring_action( time() + 60, $interval, $hook, array(), self::GROUP );
				}
				// Clear any WP-Cron duplicate left from before Action Scheduler appeared.
				if ( wp_next_scheduled( $hook ) ) {
					wp_clear_scheduled_hook( $hook );
				}
			} elseif ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time() + 60, $schedule, $hook );
			}
		}
	}

	/**
	 * Remove all events from both backends.
	 *
	 * @return void
	 */
	public function unschedule_all(): void {
		foreach ( array( self::INGEST, self::ROLLUP, self::RANGES ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook, array(), self::GROUP );
			}
		}
	}

	/**
	 * Human label of the active backend.
	 *
	 * @return string 'action-scheduler' | 'wp-cron' | 'wp-cron-disabled'
	 */
	public function backend(): string {
		if ( $this->has_action_scheduler() ) {
			return 'action-scheduler';
		}
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return 'wp-cron-disabled';
		}
		return 'wp-cron';
	}
}
