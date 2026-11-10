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
	const SLUG   = 'simply-static-cloudflare';
	const HOOK   = 'ss_cloudflare_autopublish';

	/** Quiet seconds after the last content change before the site is re-exported. */
	const DEBOUNCE = 60;

	public static function init() {
		add_filter( 'simplystatic.archive_creation_job.task_list', array( __CLASS__, 'task_list' ), 20, 2 );
		add_filter( 'ss_get_option_delivery_method', array( __CLASS__, 'delivery_method' ) );
		add_filter( 'ss_get_option_generate_404', array( __CLASS__, 'when_enabled_true' ) );
		add_filter( 'ss_get_option_generate_type', array( __CLASS__, 'full_export' ) );
		foreach ( array( 'destination_url_type', 'destination_scheme', 'destination_host', 'relative_path' ) as $name ) {
			add_filter( 'ss_get_option_' . $name, function ( $value ) use ( $name ) {
				return self::destination( $name, $value );
			} );
		}

		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 99 );
		add_action( 'admin_post_ss_cloudflare_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_ss_cloudflare_test', array( __CLASS__, 'test' ) );

		// Auto-publish: any change to published content schedules one debounced full export.
		add_action( 'transition_post_status', array( __CLASS__, 'on_post_status' ), 10, 3 );
		add_action( 'wp_update_nav_menu', array( __CLASS__, 'queue_publish' ) );
		add_action( 'customize_save_after', array( __CLASS__, 'queue_publish' ) );
		add_action( self::HOOK, array( __CLASS__, 'autopublish' ) );

		Cloudflare_Forms::init();
	}

	/**
	 * @return array account_id, worker_name, token, enabled, html_handling, not_found_handling,
	 *               domain, auto_publish, forms, webhook_url, extra_headers, extra_redirects
	 */
	public static function settings() {
		$settings = wp_parse_args( get_option( self::OPTION, array() ), array(
			'enabled'            => false,
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
		) );

		foreach ( array( 'account_id' => 'SIMPLY_STATIC_CF_ACCOUNT_ID', 'token' => 'SIMPLY_STATIC_CF_API_TOKEN', 'worker_name' => 'SIMPLY_STATIC_CF_WORKER' ) as $key => $constant ) {
			if ( defined( $constant ) ) {
				$settings[ $key ] = constant( $constant );
			}
		}

		if ( '' === $settings['worker_name'] ) {
			$settings['worker_name'] = self::default_worker_name();
		}

		return $settings;
	}

	public static function enabled() {
		$settings = get_option( self::OPTION, array() );

		return ! empty( $settings['enabled'] );
	}

	/** Worker names: lowercase letters, digits, dashes; Cloudflare allows 63 chars. */
	public static function default_worker_name() {
		$host = preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$name = trim( substr( sanitize_title( str_replace( '.', '-', $host ) ), 0, 54 ), '-' );

		return '' === $name ? 'simply-static-site' : $name;
	}

	/** Make 'cloudflare' the delivery method when enabled; the React screen keeps working untouched. */
	public static function delivery_method( $method ) {
		return self::enabled() ? 'cloudflare' : $method;
	}

	public static function when_enabled_true( $value ) {
		return self::enabled() ? true : $value;
	}

	/** The deploy replaces the WHOLE site, so a "changes only" export must never be the one that runs. */
	public static function full_export( $value ) {
		return self::enabled() ? 'export' : $value;
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
		$hosts = self::hosts( $s );

		if ( $s['forms'] ) {
			$bindings = Cloudflare_Forms::provision( $client, $s, $hosts );
			if ( is_wp_error( $bindings ) ) {
				$notes[] = sprintf( 'Forms: %s', $bindings->get_error_message() );
			} else {
				$notes[] = __( 'Forms are ready (D1 storage + Turnstile).', 'simply-static' );
				$state   = get_option( Cloudflare_Forms::OPTION, array() );
				$notes   = array_merge( $notes, (array) ( $state['warnings'] ?? array() ) );
			}
		}

		return $notes;
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

		$status['time'] = time();
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
		if ( Plugin::instance()->run_static_export( 0, 'export' ) ) {
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

	/* ---------------------------------------------------------- admin UI */

	public static function menu() {
		add_submenu_page( 'simply-static-generate', 'Cloudflare', 'Cloudflare', 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function save() {
		check_admin_referer( 'ss_cloudflare' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'simply-static' ) );
		}

		$old    = get_option( self::OPTION, array() );
		$token  = isset( $_POST['token'] ) ? trim( wp_unslash( $_POST['token'] ) ) : '';
		$domain = strtolower( trim( (string) wp_parse_url( 'https://' . preg_replace( '#^https?://#i', '', trim( wp_unslash( $_POST['domain'] ?? '' ) ) ), PHP_URL_HOST ) ) );
		$text   = function ( $key ) {
			return isset( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) : '';
		};

		update_option( self::OPTION, array(
			'enabled'            => ! empty( $_POST['enabled'] ),
			'account_id'         => sanitize_text_field( wp_unslash( $_POST['account_id'] ?? '' ) ),
			'worker_name'        => sanitize_key( wp_unslash( $_POST['worker_name'] ?? '' ) ),
			// Blank keeps the stored token: it is never printed back into the form.
			'token'              => '' !== $token ? $token : ( $old['token'] ?? '' ),
			'html_handling'      => in_array( $_POST['html_handling'] ?? '', array( 'auto-trailing-slash', 'force-trailing-slash', 'drop-trailing-slash', 'none' ), true ) ? $_POST['html_handling'] : 'auto-trailing-slash',
			'not_found_handling' => in_array( $_POST['not_found_handling'] ?? '', array( '404-page', 'single-page-application', 'none' ), true ) ? $_POST['not_found_handling'] : '404-page',
			'domain'             => $domain,
			'auto_publish'       => ! empty( $_POST['auto_publish'] ),
			'forms'              => ! empty( $_POST['forms'] ),
			'webhook_url'        => esc_url_raw( wp_unslash( $_POST['webhook_url'] ?? '' ) ),
			'extra_headers'      => $text( 'extra_headers' ),
			'extra_redirects'    => $text( 'extra_redirects' ),
		), false );

		$notes = array();
		if ( self::enabled() && self::settings()['token'] ) {
			$result = self::connect();
			$notes  = is_wp_error( $result ) ? array( $result->get_error_message() ) : $result;
			$failed = is_wp_error( $result );
		}

		wp_safe_redirect( add_query_arg(
			array( 'page' => self::SLUG, 'ss_cf' => ! empty( $failed ) ? 'fail' : 'saved', 'ss_cf_msg' => implode( ' ', $notes ) ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	public static function test() {
		check_admin_referer( 'ss_cloudflare' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'simply-static' ) );
		}

		$result = self::connect();

		wp_safe_redirect( add_query_arg(
			array(
				'page'      => self::SLUG,
				'ss_cf'     => is_wp_error( $result ) ? 'fail' : 'ok',
				'ss_cf_msg' => is_wp_error( $result ) ? $result->get_error_message() : implode( ' ', $result ),
			),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	public static function render() {
		$s      = self::settings();
		$status = isset( $_GET['ss_cf'] ) ? sanitize_key( $_GET['ss_cf'] ) : '';
		$live   = get_option( self::STATUS, array() );
		$field  = function ( $label, $name, $value, $type = 'text', $hint = '', $placeholder = '' ) {
			printf(
				'<tr><th><label for="%1$s">%2$s</label></th><td><input class="regular-text" id="%1$s" name="%1$s" type="%3$s" value="%4$s" placeholder="%6$s" autocomplete="off"><p class="description">%5$s</p></td></tr>',
				esc_attr( $name ), esc_html( $label ), esc_attr( $type ), esc_attr( $value ), esc_html( $hint ), esc_attr( $placeholder )
			);
		};
		$check  = function ( $label, $name, $on, $hint = '' ) {
			printf(
				'<tr><th>%1$s</th><td><label><input type="checkbox" name="%2$s" value="1"%3$s> %4$s</label></td></tr>',
				esc_html( $label ), esc_attr( $name ), checked( ! empty( $on ), true, false ), esc_html( $hint )
			);
		};
		$area   = function ( $label, $name, $value, $hint ) {
			printf(
				'<tr><th><label for="%1$s">%2$s</label></th><td><textarea class="large-text code" rows="4" id="%1$s" name="%1$s">%3$s</textarea><p class="description">%4$s</p></td></tr>',
				esc_attr( $name ), esc_html( $label ), esc_textarea( $value ), esc_html( $hint )
			);
		};
		$select = function ( $label, $name, $value, array $options ) {
			echo '<tr><th><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><select id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">';
			foreach ( $options as $option ) {
				printf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $option ), selected( $value, $option, false ) );
			}
			echo '</select></td></tr>';
		};

		echo '<div class="wrap"><h1>Cloudflare</h1>';
		$message = isset( $_GET['ss_cf_msg'] ) ? esc_html( wp_unslash( $_GET['ss_cf_msg'] ) ) : '';
		if ( in_array( $status, array( 'saved', 'ok' ), true ) ) {
			echo '<div class="notice notice-success"><p>' . ( $message ? $message : esc_html__( 'Settings saved.', 'simply-static' ) ) . '</p></div>';
		} elseif ( 'fail' === $status ) {
			echo '<div class="notice notice-error"><p>' . $message . '</p></div>';
		}
		if ( ! empty( $live['url'] ) ) {
			printf( '<p>%s <a href="%2$s" target="_blank" rel="noopener">%2$s</a></p>', esc_html__( 'Live at:', 'simply-static' ), esc_url( $live['url'] ) );
		}
		if ( ! empty( $live['domain_error'] ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( $live['domain_error'] ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ss_cloudflare' );
		echo '<input type="hidden" name="action" value="ss_cloudflare_save"><table class="form-table">';
		$check( __( 'Delivery', 'simply-static' ), 'enabled', $s['enabled'], __( 'Deploy exports to Cloudflare Workers', 'simply-static' ) );
		$field( 'API token', 'token', '', 'password', $s['token'] ? __( 'A token is stored. Leave blank to keep it.', 'simply-static' ) : __( 'Permissions: Workers Scripts Write, D1 Write, Turnstile Sites Write, Account Settings Read, Zone Read, Zone Workers Routes Write.', 'simply-static' ) );
		$field( 'Account ID', 'account_id', $s['account_id'], 'text', __( 'Optional: detected from the token.', 'simply-static' ) );
		$field( 'Worker name', 'worker_name', get_option( self::OPTION, array() )['worker_name'] ?? '', 'text', __( 'Optional. Lowercase letters, numbers and dashes.', 'simply-static' ), self::default_worker_name() );
		$field( __( 'Custom domain', 'simply-static' ), 'domain', $s['domain'], 'text', __( 'Optional, e.g. www.example.com. The domain must already be on Cloudflare and must not have a CNAME record.', 'simply-static' ) );
		$check( __( 'Auto-publish', 'simply-static' ), 'auto_publish', $s['auto_publish'], __( 'Re-export and deploy about a minute after content is published, updated or removed', 'simply-static' ) );
		$check( __( 'Forms', 'simply-static' ), 'forms', $s['forms'], __( 'Make WordPress forms work (stored in Cloudflare D1, protected by Turnstile)', 'simply-static' ) );
		$field( __( 'Forms webhook', 'simply-static' ), 'webhook_url', $s['webhook_url'], 'url', __( 'Optional. Every submission is also POSTed here as JSON (Zapier, Make, n8n...).', 'simply-static' ) );
		$area( __( 'Extra redirects', 'simply-static' ), 'extra_redirects', $s['extra_redirects'], __( 'One per line: /old /new 301. Redirects from the Redirection plugin are included automatically.', 'simply-static' ) );
		$area( __( 'Extra headers', 'simply-static' ), 'extra_headers', $s['extra_headers'], __( 'Cloudflare _headers syntax. Security and cache headers are added automatically.', 'simply-static' ) );
		$select( 'html_handling', 'html_handling', $s['html_handling'], array( 'auto-trailing-slash', 'force-trailing-slash', 'drop-trailing-slash', 'none' ) );
		$select( 'not_found_handling', 'not_found_handling', $s['not_found_handling'], array( '404-page', 'single-page-application', 'none' ) );
		echo '</table><p>';
		submit_button( __( 'Save and connect', 'simply-static' ), 'primary', 'submit', false );
		echo ' <button class="button" type="submit" name="action" value="ss_cloudflare_test">' . esc_html__( 'Test connection', 'simply-static' ) . '</button></p></form>';

		self::render_submissions( $s );
		echo '</div>';
	}

	protected static function render_submissions( array $s ) {
		if ( ! $s['enabled'] || ! $s['forms'] || ! $s['token'] || ! $s['account_id'] ) {
			return;
		}
		$rows = Cloudflare_Forms::recent( self::client( $s ), 50 );
		if ( is_wp_error( $rows ) || ! $rows ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Form submissions', 'simply-static' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Date (UTC)', 'simply-static' ) . '</th><th>' . esc_html__( 'Page', 'simply-static' ) . '</th><th>' . esc_html__( 'Data', 'simply-static' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$data = json_decode( (string) $row['data'], true );
			$text = '';
			foreach ( (array) $data as $key => $value ) {
				$text .= $key . ': ' . ( is_array( $value ) ? implode( ', ', $value ) : $value ) . "\n";
			}
			printf( '<tr><td>%s</td><td>%s</td><td><pre style="margin:0;white-space:pre-wrap">%s</pre></td></tr>', esc_html( $row['created_at'] ), esc_html( $row['page'] ), esc_html( trim( $text ) ) );
		}
		echo '</tbody></table>';
	}
}
