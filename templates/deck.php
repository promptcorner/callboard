<?php
/**
 * Persistent player and the Home Screen hint. Lives outside #main so it survives view swaps.
 *
 * @package Callboard
 */

defined( 'ABSPATH' ) || exit;
?>
<?php
/*
 * Two states, remembered in localStorage (see `ls` and `setDeckView()` in app.js): compact (title, artist,
 * play/pause) and expanded (everything, plus the wave, times, and chips). The JS class lands on #deck
 * before first paint reads it, so there is no compact->expanded flash on load.
 */
?>
<section class="deck" id="deck" aria-label="<?php esc_attr_e( 'Player', 'callboard' ); ?>" hidden>
	<i class="deck-glow-halo" id="deck-glow-halo" aria-hidden="true"></i><i class="deck-glow" id="deck-glow" aria-hidden="true"></i><i class="deck-glow-hot" id="deck-glow-hot" aria-hidden="true"></i>
	<?php /* Now Playing: the expanded deck is a full screen, not a taller bar. Same controls, same element — only the layout changes, so nothing has two copies of its state. */ ?>
	<?php /* Grabber bar. Click, Enter or Space closes Now Playing; so does dragging it down (app.js), Escape, and the browser's back. */ ?>
	<button type="button" class="deck-down" id="deck-down" aria-label="<?php esc_attr_e( 'Close player', 'callboard' ); ?>"><i aria-hidden="true"></i></button>
	<div class="deck-top"><button type="button" class="remote-chip is-away" id="remote" data-state="" aria-label="<?php esc_attr_e( 'Play on another device', 'callboard' ); ?>"><?php echo callboard_icon( 'cast' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG shipped with the plugin. ?></button></div>
	<div class="deck-cover" aria-hidden="true"><img id="deck-cover" alt="" draggable="false" decoding="async" sizes="(max-width:700px) 74vw, 380px"></div>
	<div class="deck-inner deck-display">
		<div class="deck-text">
			<?php /* Doubles as the compact bar's tap-to-expand target; app.js switches its job (and label) by deck state. */ ?>
			<button type="button" class="deck-open" id="deck-open" aria-expanded="false" aria-controls="deck" aria-label="<?php esc_attr_e( 'Show current track', 'callboard' ); ?>" data-label-expand="<?php esc_attr_e( 'Expand player', 'callboard' ); ?>">
				<span class="deck-title" id="now-title" aria-live="polite"><span class="mq"><span><?php esc_html_e( 'Choose a track', 'callboard' ); ?></span></span></span>
			</button>
			
		</div>
	</div>
	<div class="seek-wrap">
		<canvas class="wave wave-base" id="wave-base" aria-hidden="true"></canvas><canvas class="wave wave-hover" id="wave-hover" aria-hidden="true"></canvas><div class="wave-reveal" id="wave-reveal" aria-hidden="true"><canvas class="wave wave-played" id="wave-played"></canvas></div>
		<span class="seek-line" aria-hidden="true"><i class="seek-fill" id="seek-fill"></i></span><i class="seek-knob" id="seek-knob" aria-hidden="true"></i>
		<input type="range" id="seek" min="0" max="1000" value="0" step="1" aria-label="<?php esc_attr_e( 'Seek', 'callboard' ); ?>" aria-valuetext="0:00">
	</div>
	<div class="deck-inner deck-times"><span class="deck-time" data-offline="<?php esc_attr_e( 'Offline', 'callboard' ); ?>"><span id="cur">0:00</span><span class="sep" aria-hidden="true"> / </span><?php /* Now Playing metadata: filled by extensions through nowPlayingMeta on every track, hidden while empty. */ ?><span class="deck-meta" id="deck-meta" hidden></span><span id="dur">0:00</span></span></div>
	<div class="deck-inner deck-transport">
		<div class="deck-controls">
			<div class="deck-controls-secondary">
				<button type="button" class="ctl repeat-toggle" id="repeat" data-mode="off" aria-pressed="false" aria-label="<?php esc_attr_e( 'Repeat off', 'callboard' ); ?>" data-label-off="<?php esc_attr_e( 'Repeat off', 'callboard' ); ?>" data-label-set="<?php esc_attr_e( 'Repeat the set', 'callboard' ); ?>" data-label-one="<?php esc_attr_e( 'Repeat this track', 'callboard' ); ?>"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M17 2l4 4-4 4M3 12v-2a4 4 0 0 1 4-4h14M7 22l-4-4 4-4M21 12v2a4 4 0 0 1-4 4H3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><text class="repeat-one" x="12" y="15.5" font-size="7.5" text-anchor="middle" fill="currentColor" stroke="none">1</text></svg></button>
				<?php /** Slot transport: extension controls beside repeat. */ ?>
				<?php callboard_slot( 'transport' ); ?>
			</div>
			<div class="deck-controls-primary">
				<button type="button" class="ctl skip" id="prev" aria-label="<?php esc_attr_e( 'Previous', 'callboard' ); ?>"><?php echo callboard_icon( 'prev' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG shipped with the plugin. ?></button>
				<button type="button" class="ctl play" id="toggle" data-state="play" aria-label="<?php esc_attr_e( 'Play', 'callboard' ); ?>"><i class="cap" aria-hidden="true"><svg class="pp" viewBox="0 0 36 36" aria-hidden="true" focusable="false"><path class="glyph" d="M 12,26 18.5,22 18.5,14 12,10 z M 18.5,22 25,18 25,18 18.5,14 z"/><path class="glyph-pause" d="M 11,10 15,10 15,26 11,26 z M 20,10 24,10 24,26 20,26 z"/></svg></i></button>
				<button type="button" class="ctl skip" id="next" aria-label="<?php esc_attr_e( 'Next', 'callboard' ); ?>"><?php echo callboard_icon( 'next' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG shipped with the plugin. ?></button>
			</div>
		</div>
		<audio id="audio" preload="metadata"></audio>
	</div>
	<?php /* Expanded only: which set this came out of, the way a player tells you what you are inside. */ ?>
	<p class="deck-from"><button type="button" id="deck-from"><span><?php esc_html_e( 'Playing from', 'callboard' ); ?></span><b id="deck-from-set"></b></button></p>
</section>

<button type="button" class="update" id="update" hidden><span><?php esc_html_e( 'Updated', 'callboard' ); ?></span><span class="update-go"><?php esc_html_e( 'Reload', 'callboard' ); ?></span></button>
<p class="toast" id="toast" role="status" hidden></p>
<div class="a2hs" id="a2hs" hidden role="status">
	<?php echo callboard_icon( 'share' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG shipped with the plugin. ?>
	<p id="a2hs-text"><?php echo wp_kses( __( 'Keep this on your phone: tap <strong>Share</strong>, then <strong>Add to Home Screen</strong>.', 'callboard' ), array( 'strong' => array() ) ); ?></p>
	<button type="button" class="btn btn-small" id="a2hs-go" hidden></button>
	<button type="button" class="a2hs-x" id="a2hs-close" aria-label="<?php esc_attr_e( 'Dismiss', 'callboard' ); ?>">&times;</button>
</div>

<?php /** Slot panels: extension panels, outside #main so they last across views like the deck. */ ?>
<?php callboard_slot( 'panels' ); ?>
