<?php

declare(strict_types=1);

namespace Simply_Static\Tests\Unit;

use Simply_Static\Cloudflare;
use Simply_Static\Cloudflare_Client;
use Simply_Static\Cloudflare_Edge_Files;
use Simply_Static\Cloudflare_Forms;
use Simply_Static\Tests\Support\UnitTestCase;
use Simply_Static\Tests\Support\WpTestEnvironment;

final class CloudflareEdgeAndFormsTest extends UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->requireSource( 'src/cloudflare/class-ss-cloudflare-client.php' );
		$this->requireSource( 'src/cloudflare/class-ss-cloudflare-edge-files.php' );
		$this->requireSource( 'src/cloudflare/class-ss-cloudflare-forms.php' );
		$this->requireSource( 'src/cloudflare/class-ss-cloudflare.php' );
	}

	public function test_redirects_keep_only_valid_exact_rules_and_dedupe(): void {
		$file = Cloudflare_Edge_Files::redirects_file( array(
			array( '/old', '/new', 301 ),
			array( '/old', '/other', 301 ),            // duplicate source
			array( '/blog/*', '/news/:splat', 301 ),   // placeholders are not exact
			array( '/q?a=1', '/x', 301 ),              // query strings unsupported
			array( 'old', '/x', 301 ),                 // source must be a path
			array( '/ext', 'https://example.com/a', 302 ),
			array( '/bad', 'javascript:alert(1)', 301 ),
			array( '/same', '/same', 301 ),
			array( '/code', '/x', 200 ),               // 200 would be a rewrite
		) );

		self::assertSame( "/old /new 301\n/ext https://example.com/a 302\n", $file );
		self::assertSame( '', Cloudflare_Edge_Files::redirects_file( array() ) );
	}

	public function test_redirects_are_capped_at_cloudflare_limit(): void {
		$rules = array();
		for ( $i = 0; $i < 2100; $i ++ ) {
			$rules[] = array( "/p$i", '/x', 301 );
		}

		self::assertCount( 2000, array_filter( explode( "\n", Cloudflare_Edge_Files::redirects_file( $rules ) ) ) );
	}

	public function test_parse_rules_skips_comments_and_defaults_status(): void {
		$rules = Cloudflare_Edge_Files::parse_rules( "# nota\n\n/a /b\n/c  /d  302\n" );

		self::assertSame( array( array( '/a', '/b', 301 ), array( '/c', '/d', '302' ) ), $rules );
	}

	public function test_headers_have_disjoint_cache_rules_and_append_extras(): void {
		$file = Cloudflare_Edge_Files::headers_file( "/api/*\n  X-Test: 1" );

		self::assertStringContainsString( "X-Content-Type-Options: nosniff", $file );
		self::assertStringContainsString( "/wp-content/uploads/*\n  Cache-Control: public, max-age=31536000, immutable", $file );
		self::assertStringEndsWith( "X-Test: 1\n", $file );
		// Overlapping rules would join two Cache-Control values.
		self::assertStringNotContainsString( "\n/wp-content/*\n", $file );
	}

	public function test_merge_keeps_user_content_and_replaces_only_our_old_block(): void {
		$path = sys_get_temp_dir() . '/ss-edge-' . uniqid() . '_headers';
		file_put_contents( $path, "/mine/*\n  X-Mine: 1\n" );

		Cloudflare_Edge_Files::merge( $path, "first\n" );
		Cloudflare_Edge_Files::merge( $path, "second\n" );
		$result = (string) file_get_contents( $path );
		unlink( $path );

		self::assertStringStartsWith( "/mine/*\n  X-Mine: 1\n", $result );
		self::assertStringContainsString( 'second', $result );
		self::assertStringNotContainsString( 'first', $result );
		self::assertSame( 1, substr_count( $result, Cloudflare_Edge_Files::MARKER ) );
	}

	public function test_default_worker_name_comes_from_the_host(): void {
		// The stub home_url() is whatever the environment defines; the result must be a valid Worker name.
		self::assertMatchesRegularExpression( '/^[a-z0-9][a-z0-9-]{0,53}$/', Cloudflare::default_worker_name() );
	}

	public function test_destination_follows_domain_then_workers_dev_then_relative(): void {
		update_option( Cloudflare::OPTION, array( 'enabled' => true, 'domain' => 'www.example.com' ) );
		self::assertSame( 'absolute', Cloudflare::destination( 'destination_url_type', 'relative' ) );
		self::assertSame( 'www.example.com', Cloudflare::destination( 'destination_host', 'x' ) );

		update_option( Cloudflare::OPTION, array( 'enabled' => true ) );
		update_option( Cloudflare::STATUS, array( 'host' => 'blog.me.workers.dev' ) );
		self::assertSame( 'blog.me.workers.dev', Cloudflare::destination( 'destination_host', 'x' ) );

		update_option( Cloudflare::STATUS, array() );
		self::assertSame( 'relative', Cloudflare::destination( 'destination_url_type', 'absolute' ) );
		self::assertSame( '', Cloudflare::destination( 'relative_path', '/old' ) );

		update_option( Cloudflare::OPTION, array( 'enabled' => false ) );
		self::assertSame( 'absolute', Cloudflare::destination( 'destination_url_type', 'absolute' ) );
	}

	private function dom( string $html ): \DOMDocument {
		$dom = new \DOMDocument();
		@$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html );

		return $dom;
	}

	public function test_post_forms_are_rewritten_and_php_forms_left_alone(): void {
		update_option( Cloudflare::OPTION, array( 'enabled' => true, 'forms' => true ) );
		update_option( Cloudflare_Forms::OPTION, array( 'sitekey' => 'KEY' ) );

		$dom = $this->dom(
			'<body>'
			. '<form id="c" method="post" action=""><input name="email"><button type="submit">Go</button></form>'
			. '<form id="l" method="post" action="/wp-login.php"><input name="log"><input type="password" name="pwd"></form>'
			. '<form id="s" method="get" action="/"><input name="s"></form>'
			. '<form id="m" method="post" action="https://list.example.net/subscribe"><input name="e"></form>'
			. '</body>'
		);
		$out = Cloudflare_Forms::rewrite_forms( $dom, 'https://example.org/contacto/' );
		$xml = $out->saveHTML();

		self::assertSame( 1, substr_count( $xml, 'data-ss-form="' ) );
		self::assertMatchesRegularExpression( '#<form id="c" method="post" action="/_ss/form/[a-f0-9]{12}"#', $xml );
		self::assertStringContainsString( 'name="_ss_hp"', $xml );
		self::assertStringContainsString( 'data-sitekey="KEY"', $xml );
		self::assertStringContainsString( 'challenges.cloudflare.com/turnstile', $xml );
		self::assertStringContainsString( 'action="/wp-login.php"', $xml );
		self::assertStringContainsString( 'action="https://list.example.net/subscribe"', $xml );
	}

	public function test_forms_untouched_when_disabled_or_without_forms(): void {
		update_option( Cloudflare::OPTION, array( 'enabled' => true, 'forms' => false ) );
		$dom = $this->dom( '<body><form method="post"><input name="a"></form></body>' );
		$before = $dom->saveHTML();

		self::assertSame( $before, Cloudflare_Forms::rewrite_forms( $dom, 'https://example.org/' )->saveHTML() );

		update_option( Cloudflare::OPTION, array( 'enabled' => true, 'forms' => true ) );
		$plain = $this->dom( '<body><p>hi</p></body>' );
		self::assertStringNotContainsString( '<script', Cloudflare_Forms::rewrite_forms( $plain, 'https://example.org/' )->saveHTML() );
	}

	public function test_publish_sends_forms_bindings_and_worker_first_routes(): void {
		$sent  = array();
		$stub  = new class( 'acc', 'tok', 'site', $sent ) extends Cloudflare_Client {
			public $body = '';
			public function __construct( $a, $t, $s, &$sent ) { parent::__construct( $a, $t, $s ); }
			protected function request( $method, $url, $body = null, array $headers = array(), $token = null ) {
				$this->body = (string) $body;

				return array( 'success' => true );
			}
		};

		$stub->publish( 'JWT', array( 'html_handling' => 'auto-trailing-slash' ), array(
			'bindings'         => array( array( 'name' => 'DB', 'type' => 'd1', 'id' => 'xyz' ) ),
			'worker'           => 'export default {};',
			'run_worker_first' => array( '/_ss/*' ),
		) );

		self::assertStringContainsString( '"run_worker_first":["\/_ss\/*"]', $stub->body );
		self::assertStringContainsString( '"type":"d1","id":"xyz"', $stub->body );
		self::assertStringContainsString( '"type":"assets"', $stub->body );
		self::assertStringContainsString( 'export default {};', $stub->body );
	}
}
