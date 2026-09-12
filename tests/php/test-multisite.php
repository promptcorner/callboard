<?php
/**
 * Multisite networks: each site keeps its own service worker and manifest, and network activation
 * sets up every site.
 *
 * Run with `npm run test:php:multisite`. On a single site these are skipped.
 *
 * @package Callboard
 */

use Callboard\Plugin;
use Callboard\Pwa;

/**
 * @covers \Callboard\Pwa
 * @covers \Callboard\Plugin
 */
class Test_Callboard_Multisite extends WP_UnitTestCase {

	/**
	 * The root files the tests container serves to the end-to-end site, as they were before a test.
	 *
	 * @var array<string, string|null>
	 */
	private array $root = array();

	public function set_up(): void {
		parent::set_up();
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a multisite network: npm run test:php:multisite.' );
		}
		foreach ( array( 'sw.js', 'manifest.json' ) as $file ) {
			$this->root[ $file ] = file_exists( ABSPATH . $file ) ? (string) file_get_contents( ABSPATH . $file ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
	}

	public function tear_down(): void {
		foreach ( $this->root as $file => $before ) {
			$now = file_exists( ABSPATH . $file ) ? (string) file_get_contents( ABSPATH . $file ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( $now === $before ) {
				continue;
			}
			if ( null === $before ) {
				wp_delete_file( ABSPATH . $file );
			} else {
				file_put_contents( ABSPATH . $file, $before ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
		delete_site_option( 'active_sitewide_plugins' );
		parent::tear_down();
	}

	/**
	 * Write the current site's worker and manifest and return what the site answers for each.
	 *
	 * @return array{sw: array{headers: array<string, string>, body: string}, manifest: array<string, mixed>, sw_url: string, manifest_url: string}
	 */
	private function app_files(): array {
		Pwa::write_manifest();
		Pwa::write_sw();
		$sw       = Pwa::response( 'sw.js' );
		$manifest = Pwa::response( 'manifest.json' );
		$this->assertNotNull( $sw, 'the site serves its service worker' );
		$this->assertNotNull( $manifest, 'the site serves its manifest' );
		return array(
			'sw'           => $sw,
			'manifest'     => json_decode( $manifest['body'], true ),
			'sw_url'       => Pwa::sw_url(),
			'manifest_url' => Pwa::manifest_url(),
		);
	}

	public function test_two_sites_get_their_own_service_worker_and_manifest(): void {
		update_option( 'blogname', 'Main Stage' );
		$choir = self::factory()->blog->create( array( 'title' => 'Youth Choir' ) );
		$path  = get_site( $choir )->path; // A subdirectory site, such as /testpath1/.
		$this->assertNotSame( '/', $path );

		$main = $this->app_files();
		switch_to_blog( $choir );
		$site = $this->app_files();
		restore_current_blog();

		$this->assertSame( 'Main Stage', $main['manifest']['name'] );
		$this->assertSame( 'Youth Choir', $site['manifest']['name'] );
		$this->assertSame( '/', $main['manifest']['scope'] );
		$this->assertSame( $path, $site['manifest']['scope'] );
		$this->assertSame( $path, $site['manifest']['start_url'] );

		$this->assertSame( '/', $main['sw']['headers']['Service-Worker-Allowed'] );
		$this->assertSame( $path, $site['sw']['headers']['Service-Worker-Allowed'] );
		$this->assertStringContainsString( "const HOME = '/';", $main['sw']['body'] );
		$this->assertStringContainsString( "const HOME = '{$path}';", $site['sw']['body'] );
		$this->assertStringContainsString( "const MANIFEST = '{$path}?callboard_file=manifest.json';", $site['sw']['body'] );
		// Sites of a subdirectory network share an origin, so each names its own shell cache.
		$this->assertStringContainsString( "const SITE = '" . get_main_site_id() . "';", $main['sw']['body'] );
		$this->assertStringContainsString( "const SITE = '{$choir}';", $site['sw']['body'] );

		$this->assertSame( home_url( '/?callboard_file=sw.js' ), $main['sw_url'] );
		$this->assertSame( 'http://' . WP_TESTS_DOMAIN . $path . '?callboard_file=sw.js', $site['sw_url'] );
		$this->assertSame( 'http://' . WP_TESTS_DOMAIN . $path . '?callboard_file=manifest.json', $site['manifest_url'] );

		// The second site writing its files left the first site's alone.
		$this->assertSame( 'Main Stage', json_decode( Pwa::response( 'manifest.json' )['body'], true )['name'] );
		$this->assertSame( $main['sw']['body'], Pwa::response( 'sw.js' )['body'] );
	}

	public function test_a_subdomain_site_serves_its_files_from_its_own_host(): void {
		$band = self::factory()->blog->create(
			array(
				'domain' => 'band.' . WP_TESTS_DOMAIN,
				'path'   => '/',
				'title'  => 'Pit Band',
			)
		);

		switch_to_blog( $band );
		$site = $this->app_files();
		restore_current_blog();

		$this->assertSame( 'Pit Band', $site['manifest']['name'] );
		$this->assertSame( '/', $site['manifest']['scope'] );
		$this->assertSame( '/', $site['sw']['headers']['Service-Worker-Allowed'] );
		$this->assertSame( 'http://band.' . WP_TESTS_DOMAIN . '/?callboard_file=sw.js', $site['sw_url'] );
		$this->assertNotSame( 'Pit Band', json_decode( Pwa::contents( 'manifest.json' ), true )['name'] ?? '' );
	}

	public function test_network_activation_sets_up_every_site(): void {
		update_option( 'blogname', 'Main Stage' );
		$choir = self::factory()->blog->create( array( 'title' => 'Youth Choir' ) );
		$sites = array( get_main_site_id(), $choir );

		// As if both sites had run an earlier copy: plain permalinks, current version, no app files.
		foreach ( $sites as $id ) {
			switch_to_blog( $id );
			update_option( 'permalink_structure', '' );
			update_option( 'callboard_version', CALLBOARD_VERSION );
			delete_option( Pwa::SW_OPTION );
			delete_option( Pwa::MANIFEST_OPTION );
			restore_current_blog();
		}

		Plugin::activate( true );

		foreach ( $sites as $id ) {
			switch_to_blog( $id );
			$this->assertSame( '/%postname%/', get_option( 'permalink_structure' ), "site {$id} has pretty permalinks" );

			Plugin::maybe_upgrade(); // The site's next request.
			$manifest = json_decode( Pwa::contents( 'manifest.json' ), true );
			$this->assertSame( get_option( 'blogname' ), $manifest['name'] ?? null, "site {$id} wrote its own manifest" );
			$this->assertSame( Pwa::scope(), $manifest['scope'] );
			$this->assertStringContainsString( "const HOME = '" . Pwa::scope() . "';", Pwa::contents( 'sw.js' ), "site {$id} wrote its own service worker" );
			restore_current_blog();
		}
	}

	public function test_a_new_site_on_a_network_that_activated_callboard_is_set_up(): void {
		update_site_option( 'active_sitewide_plugins', array( plugin_basename( CALLBOARD_FILE ) => time() ) );

		$site = self::factory()->blog->create( array( 'meta' => array( 'permalink_structure' => '' ) ) );

		switch_to_blog( $site );
		$this->assertSame( '/%postname%/', get_option( 'permalink_structure' ) );
		restore_current_blog();
	}

	public function test_uninstall_clears_every_site(): void {
		$choir = self::factory()->blog->create();
		$sites = array( get_main_site_id(), $choir );
		foreach ( $sites as $id ) {
			switch_to_blog( $id );
			Pwa::write_manifest();
			update_option( 'callboard_version', CALLBOARD_VERSION );
			restore_current_blog();
		}

		Plugin::uninstall();

		foreach ( $sites as $id ) {
			switch_to_blog( $id );
			$this->assertFalse( get_option( Pwa::MANIFEST_OPTION ), "site {$id} lost its manifest" );
			$this->assertFalse( get_option( 'callboard_version' ), "site {$id} lost its version" );
			restore_current_blog();
		}
	}
}
