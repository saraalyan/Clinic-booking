<?php
if (!defined('ABSPATH')) exit;

class CB_Admin {
    static $L = 'ar';
    static $front = '';   // base URL of the stand-alone front-end dashboard page (empty inside wp-admin)

    /** Link to a plugin screen, either in wp-admin or inside the front-end dashboard. */
    static function url($page, $q = '') {
        if (self::$front && $page !== 'cb-settings' && $page !== 'cb-shortcodes') {
            $u = add_query_arg('cb_page', preg_replace('/^cb-/', '', $page), self::$front);
            if ($q !== '') { wp_parse_str(ltrim($q, '&'), $extra); $u = add_query_arg($extra, $u); }
            return $u;
        }
        return admin_url('admin.php?page=' . $page . $q);
    }

    static function hidden() { return self::$front ? '<input type="hidden" name="cb_front" value="' . esc_attr(self::$front) . '">' : ''; }

    static function back($page, $q = '') {
        $f = isset($_REQUEST['cb_front']) ? esc_url_raw(wp_unslash($_REQUEST['cb_front'])) : '';
        if ($f && wp_validate_redirect($f, false)) self::$front = $f;
        wp_safe_redirect(self::url($page, $q)); exit;
    }

    static function can_staff() { return current_user_can('manage_options') || current_user_can('manage_clinic_staff'); }

    /** Entry point of the stand-alone front-end dashboard. */
    static function render_front($base) {
        self::$front = $base;
        self::$L = CB_I18n::admin_lang();
        $p = sanitize_key($_GET['cb_page'] ?? 'dashboard');
        $map = ['dashboard', 'calendar', 'appointments', 'patients', 'services', 'doctors', 'staff'];
        if (!in_array($p, $map, true)) $p = 'dashboard';
        if ($p === 'staff' && !self::can_staff()) $p = 'dashboard';
        call_user_func([__CLASS__, 'page_' . $p]);
    }

    static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
        foreach (['save_settings', 'save_service', 'delete_service', 'save_patient', 'export', 'save_staff', 'delete_staff', 'save_doctor', 'delete_doctor', 'pay_decide', 'block_patient'] as $a) add_action("admin_post_cb_$a", [__CLASS__, "h_$a"]);
    }

    static function t($k) { return CB_I18n::t($k, self::$L); }

    static function menu() {
        self::$L = CB_I18n::admin_lang();
        add_menu_page('العيادة', 'العيادة', 'manage_clinic', 'cb-dashboard', [__CLASS__, 'page_dashboard'], 'dashicons-heart', 26);
        $subs = [
            ['cb-dashboard', self::t('a_dashboard'), 'page_dashboard', 'manage_clinic'],
            ['cb-calendar', self::t('a_calendar'), 'page_calendar', 'manage_clinic'],
            ['cb-appointments', self::t('a_appointments'), 'page_appointments', 'manage_clinic'],
            ['cb-patients', self::t('a_patients'), 'page_patients', 'manage_clinic'],
            ['cb-services', self::t('a_services'), 'page_services', 'manage_clinic'],
            ['cb-doctors', self::t('a_doctors'), 'page_doctors', 'manage_clinic'],
            ['cb-staff', self::t('a_staff'), 'page_staff', 'manage_clinic_staff'],
            ['cb-settings', self::t('a_settings'), 'page_settings', 'manage_options'],
            ['cb-shortcodes', self::t('a_shortcodes'), 'page_shortcodes', 'manage_options'],
        ];
        foreach ($subs as $s) add_submenu_page('cb-dashboard', $s[1], $s[1], $s[3], $s[0], [__CLASS__, $s[2]]);
    }

    static function assets($hook) {
        if (empty($_GET['page']) || strpos($_GET['page'], 'cb-') !== 0) return;
        self::enqueue(false);
    }

    static function enqueue($front) {
        self::$L = CB_I18n::admin_lang();
        $deps = $front ? ['dashicons'] : [];
        if (CB_Shortcodes::font_url()) { wp_register_style('cb-font', CB_Shortcodes::font_url(), [], null); $deps[] = 'cb-font'; }
        wp_enqueue_style('cb-admin', CB_URL . 'assets/css/admin.css', $deps, CB_VERSION);
        wp_add_inline_style('cb-admin', ':root{--cb-primary:' . esc_attr(CB_Settings::get('primary')) . ';--cb-accent:' . esc_attr(CB_Settings::get('accent')) . ';--cb-font:' . CB_Shortcodes::font_family() . '}');
        if (!$front) { wp_enqueue_style('wp-color-picker'); wp_enqueue_media(); }
        wp_enqueue_script('cb-admin', CB_URL . 'assets/js/admin.js', $front ? [] : ['wp-color-picker'], CB_VERSION, true);
        $sched = CB_Settings::get('schedule');
        $hrs = CB_Availability::day_hours(current_time('Y-m-d'));
        $svcs = [];
        foreach (CB_Services::all(false) as $s) $svcs[] = ['id' => (int)$s->id, 'name' => CB_Services::name($s, self::$L), 'color' => $s->color, 'duration' => (int)$s->duration];
        $docs = [];
        foreach (CB_Doctors::all(false) as $d) $docs[] = ['id' => (int)$d->id, 'name' => CB_Doctors::name($d, self::$L), 'color' => $d->color, 'services' => CB_Doctors::service_ids($d)];
        wp_localize_script('cb-admin', 'CBA', [
            'rest' => esc_url_raw(rest_url(CB_Rest::NS . '/')), 'nonce' => wp_create_nonce('wp_rest'),
            'locale' => CB_I18n::locale(self::$L), 'dir' => CB_I18n::dir(self::$L), 'today' => current_time('Y-m-d'),
            'from' => $hrs ? (int)floor($hrs[0] / 60) : 8, 'to' => $hrs ? (int)ceil($hrs[1] / 60) : 18,
            'services' => $svcs, 'doctors' => $docs, 'pay' => CB_Payments::enabled() ? 1 : 0, 's' => CB_I18n::bundle(self::$L, ['a_', 'st_', 'ps_']),
            'open_new' => !empty($_GET['new']) ? 1 : 0,
        ]);
    }

    // ------------------------------------------------------------ layout
    static function open($active) {
        self::$L = CB_I18n::admin_lang();
        $u = wp_get_current_user();
        $items = [
            'cb-dashboard' => ['a_dashboard', 'dashicons-admin-home'], 'cb-calendar' => ['a_calendar', 'dashicons-calendar-alt'],
            'cb-appointments' => ['a_appointments', 'dashicons-list-view'], 'cb-patients' => ['a_patients', 'dashicons-groups'],
            'cb-services' => ['a_services', 'dashicons-screenoptions'], 'cb-doctors' => ['a_doctors', 'dashicons-businessperson'],
        ];
        if (self::can_staff()) $items['cb-staff'] = ['a_staff', 'dashicons-id'];
        if (current_user_can('manage_options')) { $items['cb-settings'] = ['a_settings', 'dashicons-admin-generic']; $items['cb-shortcodes'] = ['a_shortcodes', 'dashicons-editor-code']; }
        echo '<div class="cb-admin' . (self::$front ? ' cb-front-app' : '') . '" dir="' . CB_I18n::dir(self::$L) . '" lang="' . esc_attr(self::$L) . '"><aside class="cb-side"><div class="cb-side-brand">';
        $logo = CB_Settings::get('logo');
        echo $logo ? '<img src="' . esc_url($logo) . '" alt="">' : '<span class="cb-heart">♥</span>';
        echo '<div><b>' . esc_html(CB_Settings::loc('clinic_name', self::$L)) . '</b><small>' . esc_html(CB_Settings::loc('tagline', self::$L)) . '</small></div></div><nav>';
        foreach ($items as $slug => $i) {
            echo '<a class="' . ($slug === $active ? 'on' : '') . '" href="' . esc_url(self::url($slug)) . '"><span class="dashicons ' . $i[1] . '"></span>' . esc_html(self::t($i[0])) . '</a>';
        }
        if (current_user_can('manage_options')) echo '<a href="' . esc_url(self::url('cb-settings', '&tab=schedule')) . '"><span class="dashicons dashicons-clock"></span>' . esc_html(self::t('a_hours')) . '</a>';
        echo '</nav><a class="cb-logout" href="' . esc_url(wp_logout_url(self::$front ?: '')) . '"><span class="dashicons dashicons-exit"></span>' . esc_html(self::t('a_logout')) . '</a></aside><main class="cb-main">';
        echo '<div class="cb-top"><div class="cb-user">' . get_avatar($u->ID, 36) . '<span>' . esc_html($u->display_name) . '</span></div><div class="cb-langs">';
        foreach (CB_I18n::langs() as $k => $n) echo '<a class="' . ($k === self::$L ? 'on' : '') . '" href="' . esc_url(add_query_arg('cb_admin_lang', $k)) . '">' . $n . '</a>';
        echo '</div></div>';
    }

    static function close() { echo '</main></div>'; }

    static function status_badge($s) {
        return '<span class="cb-st cb-st-' . esc_attr($s) . '">' . esc_html(self::t('st_' . $s)) . '</span>';
    }

    // ------------------------------------------------------------ dashboard
    static function page_dashboard() {
        global $wpdb;
        self::open('cb-dashboard');
        $A = CB_DB::t('appointments');
        $today = current_time('Y-m-d'); $now = current_time('mysql');
        $n_pat = (int)$wpdb->get_var('SELECT COUNT(*) FROM ' . CB_DB::t('patients'));
        $n_today = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $A WHERE DATE(start_dt)=%s AND status IN ('pending','confirmed','completed')", $today));
        $n_up = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $A WHERE start_dt>%s AND status IN ('pending','confirmed')", $now));
        $n_can = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $A WHERE status='cancelled' AND start_dt>=%s", date('Y-m-d', strtotime($today . ' -30 days'))));
        $near = $wpdb->get_results($wpdb->prepare(CB_Booking::select() . " WHERE a.start_dt>=%s AND a.status IN ('pending','confirmed') ORDER BY a.start_dt ASC LIMIT 5", $now));
        $day = $wpdb->get_results($wpdb->prepare(CB_Booking::select() . " WHERE DATE(a.start_dt)=%s AND a.status IN ('pending','confirmed','completed') ORDER BY a.start_dt ASC", $today));
        $pop = $wpdb->get_results("SELECT s.*, COUNT(a.id) AS c FROM " . CB_DB::t('services') . " s LEFT JOIN $A a ON a.service_id=s.id AND a.status<>'cancelled' GROUP BY s.id ORDER BY c DESC, s.sort ASC LIMIT 5");
        $open = CB_Availability::is_open_now();
        $u = wp_get_current_user();
        $loc = CB_I18n::locale(self::$L);
        $pg = fn($p, $q = '') => esc_url(self::url($p, $q));

        echo '<section class="cb-card cb-welcome"><div><h1>' . esc_html(self::t('a_welcome')) . ' ' . esc_html($u->display_name) . '</h1><p>' . esc_html(self::t('a_sub')) . '</p><small>' . esc_html(self::t('a_sub2')) . '</small></div>';
        echo '<div class="cb-welcome-side"><span class="cb-date">📅 ' . esc_html(date_i18n('l j F Y')) . '</span><span class="cb-pill ' . ($open ? 'on' : '') . '">' . esc_html(self::t($open ? 'a_open' : 'a_closed')) . '</span></div></section>';

        echo '<div class="cb-stats">';
        $stat = function ($cls, $n, $label, $link, $icon) {
            echo '<a class="cb-stat ' . $cls . '" href="' . $link . '"><span class="ico dashicons ' . $icon . '"></span><div><small>' . esc_html($label) . '</small><b>' . (int)$n . '</b></div><em>' . esc_html(self::t('a_view_all')) . ' ‹</em></a>';
        };
        $stat('s-purple', $n_pat, self::t('a_total_patients'), $pg('cb-patients'), 'dashicons-groups');
        $stat('s-green', $n_today, self::t('a_today_appts'), $pg('cb-calendar'), 'dashicons-calendar-alt');
        $stat('s-blue', $n_up, self::t('a_upcoming'), $pg('cb-appointments'), 'dashicons-clock');
        $stat('s-red', $n_can, self::t('a_cancelled'), $pg('cb-appointments', '&status=cancelled'), 'dashicons-dismiss');
        echo '</div>';
        self::receipts_card();
        echo '<div class="cb-grid">';

        // nearest
        echo '<section class="cb-card"><div class="cb-card-h"><h3>' . esc_html(self::t('a_nearest')) . '</h3><a href="' . $pg('cb-appointments') . '">' . esc_html(self::t('a_view_all')) . '</a></div>';
        if (!$near) echo '<p class="cb-empty">' . esc_html(self::t('a_none')) . '</p>';
        foreach ($near as $a) {
            echo '<div class="cb-row"><span class="cb-av dashicons dashicons-admin-users"></span><div class="grow"><b>' . esc_html($a->patient_name) . '</b><small>' . esc_html(CB_Services::name($a, self::$L)) . '</small></div><div class="cb-when"><span>' . esc_html(date_i18n('j M', strtotime($a->start_dt))) . ' · ' . esc_html(date('H:i', strtotime($a->start_dt))) . '</span>' . self::status_badge($a->status) . '</div></div>';
        }
        echo '</section>';

        // today timeline
        $hrs = CB_Availability::day_hours($today);
        $from = $hrs ? (int)floor($hrs[0] / 60) : 8; $to = $hrs ? (int)ceil($hrs[1] / 60) : 18;
        foreach ($day as $a) { $from = min($from, (int)date('G', strtotime($a->start_dt))); $to = max($to, (int)ceil((strtotime($a->end_dt) - strtotime($today)) / 3600)); }
        $px = 52;
        echo '<section class="cb-card"><div class="cb-card-h"><h3>' . esc_html(date_i18n('l j F', strtotime($today))) . '</h3><a href="' . $pg('cb-calendar') . '">' . esc_html(self::t('a_view_all')) . '</a></div>';
        echo '<div class="cb-tl" style="height:' . (($to - $from) * $px) . 'px">';
        for ($h = $from; $h <= $to; $h++) echo '<div class="cb-tl-h" style="top:' . (($h - $from) * $px) . 'px"><span>' . sprintf('%02d:00', $h) . '</span></div>';
        foreach ($day as $a) {
            $sm = (int)date('G', strtotime($a->start_dt)) * 60 + (int)date('i', strtotime($a->start_dt));
            $em = $sm + (int)$a->duration;
            echo '<div class="cb-ev" style="top:' . (($sm - $from * 60) * $px / 60) . 'px;height:' . max(26, ($em - $sm) * $px / 60) . 'px;--c:' . esc_attr($a->color ?: '#1e6fd9') . '"><b>' . esc_html($a->patient_name) . '</b> – ' . esc_html(CB_Services::name($a, self::$L)) . '<small>' . date('H:i', strtotime($a->start_dt)) . ' - ' . date('H:i', strtotime($a->end_dt)) . '</small></div>';
        }
        echo '</div></section>';

        // right column
        echo '<div class="cb-col">';
        echo '<section class="cb-card"><div class="cb-card-h"><h3>' . esc_html(self::t('a_popular')) . '</h3><a href="' . $pg('cb-services') . '">' . esc_html(self::t('a_view_all')) . '</a></div>';
        foreach ($pop as $s) echo '<div class="cb-row"><span class="cb-av" style="background:' . esc_attr($s->color) . '22">' . esc_html($s->icon ?: '🩺') . '</span><div class="grow"><b>' . esc_html(CB_Services::name($s, self::$L)) . '</b><small>' . (int)$s->c . ' ' . esc_html(self::t('a_appt_word')) . '</small></div></div>';
        echo '</section>';
        echo '<section class="cb-card"><div class="cb-card-h"><h3>' . esc_html(self::t('a_clinic_info')) . '</h3></div><ul class="cb-info">';
        $info = [['📍', CB_Settings::loc('address', self::$L)], ['📞', CB_Settings::get('phone')], ['✉️', CB_Settings::get('email')]];
        foreach ($info as $i) if ($i[1]) echo '<li>' . $i[0] . ' <span dir="auto">' . esc_html($i[1]) . '</span></li>';
        echo '</ul></section></div></div>';

        echo '<section class="cb-card"><div class="cb-card-h"><h3>' . esc_html(self::t('a_quick')) . '</h3></div><div class="cb-quick">';
        echo '<a href="' . $pg('cb-calendar', '&new=1') . '"><span class="dashicons dashicons-calendar-alt"></span>' . esc_html(self::t('a_add_appt')) . '</a>';
        echo '<a href="' . $pg('cb-patients', '&add=1') . '"><span class="dashicons dashicons-groups"></span>' . esc_html(self::t('a_add_patient')) . '</a>';
        echo '<a href="' . $pg('cb-services') . '"><span class="dashicons dashicons-screenoptions"></span>' . esc_html(self::t('a_manage_services')) . '</a>';
        if (current_user_can('manage_options')) echo '<a href="' . $pg('cb-settings', '&tab=schedule') . '"><span class="dashicons dashicons-clock"></span>' . esc_html(self::t('a_edit_hours')) . '</a>';
        echo '</div></section>';
        self::close();
    }

    // ------------------------------------------------------------ calendar / list (JS driven)
    static function page_calendar() { self::open('cb-calendar'); echo '<div id="cb-cal-app"></div>'; self::close(); }
    static function page_appointments() { self::open('cb-appointments'); echo '<div id="cb-list-app" data-status="' . esc_attr($_GET['status'] ?? '') . '"></div>'; self::close(); }

    // ------------------------------------------------------------ patients
    static function page_patients() {
        global $wpdb;
        self::open('cb-patients');
        $P = CB_DB::t('patients'); $A = CB_DB::t('appointments');
        $pg = fn($q = '') => esc_url(self::url('cb-patients', $q));
        if (!empty($_GET['saved'])) echo '<div class="cb-notice">' . esc_html(self::t('a_saved')) . '</div>';

        if (!empty($_GET['patient'])) {
            $p = $wpdb->get_row($wpdb->prepare("SELECT * FROM $P WHERE id=%d", (int)$_GET['patient']));
            if ($p) {
                echo '<section class="cb-card"><div class="cb-card-h"><h3>' . esc_html($p->name) . '</h3><a href="' . $pg() . '">‹ ' . esc_html(self::t('a_back')) . '</a></div>';
                echo '<p dir="ltr" style="text-align:right">+' . esc_html($p->phone) . ' · <a target="_blank" href="https://wa.me/' . esc_attr($p->phone) . '">WhatsApp</a> · <a href="tel:+' . esc_attr($p->phone) . '">☎</a></p>';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="cb-form-inline">';
                wp_nonce_field('cb_save_patient'); echo self::hidden(); echo '<input type="hidden" name="action" value="cb_save_patient"><input type="hidden" name="id" value="' . (int)$p->id . '">';
                echo '<label>' . esc_html(self::t('a_name')) . '<input name="name" value="' . esc_attr($p->name) . '"></label>';
                echo '<label>' . esc_html(self::t('a_email')) . '<input type="email" name="email" dir="ltr" value="' . esc_attr($p->email) . '"></label>';
                echo '<label>' . esc_html(self::t('a_patient_notes')) . '<textarea name="notes" rows="3">' . esc_textarea($p->notes) . '</textarea></label>';
                echo '<button class="button button-primary">' . esc_html(self::t('a_save')) . '</button></form>';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:14px">'; wp_nonce_field('cb_block_patient'); echo self::hidden();
                echo '<input type="hidden" name="action" value="cb_block_patient"><input type="hidden" name="id" value="' . (int)$p->id . '"><input type="hidden" name="block" value="' . ($p->blocked ? 0 : 1) . '">';
                echo '<button class="button' . ($p->blocked ? '' : ' cb-danger') . '">' . esc_html(self::t($p->blocked ? 'a_unblock' : 'a_block')) . '</button> ' . ($p->blocked ? '<span class="cb-st cb-st-cancelled">' . esc_html(self::t('a_blocked')) . '</span>' : '') . '<small style="display:block;margin-top:6px">' . esc_html(self::t('a_block_help')) . '</small></form></section>';
                $rows = $wpdb->get_results($wpdb->prepare(CB_Booking::select() . ' WHERE a.patient_id=%d ORDER BY a.start_dt DESC', $p->id));
                echo '<section class="cb-card"><div class="cb-card-h"><h3>' . esc_html(self::t('a_history')) . '</h3></div><table class="cb-table"><tr><th>' . esc_html(self::t('a_date')) . '</th><th>' . esc_html(self::t('a_time')) . '</th><th>' . esc_html(self::t('a_service')) . '</th><th>' . esc_html(self::t('a_status')) . '</th></tr>';
                foreach ($rows as $a) echo '<tr><td>' . esc_html(substr($a->start_dt, 0, 10)) . '</td><td>' . esc_html(substr($a->start_dt, 11, 5)) . '</td><td>' . esc_html(CB_Services::name($a, self::$L)) . '</td><td>' . self::status_badge($a->status) . '</td></tr>';
                if (!$rows) echo '<tr><td colspan="4">' . esc_html(self::t('a_none')) . '</td></tr>';
                echo '</table></section>';
                self::close(); return;
            }
        }

        $q = sanitize_text_field($_GET['q'] ?? '');
        $like = '%' . $wpdb->esc_like($q) . '%';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT p.*, COUNT(a.id) AS n, MAX(a.start_dt) AS last FROM $P p LEFT JOIN $A a ON a.patient_id=p.id AND a.status<>'cancelled' WHERE (p.name LIKE %s OR p.phone LIKE %s) GROUP BY p.id ORDER BY p.id DESC LIMIT 300", $like, $like));
        echo '<section class="cb-card"><div class="cb-card-h"><h3>' . esc_html(self::t('a_patients')) . '</h3>';
        echo '<form method="get" class="cb-search"><input type="hidden" name="page" value="cb-patients"><input name="q" value="' . esc_attr($q) . '" placeholder="' . esc_attr(self::t('a_search')) . '"><button class="button">' . esc_html(self::t('a_search')) . '</button></form></div>';
        if (!empty($_GET['add'])) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="cb-form-inline">';
            wp_nonce_field('cb_save_patient'); echo self::hidden(); echo '<input type="hidden" name="action" value="cb_save_patient">';
            echo '<label>' . esc_html(self::t('a_name')) . '<input name="name" required></label><label>' . esc_html(self::t('a_phone')) . '<input name="phone" required dir="ltr"></label><button class="button button-primary">' . esc_html(self::t('a_save')) . '</button></form>';
        } else echo '<p><a class="button" href="' . $pg('&add=1') . '">+ ' . esc_html(self::t('a_add_patient')) . '</a></p>';
        echo '<table class="cb-table"><tr><th>' . esc_html(self::t('a_name')) . '</th><th>' . esc_html(self::t('a_phone')) . '</th><th>' . esc_html(self::t('a_visits')) . '</th><th>' . esc_html(self::t('a_last_visit')) . '</th></tr>';
        foreach ($rows as $p) echo '<tr><td><a href="' . $pg('&patient=' . (int)$p->id) . '"><b>' . esc_html($p->name) . '</b></a>' . ($p->blocked ? ' <span class="cb-st cb-st-cancelled">' . esc_html(self::t('a_blocked')) . '</span>' : '') . '</td><td dir="ltr" style="text-align:right">+' . esc_html($p->phone) . '</td><td>' . (int)$p->n . '</td><td>' . esc_html($p->last ? substr($p->last, 0, 10) : '—') . '</td></tr>';
        if (!$rows) echo '<tr><td colspan="4">' . esc_html(self::t('a_none')) . '</td></tr>';
        echo '</table></section>';
        self::close();
    }

    static function h_save_patient() {
        if (!current_user_can('manage_clinic')) wp_die('no');
        check_admin_referer('cb_save_patient');
        global $wpdb;
        $id = (int)($_POST['id'] ?? 0);
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        if ($id) {
            $em = sanitize_email(wp_unslash($_POST['email'] ?? ''));
            $wpdb->update(CB_DB::t('patients'), ['name' => $name, 'email' => is_email($em) ? $em : '', 'notes' => sanitize_textarea_field(wp_unslash($_POST['notes'] ?? ''))], ['id' => $id]);
            self::back('cb-patients', '&patient=' . $id . '&saved=1');
        }
        $phone = CB_Booking::normalize_phone(wp_unslash($_POST['phone'] ?? ''));
        if ($phone && mb_strlen($name) >= 2) $id = CB_Booking::upsert_patient($name, $phone);
        self::back('cb-patients', '&saved=1');
    }

    // ------------------------------------------------------------ services
    static function page_services() {
        self::open('cb-services');
        $pg = fn($q = '') => esc_url(self::url('cb-services', $q));
        if (!empty($_GET['saved'])) echo '<div class="cb-notice">' . esc_html(self::t('a_saved')) . '</div>';
        $editing = isset($_GET['edit']) ? ($_GET['edit'] === 'new' ? (object)['id' => 0, 'name_ar' => '', 'name_he' => '', 'name_en' => '', 'desc_ar' => '', 'desc_he' => '', 'desc_en' => '', 'duration' => 30, 'price' => '', 'deposit' => '', 'icon' => '🩺', 'color' => '#1e6fd9', 'active' => 1, 'sort' => 0] : CB_Services::get((int)$_GET['edit'])) : null;
        if ($editing) {
            $f = fn($k, $label, $type = 'text') => '<label>' . esc_html(self::t($label)) . '<input type="' . $type . '" name="' . $k . '" value="' . esc_attr($editing->$k) . '"' . ($type === 'number' ? ' step="any" min="0"' : '') . '></label>';
            echo '<section class="cb-card"><div class="cb-card-h"><h3>' . esc_html(self::t($editing->id ? 'a_edit' : 'a_add_service')) . '</h3><a href="' . $pg() . '">‹ ' . esc_html(self::t('a_back')) . '</a></div>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="cb-form-grid">';
            wp_nonce_field('cb_save_service'); echo self::hidden(); echo '<input type="hidden" name="action" value="cb_save_service"><input type="hidden" name="id" value="' . (int)$editing->id . '">';
            echo $f('name_ar', 'a_name_ar') . $f('name_he', 'a_name_he') . $f('name_en', 'a_name_en');
            echo '<label>' . esc_html(self::t('a_desc_ar')) . '<textarea name="desc_ar" rows="2">' . esc_textarea($editing->desc_ar) . '</textarea></label><label>' . esc_html(self::t('a_desc_he')) . '<textarea name="desc_he" rows="2">' . esc_textarea($editing->desc_he) . '</textarea></label><label>' . esc_html(self::t('a_desc_en')) . '<textarea name="desc_en" rows="2">' . esc_textarea($editing->desc_en ?? '') . '</textarea></label>';
            echo $f('duration', 'a_duration', 'number') . $f('price', 'a_price', 'number') . (CB_Payments::enabled() ? $f('deposit', 'a_deposit', 'number') . '<small class="full">' . esc_html(self::t('a_deposit_help')) . '</small>' : '') . $f('icon', 'a_icon') . $f('color', 'a_color', 'color') . $f('sort', 'a_order', 'number');
            echo '<label class="chk"><input type="checkbox" name="active" value="1" ' . checked($editing->active, 1, false) . '> ' . esc_html(self::t('a_active')) . '</label>';
            echo '<div class="full"><button class="button button-primary">' . esc_html(self::t('a_save')) . '</button></div></form></section>';
            self::close(); return;
        }
        echo '<section class="cb-card"><div class="cb-card-h"><h3>' . esc_html(self::t('a_services')) . '</h3><a class="button button-primary" href="' . $pg('&edit=new') . '">+ ' . esc_html(self::t('a_add_service')) . '</a></div>';
        echo '<table class="cb-table"><tr><th></th><th>' . esc_html(self::t('a_name')) . '</th><th>' . esc_html(self::t('a_duration')) . '</th><th>' . esc_html(self::t('a_price')) . '</th><th>' . esc_html(self::t('a_status')) . '</th><th></th></tr>';
        foreach (CB_Services::all(false) as $s) {
            echo '<tr><td><span class="cb-av" style="background:' . esc_attr($s->color) . '22">' . esc_html($s->icon) . '</span></td><td><b>' . esc_html(CB_Services::name($s, self::$L)) . '</b></td><td>' . (int)$s->duration . '</td><td>' . esc_html(CB_Settings::money($s->price) ?: '—') . '</td><td>' . ($s->active ? '✔' : '—') . '</td><td>';
            echo '<a class="button" href="' . $pg('&edit=' . (int)$s->id) . '">' . esc_html(self::t('a_edit')) . '</a> ';
            echo '<a class="button" onclick="return confirm(\'' . esc_js(self::t('a_confirm')) . '\')" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=cb_delete_service&id=' . (int)$s->id . (self::$front ? '&cb_front=' . rawurlencode(self::$front) : '')), 'cb_delete_service')) . '">' . esc_html(self::t('a_delete')) . '</a></td></tr>';
        }
        echo '</table></section>';
        self::close();
    }

    static function h_save_service() {
        if (!current_user_can('manage_clinic')) wp_die('no');
        check_admin_referer('cb_save_service');
        global $wpdb;
        $p = wp_unslash($_POST);
        $d = [
            'name_ar' => sanitize_text_field($p['name_ar'] ?? ''), 'name_he' => sanitize_text_field($p['name_he'] ?? ''), 'name_en' => sanitize_text_field($p['name_en'] ?? ''),
            'desc_ar' => sanitize_textarea_field($p['desc_ar'] ?? ''), 'desc_he' => sanitize_textarea_field($p['desc_he'] ?? ''), 'desc_en' => sanitize_textarea_field($p['desc_en'] ?? ''),
            'duration' => max(5, (int)($p['duration'] ?? 30)), 'price' => ($p['price'] ?? '') === '' ? null : (float)$p['price'], 'deposit' => ($p['deposit'] ?? '') === '' ? null : (float)$p['deposit'],
            'icon' => mb_substr(sanitize_text_field($p['icon'] ?? ''), 0, 8), 'color' => sanitize_hex_color($p['color'] ?? '') ?: '#1e6fd9',
            'active' => empty($p['active']) ? 0 : 1, 'sort' => (int)($p['sort'] ?? 0),
        ];
        if ($d['name_ar'] === '' && $d['name_he'] === '' && $d['name_en'] === '') wp_die('name required');
        $id = (int)($p['id'] ?? 0);
        if ($id) $wpdb->update(CB_DB::t('services'), $d, ['id' => $id]); else $wpdb->insert(CB_DB::t('services'), $d);
        if ($id) foreach (['price', 'deposit'] as $col) if ($d[$col] === null) $wpdb->query($wpdb->prepare('UPDATE ' . CB_DB::t('services') . " SET $col=NULL WHERE id=%d", $id));
        self::back('cb-services', '&saved=1');
    }

    static function h_delete_service() {
        if (!current_user_can('manage_clinic')) wp_die('no');
        check_admin_referer('cb_delete_service');
        global $wpdb;
        $id = (int)($_GET['id'] ?? 0);
        $used = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . CB_DB::t('appointments') . ' WHERE service_id=%d', $id));
        if ($used) $wpdb->update(CB_DB::t('services'), ['active' => 0], ['id' => $id]); // keep history
        else $wpdb->delete(CB_DB::t('services'), ['id' => $id]);
        self::back('cb-services', '&saved=1');
    }

    // ------------------------------------------------------------ settings
    static function tabs() {
        return ['general' => 'معلومات العيادة', 'appearance' => 'المظهر', 'schedule' => 'ساعات العمل والإجازات', 'booking' => 'سياسة الحجز', 'whatsapp' => 'واتساب', 'email' => 'الإيميل والتقويم', 'security' => 'الحماية من الحجوزات الوهمية', 'payment' => 'الدفع بالتحويل البنكي', 'chatbot' => 'الشات بوت', 'backup' => 'النسخ الاحتياطي'];
    }

    static function fields() {
        $yn = CB_I18n::langs();
        return [
            'general' => [
                ['clinic_name_ar', 'اسم العيادة (عربي)', 'text'], ['clinic_name_he', 'اسم العيادة (עברית)', 'text'], ['clinic_name_en', 'اسم العيادة (English)', 'text'],
                ['tagline_ar', 'الوصف المختصر (عربي)', 'text'], ['tagline_he', 'الوصف المختصر (עברית)', 'text'], ['tagline_en', 'الوصف المختصر (English)', 'text'],
                ['welcome_ar', 'عنوان الصفحة الرئيسي (عربي)', 'text'], ['welcome_he', 'عنوان الصفحة الرئيسي (עברית)', 'text'], ['welcome_en', 'عنوان الصفحة الرئيسي (English)', 'text'],
                ['about_ar', 'نبذة عن العيادة (عربي)', 'textarea'], ['about_he', 'نبذة عن العيادة (עברית)', 'textarea'], ['about_en', 'نبذة عن العيادة (English)', 'textarea'],
                ['address_ar', 'العنوان (عربي)', 'text'], ['address_he', 'العنوان (עברית)', 'text'], ['address_en', 'العنوان (English)', 'text'],
                ['phone', 'رقم الهاتف', 'text', 'مثال: +966501234567'], ['whatsapp', 'رقم واتساب العيادة', 'text', 'بصيغة دولية بدون + مثال: 966501234567'],
                ['email', 'البريد الإلكتروني للعرض', 'email'], ['notify_email', 'بريد استلام إشعارات الحجز الجديد', 'email'],
                ['map_link', 'رابط الموقع على الخريطة', 'url', 'رابط Google Maps عادي'],
                ['map_embed', 'رابط تضمين الخريطة (اختياري)', 'url', 'من Google Maps ← مشاركة ← تضمين خريطة ← انسخ قيمة src فقط'],
                ['facebook', 'فيسبوك', 'url'], ['instagram', 'إنستغرام', 'url'], ['x_url', 'X (تويتر)', 'url'],
                ['currency', 'رمز العملة للأسعار', 'text', 'مثال: ر.س أو ₪ أو ج.م'], ['default_lang', 'اللغة الافتراضية للموقع', 'select', '', $yn],
            ],
            'appearance' => [
                ['logo', 'شعار العيادة', 'media'], ['hero_image', 'صورة الواجهة (اختياري)', 'media'],
                ['font', 'الخط', 'select', 'IBM Plex Sans هو الافتراضي ويدعم العربية والعبرية والإنجليزية (يُحمَّل من Google Fonts). «خط النظام» لا يتصل بخوادم جوجل إطلاقًا.', array_map(function ($f) { return $f[0]; }, CB_Shortcodes::fonts())], ['primary', 'اللون الأساسي', 'color'], ['accent', 'لون الأزرار الثانوي', 'color'],
            ],
            'schedule' => [
                ['break_from', 'بداية الاستراحة (كل الأيام)', 'time'], ['break_to', 'نهاية الاستراحة', 'time'],
                ['holidays', 'الإجازات والعطل', 'textarea', "سطر لكل إجازة. يوم واحد: 2026-12-25 — فترة: 2026-12-24..2026-12-31"],
            ],
            'booking' => [
                ['slot_step', 'الفاصل بين المواعيد (دقيقة)', 'number'], ['min_notice', 'أقل مدة قبل الموعد للحجز (ساعات)', 'number'],
                ['max_days', 'أقصى عدد أيام للحجز مسبقًا', 'number'], ['auto_confirm', 'تأكيد الحجز تلقائيًا (وإلا يبقى قيد الانتظار حتى تؤكده)', 'check'],
                ['allow_cancel', 'السماح للمريض بإلغاء الموعد', 'check'], ['allow_resched', 'السماح للمريض بتعديل الموعد', 'check'],
                ['cancel_hours', 'لا يُسمح بالإلغاء/التعديل قبل الموعد بأقل من (ساعات)', 'number'],
                ['country_code', 'مفتاح الدولة لأرقام الهاتف', 'text', 'مثال: 966 السعودية، 972 فلسطين/إسرائيل، 20 مصر'],
            ],
            'whatsapp' => [
                ['wa_enabled', 'تفعيل رسائل واتساب', 'check'], ['wa_phone_id', 'Phone Number ID', 'text'], ['wa_token', 'Access Token (دائم)', 'password'],
                ['wa_tpl_confirm', 'اسم قالب التأكيد', 'text'], ['wa_tpl_reminder', 'اسم قالب التذكير', 'text'],
                ['wa_lang_ar', 'رمز لغة القالب العربي', 'text', 'مثال: ar'], ['wa_lang_he', 'رمز لغة القالب العبري', 'text', 'مثال: he'], ['wa_lang_en', 'رمز لغة القالب الإنجليزي', 'text', 'مثال: en أو en_US'],
            ],
            'email' => [
                ['email_enabled', 'إرسال إيميل تأكيد للمريض (مجاني)', 'check'], ['email_reminders', 'إرسال إيميل تذكير قبل الموعد (مجاني)', 'check'],
                ['reminder_hours', 'إرسال التذكير قبل الموعد بـ (ساعات)', 'number', 'نفس الإعداد يخص تذكير واتساب.'],
                ['arrive_early', 'وقت الحضور المقترح قبل الموعد (دقائق)', 'number', 'يظهر للمريض في شاشة التأكيد مع رقم دوره.'],
            ],
            'security' => [
                ['verify_method', 'تأكيد رقم الهاتف برمز قبل تثبيت الحجز', 'select', 'واتساب: يلزم قالب Authentication معتمد من Meta وتكلفة رسالة لكل رمز. الإيميل: مجاني لكن يلزم أن يكتب المريض بريده. إن لم يكتمل إعداد واتساب يُعطَّل التحقق تلقائيًا كي لا يتعطل الحجز.', ['none' => 'بدون تحقق', 'whatsapp' => 'رمز عبر واتساب', 'email' => 'رمز عبر الإيميل']],
                ['wa_tpl_otp', 'اسم قالب رمز التحقق (واتساب)', 'text', 'قالب من نوع Authentication، متغير واحد {{1}} هو الرمز.'],
                ['wa_otp_button', 'القالب يحتوي زر «نسخ الرمز»', 'check', 'قوالب Authentication تتطلب عادةً هذا الزر. أوقفه فقط إن كان قالبك بلا أزرار.'],
                ['max_active', 'أقصى عدد مواعيد قادمة للمريض الواحد (0 = بلا حد)', 'number'],
            ],
            'payment' => [
                ['pay_mode', 'تفعيل الدفع بالتحويل البنكي', 'check', 'يظهر للمريض مبلغ الخدمة (أو العربون) وبيانات حسابك، يرفع صورة الإيصال، وأنت تؤكد من لوحة التحكم. الخدمات بدون سعر أو عربون لا تطلب دفعًا.'],
                ['pay_hold', 'مدة حجز الموعد مؤقتًا بانتظار الإيصال (دقائق)', 'number', 'بعدها يُلغى الحجز تلقائيًا ويُتاح الوقت لغيره. 0 = بلا مهلة.'],
                ['pay_info_ar', 'بيانات الحساب البنكي (عربي)', 'textarea', "اسم البنك، اسم صاحب الحساب، IBAN أو رقم الحساب"],
                ['pay_info_he', 'بيانات الحساب البنكي (עברית)', 'textarea'], ['pay_info_en', 'بيانات الحساب البنكي (English)', 'textarea'],
            ],
            'chatbot' => [
                ['bot_enabled', 'تفعيل الشات بوت', 'check'], ['bot_floating', 'إظهاره كزر عائم في كل صفحات الموقع', 'check'],
                ['faq_ar', 'أسئلة وأجوبة مخصصة (عربي)', 'textarea', "سطر لكل سؤال بهذا الشكل:\nكلمات مفتاحية مفصولة بفاصلة | الإجابة\nمثال: موقف, باركينج | يتوفر موقف سيارات مجاني أمام العيادة."],
                ['faq_he', 'أسئلة وأجوبة مخصصة (עברית)', 'textarea', "אותו פורמט: מילות מפתח מופרדות בפסיק | התשובה"],
                ['faq_en', 'أسئلة وأجوبة مخصصة (English)', 'textarea', "Same format: keywords separated by commas | answer"],
            ],
        ];
    }

    static function page_settings() {
        if (!current_user_can('manage_options')) wp_die('no');
        self::open('cb-settings');
        $tabs = self::tabs();
        $tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'general';
        $S = CB_Settings::all();
        echo '<section class="cb-card"><h2 class="cb-h2">⚙️ ' . esc_html($tabs[$tab]) . '</h2><p class="cb-note">لوحة الإعدادات بالعربية فقط. محتوى الموقع نفسه يظهر بالعربية والعبرية والإنجليزية حسب حقول كل لغة.</p><div class="cb-tabs">';
        foreach ($tabs as $k => $n) echo '<a class="' . ($k === $tab ? 'on' : '') . '" href="' . esc_url(admin_url('admin.php?page=cb-settings&tab=' . $k)) . '">' . esc_html($n) . '</a>';
        echo '</div>';
        if (!empty($_GET['saved'])) echo '<div class="cb-notice">' . esc_html(self::t('a_saved')) . '</div>';

        if ($tab === 'general') {
            echo '<div class="cb-help"><b>طريقة الاستخدام:</b> أنشئ صفحة جديدة وضع فيها <code>[clinic_page]</code> لصفحة العيادة الكاملة. أو استخدم أجزاء منفصلة: <code>[clinic_booking]</code> <code>[clinic_services]</code> <code>[clinic_doctors]</code> <code>[clinic_hours]</code> <code>[clinic_contact]</code> <code>[clinic_chatbot]</code> <code>[clinic_dashboard]</code> (لوحة التحكم في صفحة مستقلة). الشات بوت يظهر تلقائيًا كزر عائم.</div>';
        }
        if ($tab === 'backup') {
            echo '<p>صدّر بيانات المرضى والمواعيد كملفات CSV واحتفظ بها في مكان آمن. للنسخ الاحتياطي الكامل لقاعدة البيانات والموقع نوصي بإضافة مجانية مثل UpdraftPlus مع جدولة يومية.</p><p>';
            foreach (['appointments' => 'تصدير المواعيد', 'patients' => 'تصدير المرضى'] as $k => $n)
                echo '<a class="button button-primary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=cb_export&type=' . $k), 'cb_export')) . '">' . esc_html($n) . ' (CSV)</a> ';
            echo '</p><p class="cb-note">بيانات المرضى لا تظهر إلا للمستخدمين الذين يملكون صلاحية «إدارة العيادة» (المدير، الطبيب، موظف الاستقبال).</p></section>';
            self::close(); return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="cb-form-grid">';
        wp_nonce_field('cb_save_settings');
        echo '<input type="hidden" name="action" value="cb_save_settings"><input type="hidden" name="tab" value="' . esc_attr($tab) . '">';
        foreach (self::fields()[$tab] as $f) {
            list($key, $label, $type) = $f; $help = $f[3] ?? ''; $opts = $f[4] ?? [];
            $v = $S[$key] ?? '';
            echo '<label class="' . ($type === 'textarea' ? 'full' : '') . ($type === 'check' ? ' chk' : '') . '">';
            if ($type === 'check') {
                echo '<input type="checkbox" name="' . $key . '" value="1" ' . checked((int)$v, 1, false) . '> ' . esc_html($label);
            } else {
                echo '<span>' . esc_html($label) . '</span>';
                if ($type === 'textarea') echo '<textarea name="' . $key . '" rows="4">' . esc_textarea($v) . '</textarea>';
                elseif ($type === 'select') { echo '<select name="' . $key . '">'; foreach ($opts as $ov => $on) echo '<option value="' . esc_attr($ov) . '" ' . selected($v, $ov, false) . '>' . esc_html($on) . '</option>'; echo '</select>'; }
                elseif ($type === 'media') echo '<span class="cb-media-wrap"><input type="text" name="' . $key . '" value="' . esc_attr($v) . '" dir="ltr"><button type="button" class="button cb-media">اختيار صورة</button></span>';
                elseif ($type === 'color') echo '<input type="text" class="cb-color" name="' . $key . '" value="' . esc_attr($v) . '">';
                else echo '<input type="' . ($type === 'password' ? 'password' : $type) . '" name="' . $key . '" value="' . esc_attr($v) . '"' . (in_array($type, ['url', 'email', 'text', 'password'], true) && in_array($key, ['phone', 'whatsapp', 'map_link', 'map_embed', 'wa_phone_id', 'wa_token', 'wa_tpl_confirm', 'wa_tpl_reminder', 'wa_tpl_otp', 'email', 'notify_email', 'facebook', 'instagram', 'x_url'], true) ? ' dir="ltr"' : '') . ' autocomplete="off">';
            }
            if ($help) echo '<small>' . nl2br(esc_html($help)) . '</small>';
            echo '</label>';
        }
        if ($tab === 'schedule') {
            echo '<div class="full"><table class="cb-table"><tr><th>اليوم</th><th>مفتوح</th><th>من</th><th>إلى</th></tr>';
            foreach ([6, 0, 1, 2, 3, 4, 5] as $i) {
                $d = $S['schedule'][$i] ?? ['open' => 0, 'from' => '09:00', 'to' => '17:00'];
                echo '<tr><td><b>' . esc_html(CB_I18n::day_name($i, 'ar')) . ' / ' . esc_html(CB_I18n::day_name($i, 'he')) . '</b></td><td><input type="checkbox" name="sched[' . $i . '][open]" value="1" ' . checked(!empty($d['open']), true, false) . '></td><td><input type="time" name="sched[' . $i . '][from]" value="' . esc_attr($d['from']) . '"></td><td><input type="time" name="sched[' . $i . '][to]" value="' . esc_attr($d['to']) . '"></td></tr>';
            }
            echo '</table></div>';
        }
        echo '<div class="full"><button class="button button-primary button-hero">حفظ</button></div></form>';

        if ($tab === 'whatsapp') {
            echo '<div class="cb-help"><b>طريقة العمل:</b> يستخدم النظام WhatsApp Cloud API الرسمي من Meta. الرسائل التي تبدأها العيادة (تأكيد/تذكير) يجب أن تكون <b>قوالب</b> معتمدة من Meta، ولكل قالب 5 متغيرات بهذا الترتيب في نص القالب:<br><code>{{1}}</code> اسم المريض · <code>{{2}}</code> الخدمة · <code>{{3}}</code> التاريخ · <code>{{4}}</code> الوقت · <code>{{5}}</code> اسم العيادة<br><b>التكلفة:</b> التفعيل نفسه مجاني، لكن Meta تحاسب على المحادثات/الرسائل القالبية حسب الدولة ونوع القالب وفق جدول أسعارها الحالي، والعيادة هي التي تدفعها مباشرة لـ Meta من حسابها. راجع أسعار Meta قبل التفعيل.</div>';
            $log = get_option('cb_wa_log', []);
            echo '<h3>آخر عمليات الإرسال</h3><pre class="cb-log" dir="ltr">' . esc_html($log ? implode("\n", $log) : '—') . '</pre>';
        }
        echo '</section>';
        self::close();
    }

    static function h_save_settings() {
        if (!current_user_can('manage_options')) wp_die('no');
        check_admin_referer('cb_save_settings');
        $tab = sanitize_key($_POST['tab'] ?? 'general');
        $p = wp_unslash($_POST);
        $new = [];
        foreach ((self::fields()[$tab] ?? []) as $f) {
            list($key, , $type) = $f; $raw = $p[$key] ?? '';
            switch ($type) {
                case 'check': $v = empty($p[$key]) ? 0 : 1; break;
                case 'number': $v = max(0, (int)$raw); break;
                case 'textarea': $v = sanitize_textarea_field($raw); break;
                case 'url': case 'media': $v = esc_url_raw($raw); break;
                case 'email': $v = sanitize_email($raw); break;
                case 'color': $v = sanitize_hex_color($raw) ?: '#1e6fd9'; break;
                case 'time': $v = preg_match('/^\d{2}:\d{2}$/', $raw) ? $raw : ''; break;
                case 'select': $v = array_key_exists($raw, $f[4] ?? []) ? $raw : (string)array_key_first($f[4] ?? ['ar' => 1]); break;
                default: $v = sanitize_text_field($raw);
            }
            if ($key === 'slot_step') $v = max(5, $v);
            if ($key === 'country_code') $v = preg_replace('/\D+/', '', $v);
            $new[$key] = $v;
        }
        if ($tab === 'schedule') {
            $s = [];
            for ($i = 0; $i < 7; $i++) {
                $d = $p['sched'][$i] ?? [];
                $s[$i] = ['open' => empty($d['open']) ? 0 : 1, 'from' => preg_match('/^\d{2}:\d{2}$/', $d['from'] ?? '') ? $d['from'] : '09:00', 'to' => preg_match('/^\d{2}:\d{2}$/', $d['to'] ?? '') ? $d['to'] : '17:00'];
            }
            $new['schedule'] = $s;
        }
        if ($tab === 'whatsapp' && ($new['wa_token'] ?? '') === '') unset($new['wa_token']);
        CB_Settings::update($new);
        wp_safe_redirect(admin_url('admin.php?page=cb-settings&tab=' . $tab . '&saved=1')); exit;
    }

    static function h_export() {
        if (!current_user_can('manage_options')) wp_die('no');
        check_admin_referer('cb_export');
        global $wpdb;
        $type = ($_GET['type'] ?? '') === 'patients' ? 'patients' : 'appointments';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=clinic-' . $type . '-' . date('Y-m-d') . '.csv');
        $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
        $safe = fn($v) => (is_string($v) && preg_match('/^[=+\-@]/', $v)) ? "'" . $v : $v; // CSV-injection guard
        if ($type === 'patients') {
            fputcsv($out, ['id', 'name', 'phone', 'notes', 'created_at']);
            foreach ($wpdb->get_results('SELECT * FROM ' . CB_DB::t('patients'), ARRAY_A) as $r) fputcsv($out, array_map($safe, [$r['id'], $r['name'], '+' . $r['phone'], $r['notes'], $r['created_at']]));
        } else {
            fputcsv($out, ['id', 'patient', 'phone', 'service', 'start', 'end', 'status', 'source', 'notes']);
            foreach ($wpdb->get_results(CB_Booking::select() . ' ORDER BY a.start_dt DESC') as $a) fputcsv($out, array_map($safe, [$a->id, $a->patient_name, '+' . $a->patient_phone, CB_Services::name($a, 'ar'), $a->start_dt, $a->end_dt, $a->status, $a->source, $a->notes]));
        }
        fclose($out); exit;
    }

    // ------------------------------------------------------------ payments awaiting review
    static function receipts_card() {
        global $wpdb;
        $rows = $wpdb->get_results(CB_Booking::select() . " WHERE a.pay_status='submitted' AND a.status='pending' ORDER BY a.proof_at ASC LIMIT 20");
        if (!$rows) return;
        $post = esc_url(admin_url('admin-post.php'));
        echo '<section class="cb-card cb-receipts"><div class="cb-card-h"><h3>💳 ' . esc_html(self::t('a_pay_review')) . ' (' . count($rows) . ')</h3></div>';
        foreach ($rows as $a) {
            $proof = esc_url(admin_url('admin-post.php?action=cb_proof&id=' . (int)$a->id));
            echo '<div class="cb-row"><div class="grow"><b>' . esc_html($a->patient_name) . '</b><small dir="ltr" style="text-align:start">+' . esc_html($a->patient_phone) . ' · ' . esc_html(CB_Services::name($a, self::$L)) . ' · ' . esc_html(substr($a->start_dt, 0, 16)) . '</small></div>';
            echo '<div class="cb-when"><span>' . esc_html(self::t('a_amount')) . ': <b>' . esc_html(CB_Settings::money($a->pay_amount)) . '</b></span> <a class="button" target="_blank" rel="noopener" href="' . $proof . '">' . esc_html(self::t('a_proof')) . '</a> ';
            foreach (['approve' => 'a_approve', 'reject' => 'a_reject'] as $do => $lbl) {
                echo '<form method="post" action="' . $post . '" class="cb-inline">'; wp_nonce_field('cb_pay_decide'); echo self::hidden();
                echo '<input type="hidden" name="action" value="cb_pay_decide"><input type="hidden" name="id" value="' . (int)$a->id . '"><input type="hidden" name="do" value="' . $do . '">';
                echo '<button class="button ' . ($do === 'approve' ? 'button-primary' : 'cb-danger') . '">' . esc_html(self::t($lbl)) . '</button></form> ';
            }
            echo '</div></div>';
        }
        echo '</section>';
    }

    static function h_pay_decide() {
        if (!current_user_can('manage_clinic')) wp_die('no');
        check_admin_referer('cb_pay_decide');
        $id = (int)($_POST['id'] ?? 0);
        if (($_POST['do'] ?? '') === 'approve') CB_Payments::approve($id); else CB_Payments::reject($id);
        self::back('cb-dashboard', '&saved=1');
    }

    static function h_block_patient() {
        if (!current_user_can('manage_clinic')) wp_die('no');
        check_admin_referer('cb_block_patient');
        global $wpdb;
        $id = (int)($_POST['id'] ?? 0);
        $wpdb->update(CB_DB::t('patients'), ['blocked' => empty($_POST['block']) ? 0 : 1], ['id' => $id]);
        self::back('cb-patients', '&patient=' . $id . '&saved=1');
    }

    // ------------------------------------------------------------ doctors
    static function page_doctors() {
        self::open('cb-doctors');
        $pg = fn($q = '') => esc_url(self::url('cb-doctors', $q));
        if (!empty($_GET['saved'])) echo '<div class="cb-notice">' . esc_html(self::t('a_saved')) . '</div>';
        if (!empty($_GET['err'])) echo '<div class="cb-notice bad">' . esc_html(self::t('a_cannot_delete_last')) . '</div>';
        $editing = isset($_GET['edit']) ? ($_GET['edit'] === 'new'
            ? (object)['id' => 0, 'name_ar' => '', 'name_he' => '', 'name_en' => '', 'spec_ar' => '', 'spec_he' => '', 'spec_en' => '', 'photo' => '', 'color' => '#1e6fd9', 'active' => 1, 'sort' => 0, 'schedule' => '', 'break_from' => '', 'break_to' => '', 'holidays' => '', 'service_ids' => '']
            : CB_Doctors::get((int)$_GET['edit'])) : null;
        if ($editing) {
            $f = fn($k, $label, $type = 'text') => '<label>' . esc_html(self::t($label)) . '<input type="' . $type . '" name="' . $k . '" value="' . esc_attr($editing->$k) . '"></label>';
            echo '<section class="cb-card"><div class="cb-card-h"><h3>' . esc_html(self::t($editing->id ? 'a_edit' : 'a_add_doctor')) . '</h3><a href="' . $pg() . '">‹ ' . esc_html(self::t('a_back')) . '</a></div>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="cb-form-grid">';
            wp_nonce_field('cb_save_doctor'); echo self::hidden(); echo '<input type="hidden" name="action" value="cb_save_doctor"><input type="hidden" name="id" value="' . (int)$editing->id . '">';
            echo $f('name_ar', 'a_name_ar') . $f('name_he', 'a_name_he') . $f('name_en', 'a_name_en');
            echo $f('spec_ar', 'a_spec_ar') . $f('spec_he', 'a_spec_he') . $f('spec_en', 'a_spec_en');
            echo '<label>' . esc_html(self::t('a_photo')) . '<span class="cb-media-wrap"><input type="text" name="photo" dir="ltr" value="' . esc_attr($editing->photo) . '">' . (self::$front ? '' : '<button type="button" class="button cb-media">…</button>') . '</span></label>';
            echo $f('color', 'a_color', 'color') . $f('sort', 'a_order', 'number');
            echo '<label class="chk"><input type="checkbox" name="active" value="1" ' . checked($editing->active, 1, false) . '> ' . esc_html(self::t('a_active')) . '</label>';
            $ids = CB_Doctors::service_ids($editing);
            echo '<div class="full"><b>' . esc_html(self::t('a_dr_services')) . '</b><div class="cb-checks">';
            foreach (CB_Services::all(false) as $sv) echo '<label class="chk"><input type="checkbox" name="service_ids[]" value="' . (int)$sv->id . '" ' . checked(in_array((int)$sv->id, $ids, true), true, false) . '> ' . esc_html(CB_Services::name($sv, self::$L)) . '</label>';
            echo '</div></div>';
            $own = $editing->schedule ? json_decode($editing->schedule, true) : null;
            $sched = is_array($own) && $own ? $own : CB_Settings::get('schedule');
            echo '<div class="full"><b>' . esc_html(self::t('a_dr_hours')) . '</b><p class="cb-note">' . esc_html(self::t('a_dr_hours_help')) . '</p>';
            echo '<label class="chk"><input type="checkbox" name="own_sched" value="1" ' . checked(is_array($own) && $own, true, false) . '> ' . esc_html(self::t('a_dr_own')) . '</label>';
            echo '<table class="cb-table"><tr><th></th><th>' . esc_html(self::t('a_open_h')) . '</th><th>' . esc_html(self::t('a_from')) . '</th><th>' . esc_html(self::t('a_to')) . '</th></tr>';
            foreach ([6, 0, 1, 2, 3, 4, 5] as $i) {
                $d = $sched[$i] ?? ['open' => 0, 'from' => '09:00', 'to' => '17:00'];
                echo '<tr><td><b>' . esc_html(CB_I18n::day_name($i, self::$L)) . '</b></td><td><input type="checkbox" name="sched[' . $i . '][open]" value="1" ' . checked(!empty($d['open']), true, false) . '></td><td><input type="time" name="sched[' . $i . '][from]" value="' . esc_attr($d['from']) . '"></td><td><input type="time" name="sched[' . $i . '][to]" value="' . esc_attr($d['to']) . '"></td></tr>';
            }
            echo '</table></div>';
            echo '<label>' . esc_html(self::t('a_dr_break')) . ' — ' . esc_html(self::t('a_from')) . '<input type="time" name="break_from" value="' . esc_attr($editing->break_from) . '"></label><label>' . esc_html(self::t('a_to')) . '<input type="time" name="break_to" value="' . esc_attr($editing->break_to) . '"></label>';
            echo '<label class="full">' . esc_html(self::t('a_dr_holidays')) . '<textarea name="holidays" rows="3" dir="ltr">' . esc_textarea($editing->holidays) . '</textarea><small>' . esc_html(self::t('a_dr_holidays_help')) . '</small></label>';
            echo '<div class="full"><button class="button button-primary">' . esc_html(self::t('a_save')) . '</button></div></form></section>';
            self::close(); return;
        }
        echo '<section class="cb-card"><div class="cb-card-h"><h3>' . esc_html(self::t('a_doctors')) . '</h3><a class="button button-primary" href="' . $pg('&edit=new') . '">+ ' . esc_html(self::t('a_add_doctor')) . '</a></div>';
        echo '<table class="cb-table"><tr><th></th><th>' . esc_html(self::t('a_name')) . '</th><th>' . esc_html(self::t('a_services')) . '</th><th>' . esc_html(self::t('a_dr_hours')) . '</th><th>' . esc_html(self::t('a_status')) . '</th><th></th></tr>';
        $svc = []; foreach (CB_Services::all(false) as $sv) $svc[(int)$sv->id] = CB_Services::name($sv, self::$L);
        foreach (CB_Doctors::all(false) as $d) {
            $ids = CB_Doctors::service_ids($d);
            $names = implode('، ', array_filter(array_map(fn($i) => $svc[$i] ?? '', $ids)));
            echo '<tr><td><span class="cb-av" style="background:' . esc_attr($d->color) . ';color:#fff;font-weight:700">' . esc_html(mb_substr(CB_Doctors::name($d, self::$L), 0, 1)) . '</span></td><td><b>' . esc_html(CB_Doctors::name($d, self::$L)) . '</b><small style="display:block">' . esc_html(CB_Doctors::spec($d, self::$L)) . '</small></td>';
            echo '<td>' . esc_html($ids ? $names : '✔ ' . self::t('a_services')) . '</td><td>' . esc_html($d->schedule ? self::t('a_dr_own') : '—') . '</td><td>' . ($d->active ? '✔' : '—') . '</td><td>';
            echo '<a class="button" href="' . $pg('&edit=' . (int)$d->id) . '">' . esc_html(self::t('a_edit')) . '</a> ';
            echo '<a class="button" onclick="return confirm(\'' . esc_js(self::t('a_confirm')) . '\')" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=cb_delete_doctor&id=' . (int)$d->id . (self::$front ? '&cb_front=' . rawurlencode(self::$front) : '')), 'cb_delete_doctor')) . '">' . esc_html(self::t('a_delete')) . '</a></td></tr>';
        }
        echo '</table></section>';
        self::close();
    }

    static function h_save_doctor() {
        if (!current_user_can('manage_clinic')) wp_die('no');
        check_admin_referer('cb_save_doctor');
        global $wpdb;
        $p = wp_unslash($_POST);
        $t = fn($v) => preg_match('/^\d{2}:\d{2}$/', (string)$v) ? $v : '';
        $d = [
            'name_ar' => sanitize_text_field($p['name_ar'] ?? ''), 'name_he' => sanitize_text_field($p['name_he'] ?? ''), 'name_en' => sanitize_text_field($p['name_en'] ?? ''),
            'spec_ar' => sanitize_text_field($p['spec_ar'] ?? ''), 'spec_he' => sanitize_text_field($p['spec_he'] ?? ''), 'spec_en' => sanitize_text_field($p['spec_en'] ?? ''),
            'photo' => esc_url_raw($p['photo'] ?? ''), 'color' => sanitize_hex_color($p['color'] ?? '') ?: '#1e6fd9',
            'active' => empty($p['active']) ? 0 : 1, 'sort' => (int)($p['sort'] ?? 0),
            'break_from' => $t($p['break_from'] ?? ''), 'break_to' => $t($p['break_to'] ?? ''),
            'holidays' => sanitize_textarea_field($p['holidays'] ?? ''),
            'service_ids' => implode(',', array_filter(array_map('intval', (array)($p['service_ids'] ?? [])))),
        ];
        if ($d['name_ar'] === '' && $d['name_he'] === '' && $d['name_en'] === '') wp_die('name required');
        $sched = '';
        if (!empty($p['own_sched'])) {
            $s = [];
            for ($i = 0; $i < 7; $i++) {
                $r = $p['sched'][$i] ?? [];
                $s[$i] = ['open' => empty($r['open']) ? 0 : 1, 'from' => $t($r['from'] ?? '') ?: '09:00', 'to' => $t($r['to'] ?? '') ?: '17:00'];
            }
            $sched = wp_json_encode($s);
        }
        $d['schedule'] = $sched;
        $id = (int)($p['id'] ?? 0);
        $T = CB_DB::t('doctors');
        if ($id) { if (!$d['active'] && !(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $T WHERE active=1 AND id<>%d", $id))) self::back('cb-doctors', '&err=1'); $wpdb->update($T, $d, ['id' => $id]); }
        else $wpdb->insert($T, $d);
        self::back('cb-doctors', '&saved=1');
    }

    static function h_delete_doctor() {
        if (!current_user_can('manage_clinic')) wp_die('no');
        check_admin_referer('cb_delete_doctor');
        global $wpdb;
        $id = (int)($_GET['id'] ?? 0); $T = CB_DB::t('doctors');
        if (!(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $T WHERE active=1 AND id<>%d", $id))) self::back('cb-doctors', '&err=1');
        $used = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . CB_DB::t('appointments') . ' WHERE doctor_id=%d', $id));
        if ($used) $wpdb->update($T, ['active' => 0], ['id' => $id]); else $wpdb->delete($T, ['id' => $id]);
        self::back('cb-doctors', '&saved=1');
    }

    // ------------------------------------------------------------ staff management
    static function staff_roles() { return ['clinic_doctor' => 'a_doctor', 'clinic_reception' => 'a_reception']; }

    static function is_staff_user($u) {
        $r = (array)$u->roles;
        return $r && !array_diff($r, array_keys(self::staff_roles()));
    }

    static function page_staff() {
        if (!self::can_staff()) wp_die('no');
        self::open('cb-staff');
        $roles = self::staff_roles();
        $users = get_users(['role__in' => array_keys($roles), 'orderby' => 'display_name']);
        $msgs = ['exists' => 'a_err_exists', 'email' => 'a_err_email', 'pass' => 'a_err_pass'];
        if (!empty($_GET['saved'])) echo '<div class="cb-notice">' . esc_html(self::t('a_saved')) . '</div>';
        if (!empty($_GET['err']) && isset($msgs[$_GET['err']])) echo '<div class="cb-notice bad">' . esc_html(self::t($msgs[$_GET['err']])) . '</div>';
        $post = esc_url(admin_url('admin-post.php'));

        echo '<section class="cb-card"><div class="cb-card-h"><h3>' . esc_html(self::t('a_staff')) . '</h3></div><table class="cb-table"><tr><th>' . esc_html(self::t('a_name')) . '</th><th>' . esc_html(self::t('a_email')) . '</th><th>' . esc_html(self::t('a_username')) . '</th><th>' . esc_html(self::t('a_role')) . '</th><th></th></tr>';
        foreach ($users as $u) {
            $cur = $u->roles[0] ?? '';
            echo '<tr><td><b>' . esc_html($u->display_name) . '</b></td><td dir="ltr" style="text-align:right">' . esc_html($u->user_email) . '</td><td dir="ltr" style="text-align:right">' . esc_html($u->user_login) . '</td><td>';
            echo '<form method="post" action="' . $post . '" class="cb-inline">'; wp_nonce_field('cb_save_staff'); echo self::hidden();
            echo '<input type="hidden" name="action" value="cb_save_staff"><input type="hidden" name="id" value="' . (int)$u->ID . '"><select name="role">';
            foreach ($roles as $r => $k) echo '<option value="' . $r . '" ' . selected($cur, $r, false) . '>' . esc_html(self::t($k)) . '</option>';
            echo '</select> <button class="button">' . esc_html(self::t('a_save')) . '</button></form></td><td>';
            if ($u->ID !== get_current_user_id()) {
                $del = wp_nonce_url(admin_url('admin-post.php?action=cb_delete_staff&id=' . (int)$u->ID . (self::$front ? '&cb_front=' . rawurlencode(self::$front) : '')), 'cb_delete_staff');
                echo '<a class="button" onclick="return confirm(\'' . esc_js(self::t('a_confirm')) . '\')" href="' . esc_url($del) . '">' . esc_html(self::t('a_delete')) . '</a>';
            }
            echo '</td></tr>';
        }
        if (!$users) echo '<tr><td colspan="5">' . esc_html(self::t('a_none')) . '</td></tr>';
        echo '</table><p class="cb-note">' . esc_html(self::t('a_staff_note')) . '</p></section>';

        echo '<section class="cb-card"><div class="cb-card-h"><h3>' . esc_html(self::t('a_add_staff')) . '</h3></div><form method="post" action="' . $post . '" class="cb-form-grid">';
        wp_nonce_field('cb_save_staff'); echo self::hidden();
        echo '<input type="hidden" name="action" value="cb_save_staff">';
        echo '<label>' . esc_html(self::t('a_name')) . '<input name="name" required></label>';
        echo '<label>' . esc_html(self::t('a_email')) . '<input type="email" name="email" required dir="ltr"></label>';
        echo '<label>' . esc_html(self::t('a_username')) . '<input name="username" required dir="ltr" autocomplete="off"></label>';
        echo '<label>' . esc_html(self::t('a_role')) . '<select name="role">';
        foreach ($roles as $r => $k) echo '<option value="' . $r . '">' . esc_html(self::t($k)) . '</option>';
        echo '</select></label>';
        echo '<label class="full">' . esc_html(self::t('a_password')) . '<input type="text" name="password" dir="ltr" autocomplete="off"><small>' . esc_html(self::t('a_pw_help')) . '</small></label>';
        echo '<div class="full"><button class="button button-primary">' . esc_html(self::t('a_add_staff')) . '</button></div></form></section>';
        self::close();
    }

    static function h_save_staff() {
        if (!self::can_staff()) wp_die('no');
        check_admin_referer('cb_save_staff');
        $p = wp_unslash($_POST);
        $role = array_key_exists($p['role'] ?? '', self::staff_roles()) ? $p['role'] : 'clinic_reception';
        $id = (int)($p['id'] ?? 0);
        if ($id) {
            $u = get_userdata($id);
            if ($u && self::is_staff_user($u) && $u->ID !== get_current_user_id()) $u->set_role($role);
            self::back('cb-staff', '&saved=1');
        }
        $email = sanitize_email($p['email'] ?? ''); $login = sanitize_user($p['username'] ?? '', true); $name = sanitize_text_field($p['name'] ?? '');
        if (!is_email($email) || $login === '' || $name === '') self::back('cb-staff', '&err=email');
        if (username_exists($login) || email_exists($email)) self::back('cb-staff', '&err=exists');
        $pw = (string)($p['password'] ?? '');
        if ($pw !== '' && strlen($pw) < 8) self::back('cb-staff', '&err=pass');
        $uid = wp_insert_user(['user_login' => $login, 'user_email' => $email, 'display_name' => $name, 'first_name' => $name, 'user_pass' => $pw !== '' ? $pw : wp_generate_password(20), 'role' => $role]);
        if (is_wp_error($uid)) self::back('cb-staff', '&err=exists');
        if ($pw === '') wp_new_user_notification($uid, null, 'user'); // emails a "set your password" link
        self::back('cb-staff', '&saved=1');
    }

    static function h_delete_staff() {
        if (!self::can_staff()) wp_die('no');
        check_admin_referer('cb_delete_staff');
        $u = get_userdata((int)($_GET['id'] ?? 0));
        if ($u && self::is_staff_user($u) && $u->ID !== get_current_user_id()) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($u->ID); }
        self::back('cb-staff', '&saved=1');
    }

    // ------------------------------------------------------------ shortcodes cheat-sheet
    static function page_shortcodes() {
        if (!current_user_can('manage_options')) wp_die('no');
        self::open('cb-shortcodes');
        $rows = [
            ['[clinic_page]', 'صفحة العيادة الكاملة: واجهة + خدمات + حجز + ساعات العمل + تواصل + الشات بوت', 'דף המרפאה המלא: כותרת, שירותים, קביעת תור, שעות, יצירת קשר', 'Full clinic page: hero, services, booking, hours, contact'],
            ['[clinic_booking]', 'نموذج حجز الموعد فقط', 'טופס קביעת תור בלבד', 'Booking form only'],
            ['[clinic_services]', 'شبكة خدمات العيادة', 'רשת שירותי המרפאה', 'Services grid'],
            ['[clinic_doctors]', 'بطاقات الأطباء', 'כרטיסי הרופאים', 'Doctor cards'],
            ['[clinic_hours]', 'جدول ساعات العمل', 'טבלת שעות הפעילות', 'Opening hours table'],
            ['[clinic_contact]', 'العنوان والهاتف وواتساب والخريطة والسوشيال', 'כתובת, טלפון, וואטסאפ, מפה ורשתות חברתיות', 'Address, phone, WhatsApp, map and social links'],
            ['[clinic_chatbot]', 'الشات بوت داخل الصفحة (يظهر تلقائيًا كزر عائم إن كان مفعّلًا)', 'הצ׳אטבוט בתוך הדף (מופיע אוטומטית ככפתור צף)', 'Chatbot inside the page (also shows as a floating button when enabled)'],
            ['[clinic_dashboard]', 'لوحة تحكم العيادة في صفحة مستقلة لموظفي العيادة بعد تسجيل الدخول', 'לוח הבקרה של המרפאה בדף עצמאי לצוות לאחר התחברות', 'Clinic dashboard on its own page for staff after sign-in'],
        ];
        $he = self::$L === 'he'; $en = self::$L === 'en';
        $tr = function ($ar, $h, $e) use ($he, $en) { return $en ? $e : ($he ? $h : $ar); };
        echo '<section class="cb-card"><h2 class="cb-h2">' . esc_html(self::t('a_shortcodes')) . '</h2><p class="cb-note">' . esc_html($tr('الصق الكود داخل أي صفحة أو مقال (بلوك «شورتكود» / Shortcode).', 'הדביקו את הקוד בדף או בפוסט (בלוק «קיצור דרך» / Shortcode).', 'Paste the code into any page or post (Shortcode block).')) . '</p>';
        echo '<table class="cb-table"><tr><th>' . esc_html($tr('الكود', 'קוד', 'Code')) . '</th><th>' . esc_html($tr('الوظيفة', 'תיאור', 'What it does')) . '</th><th></th></tr>';
        foreach ($rows as $r) echo '<tr><td><code dir="ltr">' . esc_html($r[0]) . '</code></td><td>' . esc_html($en ? $r[3] : ($he ? $r[2] : $r[1])) . '</td><td><button type="button" class="button cb-copy" data-code="' . esc_attr($r[0]) . '">' . esc_html(self::t('a_copy')) . '</button></td></tr>';
        echo '</table></section>';
        $logo = CB_Settings::get('logo');
        echo '<section class="cb-card"><h3>' . esc_html($tr('الشعار', 'לוגו', 'Logo')) . '</h3><p>' . ($logo ? '<img src="' . esc_url($logo) . '" style="max-height:70px">' : '—') . '</p><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=cb-settings&tab=appearance')) . '">' . esc_html($tr('تغيير الشعار', 'שינוי לוגו', 'Change logo')) . '</a></section>';
        self::close();
    }
}
