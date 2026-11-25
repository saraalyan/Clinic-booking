<?php
if (!defined('ABSPATH')) exit;

class CB_Settings {
    private static $cache = null;

    static function defaults() {
        $sched = [];
        for ($i = 0; $i < 7; $i++) $sched[$i] = ['open' => $i === 5 ? 0 : 1, 'from' => '08:00', 'to' => '20:00'];
        return [
            'clinic_name_ar' => 'عيادة الأمل', 'clinic_name_he' => 'מרפאת התקווה', 'clinic_name_en' => 'Al-Amal Clinic',
            'tagline_ar' => 'خدمات الطبية العامة', 'tagline_he' => 'שירותי רפואה כללית', 'tagline_en' => 'General medical services',
            'welcome_ar' => 'نظام حجز مواعيد سهل وسريع', 'welcome_he' => 'מערכת קביעת תורים קלה ומהירה', 'welcome_en' => 'Easy and fast appointment booking',
            'about_ar' => 'نقدم لكم رعاية طبية متكاملة بفريق متخصص وبيئة مريحة. احجزوا موعدكم بضغطة زر.',
            'about_he' => 'אנו מציעים טיפול רפואי מקיף עם צוות מקצועי וסביבה נעימה. קבעו תור בלחיצת כפתור.',
            'about_en' => 'We offer complete medical care with a specialised team in a comfortable setting. Book your appointment with one click.',
            'address_ar' => 'الرياض - حي النخيل', 'address_he' => '', 'address_en' => 'Riyadh – Al Nakheel District',
            'phone' => '+966501234567', 'whatsapp' => '966501234567', 'email' => 'info@example.com',
            'map_embed' => '', 'map_link' => '',
            'facebook' => '', 'instagram' => '', 'x_url' => '',
            'logo' => defined('CB_URL') ? CB_URL . 'assets/img/logo.png' : '', 'hero_image' => '', 'primary' => '#244860', 'accent' => '#4f9a9b',
            'default_lang' => 'ar', 'currency' => '',
            'schedule' => $sched, 'break_from' => '', 'break_to' => '', 'holidays' => '',
            'slot_step' => 15, 'min_notice' => 2, 'max_days' => 60,
            'allow_cancel' => 1, 'allow_resched' => 1, 'cancel_hours' => 24, 'auto_confirm' => 1,
            'country_code' => '966', 'notify_email' => get_option('admin_email'),
            'wa_enabled' => 0, 'wa_phone_id' => '', 'wa_token' => '', 'wa_tpl_confirm' => '', 'wa_tpl_reminder' => '',
            'wa_lang_ar' => 'ar', 'wa_lang_he' => 'he', 'wa_lang_en' => 'en', 'reminder_hours' => 24, 'arrive_early' => 10,
            'verify_method' => 'none', 'max_active' => 3, 'wa_tpl_otp' => '', 'wa_otp_button' => 1,
            'email_enabled' => 1, 'email_reminders' => 1,
            'pay_mode' => 0, 'pay_hold' => 120, 'pay_info_ar' => '', 'pay_info_he' => '', 'pay_info_en' => '',
            'bot_enabled' => 1, 'bot_floating' => 1, 'faq_ar' => '', 'faq_he' => '', 'faq_en' => '', 'font' => 'hayyakum',
        ];
    }

    static function all() {
        if (self::$cache === null) self::$cache = wp_parse_args(get_option('cb_settings', []), self::defaults());
        return self::$cache;
    }

    static function get($k) { $a = self::all(); return $a[$k] ?? ''; }

    static function update($new) {
        $cur = get_option('cb_settings', []);
        update_option('cb_settings', array_merge(is_array($cur) ? $cur : [], $new));
        self::$cache = null;
    }

    /** Localized setting: key_ar / key_he with fallback to the other language. */
    static function loc($key, $lang) {
        foreach ([$lang, 'ar', 'he', 'en'] as $l) {
            $v = self::get($key . '_' . $l);
            if ($v !== '' && $v !== null) return $v;
        }
        return '';
    }

    static function money($price, $currency = null) {
        if ($price === null || $price === '') return '';
        $cur = $currency ?? self::get('currency');
        return rtrim(rtrim(number_format((float)$price, 2, '.', ''), '0'), '.') . ($cur ? ' ' . $cur : '');
    }
}
