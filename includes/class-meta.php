<?php
/**
 * Every piece of post meta Callboard stores, declared in one place.
 *
 * @package Callboard
 */

namespace Callboard;

defined( 'ABSPATH' ) || exit;

/**
 * The data model.
 *
 * Nothing here changes what is written — the classes that own each field already sanitize on the way
 * in. What registering buys is that the fields stop being anonymous strings in a table: WordPress
 * learns their types, hides them from the custom-fields box, applies an auth callback before anyone
 * edits one, and gives a reader somewhere to look that is not a grep.
 *
 * `show_in_rest` is false on every one of them, deliberately. A set's contents are the reason the
 * gate in Gate exists, and the subscriber fields below are live Web Push credentials — an endpoint
 * and the keys that sign for it. None of that belongs on a public endpoint, and the plugin already
 * closes anonymous REST elsewhere. Registering the meta without saying so would quietly reopen the
 * question every time somebody adds a field.
 */
final class Meta {

	/**
	 * Hook registration. `init` at the default priority, after the post types are registered.
	 */
	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'register' ), 11 );
	}

	/**
	 * Register every field against the post type that owns it.
	 */
	public static function register(): void {
		foreach ( self::map() as $post_type => $fields ) {
			foreach ( $fields as $key => $field ) {
				register_post_meta(
					$post_type,
					$key,
					array(
						'single'            => true,
						'type'              => $field['type'],
						'description'       => $field['description'],
						'show_in_rest'      => false,
						// Wrapped, always: WordPress passes the callback four arguments (value, key, object
						// type, subtype) and PHP's own single-arity functions — floatval, absint —
						// throw ArgumentCountError on the extras.
						'sanitize_callback' => isset( $field['sanitize'] )
							? static fn( $value ) => call_user_func( $field['sanitize'], $value )
							: null,
						'auth_callback'     => static fn( bool $allowed, string $meta_key, int $post_id ): bool => current_user_can( 'edit_post', $post_id ),
					)
				);
			}
		}
	}

	/**
	 * The whole model: post type, then field, then what it is.
	 *
	 * Arrays carry no sanitize callback. Each is written through a dedicated sanitizer that
	 * understands its shape — Importer::sanitize_notes() and sanitize_cues(), Calls' own save — and a
	 * second pass here would have to re-derive that shape from nothing and would flatten it.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	public static function map(): array {
		$text = 'sanitize_text_field';
		$url  = 'esc_url_raw';

		return array(
			Post_Types::SET => array(
				'_callboard_credits'         => array(
					'type'        => 'array',
					'description' => 'Where the set came from: playlist URL, curator, curator URL.',
				),
				'_callboard_lyrics_approved' => array(
					'type'        => 'integer',
					'description' => 'Whether somebody has read the imported captions and vouched for them. Lyrics stay hidden until they have.',
					'sanitize'    => 'absint',
				),
				'_callboard_tint'            => array(
					'type'        => 'string',
					'description' => 'One colour taken from the cover by Art::tint(), as #rrggbb, or "none" when GD could not read it. Cleared when the cover changes.',
					'sanitize'    => $text,
				),
				'_callboard_palette'         => array(
					'type'        => 'string',
					'description' => 'Which theatre palette the drawn cover uses. Empty means the set picks one from its own name.',
					'sanitize'    => 'sanitize_key',
				),
				'_callboard_art_drawn'       => array(
					'type'        => 'string',
					'description' => 'Fingerprint of the name, track count and length the drawn artwork was made from. A mismatch redraws it; its absence means the artwork is somebody else\'s and is left alone.',
					'sanitize'    => $text,
				),
				'_callboard_cover_image'     => array(
					'type'        => 'integer',
					'description' => 'Attachment ID of the set cover. Also the featured image.',
					'sanitize'    => 'absint',
				),
				'_callboard_share_image'     => array(
					'type'        => 'integer',
					'description' => 'Attachment ID of the link-preview card.',
					'sanitize'    => 'absint',
				),
			),

			// Tracks are attachments, so their fields live on the attachment rather than on a post
			// type of their own. That is what lets the media library, and every plugin that already
			// understands attachments, see them.
			'attachment'    => array(
				'_callboard_duration'     => array(
					'type'        => 'number',
					'description' => 'Length in seconds, as the importer measured it.',
					'sanitize'    => 'floatval',
				),
				'_callboard_bpm'          => array(
					'type'        => 'integer',
					'description' => 'Beats per minute for the count-in. Zero where no usable tempo was found.',
					'sanitize'    => 'absint',
				),
				'_callboard_notes'        => array(
					'type'        => 'array',
					'description' => 'Director\'s notes: a time in seconds, the note, and the date it was given.',
				),
				'_callboard_practice'     => array(
					'type'        => 'array',
					'description' => 'Anonymous practice counts by hour in the site time zone: { "Y-m-d H": { opens, loops, seconds } }. Written only while the Count practice setting is on.',
				),
				'_callboard_lyrics'       => array(
					'type'        => 'array',
					'description' => 'Caption cues as [start, end, text], from the source\'s own auto-captions.',
				),
				'_callboard_levels'       => array(
					'type'        => 'string',
					'description' => 'Loudness envelope: one digit 0-9 per tenth of a second. Drives the waveform and the filament where the browser cannot analyse audio live.',
					'sanitize'    => static fn( $v ): string => (string) preg_replace( '/[^0-9]/', '', (string) $v ),
				),
				'_callboard_video_id'     => array(
					'type'        => 'string',
					'description' => 'The source\'s own id for this track, and the key a .callboard file matches on.',
					'sanitize'    => $text,
				),
				'_callboard_source_url'   => array(
					'type'        => 'string',
					'description' => 'Where the audio came from, kept so a set can be traced back to its source.',
					'sanitize'    => $url,
				),
				'_callboard_source_mtime' => array(
					'type'        => 'string',
					'description' => 'Modification time of the file this attachment was made from, so a re-import can tell whether it changed.',
					'sanitize'    => $text,
				),
				'_callboard_uploader'     => array(
					'type'        => 'string',
					'description' => 'Who the track is by, for the credit line.',
					'sanitize'    => $text,
				),
				'_callboard_uploader_url' => array(
					'type'        => 'string',
					'description' => 'Where to credit them.',
					'sanitize'    => $url,
				),
				'_callboard_codec'        => array(
					'type'        => 'string',
					'description' => 'What the fetcher actually got. Provenance, not a measurement of the file.',
					'sanitize'    => $text,
				),
				'_callboard_reencoded'    => array(
					'type'        => 'integer',
					'description' => 'Whether the fetcher had to re-encode to get it, rather than keeping what the source served.',
					'sanitize'    => 'absint',
				),
			),

			Calls::TYPE     => array(
				'_callboard_when'    => array(
					'type'        => 'string',
					'description' => 'When the call is, as Y-m-d H:i in the site\'s time zone. A call without one is a plain notice.',
					'sanitize'    => $text,
				),
				'_callboard_where'   => array(
					'type'        => 'string',
					'description' => 'Where to be.',
					'sanitize'    => $text,
				),
				'_callboard_numbers' => array(
					'type'        => 'array',
					'description' => 'Attachment IDs of the tracks being worked, so a call can link straight to them.',
				),
			),

			Requests::TYPE  => array(
				'_callboard_url'    => array(
					'type'        => 'string',
					'description' => 'The URL queued for fetching.',
					'sanitize'    => $url,
				),
				'_callboard_slug'   => array(
					'type'        => 'string',
					'description' => 'Slug the fetched set will be created under.',
					'sanitize'    => 'sanitize_title',
				),
				'_callboard_status' => array(
					'type'        => 'string',
					'description' => 'queued, running, done or failed.',
					'sanitize'    => 'sanitize_key',
				),
				'_callboard_log'    => array(
					'type'        => 'string',
					'description' => 'What the fetch tool said, kept so a failure can be read without SSH.',
				),
			),

			// Live Web Push credentials. Never exposed, never in REST, and the reason the blanket
			// show_in_rest above is a decision rather than a default.
			Push::TYPE      => array(
				'_callboard_endpoint' => array(
					'type'        => 'string',
					'description' => 'Push service endpoint for one subscribed browser.',
					'sanitize'    => $url,
				),
				'_callboard_p256dh'   => array(
					'type'        => 'string',
					'description' => 'That subscription\'s public key.',
					'sanitize'    => $text,
				),
				'_callboard_auth'     => array(
					'type'        => 'string',
					'description' => 'That subscription\'s auth secret.',
					'sanitize'    => $text,
				),
				'_callboard_ua'       => array(
					'type'        => 'string',
					'description' => 'The browser that subscribed, truncated, so a stale subscription can be recognised.',
					'sanitize'    => $text,
				),
			),
		);
	}
}
