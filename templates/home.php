<?php
/**
 * Home: every set.
 *
 * @package Callboard
 * @var array<string, mixed> $args
 */

defined( 'ABSPATH' ) || exit;

$callboard_sets = Callboard\Sets::all();
?>
<main class="app" id="main" tabindex="-1">
	<header class="masthead">
		<div class="masthead-row">
			<div class="masthead-text">
				<h1><?php bloginfo( 'name' ); ?></h1>
			</div>
			<?php if ( Callboard\Settings::get( 'push' ) && Callboard\Push::available() ) : ?>
			<button type="button" class="btn btn-quiet btn-icon bell" id="notify" data-state="" aria-pressed="false" aria-label="<?php esc_attr_e( 'Notify me about new sets', 'callboard' ); ?>"><?php echo callboard_icon( 'bell' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG shipped with the plugin. ?></button>
			<?php endif; ?>
		</div>
	</header>

	<?php callboard_template( 'board' ); ?>

	<?php if ( ! empty( $args['not_found'] ) ) : ?>
		<p class="note note-404"><?php esc_html_e( "That page isn't here. Everything we have is below.", 'callboard' ); ?></p>
	<?php endif; ?>

	<?php if ( ! $callboard_sets ) : ?>
		<p class="note"><?php esc_html_e( 'Nothing here yet.', 'callboard' ); ?></p>
	<?php else : ?>
	<ul class="sets">
		<?php foreach ( $callboard_sets as $callboard_s ) : ?>
		<li>
			<a class="set" href="<?php echo esc_url( home_url( '/' . $callboard_s['slug'] . '/' ) ); ?>">
				<?php
				if ( ! empty( $callboard_s['cover'] ) ) :
					?>
					<img class="set-art" src="<?php echo esc_url( $callboard_s['cover'] ); ?>" alt="" width="56" height="56" loading="lazy" decoding="async">
					<?php
else :
	?>
					<span class="set-mark" aria-hidden="true"><?php echo esc_html( mb_strtoupper( mb_substr( $callboard_s['name'], 0, 1 ) ) ); ?></span><?php endif; ?>
				<span class="set-text">
					<span class="set-name" style="view-transition-name:set-<?php echo esc_attr( $callboard_s['slug'] ); ?>"><?php echo esc_html( $callboard_s['name'] ); ?></span>
					<span class="set-meta"><span class="set-resume" data-slug="<?php echo esc_attr( $callboard_s['slug'] ); ?>"></span><span class="set-count"><?php echo esc_html( $callboard_s['meta'] ); ?></span></span>
				</span>
				<span class="set-off" data-slug="<?php echo esc_attr( $callboard_s['slug'] ); ?>" data-state=""><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle class="dl-track" cx="12" cy="12" r="9"/><circle class="dl-ring" cx="12" cy="12" r="9"/><path class="dl-check" d="M7.5 12.5l3 3 6-6.5"/></svg></span>
				<span class="set-go" aria-hidden="true"></span>
			</a>
		</li>
		<?php endforeach; ?>
	</ul>
	<?php endif; ?>

	<?php if ( Callboard\Settings::get( 'offline' ) ) : ?>
	<p class="open-set">
		<label class="btn btn-quiet load-files" id="open-set-label" for="open-set"><?php esc_html_e( 'Open a set file', 'callboard' ); ?></label>
		<input type="file" id="open-set" class="load-input" multiple>
	</p>
	<?php endif; ?>

	<?php callboard_template( 'footer', array( 'set' => null ) ); ?>
</main>
