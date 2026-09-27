<?php
/**
 * Published crawler IP ranges.
 *
 * @package CrawlLedger
 */

namespace CrawlLedger\Logger;

use CrawlLedger\Module;
use CrawlLedger\Support\Cron;

/**
 * Fetches each provider's range JSON weekly, validates it, and keeps the last good copy on failure.
 * A failed fetch must never break logging.
 */
final class Ranges implements Module {

	const OPTION = 'crawlledger_ip_ranges';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( Cron::RANGES, array( $this, 'refresh' ) );
	}

	/**
	 * Stored ranges: bot_id => ['cidrs' => string[], 'fetched' => int, 'error' => string].
	 *
	 * @return array<mixed>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * CIDRs for a bot, or an empty list when none are known.
	 *
	 * @param int $bot_id Bot id.
	 * @return string[]
	 */
	public function for_bot( int $bot_id ): array {
		$all = $this->all();
		return isset( $all[ $bot_id ]['cidrs'] ) && is_array( $all[ $bot_id ]['cidrs'] ) ? $all[ $bot_id ]['cidrs'] : array();
	}

	/**
	 * Refresh every provider. Runs in cron only.
	 *
	 * @return void
	 */
	public function refresh(): void {
		$stored = $this->all();
		$cache  = array(); // URL => cidrs, since several bots share one document.

		foreach ( Signatures::registry() as $id => $bot ) {
			if ( Signatures::VERIFY_RANGES !== $bot['verify'] || empty( $bot['ranges'] ) ) {
				continue;
			}
			$cidrs = array();
			$error = '';
			foreach ( (array) $bot['ranges'] as $url ) {
				if ( ! isset( $cache[ $url ] ) ) {
					$cache[ $url ] = $this->fetch( $url );
				}
				if ( is_wp_error( $cache[ $url ] ) ) {
					$error = $cache[ $url ]->get_error_message();
					continue;
				}
				$cidrs = array_merge( $cidrs, $cache[ $url ] );
			}
			$cidrs = array_values( array_unique( $cidrs ) );

			if ( $cidrs ) {
				$stored[ $id ] = array(
					'cidrs'   => $cidrs,
					'fetched' => time(),
					'error'   => '',
				);
			} elseif ( isset( $stored[ $id ] ) ) {
				$stored[ $id ]['error'] = $error ? $error : 'empty';
			} else {
				$stored[ $id ] = array(
					'cidrs'   => array(),
					'fetched' => 0,
					'error'   => $error ? $error : 'empty',
				);
			}
		}

		update_option( self::OPTION, $stored, false );
	}

	/**
	 * Fetch and validate one document. Accepts the {"prefixes":[{"ipv4Prefix":..},{"ipv6Prefix":..}]} shape
	 * used by Google, OpenAI, Perplexity, Bing and Apple.
	 *
	 * @param string $url Document URL.
	 * @return string[]|\WP_Error
	 */
	private function fetch( string $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 10,
				'user-agent' => 'CrawlLedger/' . CRAWLLEDGER_VERSION,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_Error( 'http', 'HTTP ' . wp_remote_retrieve_response_code( $response ) );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['prefixes'] ) || ! is_array( $data['prefixes'] ) ) {
			return new \WP_Error( 'shape', 'Unexpected document structure' );
		}
		$cidrs = array();
		foreach ( $data['prefixes'] as $prefix ) {
			foreach ( array( 'ipv4Prefix', 'ipv6Prefix' ) as $key ) {
				if ( ! empty( $prefix[ $key ] ) && is_string( $prefix[ $key ] ) && preg_match( '~^[0-9a-f.:]+/\d{1,3}$~i', $prefix[ $key ] ) ) {
					$cidrs[] = $prefix[ $key ];
				}
			}
		}
		if ( ! $cidrs ) {
			return new \WP_Error( 'shape', 'No prefixes found' );
		}
		return $cidrs;
	}
}
