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
	 * @param string $jwt    Completion token.
	 * @param array  $config Asset routing config (html_handling, not_found_handling).
	 *
	 * @return array|\WP_Error
	 */
	public function publish( $jwt, array $config ) {
		$metadata = array(
			'main_module'        => 'worker.js',
			'compatibility_date' => gmdate( 'Y-m-d' ),
			'assets'             => array( 'jwt' => $jwt, 'config' => $config ),
			'bindings'           => array( array( 'name' => 'ASSETS', 'type' => 'assets' ) ),
		);

		list( $boundary, $body ) = self::multipart( array(
			array( 'name' => 'metadata', 'type' => 'application/json', 'content' => wp_json_encode( $metadata ) ),
			array(
				'name'     => 'worker.js',
				'filename' => 'worker.js',
				'type'     => 'application/javascript+module',
				'content'  => self::WORKER_MODULE,
			),
		) );

		return $this->request(
			'PUT',
			$this->account_url( 'workers/scripts/' . $this->script ),
			$body,
			array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary )
		);
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
