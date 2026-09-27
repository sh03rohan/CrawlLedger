<?php
/**
 * Queue ingest.
 *
 * @package CrawlLedger
 */

namespace CrawlLedger\Logger;

use CrawlLedger\Module;
use CrawlLedger\Support\Cron;
use CrawlLedger\Support\Ip;
use CrawlLedger\Support\Lock;
use CrawlLedger\Support\Options;
use CrawlLedger\Support\Str;

/**
 * Every five minutes: claim queue files, verify IPs, apply the per-minute ceiling, batch insert.
 */
final class Ingest implements Module {

	const LOCK              = 'crawlledger_ingest_lock';
	const OPTION_LAST       = 'crawlledger_last_ingest';
	const OPTION_TIMING     = 'crawlledger_timing';
	const MAX_LINES_PER_RUN = 50000;

	/**
	 * Settings.
	 *
	 * @var Options
	 */
	private $options;

	/**
	 * Repository.
	 *
	 * @var Repository
	 */
	private $repository;

	/**
	 * Queue.
	 *
	 * @var Queue
	 */
	private $queue;

	/**
	 * Verifier.
	 *
	 * @var Verifier
	 */
	private $verifier;

	/**
	 * Constructor.
	 *
	 * @param Options    $options    Settings.
	 * @param Repository $repository Repository.
	 * @param Queue      $queue      Queue.
	 * @param Verifier   $verifier   Verifier.
	 */
	public function __construct( Options $options, Repository $repository, Queue $queue, Verifier $verifier ) {
		$this->options    = $options;
		$this->repository = $repository;
		$this->queue      = $queue;
		$this->verifier   = $verifier;
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( Cron::INGEST, array( $this, 'handle' ) );
	}

	/**
	 * Cron entry point.
	 *
	 * @return void
	 */
	public function handle(): void {
		$this->run();
	}

	/**
	 * Run one ingest pass. Safe to call from cron, REST ("ingest now") or WP-CLI.
	 *
	 * @return array<mixed> Summary counts.
	 */
	public function run(): array {
		$summary = array(
			'files'   => 0,
			'rows'    => 0,
			'capped'  => 0,
			'skipped' => 0,
			'elapsed' => 0.0,
		);
		if ( ! Lock::acquire( self::LOCK, 300 ) ) {
			$summary['skipped'] = -1; // Another run holds the lock.
			return $summary;
		}
		$start = microtime( true );
		try {
			$files = $this->queue->claim();
			foreach ( $files as $file ) {
				$result = $this->ingest_file( $file );
				++$summary['files'];
				$summary['rows']    += $result['rows'];
				$summary['capped']  += $result['capped'];
				$summary['skipped'] += $result['skipped'];
				$this->queue->discard( $file );
			}
			update_option( self::OPTION_LAST, time(), false );
		} finally {
			Lock::release( self::LOCK );
		}
		$summary['elapsed'] = round( microtime( true ) - $start, 3 );
		return $summary;
	}

	/**
	 * Ingest one file.
	 *
	 * @param string $file Path.
	 * @return array{rows:int, capped:int, skipped:int}
	 */
	private function ingest_file( string $file ): array {
		$counts = array(
			'rows'    => 0,
			'capped'  => 0,
			'skipped' => 0,
		);
		$handle = @fopen( $file, 'r' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a large log line by line.
		if ( ! $handle ) {
			return $counts;
		}
		// Give any writer holding the old inode a moment to finish its append.
		usleep( 50000 );

		$per_site = array(); // Records grouped by blog id.
		$timings  = array();
		$lines    = 0;

		while ( ( $line = fgets( $handle ) ) !== false ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( ++$lines > self::MAX_LINES_PER_RUN ) {
				break;
			}
			$rec = Record::parse( $line );
			if ( null === $rec ) {
				++$counts['skipped'];
				continue;
			}
			if ( 'timing' === $rec['type'] ) {
				$timings[] = $rec['elapsed_us'];
				continue;
			}
			$blog_id = $this->resolve_blog( $rec['host'], $rec['uri'] );
			if ( null === $blog_id ) {
				++$counts['skipped'];
				continue;
			}
			if ( ! isset( $per_site[ $blog_id ] ) ) {
				$per_site[ $blog_id ] = array(
					'records' => array(),
				);
			}
			$per_site[ $blog_id ]['records'][] = $rec;
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		foreach ( $per_site as $blog_id => $bucket ) {
			$switched = false;
			if ( is_multisite() && get_current_blog_id() !== (int) $blog_id ) {
				switch_to_blog( (int) $blog_id );
				$this->options->reset();
				$switched = true;
			}
			try {
				$result = $this->store( $bucket['records'] );
			} finally {
				if ( $switched ) {
					restore_current_blog();
					$this->options->reset();
				}
			}
			$counts['rows']   += $result['rows'];
			$counts['capped'] += $result['capped'];
		}

		if ( $timings ) {
			$this->record_timing( $timings );
		}
		return $counts;
	}

	/**
	 * Verify, cap and write a set of records for the current site.
	 *
	 * @param array<mixed> $records Parsed hit records.
	 * @return array{rows:int, capped:int}
	 */
	private function store( array $records ): array {
		$cap     = max( 1, (int) $this->options->get( 'rate_cap_per_minute', 600 ) );
		$ip_mode = (string) $this->options->get( 'ip_mode', Options::IP_MODE_TRUNCATED );
		$salt    = $this->salt();
		$verify  = (bool) $this->options->get( 'verification_enabled', false );

		$rows    = array();
		$daily   = array();
		$minutes = array(); // bot_id|Y-m-d H:i => count.
		$capped  = 0;

		foreach ( $records as $rec ) {
			$bot_id = Signatures::id_for_token( $rec['token'] );
			if ( 0 === $bot_id ) {
				continue;
			}
			$at = $this->normalise_datetime( $rec['at'] );
			if ( '' === $at ) {
				continue;
			}
			$ip = Ip::is_valid( $rec['ip'] ) ? $rec['ip'] : '';
			// Verification is opt-in: with it off nothing is looked up and no request leaves the site.
			$verified = $verify && '' !== $ip && $this->verifier->verify( $bot_id, $ip );
			$status   = (int) $rec['status'];
			$bucket   = Repository::bucket( $status );
			$day      = substr( $at, 0, 10 );

			$daily_key = $day . '|' . $bot_id . '|' . $bucket . '|' . ( $verified ? 1 : 0 );
			if ( ! isset( $daily[ $daily_key ] ) ) {
				$daily[ $daily_key ] = array(
					'day'           => $day,
					'bot_id'        => $bot_id,
					'status_bucket' => $bucket,
					'verified'      => $verified ? 1 : 0,
					'hits'          => 0,
				);
			}
			++$daily[ $daily_key ]['hits'];

			// Self-defence: past the ceiling we keep counting (daily) but stop storing per-request rows.
			$minute_key             = $bot_id . '|' . substr( $at, 0, 16 );
			$minutes[ $minute_key ] = isset( $minutes[ $minute_key ] ) ? $minutes[ $minute_key ] + 1 : 1;
			if ( $minutes[ $minute_key ] > $cap ) {
				++$capped;
				continue;
			}

			$path   = Str::path( $rec['uri'] );
			$rows[] = array(
				'hit_at'    => $at,
				'bot_id'    => $bot_id,
				'url_hash'  => Str::url_hash( $path ),
				'url'       => $path,
				'status'    => $status,
				'ip'        => $this->storable_ip( $ip, $ip_mode, $salt ),
				'verified'  => $verified ? 1 : 0,
				'is_cached' => (int) $rec['cached'] ? 1 : 0,
			);
		}

		$inserted = $rows ? $this->repository->insert_hits( $rows ) : 0;
		if ( $daily ) {
			$this->repository->upsert_daily( array_values( $daily ) );
		}
		return array(
			'rows'   => $inserted,
			'capped' => $capped,
		);
	}

	/**
	 * Apply the privacy setting. Verification already happened on the real address.
	 *
	 * @param string $ip   Address, may be ''.
	 * @param string $mode Storage mode.
	 * @param string $salt Site salt for hashing.
	 * @return string Raw bytes for VARBINARY(16).
	 */
	private function storable_ip( string $ip, string $mode, string $salt ): string {
		if ( '' === $ip ) {
			return "\0\0\0\0";
		}
		switch ( $mode ) {
			case Options::IP_MODE_FULL:
				$bin = Ip::pack( $ip );
				return null === $bin ? "\0\0\0\0" : $bin;
			case Options::IP_MODE_HASHED:
				return Ip::hash( $ip, $salt );
			case Options::IP_MODE_TRUNCATED:
			default:
				$bin = Ip::pack( Ip::truncate( $ip ) );
				return null === $bin ? "\0\0\0\0" : $bin;
		}
	}

	/**
	 * Per-site salt for hashed storage, created once.
	 *
	 * @return string
	 */
	private function salt(): string {
		$salt = (string) $this->options->get( 'ip_salt', '' );
		if ( '' === $salt ) {
			$salt = Str::token( 32 );
			$this->options->set( 'ip_salt', $salt );
		}
		return $salt;
	}

	/**
	 * Validate a Y-m-d H:i:s stamp and clamp the future.
	 *
	 * @param string $at Stamp.
	 * @return string '' when invalid.
	 */
	private function normalise_datetime( string $at ): string {
		if ( ! preg_match( '~^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$~', $at ) ) {
			return '';
		}
		$ts = strtotime( $at . ' UTC' );
		if ( false === $ts || $ts > time() + 300 ) {
			return '';
		}
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	/**
	 * Map a host/path pair to a blog id. Single site: always the current blog.
	 *
	 * @param string $host Host header.
	 * @param string $uri  Request URI.
	 * @return int|null Null when the host belongs to no site in the network.
	 */
	private function resolve_blog( string $host, string $uri ): ?int {
		if ( ! is_multisite() ) {
			return get_current_blog_id();
		}
		// Mirror core's ms-settings.php: strip only the default ports, then match the host verbatim.
		$host = strtolower( $host );
		if ( ':80' === substr( $host, -3 ) ) {
			$host = substr( $host, 0, -3 );
		} elseif ( ':443' === substr( $host, -4 ) ) {
			$host = substr( $host, 0, -4 );
		}
		$path = Str::path( $uri );
		$site = get_site_by_path( $host, $path );
		if ( ! $site instanceof \WP_Site && false !== strpos( $host, ':' ) ) {
			$site = get_site_by_path( (string) preg_replace( '~:\d+$~', '', $host ), $path ); // Proxies that add a port.
		}
		if ( $site instanceof \WP_Site ) {
			return (int) $site->blog_id;
		}
		return null;
	}

	/**
	 * Fold timing samples into a running average, keeping the last 1,000 samples' worth of weight.
	 *
	 * @param int[] $samples Microseconds.
	 * @return void
	 */
	private function record_timing( array $samples ): void {
		$current = get_option( self::OPTION_TIMING, array() );
		$count   = isset( $current['count'] ) ? (int) $current['count'] : 0;
		$avg     = isset( $current['avg_us'] ) ? (float) $current['avg_us'] : 0.0;
		foreach ( $samples as $us ) {
			$count = min( 1000, $count + 1 );
			$avg   = $avg + ( ( (float) $us - $avg ) / $count );
		}
		update_option(
			self::OPTION_TIMING,
			array(
				'count'   => $count,
				'avg_us'  => round( $avg, 1 ),
				'updated' => time(),
			),
			false
		);
	}
}
