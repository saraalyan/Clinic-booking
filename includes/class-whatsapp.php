<?php
if (!defined('ABSPATH')) exit;

/**
 * WhatsApp Cloud API (Meta). Uses pre-approved templates with 5 body variables:
 * {{1}} patient name, {{2}} service, {{3}} date, {{4}} time, {{5}} clinic name
 */
class CB_WhatsApp {
    static function init() {
        add_action('cb_appointment_created', [__CLASS__, 'on_created']);
        add_action('cb_appointment_rescheduled', [__CLASS__, 'on_confirmed']);
        add_action('cb_appointment_status', function ($id, $status) { if ($status === 'confirmed') self::on_confirmed($id); }, 10, 2);
    }

    static function on_created($id) {
        $row = CB_Booking::get($id);
        if (!$row) return;
        $to = CB_Settings::get('notify_email');
        if ($to && $row->source !== 'admin') {
            $svc = CB_Services::name($row, 'ar');
            $dr = CB_Booking::doctor_name($row, 'ar');
            wp_mail($to, 'حجز جديد: ' . $row->patient_name,
                "مريض: {$row->patient_name}\nهاتف: +{$row->patient_phone}\nالخدمة: $svc\nالطبيب: $dr\nالموعد: {$row->start_dt}\nالحالة: {$row->status}\nالدفع: {$row->pay_status}\nالمصدر: {$row->source}");
        }
        if ($row->status === 'confirmed') self::on_confirmed($id);
    }

    static function on_confirmed($id) {
        $row = CB_Booking::get($id);
        if ($row && $row->status === 'confirmed') self::send($row, 'wa_tpl_confirm');
    }

    static function send($row, $tpl_key) {
        $s = CB_Settings::all();
        if (empty($s['wa_enabled']) || empty($s['wa_phone_id']) || empty($s['wa_token']) || empty($s[$tpl_key])) return false;
        $lang = CB_I18n::ok($row->lang) ? $row->lang : 'ar';
        $params = [
            $row->patient_name, CB_Services::name($row, $lang),
            date_i18n('Y-m-d', strtotime($row->start_dt)), date('H:i', strtotime($row->start_dt)),
            CB_Settings::loc('clinic_name', $lang),
        ];
        $body = [
            'messaging_product' => 'whatsapp', 'to' => $row->patient_phone, 'type' => 'template',
            'template' => [
                'name' => $s[$tpl_key], 'language' => ['code' => ($s['wa_lang_' . $lang] ?? '') ?: $lang],
                'components' => [['type' => 'body', 'parameters' => array_map(function ($t) { return ['type' => 'text', 'text' => (string)$t]; }, $params)]],
            ],
        ];
        $r = wp_remote_post('https://graph.facebook.com/v20.0/' . rawurlencode($s['wa_phone_id']) . '/messages', [
            'timeout' => 15,
            'headers' => ['Authorization' => 'Bearer ' . $s['wa_token'], 'Content-Type' => 'application/json'],
            'body' => wp_json_encode($body),
        ]);
        $code = is_wp_error($r) ? 0 : wp_remote_retrieve_response_code($r);
        $ok = $code >= 200 && $code < 300;
        self::log(($ok ? 'OK ' : 'ERR ') . "#{$row->id} → +{$row->patient_phone} [$tpl_key] " . ($ok ? '' : (is_wp_error($r) ? $r->get_error_message() : wp_remote_retrieve_body($r))));
        return $ok;
    }

    /** Authentication template: body {{1}} = code; optional URL button (copy-code) also gets the code. */
    static function send_otp($phone, $code, $lang) {
        $s = CB_Settings::all();
        if (empty($s['wa_enabled']) || empty($s['wa_phone_id']) || empty($s['wa_token']) || empty($s['wa_tpl_otp'])) return false;
        $comp = [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => (string)$code]]]];
        if (!empty($s['wa_otp_button'])) $comp[] = ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => (string)$code]]];
        $body = ['messaging_product' => 'whatsapp', 'to' => $phone, 'type' => 'template',
            'template' => ['name' => $s['wa_tpl_otp'], 'language' => ['code' => ($s['wa_lang_' . $lang] ?? '') ?: $lang], 'components' => $comp]];
        $r = wp_remote_post('https://graph.facebook.com/v20.0/' . rawurlencode($s['wa_phone_id']) . '/messages', [
            'timeout' => 15, 'headers' => ['Authorization' => 'Bearer ' . $s['wa_token'], 'Content-Type' => 'application/json'], 'body' => wp_json_encode($body)]);
        $c = is_wp_error($r) ? 0 : wp_remote_retrieve_response_code($r);
        $ok = $c >= 200 && $c < 300;
        self::log(($ok ? 'OK ' : 'ERR ') . "OTP → +$phone " . ($ok ? '' : (is_wp_error($r) ? $r->get_error_message() : wp_remote_retrieve_body($r))));
        return $ok;
    }

    static function log($msg) {
        $log = get_option('cb_wa_log', []);
        array_unshift($log, current_time('Y-m-d H:i') . ' ' . mb_substr($msg, 0, 300));
        update_option('cb_wa_log', array_slice($log, 0, 30), false);
    }
}
