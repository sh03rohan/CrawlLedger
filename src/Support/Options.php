<?php
/**
 * Settings access.
 *
 * @package CrawlLedger
 */

namespace CrawlLedger\Support;

/**
 * Every setting lives in ONE autoloaded option so a front-end request never adds a query.
 *
 * Large or frequently rewritten data (IP ranges, ingest timestamps, locks) lives in separate,
 * non-autoloaded options so it never bloats alloptions.
 */
final class Options {

	const OPTION = 'crawlledger_settings';

	const IP_MODE_FULL      = 'full';
	const IP_MODE_TRUNCATED = 'truncated';
	const IP_MODE_HASHED    = 'hashed';

	/**
	 * In-request cache of the settings array.
	 *
	 * @var array<mixed>|null
	 */
	private $cache = null;

	/**
	 * Default settings.
	 *
	 * @return array<mixed>
	 */
	public static function defaults(): array {
		return array(
			'retention_days'         => 7,
			'ip_mode'                => self::IP_MODE_TRUNCATED,
			'ip_salt'                => '',
			'rate_cap_per_minute'    => 600,
			'verification_enabled'   => false, // Opt-in: until it is on, nothing leaves the site.
			'robots'                 => array(), // bot_id => 'allow' | 'block'.
			'schema_enabled'         => true,
			'llms_enabled'           => true,
			'llms_summary'           => '',
			'llms_pages'             => array(),
			'llms_include_posts'     => false,
			'keep_data_on_uninstall' => true,
			'ua_pattern'             => '',
			'queue_token'            => '',
			'physical_robots'        => false,
			'installed_at'           => 0,
		);
	}

	/**
	 * Read all settings, merged over defaults.
	 *
	 * @return array<mixed>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION, array() );
			$this->cache = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}
		return $this->cache;
	}

	/**
	 * Read one setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $fallback Fallback when unset.
	 * @return mixed
	 */
	public function get( string $key, $fallback = null ) {
		$all = $this->all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Write one setting.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Value.
	 * @return void
	 */
	public function set( string $key, $value ): void {
		$this->update( array( $key => $value ) );
	}

	/**
	 * Merge several settings and persist.
	 *
	 * @param array<mixed> $values Key/value pairs.
	 * @return void
	 */
	public function update( array $values ): void {
		$all         = array_merge( $this->all(), $values );
		$this->cache = $all;
		update_option( self::OPTION, $all, true );
	}

	/**
	 * Drop the in-request cache (used after switch_to_blog()).
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->cache = null;
	}

	/**
	 * Days of history the dashboard may show. The free build is 7; an add-on filters this up.
	 *
	 * @return int
	 */
	public function history_days(): int {
		return max( 1, (int) apply_filters( 'crawlledger_history_days', 7 ) );
	}

	/**
	 * Retention choices exposed in the settings UI. The free build offers 7; an add-on adds 30 / 90.
	 *
	 * @return int[]
	 */
	public function retention_choices(): array {
		$choices = (array) apply_filters( 'crawlledger_retention_choices', array( 7 ) );
		$choices = array_values( array_unique( array_map( 'intval', $choices ) ) );
		return $choices ? $choices : array( 7 );
	}
}
