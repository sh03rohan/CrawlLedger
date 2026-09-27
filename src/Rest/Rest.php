<?php
/**
 * REST API.
 *
 * @package CrawlLedger
 */

namespace CrawlLedger\Rest;

use CrawlLedger\Activation;
use CrawlLedger\Logger\Coverage;
use CrawlLedger\Logger\Ingest;
use CrawlLedger\Logger\Queue;
use CrawlLedger\Logger\Ranges;
use CrawlLedger\Logger\Repository;
use CrawlLedger\Logger\Signatures;
use CrawlLedger\Logger\Verifier;
use CrawlLedger\LlmsTxt\LlmsTxt;
use CrawlLedger\Module;
use CrawlLedger\Robots\PhysicalFile;
use CrawlLedger\Robots\Robots;
use CrawlLedger\Schema\Schema;
use CrawlLedger\Support\Cron;
use CrawlLedger\Support\Options;
use CrawlLedger\Support\Rewrite;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Namespace crawlledger/v1. Every route declares a permission_callback; args carry types and enums so
 * WordPress validates before the callback runs. Dashboard ranges over 7 days never touch the raw table.
 */
final class Rest implements Module {

	const NS = 'crawlledger/v1';

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
	 * Coverage report.
	 *
	 * @var Coverage
	 */
	private $coverage;

	/**
	 * Cron.
	 *
	 * @var Cron
	 */
	private $cron;

	/**
	 * Constructor.
	 *
	 * @param Options    $options    Settings.
	 * @param Repository $repository Repository.
	 * @param Queue      $queue      Queue.
	 * @param Coverage   $coverage   Coverage report.
	 * @param Cron       $cron       Cron.
	 */
	public function __construct( Options $options, Repository $repository, Queue $queue, Coverage $coverage, Cron $cron ) {
		$this->options    = $options;
		$this->repository = $repository;
		$this->queue      = $queue;
		$this->coverage   = $coverage;
		$this->cron       = $cron;
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Permission callback shared by every route.
	 *
	 * @return bool
	 */
	public function can_manage(): bool {
		return current_user_can( Activation::CAPABILITY );
	}

	/**
	 * Range argument definition.
	 *
	 * @return array<mixed>
	 */
	private function range_arg(): array {
		return array(
			'type'              => 'string',
			'enum'              => array( '7d', '30d', '90d' ),
			'default'           => '7d',
			'sanitize_callback' => 'sanitize_key',
		);
	}

	/**
	 * Core's register_rest_route() only validates an arg when it carries a validate_callback; the schema keys
	 * (type, enum, minimum…) do nothing on their own. Decorate every arg so WordPress validates and
	 * sanitises against the schema before the callback runs.
	 *
	 * @param string       $path    Route.
	 * @param array<mixed> $handler One handler or a list of handlers.
	 * @return void
	 */
	private function route( string $path, array $handler ): void {
		$handlers = isset( $handler['methods'] ) ? array( $handler ) : $handler;
		foreach ( $handlers as &$h ) {
			if ( empty( $h['args'] ) ) {
				continue;
			}
			foreach ( $h['args'] as &$arg ) {
				if ( ! isset( $arg['validate_callback'] ) ) {
					$arg['validate_callback'] = 'rest_validate_request_arg';
				}
				if ( ! isset( $arg['sanitize_callback'] ) ) {
					$arg['sanitize_callback'] = 'rest_sanitize_request_arg';
				}
			}
			unset( $arg );
		}
		unset( $h );
		register_rest_route( self::NS, $path, $handlers );
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function routes(): void {
		$read  = WP_REST_Server::READABLE;
		$write = WP_REST_Server::CREATABLE;
		$perm  = array( $this, 'can_manage' );

		$this->route(
			'/stats',
			array(
				'methods'             => $read,
				'callback'            => array( $this, 'stats' ),
				'permission_callback' => $perm,
				'args'                => array(
					'range'    => $this->range_arg(),
					'verified' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);
		$this->route(
			'/urls',
			array(
				'methods'             => $read,
				'callback'            => array( $this, 'urls' ),
				'permission_callback' => $perm,
				'args'                => array(
					'verified' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);
		$this->route(
			'/coverage',
			array(
				'methods'             => $read,
				'callback'            => array( $this, 'coverage' ),
				'permission_callback' => $perm,
			)
		);
		$this->route(
			'/settings',
			array(
				array(
					'methods'             => $read,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => $write,
					'callback'            => array( $this, 'save_settings' ),
					'permission_callback' => $perm,
					'args'                => $this->settings_args(),
				),
			)
		);
		$this->route(
			'/robots',
			array(
				'methods'             => $read,
				'callback'            => array( $this, 'robots' ),
				'permission_callback' => $perm,
			)
		);
		$this->route(
			'/robots/physical',
			array(
				'methods'             => $write,
				'callback'            => array( $this, 'robots_physical' ),
				'permission_callback' => $perm,
				'args'                => array(
					'action' => array(
						'type'              => 'string',
						'enum'              => array( 'append', 'remove' ),
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
		$this->route(
			'/schema/preview',
			array(
				'methods'             => $read,
				'callback'            => array( $this, 'schema_preview' ),
				'permission_callback' => $perm,
				'args'                => array(
					'post' => array(
						'type'              => 'integer',
						'required'          => true,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
		$this->route(
			'/llms/preview',
			array(
				'methods'             => $write,
				'callback'            => array( $this, 'llms_preview' ),
				'permission_callback' => $perm,
				'args'                => array(
					'llms_summary'       => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'llms_pages'         => array(
						'type'    => 'array',
						'items'   => array( 'type' => 'integer' ),
						'default' => array(),
					),
					'llms_include_posts' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
		$this->route(
			'/pages',
			array(
				'methods'             => $read,
				'callback'            => array( $this, 'pages' ),
				'permission_callback' => $perm,
				'args'                => array(
					'search' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
		$this->route(
			'/ingest',
			array(
				'methods'             => $write,
				'callback'            => array( $this, 'ingest_now' ),
				'permission_callback' => $perm,
			)
		);
		$this->route(
			'/notice',
			array(
				'methods'             => $write,
				'callback'            => array( $this, 'dismiss_notice' ),
				'permission_callback' => $perm,
				'args'                => array(
					'id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * Settings argument schema. The narrowest sanitiser that fits, per field.
	 *
	 * @return array<mixed>
	 */
	private function settings_args(): array {
		return array(
			'retention_days'         => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'ip_mode'                => array(
				'type'              => 'string',
				'enum'              => array( Options::IP_MODE_FULL, Options::IP_MODE_TRUNCATED, Options::IP_MODE_HASHED ),
				'sanitize_callback' => 'sanitize_key',
			),
			'rate_cap_per_minute'    => array(
				'type'              => 'integer',
				'minimum'           => 10,
				'maximum'           => 100000,
				'sanitize_callback' => 'absint',
			),
			'robots'                 => array(
				'type'                 => 'object',
				'additionalProperties' => array(
					'type' => 'string',
					'enum' => array( 'allow', 'block', 'none' ),
				),
			),
			'schema_enabled'         => array( 'type' => 'boolean' ),
			'llms_enabled'           => array( 'type' => 'boolean' ),
			'llms_summary'           => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'llms_pages'             => array(
				'type'  => 'array',
				'items' => array( 'type' => 'integer' ),
			),
			'llms_include_posts'     => array( 'type' => 'boolean' ),
			'verification_enabled'   => array( 'type' => 'boolean' ),
			'keep_data_on_uninstall' => array( 'type' => 'boolean' ),
		);
	}

	/**
	 * Days for a range key, clamped to the history the build allows.
	 *
	 * @param string $range Range key.
	 * @return int
	 */
	private function days( string $range ): int {
		$map  = array(
			'7d'  => 7,
			'30d' => 30,
			'90d' => 90,
		);
		$days = isset( $map[ $range ] ) ? $map[ $range ] : 7;
		return min( $days, $this->options->history_days() );
	}

	/**
	 * GET /stats.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function stats( WP_REST_Request $request ): WP_REST_Response {
		$start    = microtime( true );
		$range    = (string) $request['range'];
		$verified = (bool) $request['verified'];
		$days     = $this->days( $range );

		$series   = $this->repository->series( $days, $verified );
		$two      = $this->repository->series( $days * 2, $verified );
		$previous = array_slice( $two, 0, $days, true ); // Same length, immediately before.
		// Crawler counts and per-bot sparklines are always unfiltered: the table lists every crawler with its own
		// verified share, so they must agree with it rather than with the toggle.
		$all_two      = $verified ? $this->repository->series( $days * 2, false ) : $two;
		$all_series   = array_slice( $all_two, $days, $days, true );
		$all_previous = array_slice( $all_two, 0, $days, true );
		$summary      = $this->repository->bots_summary( $days );
		$last         = $this->repository->last_seen( 7 );
		$registry     = Signatures::registry();

		$bots = array();
		foreach ( $registry as $id => $bot ) {
			if ( empty( $bot['ua'] ) ) {
				continue;
			}
			$s     = isset( $summary[ $id ] ) ? $summary[ $id ] : array(
				'total'    => 0,
				'verified' => 0,
				'errors'   => 0,
				'last_day' => '',
			);
			$spark = array();
			foreach ( $all_series as $day => $point ) {
				$spark[] = isset( $point['bots'][ $id ] ) ? (int) $point['bots'][ $id ] : 0;
			}
			$bots[] = array(
				'id'         => (int) $id,
				'name'       => $bot['name'],
				'vendor'     => $bot['vendor'],
				'type'       => $bot['type'],
				'verify'     => $bot['verify'],
				'total'      => $s['total'],
				'verified'   => $s['verified'],
				'unverified' => $s['total'] - $s['verified'],
				'errors'     => $s['errors'],
				'last_seen'  => isset( $last[ $id ] ) ? $last[ $id ] : ( $s['last_day'] ? $s['last_day'] . ' 00:00:00' : '' ),
				'spark'      => $spark,
				'docs'       => $bot['docs'],
			);
		}
		usort(
			$bots,
			static function ( $a, $b ) {
				return $b['total'] - $a['total'];
			}
		);

		$points = array();
		$total  = 0;
		$errors = 0;
		foreach ( $series as $day => $point ) {
			$points[] = array(
				'day'    => $day,
				'hits'   => $point['hits'],
				'ok'     => $point['ok'],
				'errors' => $point['errors'],
			);
			$total   += $point['hits'];
			$errors  += $point['errors'];
		}

		$prev_total  = 0;
		$prev_errors = 0;
		$prev_bots   = array();
		foreach ( $previous as $point ) {
			$prev_total  += $point['hits'];
			$prev_errors += $point['errors'];
		}
		foreach ( $all_previous as $point ) {
			foreach ( $point['bots'] as $bot_id => $n ) {
				if ( $n > 0 ) {
					$prev_bots[ $bot_id ] = true;
				}
			}
		}
		$active_bots = array();
		foreach ( $all_series as $point ) {
			foreach ( $point['bots'] as $bot_id => $n ) {
				if ( $n > 0 ) {
					$active_bots[ $bot_id ] = true;
				}
			}
		}

		$all_total = 0;
		$all_ver   = 0;
		foreach ( $summary as $s ) {
			$all_total += $s['total'];
			$all_ver   += $s['verified'];
		}

		return new WP_REST_Response(
			array(
				'range'         => $range,
				'days'          => $days,
				'history_days'  => $this->options->history_days(),
				'verified_only' => $verified,
				'series'        => $points,
				'bots'          => $bots,
				'totals'        => array(
					'hits'       => $total,
					'errors'     => $errors,
					'bots'       => count( $active_bots ),
					'all'        => $all_total,
					'verified'   => $all_ver,
					'unverified' => $all_total - $all_ver,
				),
				'previous'      => array(
					'hits'   => $prev_total,
					'errors' => $prev_errors,
					'bots'   => count( $prev_bots ),
				),
				'sizes'         => $this->repository->sizes(),
				'timing'        => $this->timing(),
				'query_ms'      => round( ( microtime( true ) - $start ) * 1000, 1 ),
			)
		);
	}

	/**
	 * Self-reported performance figures.
	 *
	 * @return array<mixed>
	 */
	private function timing(): array {
		$t = get_option( Ingest::OPTION_TIMING, array() );
		return array(
			'non_bot_avg_us' => isset( $t['avg_us'] ) ? (float) $t['avg_us'] : null,
			'samples'        => isset( $t['count'] ) ? (int) $t['count'] : 0,
			'budget_us'      => 400,
		);
	}

	/**
	 * GET /urls.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function urls( WP_REST_Request $request ): WP_REST_Response {
		$verified = (bool) $request['verified'];
		return new WP_REST_Response(
			array(
				'failing' => $this->repository->failing_urls( 7, $verified ),
				'top'     => $this->repository->top_urls( 7, $verified ),
				'recent'  => $this->repository->recent( 50 ),
			)
		);
	}

	/**
	 * GET /coverage.
	 *
	 * @return WP_REST_Response
	 */
	public function coverage(): WP_REST_Response {
		$last         = (int) get_option( Ingest::OPTION_LAST, 0 );
		$ranges       = ( new Ranges() )->all();
		$range_status = array();
		foreach ( $ranges as $bot_id => $r ) {
			$range_status[ (int) $bot_id ] = array(
				'count'   => isset( $r['cidrs'] ) ? count( $r['cidrs'] ) : 0,
				'fetched' => isset( $r['fetched'] ) ? (int) $r['fetched'] : 0,
				'error'   => isset( $r['error'] ) ? (string) $r['error'] : '',
			);
		}
		return new WP_REST_Response(
			array_merge(
				$this->coverage->report(),
				array(
					'queue'          => $this->queue->pending(),
					'queue_readable' => $this->queue->is_web_readable(),
					'last_ingest'    => $last,
					'ingest_stale'   => $last > 0 ? ( time() - $last ) > HOUR_IN_SECONDS : false,
					'cron_backend'   => $this->cron->backend(),
					'ranges'         => $range_status,
				)
			)
		);
	}

	/**
	 * GET /settings.
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings(): WP_REST_Response {
		$all = $this->options->all();
		return new WP_REST_Response(
			array(
				'retention_days'         => (int) $all['retention_days'],
				'retention_choices'      => $this->options->retention_choices(),
				'history_days'           => $this->options->history_days(),
				'ip_mode'                => (string) $all['ip_mode'],
				'rate_cap_per_minute'    => (int) $all['rate_cap_per_minute'],
				'verification_enabled'   => (bool) $all['verification_enabled'],
				'robots'                 => (object) $all['robots'],
				'schema_enabled'         => (bool) $all['schema_enabled'],
				'schema_provider'        => Schema::provider(),
				'llms_enabled'           => (bool) $all['llms_enabled'],
				'llms_summary'           => (string) $all['llms_summary'],
				'llms_pages'             => array_map( 'intval', (array) $all['llms_pages'] ),
				'llms_include_posts'     => (bool) $all['llms_include_posts'],
				'llms_url'               => home_url( '/llms.txt' ),
				'keep_data_on_uninstall' => (bool) $all['keep_data_on_uninstall'],
				'bots'                   => $this->bot_catalog(),
				'installed_at'           => (int) $all['installed_at'],
			)
		);
	}

	/**
	 * Bot catalog for the access-control UI.
	 *
	 * @return array<mixed>
	 */
	private function bot_catalog(): array {
		$out = array();
		foreach ( Signatures::registry() as $id => $bot ) {
			$out[] = array(
				'id'     => (int) $id,
				'name'   => $bot['name'],
				'vendor' => $bot['vendor'],
				'type'   => $bot['type'],
				'robots' => $bot['robots'],
				'has_ua' => ! empty( $bot['ua'] ),
				'verify' => $bot['verify'],
				'docs'   => $bot['docs'],
			);
		}
		return $out;
	}

	/**
	 * POST /settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function save_settings( WP_REST_Request $request ): WP_REST_Response {
		$values = array();
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		if ( isset( $params['retention_days'] ) ) {
			$days = (int) $params['retention_days'];
			if ( in_array( $days, $this->options->retention_choices(), true ) ) {
				$values['retention_days'] = $days;
			}
		}
		if ( isset( $params['ip_mode'] ) ) {
			$values['ip_mode'] = sanitize_key( (string) $params['ip_mode'] );
		}
		if ( isset( $params['rate_cap_per_minute'] ) ) {
			$values['rate_cap_per_minute'] = max( 10, min( 100000, (int) $params['rate_cap_per_minute'] ) );
		}
		if ( isset( $params['robots'] ) && ( is_array( $params['robots'] ) || is_object( $params['robots'] ) ) ) {
			$robots = array();
			foreach ( (array) $params['robots'] as $id => $rule ) {
				$id   = (int) $id;
				$rule = sanitize_key( (string) $rule );
				if ( Signatures::get( $id ) && in_array( $rule, array( 'allow', 'block' ), true ) ) {
					$robots[ $id ] = $rule;
				}
			}
			$values['robots'] = $robots;
		}
		if ( isset( $params['verification_enabled'] ) ) {
			$wanted = (bool) $params['verification_enabled'];
			// The weekly vendor fetch is scheduled here, on an explicit request from an administrator,
			// and nowhere else.
			if ( $wanted !== (bool) $this->options->get( 'verification_enabled', false ) ) {
				$values['verification_enabled'] = $wanted;
				$this->options->set( 'verification_enabled', $wanted );
				$this->cron->sync_verification( $wanted );
			}
		}
		foreach ( array( 'schema_enabled', 'llms_enabled', 'llms_include_posts', 'keep_data_on_uninstall' ) as $flag ) {
			if ( isset( $params[ $flag ] ) ) {
				$values[ $flag ] = (bool) $params[ $flag ];
			}
		}
		if ( isset( $params['llms_summary'] ) ) {
			$values['llms_summary'] = sanitize_textarea_field( (string) $params['llms_summary'] );
		}
		if ( isset( $params['llms_pages'] ) && is_array( $params['llms_pages'] ) ) {
			$values['llms_pages'] = array_values( array_unique( array_filter( array_map( 'absint', $params['llms_pages'] ) ) ) );
		}

		$this->options->update( $values );
		$this->options->set( 'physical_robots', PhysicalFile::exists() );

		// Rewrite rules: on settings save only, never on init.
		Rewrite::flush();

		return $this->get_settings();
	}

	/**
	 * GET /robots.
	 *
	 * @return WP_REST_Response
	 */
	public function robots(): WP_REST_Response {
		$robots = new Robots( $this->options );
		$robots->register();
		$data           = $robots->inspect();
		$data['groups'] = Signatures::by_type();
		$data['url']    = home_url( '/robots.txt' );
		return new WP_REST_Response( $data );
	}

	/**
	 * POST /robots/physical.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function robots_physical( WP_REST_Request $request ) {
		$robots = new Robots( $this->options );
		$rules  = 'append' === $request['action'] ? $robots->rules() : '';
		$result = PhysicalFile::write_block( $rules );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->robots();
	}

	/**
	 * GET /schema/preview.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function schema_preview( WP_REST_Request $request ) {
		$schema = new Schema( $this->options );
		$result = $schema->preview( (int) $request['post'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result );
	}

	/**
	 * POST /llms/preview — render llms.txt from unsaved values.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function llms_preview( WP_REST_Request $request ): WP_REST_Response {
		$llms = new LlmsTxt( $this->options );
		$text = $llms->render(
			array(
				'llms_summary'       => (string) $request['llms_summary'],
				'llms_pages'         => array_map( 'absint', (array) $request['llms_pages'] ),
				'llms_include_posts' => (bool) $request['llms_include_posts'],
			)
		);
		return new WP_REST_Response(
			array(
				'text'  => $text,
				'url'   => home_url( '/llms.txt' ),
				'ready' => '' !== trim( (string) $request['llms_summary'] ),
			)
		);
	}

	/**
	 * GET /pages — a light search for the llms.txt page picker and schema preview.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function pages( WP_REST_Request $request ): WP_REST_Response {
		$types = array( 'page', 'post' );
		if ( post_type_exists( 'product' ) ) {
			$types[] = 'product';
		}
		$posts = get_posts(
			array(
				'post_type'      => $types,
				'post_status'    => 'publish',
				'posts_per_page' => 30,
				's'              => (string) $request['search'],
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$out   = array();
		foreach ( $posts as $post ) {
			$out[] = array(
				'id'    => (int) $post->ID,
				'title' => get_the_title( $post ),
				'type'  => $post->post_type,
				'url'   => get_permalink( $post ),
			);
		}
		return new WP_REST_Response( $out );
	}

	/**
	 * POST /ingest.
	 *
	 * @return WP_REST_Response
	 */
	public function ingest_now(): WP_REST_Response {
		$ingest  = new Ingest( $this->options, $this->repository, $this->queue, new Verifier( new Ranges() ) );
		$summary = $ingest->run();
		return new WP_REST_Response( $summary );
	}

	/**
	 * POST /notice — per-user dismissal.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function dismiss_notice( WP_REST_Request $request ): WP_REST_Response {
		update_user_meta( get_current_user_id(), 'crawlledger_dismissed_' . $request['id'], time() );
		return new WP_REST_Response( array( 'ok' => true ) );
	}
}
