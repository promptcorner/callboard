<?php
/**
 * One decision: may this visitor see the front end?
 *
 * Callboard owns the gate, not the authentication. Every WordPress auth plugin ends in the
 * same place — a signed-in user — so asking `is_user_logged_in()` and nothing else inherits
 * Sign in with Apple, Google SSO, membership plugins, and Two Factor with its WebAuthn
 * provider without knowing any of them exist. Core still ships no passkeys of its own.
 * A site can also require the `view_callboard` capability, which the Cast member role has.
 *
 * Off by default: a link in a group chat is the whole setup, and that is the point of the
 * plugin. The switch is for companies that need more.
 *
 * @package Callboard
 */

namespace Callboard;

defined( 'ABSPATH' ) || exit;

/**
 * Front-end access.
 */
final class Gate {

	/**
	 * Whether the current visitor may see the app.
	 */
	public static function allowed(): bool {
		/**
		 * Short-circuit the front-end gate.
		 *
		 * Return null to leave the decision to the setting, true to let this visitor in, or
		 * false to show them the gate. Returning null rather than a boolean means a filter
		 * never has to know what the setting says.
		 *
		 * @param bool|null $allowed Whether to allow, or null for the default.
		 */
		$allowed = apply_filters( 'callboard_can_view', null );

		if ( null === $allowed ) {
			if ( ! self::required() ) {
				return true;
			}
			return self::capability_required() ? current_user_can( Roles::VIEW ) : is_user_logged_in();
		}

		return (bool) $allowed;
	}

	/**
	 * Whether the setting asks for a signed-in user.
	 */
	public static function required(): bool {
		return (bool) Settings::get( 'require_signin' );
	}

	/**
	 * Whether a signed-in user also needs the `view_callboard` capability. Only applies when sign-in is required.
	 *
	 * @since 2.3.0
	 */
	public static function capability_required(): bool {
		return self::required() && (bool) Settings::get( 'require_access' );
	}

	/**
	 * Where to send somebody to sign in, and back again afterwards.
	 */
	public static function login_url(): string {
		$request = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$path    = (string) wp_parse_url( $request, PHP_URL_PATH );

		return wp_login_url( home_url( $path ) );
	}
}
