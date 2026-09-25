<?php
/**
 * A cast-only site: unindexed, no discoverable people, no referrers, no open APIs.
 *
 * @package Callboard
 */

namespace Callboard;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Privacy hardening.
 */
final class Privacy {

	/**
	 * Hook registration.
	 */
	public static function register_hooks(): void {
		add_action( 'send_headers', array( self::class, 'headers' ) );
		add_filter( 'robots_txt', array( self::class, 'robots_txt' ), 100 );
		add_filter( 'wp_robots', array( self::class, 'robots_meta' ) );
		add_filter( 'rest_endpoints', array( self::class, 'hide_users_endpoint' ) );
		add_filter( 'rest_authentication_errors', array( self::class, 'require_auth_for_rest' ) );
		add_action( 'template_redirect', array( self::class, 'redirect_discovery_routes' ) );
		add_filter( 'feed_links_show_posts_feed', '__return_false' );
		add_filter( 'feed_links_show_comments_feed', '__return_false' );
		add_filter( 'xmlrpc_enabled', '__return_false' );
	}

	/**
	 * Header-level noindex and no referrer on every response.
	 */
	public static function headers(): void {
		header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
		header( 'Referrer-Policy: no-referrer' );
	}

	/**
	 * Block crawlers but let link-preview fetchers in so shares get the card.
	 */
	public static function robots_txt(): string {
		$out = '';
		foreach ( array( 'facebookexternalhit', 'Facebot', 'Twitterbot', 'Slackbot-LinkExpanding', 'Slackbot', 'Discordbot', 'WhatsApp', 'TelegramBot', 'LinkedInBot', 'iMessageBot', 'Applebot' ) as $bot ) {
			$out .= "User-agent: {$bot}\nAllow: /\n\n";
		}
		return $out . "User-agent: *\nDisallow: /\n";
	}

	/**
	 * Meta robots.
	 *
	 * @param array<string, mixed> $robots Directives.
	 * @return array<string, mixed>
	 */
	public static function robots_meta( array $robots ): array {
		return array(
			'noindex'      => true,
			'nofollow'     => true,
			'noarchive'    => true,
			'nosnippet'    => true,
			'noimageindex' => true,
		) + $robots;
	}

	/**
	 * Nobody lists users.
	 *
	 * @param array<string, mixed> $endpoints REST routes.
	 * @return array<string, mixed>
	 */
	public static function hide_users_endpoint( array $endpoints ): array {
		foreach ( array_keys( $endpoints ) as $route ) {
			if ( str_starts_with( $route, '/wp/v2/users' ) ) {
				unset( $endpoints[ $route ] );
			}
		}
		return $endpoints;
	}

	/**
	 * The REST API is for logged-in use only; the front end needs none of it.
	 *
	 * @param WP_Error|null|true $result Current auth result.
	 * @return WP_Error|null|true
	 */
	public static function require_auth_for_rest( $result ) {
		if ( ! empty( $result ) || is_user_logged_in() ) {
			return $result;
		}
		$route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? (string) $GLOBALS['wp']->query_vars['rest_route'] : '';
		// Extension routes still apply the gate in their permission callback.
		if ( Extensions::is_extension_route( $route ) ) {
			return $result;
		}
		return new WP_Error( 'rest_disabled', __( 'Not available.', 'callboard' ), array( 'status' => 401 ) );
	}

	/**
	 * Author archives, feeds and search reveal history and names: send them home.
	 */
	public static function redirect_discovery_routes(): void {
		if ( is_author() || is_feed() || is_search() ) {
			wp_safe_redirect( home_url( '/' ), 302 );
			exit;
		}
	}
}
