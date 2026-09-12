<?php
/**
 * Site-specific touches live here, not in code.
 *
 * @package Callboard
 */

namespace Callboard;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings.
 */
final class Settings {

	public const OPTION = 'callboard_settings';

	/**
	 * Defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'tagline'         => __( 'Rehearsal tracks', 'callboard' ),
			'footer_note'     => __( 'For rehearsal use only.', 'callboard' ),
			'badge'           => '',
			'accent'          => '',
			'confetti'        => '',
			'hearts'          => false,
			'show_hint'       => true,
			'offline'         => true,
			'push'            => true,
			'notify_new_sets' => true,
			'notify_calls'    => true,
			'count_in'        => false,
			'practice'        => false,
			'require_signin'  => false,
			'require_access'  => false,
		);
	}

	/**
	 * All settings, merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		return array_merge( self::defaults(), (array) get_option( self::OPTION, array() ) );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting key.
	 */
	public static function get( string $key ): mixed {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * Sanitize on save.
	 *
	 * @param mixed $input Raw option value.
	 * @return array<string, mixed>
	 */
	public static function sanitize( mixed $input ): array {
		$input = is_array( $input ) ? $input : array();
		return array(
			'tagline'         => sanitize_text_field( $input['tagline'] ?? '' ),
			'footer_note'     => sanitize_text_field( $input['footer_note'] ?? '' ),
			'badge'           => mb_substr( sanitize_text_field( $input['badge'] ?? '' ), 0, 4 ),
			'accent'          => (string) sanitize_hex_color( trim( (string) ( $input['accent'] ?? '' ) ) ),
			'confetti'        => mb_substr( sanitize_text_field( $input['confetti'] ?? '' ), 0, 12 ),
			'hearts'          => ! empty( $input['hearts'] ),
			'show_hint'       => ! empty( $input['show_hint'] ),
			'offline'         => ! empty( $input['offline'] ),
			'push'            => ! empty( $input['push'] ),
			'notify_new_sets' => ! empty( $input['notify_new_sets'] ),
			'notify_calls'    => ! empty( $input['notify_calls'] ),
			'count_in'        => ! empty( $input['count_in'] ),
			'practice'        => ! empty( $input['practice'] ),
			'require_signin'  => ! empty( $input['require_signin'] ),
			'require_access'  => ! empty( $input['require_access'] ),
		);
	}

	/**
	 * The subset the front-end script needs.
	 *
	 * @return array<string, mixed>
	 */
	public static function for_client(): array {
		$s = self::all();
		return array(
			'badge'    => $s['badge'],
			'confetti' => $s['confetti'],
			'hearts'   => (bool) $s['hearts'],
			'hint'     => (bool) $s['show_hint'],
			'offline'  => (bool) $s['offline'],
			'push'     => (bool) $s['push'] && Push::available(),
			'count_in' => (bool) $s['count_in'],
		);
	}
}
