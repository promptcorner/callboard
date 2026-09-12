<?php
/**
 * Web Push: the cast opts in from the home page; the site notifies them about new sets and notices.
 * Works in installed web apps on iOS 16.4+ and in every desktop/Android browser.
 *
 * @package Callboard
 */

namespace Callboard;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Push subscriptions and sending.
 */
final class Push {

	public const TYPE = 'callboard_subscriber';

	private const KEYS_OPTION = 'callboard_vapid';

	/**
	 * Hook registration.
	 */
	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'register' ) );
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_action( 'transition_post_status', array( self::class, 'on_publish' ), 10, 3 );
		add_action( 'admin_post_callboard_notify', array( self::class, 'handle_admin_notify' ) );
	}

	/**
	 * Is the library present and usable here?
	 */
	public static function available(): bool {
		return class_exists( WebPush::class ) && function_exists( 'openssl_pkey_new' ) && ( extension_loaded( 'gmp' ) || extension_loaded( 'bcmath' ) );
	}

	/**
	 * Subscriber post type (private, no UI).
	 */
	public static function register(): void {
		register_post_type(
			self::TYPE,
			array(
				'public'          => false,
				'show_ui'         => false,
				'supports'        => array( 'title' ),
				'capability_type' => Roles::CAPABILITY_TYPES[ self::TYPE ],
				'map_meta_cap'    => true,
			)
		);
	}

	/**
	 * VAPID keys, generated once.
	 *
	 * @return array{publicKey: string, privateKey: string}|null
	 */
	public static function keys(): ?array {
		$keys = get_option( self::KEYS_OPTION );
		if ( is_array( $keys ) && ! empty( $keys['publicKey'] ) ) {
			return $keys;
		}
		if ( ! self::available() ) {
			return null;
		}
		try {
			$keys = VAPID::createVapidKeys();
		} catch ( \Throwable $e ) {
			return null;
		}
		update_option( self::KEYS_OPTION, $keys, false );
		return $keys;
	}

	/**
	 * Public REST routes for the browser, plus an authenticated send.
	 */
	public static function routes(): void {
		register_rest_route(
			'callboard/v1',
			'/push/key',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					$keys = self::keys();
					return $keys ? array( 'key' => $keys['publicKey'] ) : new WP_Error( 'callboard_no_push', __( 'Push is not available.', 'callboard' ), array( 'status' => 503 ) );
				},
			)
		);
		register_rest_route(
			'callboard/v1',
			'/push/subscribe',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => '__return_true',
				'callback'            => array( self::class, 'subscribe' ),
			)
		);
		register_rest_route(
			'callboard/v1',
			'/push/unsubscribe',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => '__return_true',
				'callback'            => array( self::class, 'unsubscribe' ),
			)
		);
	}

	/**
	 * Store a PushSubscription JSON. Idempotent per endpoint.
	 *
	 * @param WP_REST_Request $r Request.
	 */
	public static function subscribe( WP_REST_Request $r ): WP_REST_Response|WP_Error {
		$sub      = $r->get_json_params();
		$endpoint = isset( $sub['endpoint'] ) ? esc_url_raw( (string) $sub['endpoint'] ) : '';
		$p256dh   = (string) ( $sub['keys']['p256dh'] ?? '' );
		$auth     = (string) ( $sub['keys']['auth'] ?? '' );
		if ( ! $endpoint || ! preg_match( '#^https://#', $endpoint ) || ! $p256dh || ! $auth ) {
			return new WP_Error( 'callboard_bad_subscription', __( 'That is not a push subscription.', 'callboard' ), array( 'status' => 400 ) );
		}
		// This route is open by design — a cast member subscribes without an account — so the
		// endpoint arrives from anybody. Later, sending a notice makes the server POST to it. An
		// https URL alone is not enough of a check: https://10.0.0.1/ and https://localhost/ are both
		// https, and either turns this into a way to knock on doors inside the network the site runs
		// in. Core already has the rule for that, written for exactly this shape of problem.
		if ( ! wp_http_validate_url( $endpoint ) ) {
			return new WP_Error( 'callboard_bad_subscription', __( 'That is not a push subscription.', 'callboard' ), array( 'status' => 400 ) );
		}
		if ( self::count() >= (int) apply_filters( 'callboard_max_subscribers', 2000 ) ) {
			return new WP_Error( 'callboard_full', __( 'Too many subscribers.', 'callboard' ), array( 'status' => 429 ) );
		}
		$hash     = hash( 'sha256', $endpoint );
		$existing = self::find( $hash );
		$data     = array(
			'post_type'   => self::TYPE,
			'post_status' => 'private',
			'post_title'  => $hash,
			'meta_input'  => array(
				'_callboard_endpoint' => $endpoint,
				'_callboard_p256dh'   => sanitize_text_field( $p256dh ),
				'_callboard_auth'     => sanitize_text_field( $auth ),
				'_callboard_ua'       => sanitize_text_field( substr( (string) $r->get_header( 'user_agent' ), 0, 200 ) ),
			),
		);
		if ( $existing ) {
			$data['ID'] = $existing->ID;
			wp_update_post( $data );
		} else {
			wp_insert_post( $data );
		}
		return new WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * Remove a subscription by endpoint.
	 *
	 * @param WP_REST_Request $r Request.
	 */
	public static function unsubscribe( WP_REST_Request $r ): WP_REST_Response {
		$endpoint = esc_url_raw( (string) ( $r->get_json_params()['endpoint'] ?? '' ) );
		$post     = $endpoint ? self::find( hash( 'sha256', $endpoint ) ) : null;
		if ( $post ) {
			wp_delete_post( $post->ID, true );
		}
		return new WP_REST_Response( array( 'ok' => true ) );
	}

	/**
	 * Subscriber by endpoint hash.
	 *
	 * @param string $hash sha256 of the endpoint.
	 */
	private static function find( string $hash ): ?WP_Post {
		$posts = get_posts(
			array(
				'post_type'      => self::TYPE,
				'post_status'    => 'private',
				'title'          => $hash,
				'posts_per_page' => 1,
			)
		);
		return $posts[0] ?? null;
	}

	/**
	 * How many devices are subscribed.
	 */
	public static function count(): int {
		return (int) ( wp_count_posts( self::TYPE )->private ?? 0 );
	}

	/**
	 * Send a notification to everyone. Dead subscriptions are pruned.
	 *
	 * @param string $title Title.
	 * @param string $body  Body text.
	 * @param string $url   Where a tap should go.
	 * @return array{sent: int, failed: int, pruned: int}|WP_Error
	 */
	public static function send( string $title, string $body, string $url = '' ) {
		/**
		 * The notification about to go to every subscriber. Return an empty array to send nothing.
		 *
		 * @param array{title: string, body: string, url: string} $message Title, body, and the URL a tap opens.
		 */
		$message = apply_filters( 'callboard_push_message', compact( 'title', 'body', 'url' ) );
		if ( empty( $message['title'] ) ) {
			return true;
		}
		$title = (string) $message['title'];
		$body  = (string) ( $message['body'] ?? '' );
		$url   = (string) ( $message['url'] ?? '' );
		$keys  = self::keys();
		if ( ! $keys ) {
			return new WP_Error( 'callboard_no_push', __( 'Push is not available on this server.', 'callboard' ) );
		}
		$subscribers = get_posts(
			array(
				'post_type'      => self::TYPE,
				'post_status'    => 'private',
				'posts_per_page' => -1,
			)
		);
		if ( ! $subscribers ) {
			return array(
				'sent'   => 0,
				'failed' => 0,
				'pruned' => 0,
			);
		}
		$url     = $url ? $url : home_url( '/' );
		$icon    = callboard_asset( 'assets/icon-192.png' );
		$tag     = 'callboard-' . substr( md5( $title . $body ), 0, 8 );
		$payload = wp_json_encode(
			array(
				'web_push'     => 8030, // declarative Web Push: Safari shows this without waking the worker.
				'notification' => array(
					'title'    => $title,
					'body'     => $body,
					'navigate' => $url,
					'icon'     => $icon,
					'tag'      => $tag,
				),
				'app_badge'    => 1,
				'title'        => $title,
				'body'         => $body,
				'url'          => $url,
				'icon'         => $icon,
				'badge'        => $icon,
				'tag'          => $tag,
			)
		);
		try {
			$push        = new WebPush(
				array(
					'VAPID' => array(
						'subject'    => home_url( '/' ),
						'publicKey'  => $keys['publicKey'],
						'privateKey' => $keys['privateKey'],
					),
				),
				array(
					'TTL'     => DAY_IN_SECONDS,
					'urgency' => 'normal',
				)
			);
			$by_endpoint = array();
			foreach ( $subscribers as $s ) {
				$endpoint                 = (string) get_post_meta( $s->ID, '_callboard_endpoint', true );
				$by_endpoint[ $endpoint ] = $s->ID;
				$push->queueNotification(
					Subscription::create(
						array(
							'endpoint'        => $endpoint,
							'publicKey'       => (string) get_post_meta( $s->ID, '_callboard_p256dh', true ),
							'authToken'       => (string) get_post_meta( $s->ID, '_callboard_auth', true ),
							'contentEncoding' => 'aes128gcm',
						)
					),
					(string) $payload
				);
			}
			$result = array(
				'sent'   => 0,
				'failed' => 0,
				'pruned' => 0,
			);
			foreach ( $push->flush() as $report ) {
				if ( $report->isSuccess() ) {
					++$result['sent'];
					continue;
				}
				++$result['failed'];
				if ( $report->isSubscriptionExpired() ) {
					$endpoint = $report->getRequest()->getUri()->__toString();
					if ( isset( $by_endpoint[ $endpoint ] ) ) {
						wp_delete_post( $by_endpoint[ $endpoint ], true );
						++$result['pruned'];
					}
				}
			}
			return $result;
		} catch ( \Throwable $e ) {
			return new WP_Error( 'callboard_push_failed', $e->getMessage() );
		}
	}

	/**
	 * A set going live for the first time notifies the cast, if the setting is on.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post Post.
	 */
	public static function on_publish( string $new_status, string $old_status, WP_Post $post ): void {
		if ( Post_Types::SET !== $post->post_type || 'publish' !== $new_status || 'publish' === $old_status || ! Settings::get( 'notify_new_sets' ) ) {
			return;
		}
		$set = Sets::build( $post );
		self::send(
			/* translators: %s: set name. */
			sprintf( __( 'New set: %s', 'callboard' ), $post->post_title ),
			$set['meta'],
			home_url( '/' . $post->post_name . '/' )
		);
	}

	/**
	 * Admin "Notify the cast" form.
	 */
	public static function handle_admin_notify(): void {
		if ( ! current_user_can( Roles::NOTIFY ) ) {
			wp_die( esc_html__( 'Not allowed.', 'callboard' ) );
		}
		check_admin_referer( 'callboard_notify' );
		$title  = isset( $_POST['callboard_title'] ) ? sanitize_text_field( wp_unslash( $_POST['callboard_title'] ) ) : '';
		$body   = isset( $_POST['callboard_body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['callboard_body'] ) ) : '';
		$url    = isset( $_POST['callboard_link'] ) ? esc_url_raw( wp_unslash( $_POST['callboard_link'] ) ) : '';
		$result = ( $title || $body ) ? self::send( $title ? $title : callboard_site_name(), $body, $url ) : new WP_Error( 'callboard_empty', __( 'Write something first.', 'callboard' ) );
		$notice = is_wp_error( $result )
			? $result->get_error_message()
			/* translators: 1: delivered count, 2: failed count. */
			: sprintf( __( 'Sent to %1$d devices (%2$d failed).', 'callboard' ), $result['sent'], $result['failed'] );
		set_transient( 'callboard_import_notice', array( $notice ), 60 );
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . Post_Types::SET . '&page=callboard-notices' ) );
		exit;
	}
}
