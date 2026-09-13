<?php
/**
 * The app icons in the manifest and service worker, with and without a site icon.
 *
 * @package Callboard
 */

use Callboard\Pwa;

/**
 * @covers \Callboard\Pwa
 */
class Test_Callboard_Pwa extends WP_UnitTestCase {

	/**
	 * What manifest.json and sw.js held before the test, keyed by path. Null for a file that did not exist.
	 *
	 * @var array<string, string|null>
	 */
	private array $site_files = array();

	public function set_up(): void {
		parent::set_up();
		// The tests container serves the end-to-end site from this ABSPATH, and changing the site icon
		// rewrites its files. Keep a copy to put back.
		foreach ( array( ABSPATH . 'manifest.json', ABSPATH . 'sw.js' ) as $file ) {
			$this->site_files[ $file ] = file_exists( $file ) ? (string) file_get_contents( $file ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
	}

	public function tear_down(): void {
		foreach ( $this->site_files as $file => $contents ) {
			if ( null === $contents ) {
				wp_delete_file( $file );
			} else {
				file_put_contents( $file, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
		parent::tear_down();
	}

	/**
	 * An image attachment to use as the site icon.
	 */
	private function site_icon(): int {
		$file = get_temp_dir() . 'callboard-site-icon-' . wp_generate_password( 8, false ) . '.png';
		$im   = imagecreatetruecolor( 512, 512 );
		imagefill( $im, 0, 0, imagecolorallocate( $im, 30, 60, 200 ) );
		imagepng( $im, $file );
		$id = self::factory()->attachment->create_upload_object( $file );
		wp_delete_file( $file );
		return $id;
	}

	/**
	 * @return array<int, string>
	 */
	private function srcs( array $icons ): array {
		return array_column( $icons, 'src' );
	}

	public function test_without_a_site_icon_the_manifest_lists_the_bundled_icons(): void {
		update_option( 'site_icon', 0 );

		$icons = Pwa::manifest()['icons'];

		$this->assertStringContainsString( 'assets/icon-192.png', $icons[0]['src'] );
		$this->assertStringContainsString( 'assets/icon-512.png', $icons[1]['src'] );
		$this->assertStringContainsString( 'assets/icon-512-maskable.png', $icons[2]['src'] );
		$this->assertSame( 'maskable', $icons[2]['purpose'] );
	}

	public function test_with_a_site_icon_the_manifest_lists_it_and_a_padded_maskable_copy(): void {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			$this->markTestSkipped( 'GD is needed to draw the maskable icon.' );
		}
		$id = $this->site_icon();
		update_option( 'site_icon', $id );
		Pwa::write_maskable_icon();

		$icons = Pwa::manifest()['icons'];

		$this->assertSame( wp_make_link_relative( get_site_icon_url( 192 ) ), $icons[0]['src'] );
		$this->assertSame( '192x192', $icons[0]['sizes'] );
		$this->assertSame( wp_make_link_relative( get_site_icon_url( 512 ) ), $icons[1]['src'] );
		$this->assertSame( '512x512', $icons[1]['sizes'] );
		$this->assertSame( 'maskable', $icons[2]['purpose'] );
		$this->assertStringContainsString( "/callboard/icons/maskable-{$id}.png", $icons[2]['src'] );
		foreach ( $this->srcs( $icons ) as $src ) {
			$this->assertStringNotContainsString( 'assets/icon-', $src, 'no bundled icon beside a site icon' );
		}
		$this->assertSame( array( 512, 512 ), array_slice( (array) getimagesize( Pwa::maskable_icon_location()['file'] ), 0, 2 ) );
	}

	public function test_a_site_icon_without_a_maskable_copy_gets_no_maskable_entry(): void {
		$id = $this->site_icon();
		update_option( 'site_icon', $id );
		wp_delete_file( Pwa::maskable_icon_location()['file'] );

		$icons = Pwa::manifest()['icons'];

		$this->assertCount( 2, $icons );
		$this->assertNotContains( 'maskable', array_column( $icons, 'purpose' ) );
	}

	public function test_the_manifest_and_service_worker_are_rewritten_when_the_site_icon_changes(): void {
		$id = $this->site_icon();
		update_option( 'site_icon', $id );

		$icon     = wp_make_link_relative( get_site_icon_url( 512 ) );
		$manifest = json_decode( Pwa::contents( 'manifest.json' ), true );
		$sw       = Pwa::contents( 'sw.js' );
		$this->assertContains( $icon, $this->srcs( $manifest['icons'] ), 'manifest.json lists the new icon' );
		$this->assertStringContainsString( wp_json_encode( wp_make_link_relative( get_site_icon_url( 180 ) ), JSON_UNESCAPED_SLASHES ), $sw, 'sw.js precaches it' );

		update_option( 'site_icon', 0 );

		$manifest = json_decode( Pwa::contents( 'manifest.json' ), true );
		$this->assertNotContains( $icon, $this->srcs( $manifest['icons'] ), 'and drops it when the site icon is removed' );
	}
}
