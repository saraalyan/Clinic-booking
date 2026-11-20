<?php
/** Stand-alone page for [clinic_dashboard]: no theme header, footer or sidebar. */
if (!defined('ABSPATH')) exit;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html(CB_Settings::loc('clinic_name', CB_I18n::admin_lang())); ?></title>
<?php wp_head(); ?>
</head>
<body class="cb-front">
<?php
while (have_posts()) {
    the_post();
    echo do_shortcode(get_the_content());
}
wp_footer();
?>
</body>
</html>
