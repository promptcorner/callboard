<?php
/**
 * One set: header, track list, credits.
 *
 * @package Callboard
 * @var array<string, mixed> $args
 */

defined( 'ABSPATH' ) || exit;

$callboard_set   = $args['set'];
$callboard_badge = Callboard\Settings::get( 'badge' );
?>
<main class="app" id="main" tabindex="-1">
	<header class="masthead">
		<a class="back" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'All sets', 'callboard' ); ?></a>
		<h1 style="view-transition-name:set-<?php echo esc_attr( $callboard_set['slug'] ); ?>"><?php echo esc_html( $callboard_set['name'] ); ?></h1>
		<p class="label"><?php echo esc_html( $callboard_set['meta'] ); ?></p>
		<?php if ( $callboard_set['tracks'] ) : ?>
		<div class="actions">
			<button type="button" class="btn" id="play-all"><?php esc_html_e( 'Play all', 'callboard' ); ?></button>
			<?php if ( Callboard\Settings::get( 'offline' ) ) : ?>
			<button type="button" class="btn btn-quiet" id="offline"><?php esc_html_e( 'Save offline', 'callboard' ); ?><span class="size"> · <?php echo esc_html( callboard_size( (int) array_sum( array_column( $callboard_set['tracks'], 'bytes' ) ) ) ); ?></span></button>
			<?php endif; ?>
			<button type="button" class="btn btn-quiet btn-icon" id="share" aria-label="<?php esc_attr_e( 'Share a link to this set', 'callboard' ); ?>"><?php echo callboard_icon( 'share' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG shipped with the plugin. ?></button>
			<?php if ( Callboard\Settings::get( 'offline' ) ) : ?>
				<?php // Rendered, not revealed: a label hidden until the script runs leaves the input with no accessible name. The script hides it where there is no cache to fill. ?>
				<label class="btn btn-quiet load-files" id="load-label" for="load-files"><?php esc_html_e( 'Load from files', 'callboard' ); ?></label>
				<?php // No accept filter: an iPhone greys out a .callboard file under one, and Android hides USB drives under audio/*. ?>
				<input type="file" id="load-files" class="load-input" multiple>
				<button type="button" class="btn btn-quiet" id="set-file" hidden><?php esc_html_e( 'Save set file', 'callboard' ); ?></button>
			<?php endif; ?>
			<?php /** Slot set_header: extension controls beside the set's own. */ ?>
			<?php callboard_slot( 'set_header', $callboard_set ); ?>
		</div>
		<?php endif; ?>
	</header>

	<?php if ( ! $callboard_set['tracks'] ) : ?>
		<p class="note"><?php esc_html_e( 'No audio in this set yet.', 'callboard' ); ?></p>
	<?php else : ?>
	<ol class="tracks" id="tracks" aria-label="<?php esc_attr_e( 'Tracks', 'callboard' ); ?>">
		<?php foreach ( $callboard_set['tracks'] as $callboard_i => $callboard_t ) : ?>
		<li>
			<button type="button" class="track" data-i="<?php echo (int) $callboard_i; ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: track title. */ __( 'Play %s', 'callboard' ), $callboard_t['title'] ) ); ?>">
				<span class="num"><span class="digits"><?php echo esc_html( str_pad( (string) $callboard_t['index'], 2, '0', STR_PAD_LEFT ) ); ?></span><span class="eq" aria-hidden="true"><i></i><i></i><i></i></span></span>
				<?php $callboard_has_lyrics = isset( $callboard_set['lyrics']->{$callboard_t['id']} ) || ( is_array( $callboard_set['lyrics'] ) && isset( $callboard_set['lyrics'][ $callboard_t['id'] ] ) ); ?>
				<span class="title"><?php echo esc_html( $callboard_t['title'] ); ?><?php echo $callboard_has_lyrics ? ' <span class="has-lyrics">' . esc_html__( 'lyrics', 'callboard' ) . '</span>' : ''; ?></span>
				<?php /** Slot track_meta: extension items in the row's second line, beside the artist. */ ?>
				<?php $callboard_row_meta = callboard_get_slot( 'track_meta', $callboard_t, $callboard_set ); ?>
				<?php echo ! empty( $callboard_t['artist'] ) || '' !== $callboard_row_meta ? '<span class="by">' . ( ! empty( $callboard_t['artist'] ) ? '<span class="by-name">' . esc_html( $callboard_t['artist'] ) . '</span>' : '' ) . $callboard_row_meta . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- slot items are escaped by the registry. ?><span class="len"><?php echo $callboard_badge ? '<span class="hh" aria-hidden="true">' . esc_html( $callboard_badge ) . '</span>' : ''; ?><?php /** Slot track_badges: extension items before the track's length. */ callboard_slot( 'track_badges', $callboard_t, $callboard_set ); ?><?php echo esc_html( callboard_fmt( $callboard_t['duration'] ) ); ?></span>
			</button>
			<?php if ( Callboard\Settings::get( 'offline' ) ) : ?>
			<button type="button" class="dl" data-i="<?php echo (int) $callboard_i; ?>" data-state="" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: track title. */ __( 'Save %s offline', 'callboard' ), $callboard_t['title'] ) ); ?>"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle class="dl-track" cx="12" cy="12" r="9"/><circle class="dl-ring" cx="12" cy="12" r="9"/><path class="dl-arrow" d="M12 7v8m0 0l-3.5-3.5M12 15l3.5-3.5"/><path class="dl-check" d="M7.5 12.5l3 3 6-6.5"/></svg></button>
			<?php endif; ?>
		</li>
		<?php endforeach; ?>
	</ol>
	<?php endif; ?>

	<?php callboard_template( 'footer', array( 'set' => $callboard_set ) ); ?>
</main>
