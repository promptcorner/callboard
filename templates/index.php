<?php
/**
 * App shell. Rendered for every front-end request.
 *
 * @package Callboard
 */

defined( 'ABSPATH' ) || exit;

$callboard_view = Callboard\Router::view();
$callboard_set  = $callboard_view ? Callboard\Sets::by_slug( $callboard_view ) : null;
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<meta name="referrer" content="no-referrer">
	<?php wp_head(); ?>
</head>
<body <?php body_class( $callboard_set ? 'view-set' : 'view-home' ); ?> data-slug="<?php echo esc_attr( (string) $callboard_view ); ?>">
<?php wp_body_open(); ?>
<nav class="skip-links" aria-label="<?php esc_attr_e( 'Skip links', 'callboard' ); ?>">
	<a class="skip-link" href="#main"><?php esc_html_e( 'Skip to content', 'callboard' ); ?></a>
</nav>
<div class="topbar" aria-hidden="true"><span class="topbar-title" id="topbar-title"><?php echo esc_html( Callboard\Router::page_title() ); ?></span></div>
<?php
callboard_template(
	$callboard_set ? 'set' : 'home',
	array(
		'set'       => $callboard_set,
		'not_found' => null === $callboard_view,
	)
);
?>
<?php callboard_template( 'deck' ); ?>
<?php wp_footer(); ?>
</body>
</html>
