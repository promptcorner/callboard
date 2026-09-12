<?php
/**
 * The gate. Shown in place of the app when the site asks for a signed-in user, or for one with access.
 *
 * Rendered here rather than redirecting to wp-login.php: a redirect out of an installed app
 * drops the cast out of the shell and into WordPress branding mid-session. This is the app's
 * own page, and signing in is a link they choose to follow.
 *
 * @package Callboard
 */

defined( 'ABSPATH' ) || exit;

$callboard_gate_panel = static function (): void {
	?>
	<main class="app gate" id="main">
		<h1><?php echo esc_html( callboard_site_name() ); ?></h1>
		<?php if ( is_user_logged_in() ) : ?>
			<p class="label"><?php esc_html_e( 'This account does not have access to the rehearsal tracks.', 'callboard' ); ?></p>
			<p><a class="btn" href="<?php echo esc_url( wp_logout_url( Callboard\Gate::login_url() ) ); ?>"><?php esc_html_e( 'Sign in with another account', 'callboard' ); ?></a></p>
		<?php else : ?>
			<p class="label"><?php esc_html_e( 'Sign in to open the rehearsal tracks.', 'callboard' ); ?></p>
			<p><a class="btn" href="<?php echo esc_url( Callboard\Gate::login_url() ); ?>"><?php esc_html_e( 'Sign in', 'callboard' ); ?></a></p>
		<?php endif; ?>
	</main>
	<?php
};

if ( Callboard\Router::is_fragment() ) {
	status_header( 403 );
	header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
	header( 'Cache-Control: no-store' ); // Never a copy of the gate in the worker's cache.
	$callboard_gate_panel();
	return;
}

status_header( 403 );
nocache_headers();
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<meta name="referrer" content="no-referrer">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'view-gate' ); ?>>
<?php $callboard_gate_panel(); ?>
<?php wp_footer(); ?>
</body>
</html>
