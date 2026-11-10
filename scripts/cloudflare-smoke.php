<?php
/**
 * Live smoke test for the Cloudflare Workers delivery client.
 *
 * Runs the real Cloudflare_Client against the API with a throwaway Worker and a
 * 3-file site, then deletes the Worker (pass --keep to leave it).
 *
 *   CLOUDFLARE_API_TOKEN=... CLOUDFLARE_ACCOUNT_ID=... php scripts/cloudflare-smoke.php
 *
 * The token needs "Workers Scripts: Edit". It is read from the environment only.
 */

if ( PHP_SAPI !== 'cli' ) {
	exit;
}

define( 'ABSPATH', __DIR__ . '/' );

// Just enough WordPress for the client (HTTP via curl).
class WP_Error {
	public $code;
	public $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function __( $s ) { return $s; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_remote_retrieve_response_message( $r ) { return ''; }
function wp_remote_request( $url, $args ) {
	$headers = array();
	foreach ( $args['headers'] as $k => $v ) {
		$headers[] = "$k: $v";
	}
	$ch = curl_init( $url );
	curl_setopt_array( $ch, array(
		CURLOPT_CUSTOMREQUEST  => $args['method'],
		CURLOPT_HTTPHEADER     => $headers,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT        => $args['timeout'],
	) );
	if ( null !== $args['body'] ) {
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $args['body'] );
	}
	$body = curl_exec( $ch );
	if ( false === $body ) {
		return new WP_Error( 'curl', curl_error( $ch ) );
	}
	return array( 'code' => curl_getinfo( $ch, CURLINFO_HTTP_CODE ), 'body' => $body );
}

require __DIR__ . '/../src/cloudflare/class-ss-cloudflare-client.php';

use Simply_Static\Cloudflare_Client;

/** Exposes the protected request() for the few extra calls (subdomain, delete). */
class Smoke_Client extends Cloudflare_Client {
	public function call( $method, $path, $body = null ) {
		return $this->request( $method, self::API . $path, null === $body ? null : json_encode( $body ), array( 'Content-Type' => 'application/json' ) );
	}
}

function fail( $message ) {
	fwrite( STDERR, "FAIL: $message\n" );
	exit( 1 );
}

function check( $result, $step ) {
	if ( is_wp_error( $result ) ) {
		fail( "$step -> " . $result->get_error_message() );
	}
	echo "ok   $step\n";

	return $result;
}

/** Same sequence as Deploy_Cloudflare_Task, minus WordPress. */
function deploy( Smoke_Client $client, array $manifest, array $byHash, $label ) {
	$session = check( $client->create_upload_session( $manifest ), "$label: upload session" );
	$needed  = array_sum( array_map( 'count', $session['buckets'] ) );
	echo "     Cloudflare asked for $needed file(s) in " . count( $session['buckets'] ) . " batch(es)\n";

	$completion = $session['jwt'];
	foreach ( $session['buckets'] as $i => $bucket ) {
		$files = array();
		foreach ( $bucket as $hash ) {
			$files[ $hash ] = $byHash[ $hash ];
		}
		$jwt = check( $client->upload_bucket( $session['jwt'], $files ), "$label: upload batch " . ( $i + 1 ) );
		if ( $jwt ) {
			$completion = $jwt;
		}
	}

	check( $client->publish( $completion, array( 'html_handling' => 'auto-trailing-slash', 'not_found_handling' => '404-page' ) ), "$label: publish" );

	return $needed;
}

$token   = getenv( 'CLOUDFLARE_API_TOKEN' );
$account = getenv( 'CLOUDFLARE_ACCOUNT_ID' );
if ( ! $token || ! $account ) {
	fail( 'Set CLOUDFLARE_API_TOKEN and CLOUDFLARE_ACCOUNT_ID.' );
}

// A tiny site on disk.
$dir   = sys_get_temp_dir() . '/ss-cf-smoke-' . bin2hex( random_bytes( 4 ) );
$site  = array( 'index.html' => "<h1>hola</h1>\n", '404.html' => '<h1>404 propia</h1>', 'css/a.css' => 'body{}' );
$types = array( 'html' => 'text/html', 'css' => 'text/css' );
mkdir( "$dir/css", 0777, true );

$manifest = array();
$byHash   = array();
foreach ( $site as $rel => $content ) {
	file_put_contents( "$dir/$rel", $content );
	$hash                    = Cloudflare_Client::hash_file( "$dir/$rel" );
	$manifest[ "/$rel" ]     = array( 'hash' => $hash, 'size' => strlen( $content ) );
	$byHash[ $hash ]         = array( 'path' => "$dir/$rel", 'type' => $types[ pathinfo( $rel, PATHINFO_EXTENSION ) ] );
}

$name   = 'ss-fork-smoke-' . bin2hex( random_bytes( 3 ) );
$client = new Smoke_Client( $account, $token, $name );
echo "Worker: $name\n";

check( $client->verify(), 'credentials' );

$first = deploy( $client, $manifest, $byHash, 'deploy 1' );
if ( 3 !== $first ) {
	fail( "expected Cloudflare to ask for 3 new files, asked for $first" );
}

// Serve it: enable the workers.dev route and request real pages.
$sub = check( $client->call( 'GET', "/accounts/$account/workers/subdomain" ), 'workers.dev subdomain' );
$sub = $sub['result']['subdomain'] ?? '';
check( $client->call( 'POST', "/accounts/$account/workers/scripts/$name/subdomain", array( 'enabled' => true ) ), 'enable route' );
$url = "https://$name.$sub.workers.dev";

$served = false;
for ( $try = 0; $try < 10 && ! $served; $try ++ ) {
	sleep( 3 );
	$home    = @file_get_contents( "$url/" );
	$missing = @file_get_contents( "$url/no-existe" ); // 404 body comes with a 4xx status, which file_get_contents reports as false.
	$served  = $home && false !== strpos( $home, 'hola' );
}
echo $served ? "ok   served: $url/ returns the exported index.html\n" : "WARN not reachable yet at $url (propagation can take longer)\n";

$ctx      = stream_context_create( array( 'http' => array( 'ignore_errors' => true ) ) );
$notfound = @file_get_contents( "$url/no-existe", false, $ctx );
if ( $served ) {
	echo ( $notfound && false !== strpos( $notfound, '404 propia' ) ) ? "ok   404-page: unknown URL returns the exported 404.html\n" : "FAIL 404-page not served\n";
}

// Second deploy of the same site: Cloudflare must not ask for anything again.
$second = deploy( $client, $manifest, $byHash, 'deploy 2 (unchanged)' );
echo 0 === $second ? "ok   dedup: unchanged site uploads 0 files\n" : "FAIL dedup: asked for $second file(s) again\n";

if ( in_array( '--keep', $argv, true ) ) {
	echo "Kept Worker $name ($url)\n";
} else {
	check( $client->call( 'DELETE', "/accounts/$account/workers/scripts/$name?force=true" ), 'cleanup: delete test Worker' );
}
echo "Done.\n";
