<?php

declare(strict_types=1);

namespace {
	// Not part of the shared WordPress stubs; only what the code under test calls.
	if ( ! function_exists( 'get_post' ) ) {
		function get_post( $id = null ) {
			return \Simply_Static\Tests\Unit\SafetyPosts::$posts[ (int) $id ] ?? null;
		}
	}
	if ( ! function_exists( 'is_post_type_viewable' ) ) {
		function is_post_type_viewable( $type ) {
			return 'attachment' !== $type;
		}
	}
}

namespace Simply_Static\Tests\Unit {

	use Simply_Static\Cloudflare_Edge_Files;
	use Simply_Static\Cloudflare_Redirects;
	use Simply_Static\Cloudflare_Verify;
	use Simply_Static\Options;
	use Simply_Static\Tests\Support\UnitTestCase;
	use Simply_Static\Tests\Support\WpTestEnvironment as WpEnv;

	require_once dirname( __DIR__, 2 ) . '/src/cloudflare/class-ss-cloudflare-redirects.php';

	/** Posts by ID, the way get_post() returns them. */
	class SafetyPosts {
		public static $posts = array();

		public static function make( int $id, string $link, string $status = 'publish', string $type = 'post' ): \stdClass {
			return self::$posts[ $id ] = (object) array( 'ID' => $id, 'link' => $link, 'post_status' => $status, 'post_type' => $type );
		}
	}

	/** get_permalink() needs a real post; here the address travels in the object. */
	class TestRedirects extends Cloudflare_Redirects {
		protected static function permalink( $post ) {
			return $post->link;
		}
	}

	final class CloudflareSafetyTest extends UnitTestCase {

		protected function setUp(): void {
			parent::setUp();
			SafetyPosts::$posts = array();
			$this->requireSource( 'src/class-ss-options.php' );
			$this->requireSource( 'src/cloudflare/class-ss-cloudflare-client.php' );
			$this->requireSource( 'src/cloudflare/class-ss-cloudflare.php' );
			$this->requireSource( 'src/cloudflare/class-ss-cloudflare-redirects.php' );
			$this->requireSource( 'src/cloudflare/class-ss-cloudflare-verify.php' );
			update_option( 'simply-static', array( 'delivery_method' => 'cloudflare' ) );
			Options::reinstance();
		}

		/** Simulates saving a post: WordPress hands over the post before and after. */
		private function edit( \stdClass $before, string $new_link, string $new_status = 'publish' ): \stdClass {
			$after              = clone $before;
			$after->link        = $new_link;
			$after->post_status = $new_status;
			SafetyPosts::$posts[ $before->ID ] = $after;
			TestRedirects::on_update( $before->ID, $after, $before );

			return $after;
		}

		private function froms( array $rules ): array {
			return array_map( function ( $rule ) {
				return $rule[0] . ' -> ' . $rule[1];
			}, $rules );
		}

		/* ---------------------------------------------------------- redirects */

		public function test_a_new_slug_redirects_the_old_address_with_and_without_slash(): void {
			$post = SafetyPosts::make( 5, 'https://example.test/old/' );
			$this->edit( $post, 'https://example.test/new/' );

			self::assertSame( array( '/old/ -> /new/', '/old -> /new/' ), $this->froms( TestRedirects::add_rules( array() ) ) );
		}

		public function test_chains_end_at_the_current_address(): void {
			$post = SafetyPosts::make( 5, 'https://example.test/a/' );
			$post = $this->edit( $post, 'https://example.test/b/' );
			$this->edit( $post, 'https://example.test/c/' );

			$rules = $this->froms( TestRedirects::add_rules( array() ) );
			self::assertContains( '/a/ -> /c/', $rules );
			self::assertContains( '/b/ -> /c/', $rules );
			self::assertNotContains( '/a/ -> /b/', $rules );
		}

		public function test_user_rules_keep_their_place_before_ours(): void {
			$this->edit( SafetyPosts::make( 5, 'https://example.test/old/' ), 'https://example.test/new/' );

			$rules = TestRedirects::add_rules( array( array( '/mine/', '/x/', 302 ) ) );
			self::assertSame( '/mine/', $rules[0][0] );
			self::assertSame( 301, $rules[1][2] );
		}

		public function test_nothing_is_recorded_unless_a_published_page_moves(): void {
			// Draft -> publish, edit without moving, front page, other delivery methods.
			$this->edit( SafetyPosts::make( 1, 'https://example.test/draft/', 'draft' ), 'https://example.test/draft/' );
			$this->edit( SafetyPosts::make( 2, 'https://example.test/same/' ), 'https://example.test/same/' );
			$this->edit( SafetyPosts::make( 3, 'https://example.test/' ), 'https://example.test/home-page/' );
			update_option( 'simply-static', array( 'delivery_method' => 'zip' ) );
			$this->edit( SafetyPosts::make( 4, 'https://example.test/off/' ), 'https://example.test/off-2/' );

			self::assertSame( array(), TestRedirects::add_rules( array() ) );
			self::assertFalse( get_option( Cloudflare_Redirects::OPTION ), 'no memory kept for them' );
		}

		public function test_an_unpublished_page_gets_no_redirect_but_comes_back_with_its_new_address(): void {
			$post = SafetyPosts::make( 5, 'https://example.test/old/' );
			$post = $this->edit( $post, 'https://example.test/?p=5', 'draft' );
			self::assertSame( array(), TestRedirects::add_rules( array() ), 'a deleted page stays a 404, not a soft 404 to the home' );

			// Published again under another slug: the old address now has somewhere to go.
			$draft = clone $post;
			$this->edit( $draft, 'https://example.test/renamed/' );
			// (draft -> publish records nothing new, the memory of /old/ is what counts)
			SafetyPosts::$posts[5]->post_status = 'publish';
			SafetyPosts::$posts[5]->link        = 'https://example.test/renamed/';

			self::assertContains( '/old/ -> /renamed/', $this->froms( TestRedirects::add_rules( array() ) ) );
		}

		public function test_an_address_that_serves_again_is_never_redirected(): void {
			$this->edit( SafetyPosts::make( 5, 'https://example.test/about/' ), 'https://example.test/about-us/' );
			// Someone created a new page at /about/.
			WpEnv::$url_post_ids['https://example.test/about/'] = 9;

			self::assertNotContains( '/about/ -> /about-us/', $this->froms( TestRedirects::add_rules( array() ) ) );
		}

		public function test_memory_is_capped_and_forgets_the_oldest(): void {
			$moved = array();
			for ( $i = 0; $i < Cloudflare_Redirects::MAX + 5; $i ++ ) {
				SafetyPosts::make( $i + 10, 'https://example.test/p' . $i . '/' );
			}
			for ( $i = 0; $i < Cloudflare_Redirects::MAX + 5; $i ++ ) {
				$this->edit( SafetyPosts::$posts[ $i + 10 ], 'https://example.test/q' . $i . '/' );
			}

			$moved = get_option( Cloudflare_Redirects::OPTION );
			self::assertCount( Cloudflare_Redirects::MAX, $moved );
			self::assertArrayNotHasKey( '/p0/', $moved );
			self::assertArrayHasKey( '/p' . ( Cloudflare_Redirects::MAX + 4 ) . '/', $moved );
		}

		public function test_a_site_in_a_subfolder_keeps_paths_relative_to_its_root(): void {
			WpEnv::$home_url = 'https://example.test/blog';
			$this->edit( SafetyPosts::make( 5, 'https://example.test/blog/old/' ), 'https://example.test/blog/new/' );

			self::assertContains( '/old/ -> /new/', $this->froms( TestRedirects::add_rules( array() ) ) );
		}

		public function test_a_changes_only_export_drops_redirects_that_no_longer_apply(): void {
			$this->requireSource( 'src/class-ss-util.php' );
			$this->requireSource( 'src/cloudflare/class-ss-cloudflare-edge-files.php' );
			$path = sys_get_temp_dir() . '/ss-redirects-' . uniqid();

			Cloudflare_Edge_Files::merge( $path, "/old/ /new/ 301\n" );
			self::assertStringContainsString( '/old/ /new/ 301', (string) file_get_contents( $path ) );

			// The next export has no rules: the previous block must not stay online.
			Cloudflare_Edge_Files::merge( $path, '' );
			self::assertFileDoesNotExist( $path );

			// A file the site ships itself keeps its own lines.
			file_put_contents( $path, "/mine/ /x/ 302\n" );
			Cloudflare_Edge_Files::merge( $path, "/old/ /new/ 301\n" );
			Cloudflare_Edge_Files::merge( $path, '' );
			self::assertSame( "/mine/ /x/ 302\n", file_get_contents( $path ) );

			unlink( $path );
		}

		/* ------------------------------------------------------------- verify */

		private function ok( string $type = 'text/html; charset=utf-8', int $bytes = 100, int $code = 200 ): array {
			return array( 'code' => $code, 'type' => $type, 'bytes' => $bytes );
		}

		public function test_targets_are_the_home_plus_a_few_uploaded_pages_and_assets(): void {
			$targets = Cloudflare_Verify::targets( array(
				'index.html'                => 'text/html',
				'about/index.html'          => 'text/html',
				'blog/hola mundo/index.html' => 'text/html',
				'third/index.html'          => 'text/html',
				'assets/site.css'           => 'text/css',
				'assets/app.js'             => 'text/javascript',
				'assets/other.js'           => 'text/javascript',
				'contact.html'              => 'text/html',
			) );

			self::assertSame( array(
				array( '/', 'text/html', 'GET' ),
				array( '/about/', 'text/html', 'GET' ),
				array( '/blog/hola%20mundo/', 'text/html', 'GET' ),
				array( '/assets/site.css', 'text/css', 'HEAD' ),
				array( '/assets/app.js', 'text/javascript', 'HEAD' ),
			), $targets );
		}

		public function test_nothing_uploaded_still_checks_the_home(): void {
			self::assertSame( array( array( '/', 'text/html', 'GET' ) ), Cloudflare_Verify::targets( array() ) );
		}

		public function test_judge_fails_only_on_proof_of_a_broken_site(): void {
			self::assertSame( 'ok', Cloudflare_Verify::judge( $this->ok(), 'text/html', 'GET' )[0] );
			self::assertSame( 'ok', Cloudflare_Verify::judge( $this->ok( 'text/css', 0 ), 'text/css', 'HEAD' )[0] );

			self::assertSame( 'fail', Cloudflare_Verify::judge( $this->ok( 'application/octet-stream' ), 'text/html', 'GET' )[0], 'the download bug' );
			self::assertSame( 'fail', Cloudflare_Verify::judge( $this->ok( 'application/octet-stream' ), 'text/css', 'HEAD' )[0] );
			self::assertSame( 'fail', Cloudflare_Verify::judge( $this->ok( 'text/plain' ), 'text/html', 'GET' )[0] );
			self::assertSame( 'fail', Cloudflare_Verify::judge( $this->ok( 'text/html', 0 ), 'text/html', 'GET' )[0], 'empty page' );
			self::assertSame( 'fail', Cloudflare_Verify::judge( $this->ok( 'text/html', 5, 503 ), 'text/html', 'GET' )[0] );

			// Doubtful, not proof: routing/DNS not ready, other normalisations of a type.
			self::assertSame( 'warn', Cloudflare_Verify::judge( $this->ok( 'text/html', 5, 404 ), 'text/html', 'GET' )[0] );
			self::assertSame( 'warn', Cloudflare_Verify::judge( new \WP_Error( 'x', 'timeout' ), 'text/html', 'GET' )[0] );
			self::assertSame( 'ok', Cloudflare_Verify::judge( $this->ok( 'application/javascript' ), 'text/javascript', 'HEAD' )[0] );
		}

		public function test_a_new_version_gets_a_moment_to_reach_the_edge(): void {
			$calls = 0;
			$fetch = function () use ( &$calls ) {
				$calls ++;

				return $calls < 3 ? $this->ok( 'application/octet-stream' ) : $this->ok();
			};

			$result = Cloudflare_Verify::check( 'https://site.test', array( array( '/', 'text/html', 'GET' ) ), $fetch, 3, 0 );

			self::assertSame( 'ok', $result['state'] );
			self::assertSame( 3, $calls );
		}

		public function test_check_fails_when_a_page_stays_broken(): void {
			$fetch = function ( $url ) {
				return false !== strpos( $url, '/about/' ) ? $this->ok( 'application/octet-stream' ) : $this->ok();
			};

			$result = Cloudflare_Verify::check( 'https://site.test/', array( array( '/', 'text/html', 'GET' ), array( '/about/', 'text/html', 'GET' ) ), $fetch, 2, 0 );

			self::assertSame( 'fail', $result['state'] );
			self::assertSame( array( '/about/: served as a download (application/octet-stream)' ), $result['problems'] );
		}

		public function test_an_unreachable_home_is_a_warning_and_stops_the_check(): void {
			$urls  = array();
			$fetch = function ( $url ) use ( &$urls ) {
				$urls[] = $url;

				return new \WP_Error( 'http', 'could not resolve host' );
			};

			$result = Cloudflare_Verify::check( 'https://site.test', array( array( '/', 'text/html', 'GET' ), array( '/about/', 'text/html', 'GET' ) ), $fetch, 2, 0 );

			self::assertSame( 'warn', $result['state'] );
			self::assertCount( 2, $urls, 'two tries on the home, nothing else' );
		}

		public function test_preflight_warns_about_limits_cloudflare_will_enforce(): void {
			self::assertSame( array(), Cloudflare_Verify::preflight( 500, "/*\n  X-A: b\n" ) );

			$headers = '';
			for ( $i = 0; $i < 101; $i ++ ) {
				$headers .= "/p$i\n  X-A: b\n";
			}
			$warnings = Cloudflare_Verify::preflight( 20001, "# comment\n" . $headers );

			self::assertCount( 2, $warnings );
			self::assertStringContainsString( '20001', $warnings[0] );
			self::assertStringContainsString( '101', $warnings[1] );
		}
	}
}
