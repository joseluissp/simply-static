<?php

namespace Simply_Static;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal client for Cloudflare Workers static assets "direct upload".
 *
 * Flow: manifest -> upload only the files Cloudflare lacks -> publish script.
 *
 * @link https://developers.cloudflare.com/workers/static-assets/direct-upload/
 */
class Cloudflare_Client {

	const API = 'https://api.cloudflare.com/client/v4';

	/** Tiny Worker so the version has a main module; static assets answer first. */
	const WORKER_MODULE = 'export default { fetch( request, env ) { return env.ASSETS.fetch( request ); } };';

	protected $account_id;
	protected $token;
	protected $script;

	public function __construct( $account_id, $token, $script ) {
		$this->account_id = rawurlencode( (string) $account_id );
		$this->token      = (string) $token;
		$this->script     = rawurlencode( (string) $script );
	}

	/**
	 * Asset hash exactly as Wrangler computes it:
	 * first 32 hex chars of sha256( base64( content ) . extension ).
	 *
	 * @param string $path Absolute file path.
	 *
	 * @return string|false
	 */
	public static function hash_file( $path ) {
		$content = file_get_contents( $path );
		if ( false === $content ) {
			return false;
		}

		// Same as node's path.extname(): dotfiles and trailing dots have no extension.
		$base = basename( $path );
		$dot  = strrpos( $base, '.' );
		$ext  = ( false === $dot || 0 === $dot ) ? '' : (string) substr( $base, $dot + 1 );

		return substr( hash( 'sha256', base64_encode( $content ) . $ext ), 0, 32 );
	}

	/**
	 * Build a multipart/form-data body.
	 *
	 * @param array $parts List of [ name, type, content, filename? ].
	 *
	 * @return array [ boundary, body ]
	 */
	public static function multipart( array $parts ) {
		$boundary = 'ss' . bin2hex( random_bytes( 12 ) );
		$body     = '';

		foreach ( $parts as $part ) {
			$disposition = 'form-data; name="' . $part['name'] . '"';
			if ( isset( $part['filename'] ) ) {
				$disposition .= '; filename="' . $part['filename'] . '"';
			}
			$body .= "--{$boundary}\r\nContent-Disposition: {$disposition}\r\nContent-Type: {$part['type']}\r\n\r\n{$part['content']}\r\n";
		}

		return array( $boundary, $body . "--{$boundary}--\r\n" );
	}

	/**
	 * Cheap credentials check: listing Worker scripts needs the same permission as deploying.
	 *
	 * @return true|\WP_Error
	 */
	public function verify() {
		$result = $this->request( 'GET', $this->account_url( 'workers/scripts?per_page=1' ) );

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Register the full manifest. Cloudflare answers with the batches it still needs.
	 *
	 * @param array $manifest Map of "/path" => [ hash, size ].
	 *
	 * @return array|\WP_Error [ jwt, buckets ]
	 */
	public function create_upload_session( array $manifest ) {
		$result = $this->request(
			'POST',
			$this->account_url( 'workers/scripts/' . $this->script . '/assets-upload-session' ),
			wp_json_encode( array( 'manifest' => $manifest ) ),
			array( 'Content-Type' => 'application/json' )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$session = isset( $result['result'] ) ? $result['result'] : array();
		if ( empty( $session['jwt'] ) ) {
			return new \WP_Error( 'ss_cloudflare_api', __( 'Cloudflare did not return an upload token.', 'simply-static' ) );
		}

		return array(
			'jwt'     => $session['jwt'],
			'buckets' => isset( $session['buckets'] ) ? $session['buckets'] : array(),
		);
	}

	/**
	 * Upload one batch of files.
	 *
	 * @param string $jwt   Upload token from create_upload_session().
	 * @param array  $files Map of hash => [ path (absolute), type (mime) ].
	 *
	 * @return string|null|\WP_Error Completion token after the last batch, null before.
	 */
	public function upload_bucket( $jwt, array $files ) {
		$parts = array();
		foreach ( $files as $hash => $file ) {
			$content = file_get_contents( $file['path'] );
			if ( false === $content ) {
				return new \WP_Error( 'ss_cloudflare_read', sprintf( 'Unable to read %s', $file['path'] ) );
			}
			$parts[] = array(
				'name'     => $hash,
				'filename' => $hash, // Wrangler names each part by its hash too.
				'type'     => $file['type'],
				'content'  => base64_encode( $content ),
			);
		}

		list( $boundary, $body ) = self::multipart( $parts );
		unset( $parts ); // Frees one full copy of the base64 payload before the HTTP layer takes its own.

		$result = $this->request(
			'POST',
			$this->account_url( 'workers/assets/upload?base64=true' ),
			$body,
			array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ),
			$jwt
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return isset( $result['result']['jwt'] ) ? $result['result']['jwt'] : null;
	}

	/**
	 * Create and deploy the Worker version that serves the uploaded assets.
	 *
	 * @param string $jwt     Completion token.
	 * @param array  $config  Asset routing config (html_handling, not_found_handling).
	 * @param array  $options Optional: bindings (extra Worker bindings), worker (module source),
	 *                        run_worker_first (asset paths the Worker answers before the assets).
	 *
	 * @return array|\WP_Error
	 */
	public function publish( $jwt, array $config, array $options = array() ) {
		if ( ! empty( $options['run_worker_first'] ) ) {
			$config['run_worker_first'] = array_values( $options['run_worker_first'] );
		}

		$metadata = array(
			'main_module'        => 'worker.js',
			'compatibility_date' => gmdate( 'Y-m-d' ),
			'assets'             => array( 'jwt' => $jwt, 'config' => $config ),
			'annotations'        => array( 'workers/message' => isset( $options['message'] ) ? substr( $options['message'], 0, 100 ) : 'Simply Static export' ),
			'bindings'           => array_merge(
				array( array( 'name' => 'ASSETS', 'type' => 'assets' ) ),
				isset( $options['bindings'] ) ? $options['bindings'] : array()
			),
		);

		list( $boundary, $body ) = self::multipart( array(
			array( 'name' => 'metadata', 'type' => 'application/json', 'content' => wp_json_encode( $metadata ) ),
			array(
				'name'     => 'worker.js',
				'filename' => 'worker.js',
				'type'     => 'application/javascript+module',
				'content'  => isset( $options['worker'] ) ? $options['worker'] : self::WORKER_MODULE,
			),
		) );

		return $this->request(
			'PUT',
			$this->account_url( 'workers/scripts/' . $this->script ),
			$body,
			array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary )
		);
	}

	/**
	 * Accounts the token can see (used to fill in the account ID automatically).
	 *
	 * @return array|\WP_Error List of [ id, name ].
	 */
	public function accounts() {
		$result = $this->request( 'GET', self::API . '/accounts?per_page=50' );

		return is_wp_error( $result ) ? $result : (array) ( isset( $result['result'] ) ? $result['result'] : array() );
	}

	/** @return string|\WP_Error The account's workers.dev subdomain ('' when it has none yet). */
	public function account_subdomain() {
		$sub = $this->call( 'GET', $this->account_url( 'workers/subdomain' ) );

		return is_wp_error( $sub ) ? $sub : (string) ( isset( $sub['result']['subdomain'] ) ? $sub['result']['subdomain'] : '' );
	}

	/**
	 * Publish the Worker on its free workers.dev address (the Worker must already exist).
	 *
	 * @return string|\WP_Error The public URL.
	 */
	public function enable_workers_dev() {
		$sub = $this->account_subdomain();
		if ( is_wp_error( $sub ) ) {
			return $sub;
		}
		if ( '' === $sub ) {
			return new \WP_Error( 'ss_cloudflare_api', __( 'This account has no workers.dev subdomain yet. Create one in the Cloudflare dashboard (Workers & Pages).', 'simply-static' ) );
		}

		$on = $this->call( 'POST', $this->account_url( 'workers/scripts/' . $this->script . '/subdomain' ), array( 'enabled' => true ) );

		return is_wp_error( $on ) ? $on : 'https://' . rawurldecode( $this->script ) . '.' . $sub . '.workers.dev';
	}

	/**
	 * Serve the Worker on one of the account's own domains. DNS and certificate are created by Cloudflare.
	 * Fails if the hostname already has a CNAME record (remove it first).
	 *
	 * @param string $hostname Full hostname, e.g. www.example.com.
	 *
	 * @return true|\WP_Error
	 */
	public function attach_domain( $hostname ) {
		$zone = $this->zone_id_for( $hostname );
		if ( is_wp_error( $zone ) ) {
			return $zone;
		}

		$result = $this->call( 'PUT', $this->account_url( 'workers/domains' ), array(
			'hostname' => $hostname,
			'service'  => rawurldecode( $this->script ),
			'zone_id'  => $zone,
		) );

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Zone holding a hostname: tries www.example.co.uk, example.co.uk, co.uk.
	 *
	 * @return string|\WP_Error Zone ID.
	 */
	public function zone_id_for( $hostname ) {
		$zone = $this->zone_for( $hostname );

		return is_wp_error( $zone ) ? $zone : $zone['id'];
	}

	/**
	 * @param string $hostname Full hostname.
	 *
	 * @return array|\WP_Error [ id, name (apex), status (active|pending...), nameservers ]
	 */
	public function zone_for( $hostname ) {
		$labels = explode( '.', strtolower( $hostname ) );
		while ( count( $labels ) >= 2 ) {
			$result = $this->request( 'GET', self::API . '/zones?name=' . rawurlencode( implode( '.', $labels ) ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( ! empty( $result['result'][0]['id'] ) ) {
				$zone = $result['result'][0];

				return array(
					'id'          => $zone['id'],
					'name'        => isset( $zone['name'] ) ? $zone['name'] : implode( '.', $labels ),
					'status'      => isset( $zone['status'] ) ? $zone['status'] : '',
					'nameservers' => isset( $zone['name_servers'] ) ? (array) $zone['name_servers'] : array(),
				);
			}
			array_shift( $labels );
		}

		return new \WP_Error( 'ss_cloudflare_zone', sprintf( 'No Cloudflare zone found for %s in this account (is the domain added to Cloudflare, and does the token have Zone Read?).', $hostname ) );
	}

	/** Turn the free workers.dev address on or off. */
	public function set_workers_dev( $enabled ) {
		return $this->call( 'POST', $this->account_url( 'workers/scripts/' . $this->script . '/subdomain' ), array( 'enabled' => (bool) $enabled ) );
	}

	/* ------------------------------------------------------------- email */

	/** @return bool|\WP_Error Whether Email Routing is on for the zone. */
	public function email_routing_enabled( $zone_id ) {
		$result = $this->request( 'GET', self::API . '/zones/' . rawurlencode( $zone_id ) . '/email/routing' );

		return is_wp_error( $result ) ? $result : ! empty( $result['result']['enabled'] );
	}

	/** Adds the MX/SPF records Email Routing needs. Refuses (error) if the domain already has other MX records. */
	public function email_routing_enable( $zone_id ) {
		return $this->request( 'POST', self::API . '/zones/' . rawurlencode( $zone_id ) . '/email/routing/enable', '{}', array( 'Content-Type' => 'application/json' ) );
	}

	/**
	 * Registers an address Cloudflare may deliver to. Cloudflare sends the owner a one-time confirmation email.
	 *
	 * @return array|\WP_Error [ email, verified ]
	 */
	public function email_destination( $email ) {
		$list = $this->request( 'GET', $this->account_url( 'email/routing/addresses?per_page=50' ) );
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		foreach ( (array) ( isset( $list['result'] ) ? $list['result'] : array() ) as $row ) {
			if ( isset( $row['email'] ) && strtolower( $row['email'] ) === strtolower( $email ) ) {
				return array( 'email' => $row['email'], 'verified' => ! empty( $row['verified'] ) );
			}
		}

		$created = $this->call( 'POST', $this->account_url( 'email/routing/addresses' ), array( 'email' => $email ) );

		return is_wp_error( $created ) ? $created : array( 'email' => $email, 'verified' => ! empty( $created['result']['verified'] ) );
	}

	/* ---------------------------------------------------------- versions */

	/**
	 * Recent Worker versions (newest first), flagged with the one serving traffic.
	 *
	 * @return array|\WP_Error List of [ id, number, created, message, active ].
	 */
	public function versions( $limit = 10 ) {
		$list = $this->request( 'GET', $this->account_url( 'workers/scripts/' . $this->script . '/versions?deployable=true&per_page=' . (int) $limit ) );
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		$deployments = $this->request( 'GET', $this->account_url( 'workers/scripts/' . $this->script . '/deployments' ) );
		$active      = '';
		if ( ! is_wp_error( $deployments ) ) {
			$latest = isset( $deployments['result']['deployments'][0] ) ? $deployments['result']['deployments'][0] : array();
			foreach ( (array) ( isset( $latest['versions'] ) ? $latest['versions'] : array() ) as $version ) {
				if ( isset( $version['percentage'] ) && 100 === (int) $version['percentage'] ) {
					$active = $version['version_id'];
				}
			}
		}

		$items = isset( $list['result']['items'] ) ? $list['result']['items'] : ( isset( $list['result'] ) ? $list['result'] : array() );
		$out   = array();
		foreach ( array_slice( (array) $items, 0, (int) $limit ) as $item ) {
			$out[] = array(
				'id'      => $item['id'],
				'number'  => isset( $item['number'] ) ? (int) $item['number'] : 0,
				'created' => isset( $item['metadata']['created_on'] ) ? $item['metadata']['created_on'] : '',
				'message' => isset( $item['annotations']['workers/message'] ) ? $item['annotations']['workers/message'] : '',
				'active'  => $item['id'] === $active,
			);
		}

		return $out;
	}

	/** Serve an earlier version again (assets of old versions are kept by Cloudflare). */
	public function rollback( $version_id ) {
		return $this->call( 'POST', $this->account_url( 'workers/scripts/' . $this->script . '/deployments' ), array(
			'strategy'    => 'percentage',
			'versions'    => array( array( 'percentage' => 100, 'version_id' => $version_id ) ),
			'annotations' => array( 'workers/message' => 'Rollback from Simply Static' ),
		) );
	}

	/** @return string|\WP_Error D1 database ID (existing one with that name is reused). */
	public function ensure_d1( $name ) {
		$list = $this->request( 'GET', $this->account_url( 'd1/database?name=' . rawurlencode( $name ) ) );
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		foreach ( (array) $list['result'] as $db ) {
			if ( isset( $db['name'], $db['uuid'] ) && $db['name'] === $name ) {
				return $db['uuid'];
			}
		}

		$created = $this->call( 'POST', $this->account_url( 'd1/database' ), array( 'name' => $name ) );

		return is_wp_error( $created ) ? $created : $created['result']['uuid'];
	}

	/** @return array|\WP_Error */
	public function d1_query( $database_id, $sql ) {
		return $this->call( 'POST', $this->account_url( 'd1/database/' . rawurlencode( $database_id ) . '/query' ), array( 'sql' => $sql ) );
	}

	/** @return array|\WP_Error [ sitekey, secret ] */
	public function create_turnstile( $name, array $domains ) {
		$result = $this->call( 'POST', $this->account_url( 'challenges/widgets' ), array(
			'name'    => $name,
			'mode'    => 'managed',
			'domains' => array_values( $domains ),
		) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'sitekey' => $result['result']['sitekey'], 'secret' => $result['result']['secret'] );
	}

	/** JSON request helper. */
	protected function call( $method, $url, array $data = null ) {
		return $this->request( $method, $url, null === $data ? null : wp_json_encode( $data ), array( 'Content-Type' => 'application/json' ) );
	}

	protected function account_url( $path ) {
		return self::API . '/accounts/' . $this->account_id . '/' . $path;
	}

	/**
	 * @param string      $method  HTTP method.
	 * @param string      $url     Full URL.
	 * @param string|null $body    Raw body.
	 * @param array       $headers Extra headers.
	 * @param string|null $token   Bearer token override (upload JWT).
	 *
	 * @return array|\WP_Error Decoded JSON response.
	 */
	protected function request( $method, $url, $body = null, array $headers = array(), $token = null ) {
		$response = wp_remote_request( $url, array(
			'method'  => $method,
			'timeout' => 120,
			'body'    => $body,
			'headers' => array_merge( array( 'Authorization' => 'Bearer ' . ( $token ? $token : $this->token ) ), $headers ),
		) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		$json = is_array( $json ) ? $json : array();

		if ( $code < 200 || $code >= 300 || ( isset( $json['success'] ) && ! $json['success'] ) ) {
			$message = isset( $json['errors'][0]['message'] ) ? $json['errors'][0]['message'] : wp_remote_retrieve_response_message( $response );

			return new \WP_Error( 'ss_cloudflare_api', sprintf( 'Cloudflare API error (HTTP %d): %s', $code, $message ) );
		}

		return $json;
	}
}
