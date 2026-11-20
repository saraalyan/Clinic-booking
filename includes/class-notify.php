<?php
if (!defined('ABSPATH')) exit;

/**
 * Free notifications: e-mail confirmation / reminder to the patient (with an .ics calendar file attached
 * and an "add to Google Calendar" link), payment notices, and the reminder cron (e-mail + WhatsApp).
 */
class CB_Notify {
    static function init() {
        add_action('cb_appointment_created', [__CLASS__, 'on_created']);
        add_action('cb_appointment_rescheduled', [__CLASS__, 'on_rescheduled']);
        add_action('cb_appointment_status', [__CLASS__, 'on_status'], 10, 2);
        add_action('cb_payment_submitted', [__CLASS__, 'on_proof']);
        add_action('cb_payment_rejected', [__CLASS__, 'on_rejected']);
        add_action('cb_send_reminders', [__CLASS__, 'reminders']);
    }

    // ---------- calendar ----------
    private static function utc($local) {
        return (new DateTime($local, wp_timezone()))->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    private static function esc($s) {
        return str_replace(["\\", ";", ",", "\r\n", "\n", "\r"], ["\\\\", "\\;", "\\,", "\\n", "\\n", "\\n"], (string)$s);
    }

    static function title($row, $lang) {
        return CB_Services::name($row, $lang) . ' – ' . CB_Settings::loc('clinic_name', $lang);
    }

    static function ics($row) {
        $lang = CB_I18n::ok($row->lang) ? $row->lang : 'ar';
        $desc = CB_Services::name($row, $lang);
        $dr = CB_Booking::doctor_name($row, $lang);
        if ($dr) $desc .= "\n" . $dr;
        $L = [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Clinic Booking//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
            'BEGIN:VEVENT', 'UID:cb-' . (int)$row->id . '-' . substr(md5($row->token), 0, 8) . '@' . wp_parse_url(home_url(), PHP_URL_HOST),
            'DTSTAMP:' . gmdate('Ymd\THis\Z'), 'DTSTART:' . self::utc($row->start_dt), 'DTEND:' . self::utc($row->end_dt),
            'SUMMARY:' . self::esc(self::title($row, $lang)), 'DESCRIPTION:' . self::esc($desc),
            'LOCATION:' . self::esc(CB_Settings::loc('address', $lang)),
            'BEGIN:VALARM', 'TRIGGER:-PT2H', 'ACTION:DISPLAY', 'DESCRIPTION:' . self::esc(self::title($row, $lang)), 'END:VALARM',
            'END:VEVENT', 'END:VCALENDAR',
        ];
        return implode("\r\n", $L) . "\r\n";
    }

    static function google_url($row) {
        $lang = CB_I18n::ok($row->lang) ? $row->lang : 'ar';
        return add_query_arg([
            'action' => 'TEMPLATE', 'text' => self::title($row, $lang),
            'dates' => self::utc($row->start_dt) . '/' . self::utc($row->end_dt),
            'details' => CB_Services::name($row, $lang), 'location' => CB_Settings::loc('address', $lang),
        ], 'https://calendar.google.com/calendar/render');
    }

    static function ics_url($row) { return add_query_arg('token', rawurlencode($row->token), rest_url(CB_Rest::NS . '/ics')); }

    // ---------- e-mail ----------
    private static function tr($key, $lang, $vars = []) {
        $s = CB_I18n::t($key, $lang);
        foreach ($vars as $k => $v) $s = str_replace('{' . $k . '}', $v, $s);
        return $s;
    }

    static function enabled() { return (int)CB_Settings::get('email_enabled') === 1; }

    private static function send($to, $subject, $body, $attach = []) {
        return wp_mail($to, $subject, $body, ['Content-Type: text/plain; charset=UTF-8'], $attach);
    }

    /** Builds and sends a patient e-mail. $kind: confirmed|pending|pay_wait|pay_sub|pay_rej|resched|cancelled|reminder */
    static function patient_mail($row, $kind) {
        if (!self::enabled() || !is_email($row->patient_email)) return false;
        $lang = CB_I18n::ok($row->lang) ? $row->lang : 'ar';
        $clinic = CB_Settings::loc('clinic_name', $lang);
        $when = date_i18n('Y-m-d', strtotime($row->start_dt)) . ' ' . date('H:i', strtotime($row->start_dt));
        $L = [self::tr('m_hello', $lang, ['name' => $row->patient_name]), '', self::tr('m_body_' . $kind, $lang), ''];
        $L[] = self::tr('m_service', $lang) . ': ' . CB_Services::name($row, $lang);
        if ($dr = CB_Booking::doctor_name($row, $lang)) $L[] = self::tr('m_doctor', $lang) . ': ' . $dr;
        if ($kind !== 'cancelled') $L[] = self::tr('m_when', $lang) . ': ' . $when;
        if ($ad = CB_Settings::loc('address', $lang)) $L[] = self::tr('m_address', $lang) . ': ' . $ad;
        $attach = []; $tmp = '';
        if (in_array($kind, ['pay_wait', 'pay_rej'], true) && $row->pay_amount !== null) {
            $L[] = ''; $L[] = self::tr('m_pay_amount', $lang) . ': ' . CB_Settings::money($row->pay_amount);
            if ($info = CB_Payments::info($lang)) { $L[] = self::tr('m_pay_info', $lang) . ':'; $L[] = $info; }
            if ($row->hold_until) $L[] = self::tr('m_pay_until', $lang, ['t' => date_i18n('Y-m-d H:i', strtotime($row->hold_until))]);
            $L[] = ''; $L[] = self::tr('m_pay_link', $lang); $L[] = CB_Booking::manage_url($row->token);
        } elseif (in_array($kind, ['confirmed', 'resched', 'reminder'], true)) {
            $L[] = ''; $L[] = self::tr('m_add_cal', $lang); $L[] = self::google_url($row);
            if (!function_exists('wp_tempnam')) require_once ABSPATH . 'wp-admin/includes/file.php';
            $tmp = wp_tempnam('cb-ics');
            if ($tmp && file_put_contents($tmp, self::ics($row))) {
                $named = dirname($tmp) . '/appointment-' . (int)$row->id . '.ics';
                if (@rename($tmp, $named)) $tmp = $named;
                $attach[] = $tmp; $L[] = self::tr('m_ics_attached', $lang);
            }
        }
        if (in_array($kind, ['confirmed', 'pending', 'resched', 'reminder'], true)) {
            $L[] = ''; $L[] = self::tr('m_manage', $lang); $L[] = CB_Booking::manage_url($row->token);
        }
        $L[] = ''; $L[] = $clinic;
        if ($ph = CB_Settings::get('phone')) $L[] = $ph;
        $ok = self::send($row->patient_email, self::tr('m_subj_' . $kind, $lang, ['clinic' => $clinic]), implode("\n", $L), $attach);
        foreach ($attach as $f) @unlink($f);
        return $ok;
    }

    static function send_otp($email, $code, $lang) {
        if (!is_email($email)) return false;
        return self::send($email, self::tr('m_otp_subj', $lang, ['code' => $code]), self::tr('m_otp_body', $lang, ['code' => $code]));
    }

    // ---------- events ----------
    static function on_created($id) {
        $row = CB_Booking::get($id);
        if (!$row) return;
        if ($row->pay_status === 'awaiting') self::patient_mail($row, 'pay_wait');
        elseif ($row->status === 'confirmed') self::patient_mail($row, 'confirmed');
        else self::patient_mail($row, 'pending');
    }

    static function on_status($id, $status) {
        $row = CB_Booking::get($id);
        if (!$row) return;
        if ($status === 'confirmed' && $row->source !== 'admin_silent') self::patient_mail($row, 'confirmed');
        elseif ($status === 'cancelled' && $row->pay_status !== 'expired') self::patient_mail($row, 'cancelled');
    }

    static function on_rescheduled($id) {
        $row = CB_Booking::get($id);
        if ($row && $row->status === 'confirmed') self::patient_mail($row, 'resched');
    }

    static function on_proof($id) {
        $row = CB_Booking::get($id);
        if (!$row) return;
        self::patient_mail($row, 'pay_sub');
        if ($to = CB_Settings::get('notify_email')) {
            $l = 'ar';
            self::send($to, self::tr('m_staff_proof_subj', $l), self::tr('m_staff_proof_body', $l) . "\n" . admin_url('admin.php?page=clinic-appointments&id=' . (int)$id) .
                "\n{$row->patient_name} +{$row->patient_phone}\n" . CB_Settings::money($row->pay_amount));
        }
    }

    static function on_rejected($id) {
        $row = CB_Booking::get($id);
        if ($row) self::patient_mail($row, 'pay_rej');
    }

    /** Hourly: reminder by WhatsApp and/or e-mail for confirmed appointments inside the reminder window. */
    static function reminders() {
        global $wpdb;
        $wa = CB_Settings::get('wa_enabled') && CB_Settings::get('wa_tpl_reminder');
        $em = self::enabled() && CB_Settings::get('email_reminders');
        $now = current_time('timestamp');
        $until = date('Y-m-d H:i:s', $now + (int)CB_Settings::get('reminder_hours') * 3600);
        $ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . CB_DB::t('appointments') .
            " WHERE reminded=0 AND status='confirmed' AND start_dt > %s AND start_dt <= %s LIMIT 50", date('Y-m-d H:i:s', $now), $until));
        foreach ($ids as $id) {
            $wpdb->update(CB_DB::t('appointments'), ['reminded' => 1], ['id' => $id]); // mark first: no endless retries
            $row = CB_Booking::get($id);
            if (!$row) continue;
            if ($wa) CB_WhatsApp::send($row, 'wa_tpl_reminder');
            if ($em) self::patient_mail($row, 'reminder');
        }
    }
}
