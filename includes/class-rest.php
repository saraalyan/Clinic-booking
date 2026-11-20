<?php
if (!defined('ABSPATH')) exit;

class CB_Rest {
    const NS = 'clinic/v1';

    static function init() { add_action('rest_api_init', [__CLASS__, 'routes']); }

    static function lang($r) {
        $l = sanitize_key($r->get_param('lang'));
        return CB_I18n::ok($l) ? $l : CB_I18n::front_lang();
    }

    static function staff() { return current_user_can('manage_clinic'); }

    static function routes() {
        $pub = '__return_true';
        $g = function ($path, $cb, $perm = null) {
            register_rest_route(self::NS, $path, ['methods' => 'GET', 'callback' => [__CLASS__, $cb], 'permission_callback' => $perm ?: '__return_true']);
        };
        $p = function ($path, $cb, $perm = null, $m = 'POST') {
            register_rest_route(self::NS, $path, ['methods' => $m, 'callback' => [__CLASS__, $cb], 'permission_callback' => $perm ?: '__return_true']);
        };
        $staff = [__CLASS__, 'staff'];
        $g('/services', 'services');
        $g('/doctors', 'doctors');
        $g('/days', 'days');
        $g('/slots', 'slots');
        $g('/appointment', 'appointment');
        $g('/ics', 'ics');
        $p('/otp', 'otp');
        $p('/pay', 'pay');
        $p('/book', 'book');
        $p('/cancel', 'cancel');
        $p('/reschedule', 'reschedule');
        $p('/chat', 'chat');
        $p('/handoff', 'handoff');
        // staff
        $g('/admin/appointments', 'a_list', $staff);
        $p('/admin/appointments', 'a_create', $staff);
        $p('/admin/appointments/(?P<id>\d+)', 'a_update', $staff, 'PUT,PATCH,POST');
        $p('/admin/appointments/(?P<id>\d+)/delete', 'a_delete', $staff);
        $p('/admin/appointments/(?P<id>\d+)/pay', 'a_pay', $staff);
    }

    private static function limit($key, $max, $window) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0';
        $k = 'cb_rl_' . $key . '_' . md5($ip);
        $n = (int)get_transient($k);
        if ($n >= $max) return false;
        set_transient($k, $n + 1, $window);
        return true;
    }

    private static function err($code, $msg, $status = 400) { return new WP_REST_Response(['ok' => false, 'code' => $code, 'message' => $msg], $status); }

    // ---------- public ----------
    static function services($r) {
        $lang = self::lang($r);
        return ['services' => array_map(function ($s) use ($lang) { return CB_Services::public_row($s, $lang); }, CB_Services::all())];
    }

    static function doctors($r) {
        $lang = self::lang($r);
        $sid = (int)$r->get_param('service_id');
        $list = $sid ? CB_Doctors::for_service($sid) : CB_Doctors::all(true);
        return ['doctors' => array_map(function ($d) use ($lang) { return CB_Doctors::public_row($d, $lang); }, $list)];
    }

    static function days($r) {
        $m = sanitize_text_field($r->get_param('month'));
        if (!preg_match('/^\d{4}-\d{2}$/', $m)) $m = current_time('Y-m');
        return ['days' => CB_Availability::month_days($m, (int)$r->get_param('service_id'), (int)$r->get_param('doctor_id'))];
    }

    static function slots($r) {
        return ['slots' => CB_Availability::slots(sanitize_text_field($r->get_param('date')), (int)$r->get_param('service_id'), (int)$r->get_param('doctor_id'))];
    }

    /** Approximate queue position for the day (same doctor) + suggested arrival time. */
    private static function queue($row) {
        global $wpdb;
        $day = substr($row->start_dt, 0, 10);
        $t = CB_DB::t('appointments');
        $base = $wpdb->prepare(" WHERE doctor_id=%d AND status IN ('pending','confirmed') AND start_dt >= %s AND start_dt < %s", (int)$row->doctor_id, $day . ' 00:00:00', $day . ' 23:59:59');
        $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM $t" . $base);
        $pos = (int)$wpdb->get_var("SELECT COUNT(*) FROM $t" . $base . $wpdb->prepare(' AND (start_dt < %s OR (start_dt = %s AND id <= %d))', $row->start_dt, $row->start_dt, (int)$row->id));
        $m = max(0, (int)CB_Settings::get('arrive_early'));
        return ['no' => max(1, $pos), 'total' => max(1, $total), 'arrive' => date('H:i', strtotime($row->start_dt) - $m * 60), 'minutes' => $m];
    }

    private static function public_appt($row, $lang) {
        return [
            'service' => CB_Services::name($row, $lang), 'service_id' => (int)$row->service_id,
            'doctor' => CB_Booking::doctor_name($row, $lang), 'doctor_id' => (int)$row->doctor_id,
            'date' => substr($row->start_dt, 0, 10), 'time' => substr($row->start_dt, 11, 5),
            'status' => $row->status, 'name' => $row->patient_name, 'can_change' => CB_Booking::can_change($row),
            'allow_resched' => (bool)CB_Settings::get('allow_resched'), 'cancel_hours' => (int)CB_Settings::get('cancel_hours'),
            'payment' => CB_Payments::public_view($row, $lang),
            'queue' => self::queue($row),
            'hold_until' => $row->hold_until ? date_i18n('Y-m-d H:i', strtotime($row->hold_until)) : '',
            'cal' => in_array($row->status, ['pending', 'confirmed'], true) ? ['google' => CB_Notify::google_url($row), 'ics' => CB_Notify::ics_url($row)] : null,
        ];
    }

    static function appointment($r) {
        CB_Payments::expire_holds();
        $row = CB_Booking::get_by_token(sanitize_text_field($r->get_param('token')));
        if (!$row) return self::err('nf', 'not found', 404);
        return ['ok' => true, 'appointment' => self::public_appt($row, self::lang($r))];
    }

    private static function booking_input($r, $lang) {
        return [
            'service_id' => $r->get_param('service_id'), 'doctor_id' => $r->get_param('doctor_id'), 'date' => $r->get_param('date'), 'time' => $r->get_param('time'),
            'name' => $r->get_param('name'), 'phone' => $r->get_param('phone'), 'email' => $r->get_param('email'), 'notes' => $r->get_param('notes'),
            'lang' => $lang, 'source' => $r->get_param('source') === 'chatbot' ? 'chatbot' : 'web',
        ];
    }

    private static function fail($e) {
        $c = $e->get_error_code();
        $status = ['slot_taken' => 409, 'blocked' => 403, 'limit' => 403, 'otp_wait' => 429, 'otp_fail' => 502, 'otp_toomany' => 429][$c] ?? 400;
        $out = ['ok' => false, 'code' => $c, 'message' => $e->get_error_message()];
        if ($c === 'otp_wait') $out['wait'] = (int)$e->get_error_message();
        return new WP_REST_Response($out, $status);
    }

    /** Step 1 when verification is on: validate everything, then send the code. */
    static function otp($r) {
        if ($r->get_param('website')) return ['ok' => true, 'method' => 'none'];
        if (!self::limit('otp', 8, HOUR_IN_SECONDS)) return self::err('rate', 'too many requests', 429);
        $lang = self::lang($r);
        $n = CB_Booking::precheck(self::booking_input($r, $lang));
        if (is_wp_error($n)) return self::fail($n);
        $method = CB_Verify::method();
        if ($method === 'none') return ['ok' => true, 'method' => 'none'];
        $res = CB_Verify::send($n);
        if (is_wp_error($res)) return self::fail($res);
        return ['ok' => true, 'method' => $method, 'to' => CB_Verify::target($n)];
    }

    static function book($r) {
        if ($r->get_param('website')) return ['ok' => true, 'status' => 'pending']; // honeypot
        if (!self::limit('book', 20, HOUR_IN_SECONDS)) return self::err('rate', 'too many requests', 429);
        $lang = self::lang($r);
        $in = self::booking_input($r, $lang);
        $n = CB_Booking::precheck($in);
        if (is_wp_error($n)) return self::fail($n);
        if (CB_Verify::method() !== 'none') {
            $code = (string)$r->get_param('code');
            if ($code === '') return self::err('otp_required', 'verification required', 403);
            $v = CB_Verify::check($n['phone'], $code);
            if (is_wp_error($v)) return self::fail($v);
        }
        $id = CB_Booking::create($in);
        if (is_wp_error($id)) return self::fail($id);
        $row = CB_Booking::get($id);
        return ['ok' => true, 'id' => $id, 'status' => $row->status, 'token' => $row->token, 'manage_url' => CB_Booking::manage_url($row->token),
            'appointment' => self::public_appt($row, $lang)];
    }

    /** Patient uploads the bank-transfer receipt (multipart, field "proof"). */
    static function pay($r) {
        if (!self::limit('pay', 15, HOUR_IN_SECONDS)) return self::err('rate', 'too many requests', 429);
        CB_Payments::expire_holds();
        $row = CB_Booking::get_by_token(sanitize_text_field($r->get_param('token')));
        if (!$row) return self::err('nf', 'not found', 404);
        $f = $_FILES['proof'] ?? null;
        if (!$f) return self::err('pay_file', 'no file');
        $res = CB_Payments::upload($row, $f);
        if (is_wp_error($res)) return self::err($res->get_error_code(), $res->get_error_message());
        return ['ok' => true, 'appointment' => self::public_appt(CB_Booking::get($row->id), self::lang($r))];
    }

    static function ics($r) {
        $row = CB_Booking::get_by_token(sanitize_text_field($r->get_param('token')));
        if (!$row) return self::err('nf', 'not found', 404);
        nocache_headers();
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="appointment-' . (int)$row->id . '.ics"');
        echo CB_Notify::ics($row);
        exit;
    }

    static function cancel($r) {
        $row = CB_Booking::get_by_token(sanitize_text_field($r->get_param('token')));
        if (!$row) return self::err('nf', 'not found', 404);
        if (!CB_Booking::can_change($row)) return self::err('policy', 'not allowed', 403);
        $res = CB_Booking::set_status($row->id, 'cancelled');
        return is_wp_error($res) ? self::err('err', 'error') : ['ok' => true];
    }

    static function reschedule($r) {
        $row = CB_Booking::get_by_token(sanitize_text_field($r->get_param('token')));
        if (!$row) return self::err('nf', 'not found', 404);
        if (!CB_Booking::can_change($row) || !CB_Settings::get('allow_resched')) return self::err('policy', 'not allowed', 403);
        $res = CB_Booking::reschedule($row->id, sanitize_text_field($r->get_param('date')), sanitize_text_field($r->get_param('time')));
        if (is_wp_error($res)) return self::err($res->get_error_code(), $res->get_error_message(), $res->get_error_code() === 'slot_taken' ? 409 : 400);
        return ['ok' => true, 'appointment' => self::public_appt(CB_Booking::get($row->id), self::lang($r))];
    }

    static function chat($r) {
        if (!self::limit('chat', 60, 10 * MINUTE_IN_SECONDS)) return self::err('rate', 'too many requests', 429);
        $msg = mb_substr(sanitize_text_field($r->get_param('message')), 0, 300);
        return CB_Chatbot::reply($msg, self::lang($r));
    }

    static function handoff($r) {
        if (!self::limit('handoff', 5, HOUR_IN_SECONDS)) return self::err('rate', 'too many requests', 429);
        $name = mb_substr(sanitize_text_field($r->get_param('name')), 0, 100);
        $phone = CB_Booking::normalize_phone($r->get_param('phone'));
        if (mb_strlen($name) < 2 || !$phone) return self::err('bad', 'invalid');
        $to = CB_Settings::get('notify_email');
        if ($to) wp_mail($to, 'طلب تحويل لموظف من الشات بوت', "الاسم: $name\nالهاتف: +$phone\nالرسالة: " . mb_substr(sanitize_textarea_field($r->get_param('message')), 0, 500));
        return ['ok' => true];
    }

    // ---------- staff ----------
    static function row_out($a) {
        $lang = CB_I18n::admin_lang();
        return [
            'id' => (int)$a->id, 'service_id' => (int)$a->service_id, 'service' => CB_Services::name($a, $lang), 'color' => $a->color ?: '#1e6fd9',
            'doctor_id' => (int)$a->doctor_id, 'doctor' => CB_Booking::doctor_name($a, $lang), 'doctor_color' => $a->doc_color ?: '',
            'patient_id' => (int)$a->patient_id, 'name' => $a->patient_name, 'phone' => $a->patient_phone, 'email' => (string)$a->patient_email,
            'date' => substr($a->start_dt, 0, 10), 'time' => substr($a->start_dt, 11, 5), 'end' => substr($a->end_dt, 11, 5),
            'status' => $a->status, 'notes' => (string)$a->notes, 'source' => $a->source,
            'pay_status' => $a->pay_status, 'pay_amount' => $a->pay_amount !== null ? CB_Settings::money($a->pay_amount) : '',
            'has_proof' => (string)$a->proof !== '',
            'proof_url' => $a->proof ? admin_url('admin-post.php?action=cb_proof&id=' . (int)$a->id) : '',
        ];
    }

    static function a_list($r) {
        global $wpdb;
        $from = sanitize_text_field($r->get_param('from')); $to = sanitize_text_field($r->get_param('to'));
        $status = sanitize_key($r->get_param('status')); $q = sanitize_text_field($r->get_param('q'));
        $doc = (int)$r->get_param('doctor_id'); $pay = sanitize_key($r->get_param('pay_status'));
        $w = ['1=1']; $args = [];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $w[] = 'a.start_dt >= %s'; $args[] = "$from 00:00:00"; }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $w[] = 'a.start_dt <= %s'; $args[] = "$to 23:59:59"; }
        if ($status) { $w[] = 'a.status = %s'; $args[] = $status; }
        if ($doc) { $w[] = 'a.doctor_id = %d'; $args[] = $doc; }
        if ($pay) { $w[] = 'a.pay_status = %s'; $args[] = $pay; }
        if ($q !== '') { $like = '%' . $wpdb->esc_like($q) . '%'; $w[] = '(p.name LIKE %s OR p.phone LIKE %s)'; $args[] = $like; $args[] = $like; }
        $sql = CB_Booking::select() . ' WHERE ' . implode(' AND ', $w) . ' ORDER BY a.start_dt ASC LIMIT 1000';
        $rows = $args ? $wpdb->get_results($wpdb->prepare($sql, $args)) : $wpdb->get_results($sql);
        return ['appointments' => array_map([__CLASS__, 'row_out'], $rows)];
    }

    static function a_create($r) {
        $id = CB_Booking::create([
            'service_id' => $r->get_param('service_id'), 'doctor_id' => $r->get_param('doctor_id'), 'date' => $r->get_param('date'), 'time' => $r->get_param('time'),
            'name' => $r->get_param('name'), 'phone' => $r->get_param('phone'), 'email' => $r->get_param('email'), 'notes' => $r->get_param('notes'),
            'status' => in_array($r->get_param('status'), ['pending', 'confirmed'], true) ? $r->get_param('status') : 'confirmed',
            'source' => 'admin', 'lang' => 'ar', 'force' => true,
        ]);
        if (is_wp_error($id)) return self::err($id->get_error_code(), $id->get_error_message(), 409);
        return ['ok' => true, 'appointment' => self::row_out(CB_Booking::get($id))];
    }

    static function a_update($r) {
        global $wpdb;
        $id = (int)$r['id'];
        $row = CB_Booking::get($id);
        if (!$row) return self::err('nf', 'not found', 404);
        if ($r->get_param('notes') !== null) $wpdb->update(CB_DB::t('appointments'), ['notes' => sanitize_textarea_field($r->get_param('notes'))], ['id' => $id]);
        $date = $r->get_param('date'); $time = $r->get_param('time'); $sv = (int)$r->get_param('service_id'); $dr = (int)$r->get_param('doctor_id');
        if ($date && $time && ($date !== substr($row->start_dt, 0, 10) || $time !== substr($row->start_dt, 11, 5) || ($sv && $sv !== (int)$row->service_id) || ($dr && $dr !== (int)$row->doctor_id))) {
            $res = CB_Booking::reschedule($id, $date, $time, true, $sv, $dr);
            if (is_wp_error($res)) return self::err($res->get_error_code(), $res->get_error_message(), 409);
        }
        $status = $r->get_param('status');
        if ($status && $status !== $row->status) {
            $res = CB_Booking::set_status($id, $status);
            if (is_wp_error($res)) return self::err($res->get_error_code(), $res->get_error_message(), 409);
        }
        return ['ok' => true, 'appointment' => self::row_out(CB_Booking::get($id))];
    }

    static function a_delete($r) {
        global $wpdb;
        $wpdb->delete(CB_DB::t('appointments'), ['id' => (int)$r['id']]);
        return ['ok' => true];
    }

    static function a_pay($r) {
        $id = (int)$r['id'];
        if (!CB_Booking::get($id)) return self::err('nf', 'not found', 404);
        $act = $r->get_param('do');
        $res = $act === 'approve' ? CB_Payments::approve($id) : ($act === 'reject' ? CB_Payments::reject($id) : new WP_Error('bad', 'action'));
        if (is_wp_error($res)) return self::err($res->get_error_code(), $res->get_error_message(), 409);
        return ['ok' => true, 'appointment' => self::row_out(CB_Booking::get($id))];
    }
}
