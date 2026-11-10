<?php

namespace Simply_Static;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deploys the finished export to a Cloudflare Worker with static assets.
 *
 * Cloudflare's asset manifest is the WHOLE site, so a partial export would delete
 * everything it does not contain. Partial exports are therefore skipped, not deployed.
 * Unchanged files are never re-sent: Cloudflare only asks for hashes it lacks.
 */
class Deploy_Cloudflare_Task extends Task {

	/** Cloudflare rejects single assets above 25 MiB. */
	const MAX_FILE_SIZE = 26214400;

	/** Seconds of uploading per perform() call before yielding to the queue. */
	const TIME_BUDGET = 20;

	/**
	 * Raw bytes per upload request. Cloudflare's batches can reach tens of MB, and one
	 * request holds a file ~4x over (raw, base64, multipart body, HTTP layer), which
	 * exhausts PHP's 128M. ponytail: a single file near 25 MiB still needs ~100M.
	 */
	const MAX_CHUNK_BYTES = 4194304;

	protected static $task_name = 'deploy_cloudflare';

	/** Fresh state for every export; a stale upload token must never be reused. */
	public function cleanup() {
		@unlink( self::state_file() );
	}

	public function perform() {
		$reason = $this->partial_export_reason();
		if ( $reason ) {
			$this->save_status_message( $reason );

			return true;
		}

		$settings = Cloudflare::ensure_account( Cloudflare::settings() );
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}
		if ( empty( $settings['token'] ) ) {
			return new \WP_Error( 'ss_cloudflare_settings', __( 'A Cloudflare API token is required.', 'simply-static' ) );
		}

		$client = Cloudflare::client( $settings );
		$state  = $this->read_state();

		if ( ! $state ) {
			$state = $this->start( $client );
			if ( is_wp_error( $state ) ) {
				return $state;
			}
		}

		$dir     = $this->options->get_archive_dir();
		$started = time();
		$total   = count( $state['buckets'] );

		while ( $state['next'] < $total ) {
			$files = array();
			foreach ( $state['buckets'][ $state['next'] ] as $hash ) {
				if ( ! isset( $state['files'][ $hash ] ) ) {
					$this->cleanup();

					return new \WP_Error( 'ss_cloudflare_missing_file', __( 'Cloudflare requested a file that is not part of this export. Run a full export.', 'simply-static' ) );
				}
				$files[ $hash ] = array(
					'path' => $dir . $state['files'][ $hash ]['path'],
					'type' => $state['files'][ $hash ]['type'],
				);
			}

			$jwt = $client->upload_bucket( $state['jwt'], $files );
			if ( is_wp_error( $jwt ) ) {
				$this->cleanup();

				return $jwt;
			}

			if ( $jwt ) {
				$state['completion'] = $jwt;
			}
			$state['next'] ++;
			$this->write_state( $state );
			$this->save_status_message( sprintf(
				/* translators: 1: batches uploaded, 2: total batches. */
				__( 'Cloudflare: uploaded batch %1$d of %2$d', 'simply-static' ),
				$state['next'],
				$total
			) );

			// ponytail: sequential uploads, yields between batches. Parallel uploads if big sites need it.
			if ( $state['next'] < $total && ( time() - $started ) > self::TIME_BUDGET ) {
				return false;
			}
		}

		// No batches needed (nothing changed): the session token is the completion token.
		$completion = ! empty( $state['completion'] ) ? $state['completion'] : $state['jwt'];

		$options = array();
		if ( $settings['forms'] ) {
			$bindings = Cloudflare_Forms::provision( $client, $settings, Cloudflare::hosts( $settings ) );
			if ( is_wp_error( $bindings ) ) {
				// The site still deploys; only its forms are off.
				$this->save_status_message( 'Cloudflare forms disabled: ' . $bindings->get_error_message() );
			} else {
				$options = array(
					'bindings'         => $bindings,
					'worker'           => file_get_contents( __DIR__ . '/worker.js' ),
					'run_worker_first' => array( '/_ss/*' ),
				);
			}
		}

		$result = $client->publish( $completion, array(
			'html_handling'      => $settings['html_handling'],
			'not_found_handling' => $settings['not_found_handling'],
		), $options );
		$this->cleanup();
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->save_status_message( sprintf(
			/* translators: 1: Worker name, 2: files in the site, 3: files uploaded, 4: public address. */
			__( 'Deployed to Cloudflare Worker "%1$s": %2$d files in the site, %3$d uploaded. %4$s', 'simply-static' ),
			$settings['worker_name'],
			$state['total'],
			$state['uploaded'],
			Cloudflare::go_live( $client, $settings )
		) );

		return true;
	}

	/**
	 * Why this export must not be deployed, or null when it is a complete site.
	 *
	 * @return string|null
	 */
	protected function partial_export_reason() {
		$partial = get_option( 'simply-static-use-single' )
			|| get_option( 'simply-static-use-build' )
			|| get_option( 'simply-static-404-only' )
			|| 'update' === $this->options->get( 'generate_type' );

		return $partial
			? __( 'Cloudflare deploy skipped: it needs a full export, because a partial one would delete the rest of the live site.', 'simply-static' )
			: null;
	}

	/**
	 * Hash the archive, register the manifest and keep what the upload phase needs.
	 *
	 * @param Cloudflare_Client $client API client.
	 *
	 * @return array|\WP_Error
	 */
	protected function start( Cloudflare_Client $client ) {
		$dir = $this->options->get_archive_dir();
		if ( ! is_dir( $dir ) ) {
			return new \WP_Error( 'ss_cloudflare_no_archive', __( 'Export directory not found.', 'simply-static' ) );
		}

		// Never publish a site without a working homepage.
		$homepage = $this->validate_homepage_file( $dir, true );
		if ( is_wp_error( $homepage ) ) {
			return $homepage;
		}

		// Headers and redirects travel inside the site like any other file.
		Cloudflare_Edge_Files::write( $dir );

		$manifest = array();
		$files    = array();
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );

		// ponytail: hashes every file in one call; chunk across perform() calls if huge sites time out.
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || '.DS_Store' === $file->getFilename() ) {
				continue;
			}

			$relative = ltrim( Util::normalize_slashes( substr( $file->getPathname(), strlen( $dir ) ) ), '/' );
			if ( $file->getSize() > self::MAX_FILE_SIZE ) {
				return new \WP_Error( 'ss_cloudflare_file_too_large', sprintf( 'File exceeds Cloudflare 25 MiB asset limit: %s', $relative ) );
			}

			$hash = Cloudflare_Client::hash_file( $file->getPathname() );
			if ( false === $hash ) {
				return new \WP_Error( 'ss_cloudflare_read', sprintf( 'Unable to read %s', $relative ) );
			}

			$manifest[ '/' . $relative ] = array( 'hash' => $hash, 'size' => $file->getSize() );
			$files[ $hash ]              = array( 'path' => $relative, 'type' => self::mime_type( $relative ), 'size' => $file->getSize() );
		}

		if ( ! $manifest ) {
			return new \WP_Error( 'ss_cloudflare_empty', __( 'The export is empty; nothing to deploy.', 'simply-static' ) );
		}

		$session = $client->create_upload_session( $manifest );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$needed = 0;
		$sizes  = array();
		foreach ( $session['buckets'] as $bucket ) {
			$needed += count( $bucket );
			foreach ( $bucket as $hash ) {
				$sizes[ $hash ] = isset( $files[ $hash ] ) ? $files[ $hash ]['size'] : 0;
			}
		}

		$state = array(
			'jwt'        => $session['jwt'],
			'buckets'    => self::chunk_buckets( $session['buckets'], $sizes, self::MAX_CHUNK_BYTES ),
			'next'       => 0,
			'completion' => '',
			'files'      => $files,
			'total'      => count( $manifest ),
			'uploaded'   => $needed,
		);
		$this->write_state( $state );

		return $state;
	}

	/**
	 * Split Cloudflare's batches into upload requests of at most $limit raw bytes.
	 * A file bigger than the limit travels alone.
	 *
	 * @param array $buckets Lists of hashes, as Cloudflare returned them.
	 * @param array $sizes   Map of hash => bytes.
	 * @param int   $limit   Max raw bytes per request.
	 *
	 * @return array
	 */
	public static function chunk_buckets( array $buckets, array $sizes, $limit ) {
		$chunks = array();
		foreach ( $buckets as $bucket ) {
			$current = array();
			$bytes   = 0;
			foreach ( $bucket as $hash ) {
				$size = isset( $sizes[ $hash ] ) ? $sizes[ $hash ] : 0;
				if ( $current && $bytes + $size > $limit ) {
					$chunks[] = $current;
					$current  = array();
					$bytes    = 0;
				}
				$current[] = $hash;
				$bytes    += $size;
			}
			if ( $current ) {
				$chunks[] = $current;
			}
		}

		return $chunks;
	}

	public static function mime_type( $path ) {
		static $overrides = array(
			'woff'        => 'font/woff',
			'woff2'       => 'font/woff2',
			'webp'        => 'image/webp',
			'avif'        => 'image/avif',
			'svg'         => 'image/svg+xml',
			'json'        => 'application/json',
			'xml'         => 'application/xml',
			'txt'         => 'text/plain',
			'map'         => 'application/json',
			'webmanifest' => 'application/manifest+json',
			'ico'         => 'image/x-icon',
		);

		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( isset( $overrides[ $ext ] ) ) {
			return $overrides[ $ext ];
		}

		$type = wp_check_filetype( $path );

		return ! empty( $type['type'] ) ? $type['type'] : 'application/octet-stream';
	}

	protected static function state_file() {
		return Util::get_temp_dir() . 'ss-cloudflare-state.json';
	}

	protected function read_state() {
		$raw = @file_get_contents( self::state_file() );

		return $raw ? json_decode( $raw, true ) : null;
	}

	protected function write_state( array $state ) {
		file_put_contents( self::state_file(), wp_json_encode( $state ) );
	}
}
