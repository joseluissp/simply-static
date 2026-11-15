<?php

namespace Simply_Static;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cloudflare Workers delivery method: settings, defaults, auto-publish and admin screen.
 *
 * Goal: paste one API token, tick "enabled", and the site exports and goes live.
 * Everything else (account, Worker name, URLs, 404 page, headers, forms) is derived.
 *
 * Self-contained on purpose: the only core change is the require/init line in
 * Plugin, so upstream merges stay conflict-free.
 *
 * Credentials may live in wp-config.php (SIMPLY_STATIC_CF_ACCOUNT_ID,
 * SIMPLY_STATIC_CF_API_TOKEN, SIMPLY_STATIC_CF_WORKER); constants win over the screen.
 */
class Cloudflare {

	const OPTION = 'ss_cloudflare';
	const STATUS = 'ss_cloudflare_status';
	const HOOK   = 'ss_cloudflare_autopublish';

	/** Quiet seconds after the last content change before the site is re-exported. */
	const DEBOUNCE = 60;

	public static function init() {
		self::migrate_enabled_flag();
		add_filter( 'simplystatic.archive_creation_job.task_list', array( __CLASS__, 'task_list' ), 20, 2 );
		add_filter( 'ss_get_option_generate_404', array( __CLASS__, 'when_enabled_true' ) );
		add_filter( 'ss_get_option_crawlers', array( __CLASS__, 'default_crawlers' ) );
		foreach ( array( 'destination_url_type', 'destination_scheme', 'destination_host', 'relative_path' ) as $name ) {
			add_filter( 'ss_get_option_' . $name, function ( $value ) use ( $name ) {
				return self::destination( $name, $value );
			} );
		}

		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );

		// Auto-publish: any change to published content schedules one debounced full export.
		add_action( 'transition_post_status', array( __CLASS__, 'on_post_status' ), 10, 3 );
		add_action( 'wp_update_nav_menu', array( __CLASS__, 'queue_publish' ) );
		add_action( 'customize_save_after', array( __CLASS__, 'queue_publish' ) );
		add_action( self::HOOK, array( __CLASS__, 'autopublish' ) );

		Cloudflare_Forms::init();
		Cloudflare_Search::init();
		Cloudflare_Changes::init();
		Hide_WP::init();
		Cloudflare_Ui::init();
		Cloudflare_Redirects::init();
		Cloudflare_Pages::init();
	}

	/** Early builds kept an "enabled" flag here; the delivery method is the single switch now. */
	protected static function migrate_enabled_flag() {
		$stored = get_option( self::OPTION );
		if ( ! is_array( $stored ) || ! array_key_exists( 'enabled', $stored ) ) {
			return;
		}
		if ( ! empty( $stored['enabled'] ) ) {
			$core                    = (array) get_option( 'simply-static', array() );
			$core['delivery_method'] = 'cloudflare';
			update_option( 'simply-static', $core );
		}
		unset( $stored['enabled'] );
		update_option( self::OPTION, $stored, false );
	}

	/**
	 * @return array account_id, worker_name, token, enabled, html_handling, not_found_handling,
	 *               domain, auto_publish, forms, webhook_url, extra_headers, extra_redirects
	 */
	public static function settings() {
		$settings = wp_parse_args( get_option( self::OPTION, array() ), array(
			'account_id'         => '',
			'worker_name'        => '',
			'token'              => '',
			'html_handling'      => 'auto-trailing-slash',
			'not_found_handling' => '404-page',
			'domain'             => '',
			'auto_publish'       => true,
			'forms'              => true,
			'webhook_url'        => '',
			'extra_headers'      => '',
			'extra_redirects'    => '',
			'notify'             => true,
			'notify_email'       => '',
			'search'             => true,
			'hide_wp'            => true,
			'autopublish_type'   => 'update',
			'disable_workers_dev' => false,
			'excluded_urls'      => array(),
		) );

		// The Worker's name is its identity: once deployed, a moved or cloned site (local copy, new
		// address) must keep updating the same one instead of deriving a new name from its host.
		$status = get_option( self::STATUS, array() );
		if ( '' === $settings['worker_name'] && ! empty( $status['worker'] ) ) {
			$settings['worker_name'] = $status['worker'];
		}

		foreach ( array( 'account_id' => 'SIMPLY_STATIC_CF_ACCOUNT_ID', 'token' => 'SIMPLY_STATIC_CF_API_TOKEN', 'worker_name' => 'SIMPLY_STATIC_CF_WORKER' ) as $key => $constant ) {
			if ( defined( $constant ) ) {
				$settings[ $key ] = constant( $constant );
			}
		}

		if ( '' === $settings['worker_name'] ) {
			$settings['worker_name'] = self::default_worker_name();
		}
		$settings['enabled'] = self::enabled();

		return $settings;
	}

	/** Cloudflare is on when it is the plugin's delivery method (same place every other method lives). */
	public static function enabled() {
		if ( defined( 'SIMPLY_STATIC_DELIVERY_METHOD' ) ) {
			return 'cloudflare' === constant( 'SIMPLY_STATIC_DELIVERY_METHOD' );
		}
		$core = get_option( 'simply-static', array() );

		return is_array( $core ) && isset( $core['delivery_method'] ) && 'cloudflare' === $core['delivery_method'];
	}

	/** Worker names: lowercase letters, digits, dashes; Cloudflare allows 63 chars. */
	public static function default_worker_name() {
		$host = preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		// Local addresses say nothing about the site: use its title instead.
		if ( 'localhost' === $host || filter_var( $host, FILTER_VALIDATE_IP ) || preg_match( '/\.(local|test|localhost|invalid)$/', $host ) ) {
			$host = get_bloginfo( 'name' );
		}
		$name = trim( substr( sanitize_title( str_replace( '.', '-', $host ) ), 0, 54 ), '-' );

		return '' === $name ? 'simply-static-site' : $name;
	}

	/**
	 * Smart Crawl finds pages only through crawlers, and an empty saved list means "none":
	 * the export would contain just the home page and the deploy would delete the rest of the
	 * live site. With Cloudflare delivery an empty list falls back to the default crawlers.
	 */
	public static function default_crawlers( $value ) {
		return self::enabled() && is_array( $value ) && ! $value ? null : $value;
	}

	public static function when_enabled_true( $value ) {
		return self::enabled() ? true : $value;
	}

	/**
	 * URL settings derived from the real address, so no one has to type them:
	 * custom domain, else the workers.dev URL, else relative links (work anywhere).
	 */
	public static function destination( $name, $value ) {
		if ( ! self::enabled() ) {
			return $value;
		}

		$s      = get_option( self::OPTION, array() );
		$status = get_option( self::STATUS, array() );
		$host   = ! empty( $s['domain'] ) ? $s['domain'] : ( isset( $status['host'] ) ? $status['host'] : '' );

		switch ( $name ) {
			case 'destination_url_type':
				return $host ? 'absolute' : 'relative';
			case 'destination_scheme':
				return $host ? 'https://' : $value;
			case 'destination_host':
				return $host ? $host : $value;
			default: // relative_path
				return $host ? $value : '';
		}
	}

	/** Deliver right before wrap-up. */
	public static function task_list( $task_list, $delivery_method ) {
		if ( 'cloudflare' !== $delivery_method ) {
			return $task_list;
		}

		$index = array_search( 'wrapup', $task_list, true );
		if ( false === $index ) {
			$task_list[] = 'deploy_cloudflare';
		} else {
			array_splice( $task_list, $index, 0, 'deploy_cloudflare' );
		}

		return $task_list;
	}

	/* ---------------------------------------------------------------- API */

	public static function client( array $s ) {
		return new Cloudflare_Client( $s['account_id'], $s['token'], $s['worker_name'] );
	}

	/**
	 * Fill in the account ID from the token when it can only mean one account.
	 *
	 * @return array|\WP_Error Settings with account_id.
	 */
	public static function ensure_account( array $s ) {
		if ( ! empty( $s['account_id'] ) ) {
			return $s;
		}
		if ( empty( $s['token'] ) ) {
			return new \WP_Error( 'ss_cloudflare_settings', __( 'An API token is required.', 'simply-static' ) );
		}

		$accounts = ( new Cloudflare_Client( '', $s['token'], $s['worker_name'] ) )->accounts();
		if ( is_wp_error( $accounts ) ) {
			return $accounts;
		}
		if ( 1 !== count( $accounts ) ) {
			return new \WP_Error( 'ss_cloudflare_settings', sprintf(
				/* translators: %d: number of accounts. */
				__( 'This token can see %d Cloudflare accounts; enter the Account ID.', 'simply-static' ),
				count( $accounts )
			) );
		}

		$stored               = get_option( self::OPTION, array() );
		$stored['account_id'] = $accounts[0]['id'];
		update_option( self::OPTION, $stored, false );
		$s['account_id'] = $accounts[0]['id'];

		return $s;
	}

	/**
	 * Check the token, then prepare everything the deploy needs, so the first export is already
	 * complete (the forms' Turnstile key has to be in the HTML before it is exported).
	 *
	 * @return array|\WP_Error Human-readable notes.
	 */
	public static function connect() {
		$s = self::ensure_account( self::settings() );
		if ( is_wp_error( $s ) ) {
			return $s;
		}

		$client = self::client( $s );
		$ok     = $client->verify();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}

		$notes     = array( __( 'Connected to Cloudflare.', 'simply-static' ) );
		$subdomain = $client->account_subdomain();
		$status    = get_option( self::STATUS, array() );

		if ( ! is_wp_error( $subdomain ) && '' !== $subdomain ) {
			$status['host'] = $s['worker_name'] . '.' . $subdomain . '.workers.dev';
		} elseif ( ! $s['domain'] ) {
			$notes[] = __( 'No workers.dev subdomain on this account yet: set a custom domain or create the subdomain in Cloudflare (Workers & Pages).', 'simply-static' );
		}
		update_option( self::STATUS, $status, false );

		$provisioned = self::provision( $client, $s );
		$notes       = array_merge( $notes, $provisioned['notes'] );

		// A domain that is already in the account can be served right away; no export needed to see the result.
		if ( $s['domain'] ) {
			$zone = $client->zone_for( $s['domain'] );
			$notes[] = is_wp_error( $zone )
				? sprintf( /* translators: %s: domain. */ __( '%s is not in this Cloudflare account yet: add it, point its nameservers to Cloudflare, and the next publish attaches it.', 'simply-static' ), $s['domain'] )
				: ( 'active' === $zone['status']
					? sprintf( /* translators: %s: domain. */ __( '%s is ready to be attached on the next publish.', 'simply-static' ), $s['domain'] )
					: sprintf( /* translators: 1: domain, 2: nameservers. */ __( '%1$s is waiting for its nameservers to change to: %2$s', 'simply-static' ), $s['domain'], implode( ', ', $zone['nameservers'] ) ) );
		}

		return $notes;
	}

	/**
	 * Everything the Worker's bindings need besides the assets: forms storage + Turnstile and owner email.
	 * Existing resources are reused; problems become notes, they never stop a deploy.
	 *
	 * @return array [ bindings, notes, forms (bool: forms usable) ]
	 */
	public static function provision( Cloudflare_Client $client, array $s ) {
		$out = array( 'bindings' => array(), 'notes' => array(), 'forms' => false );
		if ( ! $s['forms'] ) {
			return $out;
		}

		$bindings = Cloudflare_Forms::provision( $client, $s, self::hosts( $s ) );
		if ( is_wp_error( $bindings ) ) {
			$out['notes'][] = sprintf( 'Forms: %s', $bindings->get_error_message() );

			return $out;
		}

		$state            = get_option( Cloudflare_Forms::OPTION, array() );
		$out['forms']     = true;
		$out['notes'][]   = __( 'Forms are ready (D1 storage + Turnstile).', 'simply-static' );
		$out['notes']     = array_merge( $out['notes'], (array) ( isset( $state['warnings'] ) ? $state['warnings'] : array() ) );
		$out['bindings']  = array_merge( $bindings, Cloudflare_Email::provision( $client, $s ) );
		$out['notes'][]   = self::email_note( Cloudflare_Email::status() );

		return $out;
	}

	protected static function email_note( array $mail ) {
		switch ( $mail['state'] ) {
			case 'ready':
				/* translators: %s: email address. */
				return sprintf( __( 'Form messages are emailed to %s.', 'simply-static' ), $mail['to'] );
			case 'pending_verification':
				/* translators: %s: email address. */
				return sprintf( __( 'Email: Cloudflare sent a confirmation message to %s. Click its link once and form messages will start arriving.', 'simply-static' ), $mail['to'] );
			case 'needs_domain':
				return __( 'Email notifications turn on by themselves once the site has its own domain in your Cloudflare account. Until then messages are kept in the Submissions list.', 'simply-static' );
			case 'zone_pending':
				return __( 'Email notifications will turn on once the domain is active in Cloudflare (nameservers changed).', 'simply-static' );
			case 'off':
				return __( 'Email notifications are off.', 'simply-static' );
			default:
				/* translators: %s: reason. */
				return sprintf( __( 'Email notifications could not be set up: %s', 'simply-static' ), $mail['detail'] );
		}
	}

	/** Public hostnames of the site: custom domain first, then the workers.dev one. */
	public static function hosts( array $s ) {
		$status = get_option( self::STATUS, array() );

		return array_values( array_unique( array_filter( array( $s['domain'], isset( $status['host'] ) ? $status['host'] : '' ) ) ) );
	}

	/**
	 * After a successful publish: open the free workers.dev URL and attach the custom domain.
	 *
	 * @return string Short summary for the status line.
	 */
	public static function go_live( Cloudflare_Client $client, array $s ) {
		$status = get_option( self::STATUS, array() );
		$parts  = array();

		$url = $client->enable_workers_dev();
		if ( is_wp_error( $url ) ) {
			$parts[] = $url->get_error_message();
		} else {
			$status['url'] = $url;
			$parts[]       = $url;
		}

		// Once the real domain is serving, the preview address can go (it would be a duplicate of the site).
		$live = $s['domain'] && ! empty( $status['domain_ok'] ) && $status['domain_ok'] === $s['domain'];
		if ( $live && ! empty( $s['disable_workers_dev'] ) && ! is_wp_error( $url ) ) {
			$client->set_workers_dev( false );
			unset( $status['url'] );
			$parts = array();
		}

		if ( $s['domain'] && ( empty( $status['domain_ok'] ) || $status['domain_ok'] !== $s['domain'] ) ) {
			$attached = $client->attach_domain( $s['domain'] );
			if ( is_wp_error( $attached ) ) {
				$status['domain_error'] = $attached->get_error_message();
				$parts[]                = $s['domain'] . ': ' . $attached->get_error_message();
			} else {
				$status['domain_ok'] = $s['domain'];
				unset( $status['domain_error'] );
			}
		}
		if ( $s['domain'] && ! empty( $status['domain_ok'] ) ) {
			$parts[] = 'https://' . $s['domain'];
		}

		$status['time']   = time();
		$status['worker'] = $s['worker_name'];
		update_option( self::STATUS, $status, false );

		return implode( ' | ', $parts );
	}

	/* ------------------------------------------------------- auto-publish */

	public static function on_post_status( $new, $old, $post ) {
		if ( ( 'publish' === $new || 'publish' === $old ) && 'revision' !== $post->post_type && is_post_type_viewable( $post->post_type ) ) {
			self::queue_publish();
		}
	}

	/** One export per burst of edits. */
	public static function queue_publish() {
		$s = self::settings();
		if ( ! self::enabled() || ! $s['auto_publish'] || empty( $s['token'] ) ) {
			return;
		}

		wp_clear_scheduled_hook( self::HOOK );
		wp_schedule_single_event( time() + self::DEBOUNCE, self::HOOK );
	}

	public static function autopublish() {
		$s    = self::settings();
		$type = Cloudflare_Changes::prepare( 'update' === $s['autopublish_type'] ? 'update' : 'export' );

		if ( Plugin::instance()->run_static_export( 0, $type ) ) {
			delete_transient( 'ss_cloudflare_retries' );

			return;
		}

		// Busy (or blocked): try again, but not forever.
		$tries = (int) get_transient( 'ss_cloudflare_retries' );
		if ( $tries < 10 ) {
			set_transient( 'ss_cloudflare_retries', $tries + 1, HOUR_IN_SECONDS );
			wp_schedule_single_event( time() + 90, self::HOOK );
		}
	}

	/* ------------------------------------------------------------- REST */

	/** Dashboard page that creates a token with exactly the permissions the plugin uses. */
	public static function token_url() {
		$permissions = array(
			array( 'key' => 'workers_scripts', 'type' => 'edit' ),
			array( 'key' => 'workers_routes', 'type' => 'edit' ),
			array( 'key' => 'd1', 'type' => 'edit' ),
			array( 'key' => 'account_settings', 'type' => 'edit' ),
			array( 'key' => 'zone', 'type' => 'read' ),
			array( 'key' => 'turnstile', 'type' => 'edit' ),
			// Form notifications by email. ponytail: key names follow Cloudflare's naming; the dashboard lists any it does not recognise.
			array( 'key' => 'email_routing_addresses', 'type' => 'edit' ),
			array( 'key' => 'email_routing_rules', 'type' => 'edit' ),
		);

		return 'https://dash.cloudflare.com/?to=/:account/api-tokens&permissionGroupKeys=' . rawurlencode( wp_json_encode( $permissions ) ) . '&name=' . rawurlencode( 'Simply Static - ' . self::default_worker_name() );
	}

	public static function routes() {
		$can = function () {
			return current_user_can( 'manage_options' );
		};
		register_rest_route( 'simplystatic/v1', '/cloudflare', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_get' ), 'permission_callback' => $can ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_save' ), 'permission_callback' => $can ),
		) );
		register_rest_route( 'simplystatic/v1', '/cloudflare/submissions', array(
			'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_submissions' ), 'permission_callback' => $can,
		) );
		register_rest_route( 'simplystatic/v1', '/cloudflare/versions', array(
			'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_versions' ), 'permission_callback' => $can,
		) );
		register_rest_route( 'simplystatic/v1', '/cloudflare/rollback', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_rollback' ), 'permission_callback' => $can,
		) );
	}

	/** Everything the screens need; the token itself never leaves the server. */
	public static function payload() {
		$s      = self::settings();
		$status = get_option( self::STATUS, array() );
		$forms  = get_option( Cloudflare_Forms::OPTION, array() );
		$locked = array();
		foreach ( array( 'account_id' => 'SIMPLY_STATIC_CF_ACCOUNT_ID', 'token' => 'SIMPLY_STATIC_CF_API_TOKEN', 'worker_name' => 'SIMPLY_STATIC_CF_WORKER' ) as $key => $constant ) {
			if ( defined( $constant ) ) {
				$locked[] = $key;
			}
		}

		$stored = get_option( self::OPTION, array() );
		$worker = ! empty( $status['worker'] ) ? $status['worker'] : $s['worker_name']; // The one actually deployed.
		unset( $s['token'] );

		return array(
			'enabled'      => self::enabled(),
			'settings'     => array_merge( $s, array( 'worker_name' => isset( $stored['worker_name'] ) ? $stored['worker_name'] : '' ) ),
			'token_set'    => (bool) self::settings()['token'],
			'locked'       => $locked,
			'default_name' => self::default_worker_name(),
			'token_url'    => self::token_url(),
			'url'          => isset( $status['url'] ) ? $status['url'] : '',
			'domain'       => ! empty( $status['domain_ok'] ) ? $status['domain_ok'] : '',
			'domain_error' => isset( $status['domain_error'] ) ? $status['domain_error'] : '',
			'deployed_at'  => isset( $status['time'] ) ? (int) $status['time'] : 0,
			'worker_name'  => $worker,
			'dashboard'    => $s['account_id'] ? 'https://dash.cloudflare.com/' . rawurlencode( $s['account_id'] ) . '/workers/services/view/' . rawurlencode( $worker ) . '/production' : '',
			'forms_ready'  => ! empty( $forms['d1'] ),
			'warnings'     => isset( $forms['warnings'] ) ? (array) $forms['warnings'] : array(),
			'email'        => array_merge( Cloudflare_Email::status(), array( 'note' => self::email_note( Cloudflare_Email::status() ) ) ),
			'can_update'   => Cloudflare_Changes::can_update(),
		);
	}

	/** Last Worker versions, newest first, for the rollback list. */
	public static function rest_versions() {
		$s = self::settings();
		if ( ! $s['token'] || ! $s['account_id'] ) {
			return rest_ensure_response( array() );
		}
		$status = get_option( self::STATUS, array() );
		$client = new Cloudflare_Client( $s['account_id'], $s['token'], ! empty( $status['worker'] ) ? $status['worker'] : $s['worker_name'] );
		$list   = $client->versions( 10 );

		return is_wp_error( $list ) ? $list : rest_ensure_response( $list );
	}

	/** Serve an earlier version again. The next export publishes on top of the current archive, so the archive is also invalidated. */
	public static function rest_rollback( \WP_REST_Request $request ) {
		$version = sanitize_text_field( (string) $request->get_param( 'version' ) );
		$s       = self::settings();
		if ( ! preg_match( '/^[a-f0-9-]{8,64}$/i', $version ) || ! $s['token'] ) {
			return new \WP_Error( 'ss_cloudflare_rollback', __( 'Invalid version.', 'simply-static' ), array( 'status' => 400 ) );
		}
		$status = get_option( self::STATUS, array() );
		$client = new Cloudflare_Client( $s['account_id'], $s['token'], ! empty( $status['worker'] ) ? $status['worker'] : $s['worker_name'] );
		$result = $client->rollback( $version );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		// What is online is no longer what the archive holds: the next publish has to rebuild everything.
		Cloudflare_Changes::need_full();

		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function rest_get() {
		return rest_ensure_response( self::payload() );
	}

	/** Save, make Cloudflare the delivery method, connect and report what happened. */
	public static function rest_save( \WP_REST_Request $request ) {
		$in    = (array) $request->get_json_params();
		$old   = get_option( self::OPTION, array() );
		$token = isset( $in['token'] ) ? trim( (string) $in['token'] ) : '';
		$host  = strtolower( (string) wp_parse_url( 'https://' . preg_replace( '#^https?://#i', '', trim( isset( $in['domain'] ) ? (string) $in['domain'] : '' ) ), PHP_URL_HOST ) );
		$text  = function ( $key ) use ( $in ) {
			return isset( $in[ $key ] ) ? sanitize_textarea_field( (string) $in[ $key ] ) : '';
		};
		$pick  = function ( $key, array $allowed, $default ) use ( $in ) {
			return isset( $in[ $key ] ) && in_array( $in[ $key ], $allowed, true ) ? $in[ $key ] : $default;
		};

		update_option( self::OPTION, array(
			'account_id'         => sanitize_text_field( isset( $in['account_id'] ) ? (string) $in['account_id'] : '' ),
			'worker_name'        => sanitize_key( isset( $in['worker_name'] ) ? (string) $in['worker_name'] : '' ),
			// Blank keeps the stored token: it is never sent back to the browser.
			'token'              => '' !== $token ? $token : ( isset( $old['token'] ) ? $old['token'] : '' ),
			'html_handling'      => $pick( 'html_handling', array( 'auto-trailing-slash', 'force-trailing-slash', 'drop-trailing-slash', 'none' ), 'auto-trailing-slash' ),
			'not_found_handling' => $pick( 'not_found_handling', array( '404-page', 'single-page-application', 'none' ), '404-page' ),
			'domain'             => $host,
			'auto_publish'       => ! empty( $in['auto_publish'] ),
			'forms'              => ! empty( $in['forms'] ),
			'webhook_url'        => esc_url_raw( isset( $in['webhook_url'] ) ? (string) $in['webhook_url'] : '' ),
			'extra_headers'      => $text( 'extra_headers' ),
			'extra_redirects'    => $text( 'extra_redirects' ),
			'notify'             => ! empty( $in['notify'] ),
			'notify_email'       => sanitize_email( isset( $in['notify_email'] ) ? (string) $in['notify_email'] : '' ),
			'search'             => ! empty( $in['search'] ),
			'hide_wp'            => ! empty( $in['hide_wp'] ),
			'autopublish_type'   => $pick( 'autopublish_type', array( 'update', 'export' ), 'update' ),
			'disable_workers_dev' => ! empty( $in['disable_workers_dev'] ),
			'excluded_urls'      => isset( $in['excluded_urls'] ) ? Cloudflare_Pages::clean_paths( (array) $in['excluded_urls'] ) : ( isset( $old['excluded_urls'] ) ? (array) $old['excluded_urls'] : array() ),
		), false );

		// Pointing the site at its final domain changes every link: publish again (as a full export, see Cloudflare_Changes).
		if ( ( isset( $old['domain'] ) ? $old['domain'] : '' ) !== $host ) {
			self::queue_publish();
		}

		// Picking Cloudflare in the form is choosing it as the delivery method.
		$core = get_option( 'simply-static', array() );
		$core = is_array( $core ) ? $core : array();
		if ( empty( $core['delivery_method'] ) || 'cloudflare' !== $core['delivery_method'] ) {
			$core['delivery_method'] = 'cloudflare';
			update_option( 'simply-static', $core );
			Options::reinstance();
		}

		$error = '';
		$notes = array();
		if ( self::settings()['token'] ) {
			$result = self::connect();
			if ( is_wp_error( $result ) ) {
				$error = $result->get_error_message();
			} else {
				$notes = $result;
			}
		} else {
			$error = __( 'Add an API token to connect.', 'simply-static' );
		}

		return rest_ensure_response( array_merge( self::payload(), array( 'notes' => $notes, 'error' => $error ) ) );
	}

	public static function rest_submissions() {
		$s = self::settings();
		if ( ! self::enabled() || ! $s['forms'] || ! $s['token'] || ! $s['account_id'] ) {
			return rest_ensure_response( array() );
		}
		$rows = Cloudflare_Forms::recent( self::client( $s ), 50 );

		return is_wp_error( $rows ) ? $rows : rest_ensure_response( $rows );
	}
}
