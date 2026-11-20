<?php
if (!defined('ABSPATH')) exit;

/**
 * Phone verification before a public booking is created (stops fake bookings).
 * The code is sent by WhatsApp (authentication template) or e-mail, lives 10 minutes, allows 5 attempts.
 */
class CB_Verify {
    const TTL = 600;
    const MAX_TRIES = 5;
    const COOLDOWN = 60;

    /** Effective method: 'none' | 'whatsapp' | 'email'. A method that is not configured is treated as 'none' so patients are never locked out. */
    static function method() {
        $m = CB_Settings::get('verify_method');
        if ($m === 'whatsapp') {
            return (CB_Settings::get('wa_enabled') && CB_Settings::get('wa_phone_id') && CB_Settings::get('wa_token') && CB_Settings::get('wa_tpl_otp')) ? 'whatsapp' : 'none';
        }
        return $m === 'email' ? 'email' : 'none';
    }

    private static function key($phone) { return 'cb_otp_' . md5($phone); }

    static function mask_email($e) {
        $p = explode('@', $e);
        return count($p) === 2 ? mb_substr($p[0], 0, 2) . '***@' . $p[1] : $e;
    }

    /** Where the code went, for the on-screen message. */
    static function target($n) {
        return self::method() === 'email' ? self::mask_email($n['email']) : '+' . substr($n['phone'], 0, 3) . '••••' . substr($n['phone'], -3);
    }

    /** Creates and sends a code. $n = normalized booking data (phone, email, lang). */
    static function send($n) {
        $method = self::method();
        if ($method === 'none') return true;
        $key = self::key($n['phone']);
        $cur = get_transient($key);
        if (is_array($cur) && time() - $cur['sent'] < self::COOLDOWN) {
            return new WP_Error('otp_wait', (string)(self::COOLDOWN - (time() - $cur['sent'])));
        }
        $code = (string)random_int(100000, 999999);
        $ok = $method === 'whatsapp'
            ? CB_WhatsApp::send_otp($n['phone'], $code, $n['lang'])
            : CB_Notify::send_otp($n['email'], $code, $n['lang']);
        if (!$ok) return new WP_Error('otp_fail', 'send failed');
        set_transient($key, ['h' => wp_hash($code . $n['phone']), 'tries' => 0, 'sent' => time()], self::TTL);
        return true;
    }

    /** true | WP_Error(otp_expired|otp_bad|otp_toomany) */
    static function check($phone, $code) {
        if (self::method() === 'none') return true;
        $key = self::key($phone);
        $cur = get_transient($key);
        if (!is_array($cur)) return new WP_Error('otp_expired', 'expired');
        if ($cur['tries'] >= self::MAX_TRIES) { delete_transient($key); return new WP_Error('otp_toomany', 'too many'); }
        $code = preg_replace('/\D+/', '', (string)$code);
        if (!hash_equals($cur['h'], wp_hash($code . $phone))) {
            $cur['tries']++;
            set_transient($key, $cur, self::TTL);
            return new WP_Error($cur['tries'] >= self::MAX_TRIES ? 'otp_toomany' : 'otp_bad', 'bad code');
        }
        delete_transient($key);
        return true;
    }
}
