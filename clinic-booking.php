<?php
/**
 * Plugin Name: Clinic Booking – حجز مواعيد العيادة
 * Description: صفحة عيادة + حجز مواعيد (أكثر من دكتور) + لوحة تحكم + شات بوت + إشعارات واتساب/إيميل + دفع بتحويل بنكي. عربي / עברית / English. سهل التخصيص من الإعدادات.
 * Version: 1.0.0
 * Author: Clinic Booking
 * Text Domain: clinic-booking
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) exit;

define('CB_VERSION', '1.0.0');
define('CB_DIR', plugin_dir_path(__FILE__));
define('CB_URL', plugin_dir_url(__FILE__));

foreach (['i18n', 'settings'] as $f) {
    require_once CB_DIR . "includes/class-$f.php";
}

register_activation_hook(__FILE__, ['CB_DB', 'activate']);
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('cb_send_reminders');
});

add_action('plugins_loaded', function () {
    CB_I18n::init();
});
