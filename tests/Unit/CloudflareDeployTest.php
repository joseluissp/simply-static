<?php

declare(strict_types=1);

namespace Simply_Static\Tests\Unit;

use Simply_Static\Cloudflare;
use Simply_Static\Cloudflare_Client;
use Simply_Static\Deploy_Cloudflare_Task;
use Simply_Static\Tests\Support\UnitTestCase;

final class CloudflareDeployTest extends UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->requireSource( 'src/tasks/class-ss-task.php' );
		$this->requireSource( 'src/cloudflare/class-ss-cloudflare-client.php' );
		$this->requireSource( 'src/cloudflare/class-ss-deploy-cloudflare-task.php' );
		$this->requireSource( 'src/cloudflare/class-ss-cloudflare.php' );
	}

	/**
	 * Expected values come from Wrangler's algorithm run in node:
	 * sha256( base64( content ) + path.extname( name ).substring( 1 ) ).slice( 0, 32 )
	 *
	 * @dataProvider hash_vectors
	 */
	public function test_hash_matches_wrangler( string $name, string $content, string $expected ): void {
		$dir = sys_get_temp_dir() . '/ss-cf-' . uniqid();
		mkdir( $dir );
		file_put_contents( $dir . '/' . $name, $content );

		self::assertSame( $expected, Cloudflare_Client::hash_file( $dir . '/' . $name ) );

		unlink( $dir . '/' . $name );
		rmdir( $dir );
	}

	public function hash_vectors(): array {
		return array(
			'html'           => array( 'index.html', "<h1>hola</h1>\n", '1fe041c94ceca6dc611c8fb79a7303f5' ),
			'no extension'   => array( 'LICENSE', 'hello', '333d6b3a3c1f5db6c9bdda5939b13698' ),
			'dotfile'        => array( '.htaccess', 'x', '5e21d86b709b6aa2d5fff6d7cfed56ab' ),
			'multiple dots'  => array( 'a.min.css', 'body{}', 'f78a1c20d726372960baaeb9d0aacb0c' ),
		);
	}

	public function test_multipart_wraps_every_part_with_the_boundary(): void {
		list( $boundary, $body ) = Cloudflare_Client::multipart( array(
			array( 'name' => 'abc', 'type' => 'text/html', 'content' => 'AAA' ),
			array( 'name' => 'worker.js', 'filename' => 'worker.js', 'type' => 'application/javascript+module', 'content' => 'BBB' ),
		) );

		self::assertSame( 3, substr_count( $body, '--' . $boundary ) );
		self::assertStringEndsWith( "--{$boundary}--\r\n", $body );
		self::assertStringContainsString( "name=\"abc\"\r\nContent-Type: text/html\r\n\r\nAAA\r\n", $body );
		self::assertStringContainsString( 'name="worker.js"; filename="worker.js"', $body );
	}

	public function test_task_is_inserted_before_wrapup_only_for_cloudflare(): void {
		$list = array( 'setup', 'fetch_urls', 'wrapup' );

		self::assertSame( array( 'setup', 'fetch_urls', 'deploy_cloudflare', 'wrapup' ), Cloudflare::task_list( $list, 'cloudflare' ) );
		self::assertSame( $list, Cloudflare::task_list( $list, 'zip' ) );
	}

	public function test_buckets_are_split_by_size_and_keep_every_file_once(): void {
		$sizes  = array( 'a' => 3, 'b' => 3, 'c' => 3, 'big' => 50, 'd' => 1 );
		$chunks = Deploy_Cloudflare_Task::chunk_buckets( array( array( 'a', 'b', 'c' ), array( 'big', 'd' ) ), $sizes, 6 );

		// Chunks never cross a bucket, never exceed the limit (except a lone oversized file), and lose nothing.
		self::assertSame( array( array( 'a', 'b' ), array( 'c' ), array( 'big' ), array( 'd' ) ), $chunks );
	}

	public function test_mime_overrides_cover_types_wordpress_misses(): void {
		self::assertSame( 'font/woff2', Deploy_Cloudflare_Task::mime_type( 'fonts/a.woff2' ) );
		self::assertSame( 'image/webp', Deploy_Cloudflare_Task::mime_type( 'img/a.WEBP' ) );
	}
}
