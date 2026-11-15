<?php

declare(strict_types=1);

namespace {
	// Not part of the shared WordPress stubs; only what the code under test calls.
	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $value ) {
			return trim( strip_tags( (string) $value ) );
		}
	}

	/** Stand-in for TranslatePress: two published languages, English being the default. */
	if ( ! class_exists( 'TRP_Translate_Press' ) ) {
		class TRP_Translate_Press {
			public static $settings = array( 'default-language' => 'en_US', 'publish-languages' => array( 'en_US', 'es_ES', 'fr_FR' ) );

			public static function get_trp_instance() {
				return new self();
			}

			public function get_component( $name ) {
				return 'settings' === $name ? new TRP_Fake_Settings() : new TRP_Fake_Converter();
			}
		}

		class TRP_Fake_Settings {
			public function get_settings() {
				return TRP_Translate_Press::$settings;
			}
		}

		class TRP_Fake_Converter {
			public function get_url_for_language( $code, $url, $trailing ) {
				return preg_replace( '#^(https?://[^/]+)#', '$1/' . strtolower( substr( $code, 0, 2 ) ), $url );
			}
		}
	}
}

namespace Simply_Static\Tests\Unit {

	use Simply_Static\Cloudflare;
	use Simply_Static\Cloudflare_Changes;
	use Simply_Static\Cloudflare_Pages;
	use Simply_Static\Options;
	use Simply_Static\Util;
	use Simply_Static\Tests\Support\UnitTestCase;

	require_once dirname( __DIR__, 2 ) . '/src/cloudflare/class-ss-cloudflare-pages.php';

	/** A crawler as the pages list sees it: an id, a name, URLs and an on/off switch. */
	class FakePageCrawler {
		public $id;
		public $name;
		public $urls;
		public $active;

		public function __construct( $id, $name, array $urls, $active = true ) {
			list( $this->id, $this->name, $this->urls, $this->active ) = array( $id, $name, $urls, $active );
		}

		public function is_active() {
			return $this->active;
		}

		public function detect() {
			return $this->urls;
		}

		public function js_object() {
			return array( 'id' => $this->id, 'name' => $this->name );
		}
	}

	/** Cloudflare_Pages without the real crawlers (they need a whole WordPress). */
	class TestPages extends Cloudflare_Pages {
		public static $crawlers = array();

		protected static function page_crawlers() {
			return self::$crawlers;
		}
	}

	final class CloudflarePagesTest extends UnitTestCase {

		protected function setUp(): void {
			parent::setUp();
			$this->requireSource( 'src/class-ss-options.php' );
			$this->requireSource( 'src/class-ss-util.php' );
			$this->requireSource( 'src/cloudflare/class-ss-cloudflare-client.php' );
			$this->requireSource( 'src/cloudflare/class-ss-cloudflare-forms.php' );
			$this->requireSource( 'src/cloudflare/class-ss-cloudflare-email.php' );
			$this->requireSource( 'src/cloudflare/class-ss-cloudflare-search.php' );
			$this->requireSource( 'src/cloudflare/class-ss-cloudflare-changes.php' );
			$this->requireSource( 'src/cloudflare/class-ss-hide-wp.php' );
			$this->requireSource( 'src/cloudflare/class-ss-cloudflare-ui.php' );
			$this->requireSource( 'src/cloudflare/class-ss-cloudflare.php' );
			$this->requireSource( 'src/cloudflare/class-ss-cloudflare-pages.php' );
			update_option( 'simply-static', array( 'delivery_method' => 'cloudflare' ) );
			update_option( 'ss_cloudflare', array() );
			Options::reinstance();
			TestPages::$crawlers = array();
		}

		/** Same check the core makes for every URL against the "Exclude URLs" list. */
		private function excluded( string $url, array $paths ): bool {
			$parsed = Util::parse_patterns( Cloudflare_Pages::patterns( $paths ) );
			foreach ( $parsed['regex'] as $pattern ) {
				if ( preg_match( $pattern, $url ) ) {
					return true;
				}
			}

			return false;
		}

		public function test_an_unticked_page_is_excluded_but_not_what_lives_below_it(): void {
			$paths = array( '/portfolio/work-1/' );

			self::assertTrue( $this->excluded( 'https://example.test/portfolio/work-1/', $paths ) );
			self::assertTrue( $this->excluded( 'http://other.test/portfolio/work-1', $paths ), 'with or without the trailing slash, on any host' );
			self::assertTrue( $this->excluded( 'https://example.test/portfolio/work-1/?x=1', $paths ) );
			self::assertFalse( $this->excluded( 'https://example.test/portfolio/work-1/team/', $paths ), 'children are separate pages' );
			self::assertFalse( $this->excluded( 'https://example.test/portfolio/work-10/', $paths ) );
			self::assertFalse( $this->excluded( 'https://example.test/portfolio/', $paths ) );
		}

		public function test_paths_with_regex_characters_are_matched_literally(): void {
			$paths = array( '/a.b/(c)+/' );

			self::assertTrue( $this->excluded( 'https://example.test/a.b/(c)+/', $paths ) );
			self::assertFalse( $this->excluded( 'https://example.test/aXb/(c)+/', $paths ) );
		}

		public function test_the_home_page_and_junk_can_never_be_excluded(): void {
			self::assertSame(
				array( '/service/', '/about' ),
				Cloudflare_Pages::clean_paths( array( '/', '', 'https://example.test/', '/service/', 'https://example.test/about?x=1', '/service/', array( 'x' ), 42 ) ),
				'only absolute paths, once each, never "/" and never a query'
			);
			self::assertSame( array(), Cloudflare_Pages::patterns( array( '/' ) ) );
		}

		public function test_unticked_pages_join_the_core_exclusion_list(): void {
			update_option( 'ss_cloudflare', array( 'excluded_urls' => array( '/service/' ) ) );

			$as_text = Cloudflare_Pages::exclude( "/private/\n" );
			self::assertStringStartsWith( "/private/\n/^https?:", $as_text );

			$as_rows = Cloudflare_Pages::exclude( array( array( 'url' => '/private/' ) ) );
			self::assertCount( 2, $as_rows );
			self::assertSame( '/private/', $as_rows[0]['url'] );
		}

		public function test_nothing_changes_without_cloudflare_delivery_or_exclusions(): void {
			update_option( 'ss_cloudflare', array( 'excluded_urls' => array( '/service/' ) ) );
			update_option( 'simply-static', array( 'delivery_method' => 'zip' ) );
			Options::reinstance();
			self::assertSame( "/a/\n", Cloudflare_Pages::exclude( "/a/\n" ) );

			update_option( 'simply-static', array( 'delivery_method' => 'cloudflare' ) );
			update_option( 'ss_cloudflare', array() );
			Options::reinstance();
			self::assertSame( "/a/\n", Cloudflare_Pages::exclude( "/a/\n" ) );
		}

		public function test_pages_are_listed_once_and_only_active_crawlers_count_when_asked(): void {
			TestPages::$crawlers = array(
				new FakePageCrawler( 'home', 'Home', array( 'https://example.test/' ) ),
				new FakePageCrawler( 'post_type', 'Post Type URLs', array( 'https://example.test/', 'https://example.test/about/', 'https://example.test/portfolio/work-1/' ) ),
				new FakePageCrawler( 'author', 'Authors', array( 'https://example.test/author/admin/' ), false ),
			);

			$all = TestPages::groups();
			self::assertCount( 3, $all );
			self::assertSame( array( 'https://example.test/about/', 'https://example.test/portfolio/work-1/' ), $all[1]['urls'], 'the home page is not repeated' );

			$active = TestPages::groups( true );
			self::assertSame( array( 'home', 'post_type' ), array_column( $active, 'id' ) );
		}

		public function test_the_rest_list_marks_what_the_user_left_out(): void {
			update_option( 'ss_cloudflare', array( 'excluded_urls' => array( '/portfolio/work-1/' ) ) );
			TestPages::$crawlers = array(
				new FakePageCrawler( 'home', 'Home', array( 'https://example.test/' ) ),
				new FakePageCrawler( 'post_type', 'Post Type URLs', array( 'https://example.test/portfolio/work-1/', 'https://example.test/about/' ) ),
			);

			$groups = TestPages::rest_pages()->get_data()['groups'];

			self::assertSame( array( 'path' => '/', 'excluded' => false, 'locked' => true ), $groups[0]['items'][0] );
			self::assertSame( array( 'path' => '/portfolio/work-1/', 'excluded' => true, 'locked' => false ), $groups[1]['items'][0] );
			self::assertFalse( $groups[1]['items'][1]['excluded'] );
		}

		public function test_changing_the_exclusions_forces_a_full_export(): void {
			$before = Cloudflare_Changes::fingerprint();
			update_option( 'ss_cloudflare', array( 'excluded_urls' => array( '/service/' ) ) );

			self::assertNotSame( $before, Cloudflare_Changes::fingerprint() );
		}

		public function test_the_translatepress_crawler_seeds_every_other_language(): void {
			$this->requireSource( 'src/crawler/class-ss-crawler.php' );
			$this->requireSource( 'src/cloudflare/class-ss-cloudflare-translatepress-crawler.php' );

			$crawlers = Cloudflare_Pages::add_translatepress_crawler( array() );
			self::assertCount( 1, $crawlers );
			self::assertSame( 'translatepress', $crawlers[0]->js_object()['id'] );
			self::assertTrue( $crawlers[0]->is_active(), 'not a user choice: without it the languages would be missing' );

			$crawler = new class extends \Simply_Static\Crawler\Translatepress_Crawler {
				protected function base_urls() {
					return array( 'https://example.test/', 'https://example.test/about/' );
				}
			};

			self::assertSame(
				array(
					'https://example.test/es/',
					'https://example.test/es/about/',
					'https://example.test/fr/',
					'https://example.test/fr/about/',
				),
				$crawler->detect(),
				'default language (en_US) is not repeated'
			);
		}

		public function test_a_site_with_one_language_seeds_nothing(): void {
			$this->requireSource( 'src/crawler/class-ss-crawler.php' );
			$this->requireSource( 'src/cloudflare/class-ss-cloudflare-translatepress-crawler.php' );
			\TRP_Translate_Press::$settings = array( 'default-language' => 'en_US', 'publish-languages' => array( 'en_US' ) );

			try {
				self::assertSame( array(), ( new \Simply_Static\Crawler\Translatepress_Crawler() )->detect() );
			} finally {
				\TRP_Translate_Press::$settings = array( 'default-language' => 'en_US', 'publish-languages' => array( 'en_US', 'es_ES', 'fr_FR' ) );
			}
		}

		public function test_the_translatepress_crawler_is_not_added_without_cloudflare_delivery(): void {
			update_option( 'simply-static', array( 'delivery_method' => 'zip' ) );
			Options::reinstance();

			self::assertSame( array(), Cloudflare_Pages::add_translatepress_crawler( array() ) );
		}
	}
}
