<?php

declare(strict_types=1);

namespace {
	// Not part of the shared WordPress stubs; only what the code under test calls.
	if ( ! function_exists( 'is_email' ) ) {
		function is_email( $email ) {
			return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
		}
	}
	if ( ! function_exists( 'get_locale' ) ) {
		function get_locale() {
			return 'es_AR';
		}
	}
	if ( ! function_exists( 'get_page_by_path' ) ) {
		function get_page_by_path( $path ) {
			return null;
		}
	}
}

namespace Simply_Static\Tests\Unit {

	use Simply_Static\Cloudflare;
	use Simply_Static\Cloudflare_Changes;
	use Simply_Static\Cloudflare_Client;
	use Simply_Static\Cloudflare_Edge_Files;
	use Simply_Static\Cloudflare_Email;
	use Simply_Static\Cloudflare_Search;
	use Simply_Static\Hide_WP;
	use Simply_Static\Options;
	use Simply_Static\Tests\Support\UnitTestCase;

	require_once dirname( __DIR__, 2 ) . '/src/cloudflare/class-ss-cloudflare-client.php';

	/** Answers Cloudflare API calls from a table of "METHOD substring" => response. */
	class FakeCloudflare extends Cloudflare_Client {
		public $calls = array();
		public $routes = array();

		protected function request( $method, $url, $body = null, array $headers = array(), $token = null ) {
			$this->calls[] = array( $method, $url, $body );
			foreach ( $this->routes as $needle => $response ) {
				if ( 0 === strpos( $needle, $method . ' ' ) && false !== strpos( $url, substr( $needle, strlen( $method ) + 1 ) ) ) {
					return $response;
				}
			}

			return new \WP_Error( 'unrouted', $method . ' ' . $url );
		}
	}

	final class CloudflareFeaturesTest extends UnitTestCase {

		protected function setUp(): void {
			parent::setUp();
			$this->requireSource( 'src/class-ss-options.php' );
			$this->requireSource( 'src/class-ss-util.php' );
			$this->requireSource( 'src/cloudflare/class-ss-cloudflare-client.php' );
			$this->requireSource( 'src/cloudflare/class-ss-cloudflare-edge-files.php' );
			$this->requireSource( 'src/cloudflare/class-ss-cloudflare-forms.php' );
			$this->requireSource( 'src/cloudflare/class-ss-cloudflare-email.php' );
			$this->requireSource( 'src/cloudflare/class-ss-cloudflare-search.php' );
			$this->requireSource( 'src/cloudflare/class-ss-cloudflare-changes.php' );
			$this->requireSource( 'src/cloudflare/class-ss-hide-wp.php' );
			$this->requireSource( 'src/cloudflare/class-ss-cloudflare.php' );
		}

		private function core( array $extra = array() ): void {
			update_option( 'simply-static', array_merge( array( 'delivery_method' => 'cloudflare' ), $extra ) );
			Options::reinstance();
		}

		private function dom( string $html ): \DOMDocument {
			$dom = new \DOMDocument();
			@$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html );

			return $dom;
		}

		/* -------------------------------------------------------------- email */

		public function test_email_waits_for_a_domain_and_binds_nothing(): void {
			$client = new FakeCloudflare( 'acc', 'tok', 'site' );

			$bindings = Cloudflare_Email::provision( $client, array( 'notify' => true, 'notify_email' => 'me@example.org', 'domain' => '' ) );

			self::assertSame( array(), $bindings );
			self::assertSame( 'needs_domain', Cloudflare_Email::status()['state'] );
			self::assertSame( array(), $client->calls, 'no API calls before there is a domain' );
		}

		public function test_email_off_when_disabled_or_recipient_invalid(): void {
			$client = new FakeCloudflare( 'acc', 'tok', 'site' );

			self::assertSame( array(), Cloudflare_Email::provision( $client, array( 'notify' => false, 'notify_email' => 'me@example.org', 'domain' => 'www.example.com' ) ) );
			self::assertSame( 'off', Cloudflare_Email::status()['state'] );

			self::assertSame( array(), Cloudflare_Email::provision( $client, array( 'notify' => true, 'notify_email' => 'nope', 'domain' => 'www.example.com' ) ) );
			self::assertSame( 'off', Cloudflare_Email::status()['state'] );
		}

		public function test_email_full_chain_registers_the_address_and_binds_send_email(): void {
			$client         = new FakeCloudflare( 'acc', 'tok', 'site' );
			$client->routes = array(
				'GET /zones?name=www.example.com'   => array( 'result' => array() ),
				'GET /zones?name=example.com'       => array( 'result' => array( array( 'id' => 'Z1', 'name' => 'example.com', 'status' => 'active' ) ) ),
				'GET /zones/Z1/email/routing'       => array( 'result' => array( 'enabled' => false ) ),
				'POST /zones/Z1/email/routing/enable' => array( 'success' => true ),
				'GET /email/routing/addresses'      => array( 'result' => array() ),
				'POST /email/routing/addresses'     => array( 'result' => array( 'email' => 'me@example.org', 'verified' => null ) ),
			);

			$bindings = Cloudflare_Email::provision( $client, array( 'notify' => true, 'notify_email' => 'me@example.org', 'domain' => 'www.example.com' ) );
			$by_name  = array_column( $bindings, null, 'name' );

			self::assertSame( 'pending_verification', Cloudflare_Email::status()['state'] );
			self::assertSame( 'send_email', $by_name['EMAIL']['type'] );
			self::assertSame( array( 'me@example.org' ), $by_name['EMAIL']['allowed_destination_addresses'] );
			self::assertSame( 'noreply@example.com', $by_name['MAIL_FROM']['text'] );
			self::assertSame( 'me@example.org', $by_name['MAIL_TO']['text'] );
			self::assertContains( 'POST', array_column( $client->calls, 0 ) );
		}

		public function test_email_ready_once_the_owner_confirmed_and_routing_was_already_on(): void {
			$client         = new FakeCloudflare( 'acc', 'tok', 'site' );
			$client->routes = array(
				'GET /zones?name=example.com'   => array( 'result' => array( array( 'id' => 'Z1', 'name' => 'example.com', 'status' => 'active' ) ) ),
				'GET /zones/Z1/email/routing'   => array( 'result' => array( 'enabled' => true ) ),
				'GET /email/routing/addresses'  => array( 'result' => array( array( 'email' => 'ME@example.org', 'verified' => '2026-01-01T00:00:00Z' ) ) ),
			);

			Cloudflare_Email::provision( $client, array( 'notify' => true, 'notify_email' => 'me@example.org', 'domain' => 'example.com' ) );

			self::assertSame( 'ready', Cloudflare_Email::status()['state'] );
			self::assertNotContains( 'POST', array_column( $client->calls, 0 ), 'nothing to create' );
		}

		public function test_email_reports_a_domain_that_already_has_mail_elsewhere(): void {
			$client         = new FakeCloudflare( 'acc', 'tok', 'site' );
			$client->routes = array(
				'GET /zones?name=example.com'         => array( 'result' => array( array( 'id' => 'Z1', 'name' => 'example.com', 'status' => 'active' ) ) ),
				'GET /zones/Z1/email/routing'         => array( 'result' => array( 'enabled' => false ) ),
				'POST /zones/Z1/email/routing/enable' => new \WP_Error( 'x', 'Non-Cloudflare MX records exist' ),
			);

			self::assertSame( array(), Cloudflare_Email::provision( $client, array( 'notify' => true, 'notify_email' => 'me@example.org', 'domain' => 'example.com' ) ) );
			self::assertSame( 'error', Cloudflare_Email::status()['state'] );
			self::assertStringContainsString( 'MX', Cloudflare_Email::status()['detail'] );
		}

		public function test_email_waits_for_a_pending_zone(): void {
			$client         = new FakeCloudflare( 'acc', 'tok', 'site' );
			$client->routes = array( 'GET /zones?name=example.com' => array( 'result' => array( array( 'id' => 'Z1', 'name' => 'example.com', 'status' => 'pending' ) ) ) );

			self::assertSame( array(), Cloudflare_Email::provision( $client, array( 'notify' => true, 'notify_email' => 'me@example.org', 'domain' => 'example.com' ) ) );
			self::assertSame( 'zone_pending', Cloudflare_Email::status()['state'] );
		}

		/* ----------------------------------------------------------- hide wp */

		public function test_strip_version_keeps_other_parameters(): void {
			self::assertSame( '/a.css', Hide_WP::strip_version( '/a.css?ver=6.4.1' ) );
			self::assertSame( '/a.css?x=2', Hide_WP::strip_version( '/a.css?ver=1&x=2' ) );
			self::assertSame( '/a.css?x=2', Hide_WP::strip_version( '/a.css?x=2&ver=1' ) );
			self::assertSame( '/a.css#top', Hide_WP::strip_version( '/a.css?ver=1#top' ) );
			self::assertSame( '/a.css?x=1', Hide_WP::strip_version( '/a.css?x=1' ) );
		}

		public function test_default_folder_names_apply_only_to_untouched_values_and_only_with_cloudflare(): void {
			Hide_WP::init();

			$this->core( array( 'wp_content_directory' => 'wp-content', 'wp_includes_directory' => 'wp-includes', 'wp_uploads_directory' => 'mine' ) );
			$options = Options::instance();
			self::assertSame( 'assets', $options->get( 'wp_content_directory' ) );
			self::assertSame( 'lib', $options->get( 'wp_includes_directory' ) );
			self::assertSame( 'mine', $options->get( 'wp_uploads_directory' ), 'what the user typed wins' );

			update_option( Cloudflare::OPTION, array( 'hide_wp' => false ) );
			self::assertSame( 'wp-content', Options::instance()->get( 'wp_content_directory' ), 'one switch turns it off' );

			update_option( Cloudflare::OPTION, array() );
			update_option( 'simply-static', array( 'delivery_method' => 'zip', 'wp_content_directory' => 'wp-content' ) );
			Options::reinstance();
			self::assertSame( 'wp-content', Options::instance()->get( 'wp_content_directory' ), 'other delivery methods are untouched' );
		}

		public function test_html_markers_are_removed_when_the_toggles_are_on(): void {
			Hide_WP::init();
			// Master switch off: only the toggles the user chose apply.
			update_option( Cloudflare::OPTION, array( 'hide_wp' => false ) );
			$this->core( array(
				'hide_generator' => true, 'hide_prefetch' => true, 'hide_rsd' => true, 'hide_emotes' => true,
				'hide_comments' => true, 'hide_version' => false, 'disable_xmlrpc' => true, 'disable_embed' => true, 'disable_wlw_manifest' => true,
			) );

			$html = '<html><head>'
				. '<meta name="generator" content="WordPress 6.9">'
				. '<link rel="dns-prefetch" href="//s.w.org">'
				. '<link rel="EditURI" type="application/rsd+xml" href="/xmlrpc.php?rsd">'
				. '<link rel="wlwmanifest" href="/wlwmanifest.xml">'
				. '<link rel="pingback" href="/xmlrpc.php">'
				. '<link rel="alternate" type="application/json+oembed" href="/oembed">'
				. '<link rel="stylesheet" href="/a.css?ver=6.9">'
				. '<script src="/wp-includes/js/wp-emoji-release.min.js?ver=6.9"></script>'
				. '<script>window._wpemojiSettings = {};</script>'
				. '<style>a{color:red}/*# sourceURL=/wp-includes/css/x.css */</style>'
				. '</head><body><!-- built by WordPress --><!--[if IE]><p>ie</p><![endif]--><p>Hola</p></body></html>';

			$out = Hide_WP::clean_dom( $this->dom( $html ) )->saveHTML();

			self::assertStringNotContainsString( 'generator', $out );
			self::assertStringNotContainsString( 'dns-prefetch', $out );
			self::assertStringNotContainsString( 'EditURI', $out );
			self::assertStringNotContainsString( 'wlwmanifest', $out );
			self::assertStringNotContainsString( 'pingback', $out );
			self::assertStringNotContainsString( 'oembed', $out );
			self::assertStringNotContainsString( 'emoji', $out );
			self::assertStringNotContainsString( 'built by WordPress', $out );
			self::assertStringNotContainsString( 'sourceURL', $out );
			self::assertStringContainsString( 'ver=6.9', $out, 'versions stay while hide_version is off' );
			self::assertStringContainsString( 'Hola', $out );

			// Master switch (default with Cloudflare delivery): everything on, versions included.
			update_option( Cloudflare::OPTION, array() );
			$this->core( array_fill_keys( Hide_WP::TOGGLES, false ) ); // saved defaults are false
			$on = Hide_WP::clean_dom( $this->dom( $html ) )->saveHTML();
			self::assertStringNotContainsString( 'generator', $on );
			self::assertStringNotContainsString( 'ver=6.9', $on );
			self::assertStringContainsString( 'if IE', $on, 'IE conditionals are kept' );
			self::assertStringContainsString( 'a{color:red}', $on, 'CSS is not escaped or lost' );
		}

		public function test_custom_replacements_are_applied(): void {
			$this->core( array( 'custom_replacements' => array( array( 'search' => 'Acme', 'replace' => 'Foo' ), array( 'search' => '', 'replace' => 'x' ) ) ) );

			self::assertSame( 'Foo y Foo', Hide_WP::custom_replacements( 'Acme y Acme' ) );
		}

		public function test_cache_headers_follow_the_renamed_folders(): void {
			Hide_WP::init();
			$this->core( array( 'wp_content_directory' => 'wp-content', 'wp_includes_directory' => 'wp-includes', 'wp_uploads_directory' => 'uploads', 'wp_plugins_directory' => 'plugins', 'wp_themes_directory' => 'themes' ) );

			$file = Cloudflare_Edge_Files::headers_file();

			self::assertStringContainsString( "/assets/media/*\n  Cache-Control: public, max-age=31536000, immutable", $file );
			self::assertStringContainsString( "/lib/*\n", $file );
			self::assertStringNotContainsString( 'wp-content', $file );
		}

		/* ------------------------------------------------------------ search */

		public function test_search_boxes_point_to_the_results_page_and_other_forms_are_left_alone(): void {
			$this->core();

			$dom = $this->dom(
				'<body>'
				. '<form id="a" role="search" method="get" action="http://x/"><input type="search" name="s"></form>'
				. '<form id="b" action=""><input name="s"></form>'
				. '<form id="c" method="post" action="/contact"><input name="s"></form>'
				. '<form id="d" method="get" action="/filter"><input name="color"></form>'
				. '</body>'
			);
			$xml = Cloudflare_Search::point_forms( $dom )->saveHTML();

			self::assertSame( 'buscar', Cloudflare_Search::page_path() );
			self::assertMatchesRegularExpression( '#<form id="a"[^>]*action="/buscar/"#', $xml );
			self::assertMatchesRegularExpression( '#<form id="b" action="/buscar/"#', $xml );
			self::assertStringContainsString( 'action="/contact"', $xml );
			self::assertStringContainsString( 'action="/filter"', $xml );
		}

		public function test_search_is_inactive_without_cloudflare(): void {
			update_option( 'simply-static', array( 'delivery_method' => 'zip' ) );
			Options::reinstance();
			$dom = $this->dom( '<body><form method="get" action="/x"><input name="s"></form></body>' );

			self::assertStringContainsString( 'action="/x"', Cloudflare_Search::point_forms( $dom )->saveHTML() );
		}

		/* ----------------------------------------------------------- changes */

		public function test_update_needs_a_known_good_full_export_first(): void {
			$this->core();

			self::assertFalse( Cloudflare_Changes::can_update() );
			self::assertSame( 'export', Cloudflare_Changes::prepare( 'update' ), 'no baseline: full export' );
			self::assertSame( 'export', Cloudflare_Changes::prepare( 'export' ) );
		}

		public function test_fingerprint_changes_with_what_alters_every_page(): void {
			$this->core();
			$before = Cloudflare_Changes::fingerprint();
			self::assertSame( $before, Cloudflare_Changes::fingerprint() );

			update_option( Cloudflare::OPTION, array( 'search' => false ) );
			self::assertNotSame( $before, Cloudflare_Changes::fingerprint(), 'search on/off' );

			update_option( Cloudflare::OPTION, array() );
			update_option( Cloudflare::STATUS, array( 'host' => 'other.workers.dev' ) );
			self::assertNotSame( $before, Cloudflare_Changes::fingerprint(), 'a new address rewrites every link' );
		}

		public function test_other_delivery_methods_keep_the_requested_type(): void {
			update_option( 'simply-static', array( 'delivery_method' => 'zip' ) );
			Options::reinstance();

			self::assertSame( 'update', Cloudflare_Changes::prepare( 'update' ) );
		}

		public function test_empty_crawler_list_falls_back_to_defaults_only_with_cloudflare(): void {
			$this->core();
			self::assertNull( Cloudflare::default_crawlers( array() ), 'an empty list would export only the home page' );
			self::assertSame( array( 'post_type' ), Cloudflare::default_crawlers( array( 'post_type' ) ) );

			update_option( 'simply-static', array( 'delivery_method' => 'zip' ) );
			self::assertSame( array(), Cloudflare::default_crawlers( array() ) );
		}

		/* ---------------------------------------------------------- versions */

		public function test_versions_are_listed_newest_first_with_the_live_one_flagged(): void {
			$client         = new FakeCloudflare( 'acc', 'tok', 'site' );
			$client->routes = array(
				'GET /versions'    => array( 'result' => array( 'items' => array(
					array( 'id' => 'v2', 'number' => 2, 'metadata' => array( 'created_on' => '2026-11-10T10:00:00Z' ), 'annotations' => array( 'workers/message' => 'second' ) ),
					array( 'id' => 'v1', 'number' => 1, 'metadata' => array( 'created_on' => '2026-11-09T10:00:00Z' ) ),
				) ) ),
				'GET /deployments' => array( 'result' => array( 'deployments' => array( array( 'versions' => array( array( 'version_id' => 'v1', 'percentage' => 100 ) ) ) ) ) ),
			);

			$list = $client->versions( 10 );

			self::assertSame( array( 'v2', 'v1' ), array_column( $list, 'id' ) );
			self::assertSame( array( false, true ), array_column( $list, 'active' ) );
			self::assertSame( 'second', $list[0]['message'] );
		}

		public function test_rollback_deploys_the_chosen_version_at_100_percent(): void {
			$client         = new FakeCloudflare( 'acc', 'tok', 'site' );
			$client->routes = array( 'POST /deployments' => array( 'success' => true ) );

			$client->rollback( 'v1' );
			$body = json_decode( $client->calls[0][2], true );

			self::assertSame( 'percentage', $body['strategy'] );
			self::assertSame( array( array( 'percentage' => 100, 'version_id' => 'v1' ) ), $body['versions'] );
		}

		public function test_publish_labels_the_version(): void {
			$client         = new FakeCloudflare( 'acc', 'tok', 'site' );
			$client->routes = array( 'PUT /workers/scripts/site' => array( 'success' => true ) );

			$client->publish( 'JWT', array(), array( 'message' => 'Simply Static full 2026-11-10 08:00 UTC' ) );

			self::assertStringContainsString( '"workers\/message":"Simply Static full 2026-11-10 08:00 UTC"', $client->calls[0][2] );
		}

		/* ------------------------------------------------------------ domain */

		public function test_worker_name_sticks_to_the_deployed_one_when_the_site_moves(): void {
			update_option( Cloudflare::STATUS, array( 'worker' => 'mi-sitio' ) );

			self::assertSame( 'mi-sitio', Cloudflare::settings()['worker_name'], 'a local copy keeps updating the same Worker' );

			update_option( Cloudflare::OPTION, array( 'worker_name' => 'elegido' ) );
			self::assertSame( 'elegido', Cloudflare::settings()['worker_name'] );
		}
	}
}
