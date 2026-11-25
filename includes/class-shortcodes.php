<?php
if (!defined('ABSPATH')) exit;

/**
 * [clinic_page]      full clinic landing page (hero + services + booking + hours + contact)
 * [clinic_booking]   booking widget only
 * [clinic_services]  services grid
 * [clinic_hours]     working hours
 * [clinic_contact]   location + contact + social
 * [clinic_chatbot]   chatbot (auto-added in footer when "floating" is enabled)
 */
class CB_Shortcodes {
    private static $assets = false, $bot_assets = false, $bot_printed = false, $dash = false;

    static function init() {
        foreach (['page', 'booking', 'services', 'doctors', 'hours', 'contact', 'chatbot', 'dashboard'] as $s) add_shortcode("clinic_$s", [__CLASS__, "sc_$s"]);
        add_action('wp_enqueue_scripts', function () {
            self::register();
            if (self::is_dashboard_page()) { self::$dash = true; CB_Admin::enqueue(true); return; }
            if (CB_Settings::get('bot_enabled') && CB_Settings::get('bot_floating')) self::bot_assets();
        });
        // the dashboard page is rendered stand-alone (no theme header/footer/sidebar) and is never cached or indexed
        add_filter('template_include', function ($t) {
            if (!self::is_dashboard_page()) return $t;
            nocache_headers();
            return CB_DIR . 'templates/dashboard-page.php';
        });
        add_filter('show_admin_bar', function ($v) { return self::is_dashboard_page() ? false : $v; });
        add_action('wp_footer', function () {
            if (CB_Settings::get('bot_enabled') && CB_Settings::get('bot_floating') && !self::$bot_printed && !self::$dash) echo self::chatbot_html();
        });
        add_action('wp_head', function () {
            $p = CB_Settings::get('primary'); $a = CB_Settings::get('accent');
            echo '<style>:root{--cb-primary:' . esc_attr($p) . ';--cb-accent:' . esc_attr($a) . ';--cb-font:' . self::font_family() . '}</style>';
        });
    }

    /** Font choice (Settings → Appearance). Rubik covers Arabic, Hebrew and Latin in one family. */
    static function fonts() {
        return [
            'hayyakum' => ['Hayyakum (خط العيادة – مدمج، بدون اتصال بجوجل)', '', '"Hayyakum"'],
            'plex' => ['IBM Plex Sans (عربي + עברית + English) – موصى به', 'IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=IBM+Plex+Sans+Hebrew:wght@400;500;600;700&family=IBM+Plex+Sans:wght@400;500;600;700', '"IBM Plex Sans Arabic","IBM Plex Sans Hebrew","IBM Plex Sans"'],
            'cairo' => ['Cairo (عربي + English، حديث وواضح)', 'Cairo:wght@400;500;600;700', '"Cairo"'],
            'almarai' => ['Almarai (عربي + English، هادئ للقراءة)', 'Almarai:wght@400;700', '"Almarai"'],
            'rubik' => ['Rubik (عربي + עברית + English)', 'Rubik:wght@400;500;600;700', '"Rubik"'],
            'tajawal' => ['Tajawal (عربي + English)', 'Tajawal:wght@400;500;700', '"Tajawal"'],
            'system' => ['خط النظام – بدون اتصال بخوادم جوجل', '', ''],
        ];
    }

    static function font_url() {
        $f = self::fonts()[CB_Settings::get('font')] ?? self::fonts()['hayyakum'];
        return $f[1] ? 'https://fonts.googleapis.com/css2?family=' . $f[1] . '&display=swap' : '';
    }

    static function font_family() {
        $f = self::fonts()[CB_Settings::get('font')] ?? self::fonts()['hayyakum'];
        return ($f[2] ? $f[2] . ',' : '') . '"Segoe UI",Tahoma,Arial,"Noto Sans Arabic","Noto Sans Hebrew",sans-serif';
    }

    static function is_dashboard_page() {
        if (!is_singular()) return false;
        $p = get_post(get_queried_object_id());
        return $p && has_shortcode($p->post_content, 'clinic_dashboard');
    }

    static function sc_dashboard() {
        $base = get_permalink();
        if ($base && get_option('cb_dashboard_url') !== $base) update_option('cb_dashboard_url', $base);
        if (!is_user_logged_in()) return self::login_box($base);
        if (!current_user_can('manage_clinic')) {
            return '<div class="cb-front-login"><h2>' . esc_html(CB_I18n::t('a_no_access', CB_I18n::admin_lang())) . '</h2><p><a href="' . esc_url(wp_logout_url($base)) . '">' . esc_html(CB_I18n::t('a_logout', CB_I18n::admin_lang())) . '</a></p></div>';
        }
        CB_Admin::enqueue(true);
        ob_start();
        CB_Admin::render_front($base);
        return ob_get_clean();
    }

    private static function login_box($base) {
        $L = CB_I18n::admin_lang();
        $logo = CB_Settings::get('logo');
        $h = '<div class="cb-front-login" dir="' . CB_I18n::dir($L) . '">' . ($logo ? '<img src="' . esc_url($logo) . '" alt="">' : '<span class="cb-heart">♥</span>');
        $h .= '<h2>' . esc_html(CB_Settings::loc('clinic_name', $L)) . '</h2><p>' . esc_html(CB_I18n::t('a_login_title', $L)) . '</p>';
        $h .= wp_login_form(['echo' => false, 'redirect' => $base, 'label_username' => CB_I18n::t('a_login_user', $L), 'label_password' => CB_I18n::t('a_login_pass', $L), 'label_remember' => CB_I18n::t('a_login_remember', $L), 'label_log_in' => CB_I18n::t('a_login_btn', $L)]);
        return $h . '</div>';
    }

    static function data() {
        $lang = CB_I18n::front_lang();
        return [
            'rest' => esc_url_raw(rest_url(CB_Rest::NS . '/')), 'lang' => $lang, 'dir' => CB_I18n::dir($lang),
            'locale' => CB_I18n::locale($lang), 'currency' => CB_Settings::get('currency'),
            'phone' => CB_Settings::get('phone'), 'wa' => preg_replace('/\D+/', '', (string)CB_Settings::get('whatsapp')),
            'verify' => CB_Verify::method(), 'pay' => CB_Payments::enabled(),
            'clinic' => CB_Settings::loc('clinic_name', $lang), 'bookUrl' => get_option('cb_booking_url') ?: '',
            's' => CB_I18n::bundle($lang, ['b_', 'c_', 'p_']),
        ];
    }

    /** Idempotent. Block themes render page content before wp_enqueue_scripts fires, so shortcodes must be able to register their own handles. */
    static function register() {
        if (self::font_url() && !wp_style_is('cb-font', 'registered')) wp_register_style('cb-font', self::font_url(), [], null);
        if (!wp_style_is('cb-front', 'registered')) wp_register_style('cb-front', CB_URL . 'assets/css/front.css', self::font_url() ? ['cb-font'] : [], CB_VERSION);
        if (!wp_script_is('cb-front', 'registered')) wp_register_script('cb-front', CB_URL . 'assets/js/front.js', [], CB_VERSION, true);
        if (!wp_script_is('cb-chat', 'registered')) wp_register_script('cb-chat', CB_URL . 'assets/js/chatbot.js', [], CB_VERSION, true);
    }

    static function assets() {
        self::register();
        if (self::$assets) return;
        self::$assets = true;
        wp_enqueue_style('cb-front');
        wp_enqueue_script('cb-front');
        wp_localize_script('cb-front', 'CBData', self::data());
    }

    static function bot_assets() {
        self::register();
        if (self::$bot_assets) return;
        self::$bot_assets = true;
        wp_enqueue_style('cb-front');
        wp_enqueue_script('cb-chat');
        wp_localize_script('cb-chat', 'CBChat', self::data());
    }

    private static function remember_booking_url() {
        if (is_singular()) {
            $u = get_permalink();
            if ($u && get_option('cb_booking_url') !== $u) update_option('cb_booking_url', $u);
        }
    }

    static function lang_switch() {
        $cur = CB_I18n::front_lang();
        $h = '<div class="cb-langs">';
        foreach (CB_I18n::langs() as $k => $n) {
            $h .= '<a class="' . ($k === $cur ? 'on' : '') . '" href="' . esc_url(add_query_arg('cb_lang', $k, remove_query_arg('cb_manage'))) . '" onclick="document.cookie=\'cb_lang=' . $k . ';path=/;max-age=31536000\'">' . $n . '</a>';
        }
        return $h . '</div>';
    }

    static function wrap($inner, $cls = '') {
        return '<div class="cb-root ' . esc_attr($cls) . '" dir="' . CB_I18n::dir(CB_I18n::front_lang()) . '" lang="' . esc_attr(CB_I18n::front_lang()) . '">' . $inner . '</div>';
    }

    static function sc_booking() {
        self::assets();
        self::remember_booking_url();
        return self::wrap('<div class="cb-booking" id="cb-booking"><div class="cb-loading">' . esc_html(CB_I18n::t('b_loading')) . '</div></div>');
    }

    static function sc_doctors() {
        $lang = CB_I18n::front_lang();
        self::assets();
        $h = '<div class="cb-doctors">';
        foreach (CB_Doctors::all(true) as $d) {
            $img = $d->photo ? '<img class="cb-avatar" src="' . esc_url($d->photo) . '" alt="">' : '<span class="cb-avatar" style="background:' . esc_attr($d->color ?: '#1e6fd9') . '">' . esc_html(mb_substr(CB_Doctors::name($d, $lang), 0, 1)) . '</span>';
            $h .= '<div class="cb-doc">' . $img . '<div><h3>' . esc_html(CB_Doctors::name($d, $lang)) . '</h3><small>' . esc_html(CB_Doctors::spec($d, $lang)) . '</small></div></div>';
        }
        return self::wrap($h . '</div>');
    }

    static function sc_services() {
        $lang = CB_I18n::front_lang();
        self::assets();
        $h = '<div class="cb-services">';
        foreach (CB_Services::all() as $s) {
            $price = CB_Settings::money($s->price);
            $h .= '<div class="cb-svc" style="--c:' . esc_attr($s->color) . '"><span class="cb-ico">' . CB_Icons::svg($s->icon, 26) . '</span><div><h3>' . esc_html(CB_Services::name($s, $lang)) . '</h3>';
            $d = CB_Services::desc($s, $lang);
            if ($d) $h .= '<p>' . esc_html($d) . '</p>';
            $h .= '<small>' . (int)$s->duration . ' ' . esc_html(CB_I18n::t('b_min')) . ($price ? ' · ' . esc_html($price) : '') . '</small></div></div>';
        }
        return self::wrap($h . '</div>');
    }

    static function sc_hours() {
        $lang = CB_I18n::front_lang();
        self::assets();
        $s = CB_Settings::get('schedule');
        $order = [6, 0, 1, 2, 3, 4, 5]; // Sat first (Arab week) – purely visual
        $h = '<ul class="cb-hours">';
        foreach ($order as $i) {
            $d = $s[$i] ?? [];
            $h .= '<li' . ($i === (int)date('w', current_time('timestamp')) ? ' class="today"' : '') . '><b>' . esc_html(CB_I18n::day_name($i, $lang)) . '</b><span>' . (!empty($d['open']) ? esc_html($d['from'] . ' – ' . $d['to']) : '<em>' . esc_html(CB_I18n::t('p_closed')) . '</em>') . '</span></li>';
        }
        $bf = CB_Settings::get('break_from'); $bt = CB_Settings::get('break_to');
        if ($bf && $bt) $h .= '<li class="br"><b>' . esc_html(CB_I18n::t('p_break')) . '</b><span>' . esc_html("$bf – $bt") . '</span></li>';
        return self::wrap($h . '</ul>');
    }

    static function sc_contact() {
        $lang = CB_I18n::front_lang();
        self::assets();
        $S = 'CB_Settings';
        $h = '<div class="cb-contact">';
        $addr = $S::loc('address', $lang);
        if ($addr) $h .= '<p><i class="ic">' . CB_Icons::svg('pin', 20) . '</i><span><small>' . esc_html(CB_I18n::t('p_address')) . '</small>' . esc_html($addr) . '</span></p>';
        if ($S::get('phone')) $h .= '<p><i class="ic">' . CB_Icons::svg('phone', 20) . '</i><span><small>' . esc_html(CB_I18n::t('p_call')) . '</small><a dir="ltr" href="tel:' . esc_attr($S::get('phone')) . '">' . esc_html($S::get('phone')) . '</a></span></p>';
        if ($S::get('email')) $h .= '<p><i class="ic">' . CB_Icons::svg('mail', 20) . '</i><span><small>' . esc_html(CB_I18n::t('p_email')) . '</small><a href="mailto:' . esc_attr($S::get('email')) . '">' . esc_html($S::get('email')) . '</a></span></p>';
        $btns = '';
        $wa = preg_replace('/\D+/', '', (string)$S::get('whatsapp'));
        if ($wa) $btns .= '<a class="cb-btn cb-btn-wa" target="_blank" rel="noopener" href="https://wa.me/' . $wa . '">' . CB_Icons::svg('chat', 18) . '<span>' . esc_html(CB_I18n::t('p_wa')) . '</span></a>';
        if ($S::get('map_link')) $btns .= '<a class="cb-btn cb-btn-ghost" target="_blank" rel="noopener" href="' . esc_url($S::get('map_link')) . '">' . CB_Icons::svg('map', 18) . '<span>' . esc_html(CB_I18n::t('p_map')) . '</span></a>';
        if ($btns) $h .= '<div class="cb-btns">' . $btns . '</div>';
        $soc = '';
        foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram', 'x_url' => 'X'] as $k => $n) {
            if ($S::get($k)) $soc .= '<a target="_blank" rel="noopener" href="' . esc_url($S::get($k)) . '">' . $n . '</a>';
        }
        if ($soc) $h .= '<div class="cb-social"><b>' . esc_html(CB_I18n::t('p_follow')) . '</b> ' . $soc . '</div>';
        $embed = $S::get('map_embed');
        if ($embed && preg_match('~^https://(www\.)?(google\.[a-z.]+/maps|maps\.google\.[a-z.]+|www\.openstreetmap\.org)~', $embed)) {
            $h .= '<iframe class="cb-map" loading="lazy" src="' . esc_url($embed) . '" referrerpolicy="no-referrer-when-downgrade"></iframe>';
        }
        return self::wrap($h . '</div>');
    }

    static function sc_page() {
        $lang = CB_I18n::front_lang();
        self::assets();
        self::remember_booking_url();
        $S = 'CB_Settings';
        $open = CB_Availability::is_open_now();
        $logo = $S::get('logo') ? '<img class="cb-logo" src="' . esc_url($S::get('logo')) . '" alt="">' : '<span class="cb-logo-ph">' . CB_Icons::svg('stethoscope', 26) . '</span>';
        $hero_img = $S::get('hero_image') ? ' style="--hero:url(' . esc_url($S::get('hero_image')) . ')"' : '';
        $h = '<header class="cb-hero"' . $hero_img . '><div class="cb-hero-top"><div class="cb-brand">' . $logo . '<div><b>' . esc_html($S::loc('clinic_name', $lang)) . '</b><small>' . esc_html($S::loc('tagline', $lang)) . '</small></div></div>' . self::lang_switch() . '</div>';
        $h .= '<div class="cb-hero-body"><span class="cb-pill ' . ($open ? 'on' : '') . '">' . esc_html(CB_I18n::t($open ? 'p_open_now' : 'p_closed_now')) . '</span>';
        $h .= '<h1>' . esc_html($S::loc('welcome', $lang)) . '</h1><p>' . esc_html($S::loc('about', $lang)) . '</p>';
        $ph = trim((string)$S::get('phone'));
        $h .= '<div class="cb-cta"><a class="cb-btn cb-btn-primary" href="#cb-booking">' . CB_Icons::svg('calendar', 20) . '<span>' . esc_html(CB_I18n::t('p_cta')) . '</span></a>';
        if ($ph) $h .= '<a class="cb-btn cb-btn-ghost" dir="ltr" href="tel:' . esc_attr($ph) . '">' . CB_Icons::svg('phone', 18) . '<span>' . esc_html($ph) . '</span></a>';
        $h .= '</div></div>';
        if ($S::get('logo')) $h .= '<img class="cb-hero-art" src="' . esc_url($S::get('logo')) . '" alt="">';
        $h .= '</header>';
        $h .= '<section class="cb-sec"><h2>' . esc_html(CB_I18n::t('p_services')) . '</h2>' . self::sc_services() . '</section>';
        if (count(CB_Doctors::all(true)) > 1) $h .= '<section class="cb-sec"><h2>' . esc_html(CB_I18n::t('a_doctors', $lang)) . '</h2>' . self::sc_doctors() . '</section>';
        $h .= '<section class="cb-sec" id="cb-book-sec"><h2>' . esc_html(CB_I18n::t('b_title')) . '</h2>' . self::sc_booking() . '</section>';
        $h .= '<section class="cb-sec cb-two"><div><h2>' . esc_html(CB_I18n::t('p_hours')) . '</h2>' . self::sc_hours() . '</div><div><h2>' . esc_html(CB_I18n::t('p_location')) . '</h2>' . self::sc_contact() . '</div></section>';
        if ($S::get('bot_enabled') && !$S::get('bot_floating')) $h .= self::sc_chatbot();
        return self::wrap($h, 'cb-page alignwide');
    }

    static function chatbot_html() {
        self::bot_assets();
        self::$bot_printed = true;
        return '<div class="cb-root cb-chat-host" dir="' . CB_I18n::dir(CB_I18n::front_lang()) . '" lang="' . esc_attr(CB_I18n::front_lang()) . '"><div id="cb-chat"></div></div>';
    }

    static function sc_chatbot() {
        if (!CB_Settings::get('bot_enabled')) return '';
        return self::chatbot_html();
    }
}
