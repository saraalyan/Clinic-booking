<?php
if (!defined('ABSPATH')) exit;

class CB_Booking {
    const SELECT = 'SELECT a.*, p.name AS patient_name, p.phone AS patient_phone, p.email AS patient_email, p.blocked AS patient_blocked,
        s.name_ar, s.name_he, s.name_en, s.duration, s.color,
        d.name_ar AS doc_ar, d.name_he AS doc_he, d.name_en AS doc_en, d.color AS doc_color
        FROM {a} a LEFT JOIN {p} p ON p.id=a.patient_id LEFT JOIN {s} s ON s.id=a.service_id LEFT JOIN {d} d ON d.id=a.doctor_id';

    static function select() {
        return str_replace(['{a}', '{p}', '{s}', '{d}'], [CB_DB::t('appointments'), CB_DB::t('patients'), CB_DB::t('services'), CB_DB::t('doctors')], self::SELECT);
    }

    static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(self::select() . ' WHERE a.id=%d', $id));
    }

    static function get_by_token($token) {
        global $wpdb;
        if (strlen($token) < 10) return null;
        return $wpdb->get_row($wpdb->prepare(self::select() . ' WHERE a.token=%s', $token));
    }

    static function doctor_name($row, $lang) {
        foreach ([$lang, 'ar', 'he', 'en'] as $l) { $k = 'doc_' . $l; if (!empty($row->$k)) return $row->$k; }
        return '';
    }

    static function normalize_phone($p) {
        $d = preg_replace('/\D+/', '', (string)$p);
        if (!$d) return '';
        if (strpos($d, '00') === 0) $d = substr($d, 2);
        elseif (strpos($d, '0') === 0) $d = preg_replace('/\D+/', '', CB_Settings::get('country_code')) . substr($d, 1);
        return (strlen($d) >= 9 && strlen($d) <= 15) ? $d : '';
    }

    static function upsert_patient($name, $phone, $email = '') {
        global $wpdb;
        $t = CB_DB::t('patients');
        $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE phone=%s", $phone));
        if ($id) {
            if ($email) $wpdb->update($t, ['email' => $email], ['id' => $id]);
            return (int)$id;
        }
        $wpdb->insert($t, ['name' => $name, 'phone' => $phone, 'email' => $email, 'created_at' => current_time('mysql')]);
        return (int)$wpdb->insert_id;
    }

    private static function lock() { global $wpdb; return (int)$wpdb->get_var("SELECT GET_LOCK('cb_booking', 5)"); }
    private static function unlock() { global $wpdb; $wpdb->query("SELECT RELEASE_LOCK('cb_booking')"); }

    /** Cleans and validates the raw input. Returns array or WP_Error. */
    static function normalize($a) {
        $svc = CB_Services::get((int)($a['service_id'] ?? 0));
        if (!$svc) return new WP_Error('service', 'service');
        $name = trim(sanitize_text_field($a['name'] ?? ''));
        if (mb_strlen($name) < 2) return new WP_Error('bad_name', 'name');
        $phone = self::normalize_phone($a['phone'] ?? '');
        if (!$phone) return new WP_Error('bad_phone', 'phone');
        $email = sanitize_email($a['email'] ?? '');
        if (($a['email'] ?? '') !== '' && !is_email($email)) return new WP_Error('bad_email', 'email');
        if (!$email && CB_Verify::method() === 'email') return new WP_Error('bad_email', 'email');
        $date = $a['date'] ?? ''; $time = $a['time'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) return new WP_Error('bad_time', 'time');
        $doctor_id = (int)($a['doctor_id'] ?? 0);
        if ($doctor_id) {
            $d = CB_Doctors::get($doctor_id);
            if (!$d || !$d->active || !CB_Doctors::serves($d, $svc->id)) return new WP_Error('doctor', 'doctor');
        }
        return [
            'svc' => $svc, 'name' => $name, 'phone' => $phone, 'email' => $email, 'date' => $date, 'time' => $time, 'doctor_id' => $doctor_id,
            'lang' => CB_I18n::ok($a['lang'] ?? '') ? $a['lang'] : 'ar', 'notes' => sanitize_textarea_field($a['notes'] ?? ''),
            'source' => sanitize_key($a['source'] ?? 'web'),
        ];
    }

    /** Public-booking rules: blocked patient, active-appointment limit, slot still free. */
    static function check_allowed($n) {
        global $wpdb;
        $pt = $wpdb->get_row($wpdb->prepare('SELECT id, blocked FROM ' . CB_DB::t('patients') . ' WHERE phone=%s', $n['phone']));
        if ($pt && (int)$pt->blocked === 1) return new WP_Error('blocked', 'blocked');
        $max = (int)CB_Settings::get('max_active');
        if ($pt && $max > 0) {
            $c = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . CB_DB::t('appointments') . " WHERE patient_id=%d AND status IN ('pending','confirmed') AND start_dt >= %s", $pt->id, current_time('mysql')));
            if ($c >= $max) return new WP_Error('limit', 'limit');
        }
        if (!in_array($n['time'], CB_Availability::slots($n['date'], $n['svc']->id, $n['doctor_id']), true)) return new WP_Error('slot_taken', 'taken');
        return true;
    }

    /** Validation only (used before sending a verification code). */
    static function precheck($a) {
        $n = self::normalize($a);
        if (is_wp_error($n)) return $n;
        $ok = self::check_allowed($n);
        return is_wp_error($ok) ? $ok : $n;
    }

    /** $a: service_id,doctor_id,date,time,name,phone,email,lang,source,notes,status,force */
    static function create($a) {
        global $wpdb;
        CB_Payments::expire_holds();
        $n = self::normalize($a);
        if (is_wp_error($n)) return $n;
        $force = !empty($a['force']);
        if (!$force) { $ok = self::check_allowed($n); if (is_wp_error($ok)) return $ok; }
        $svc = $n['svc'];
        $start = $n['date'] . ' ' . $n['time'] . ':00';
        $end = date('Y-m-d H:i:s', strtotime($start) + $svc->duration * 60);
        if (!self::lock()) return new WP_Error('busy', 'busy');

        $doctor_id = $n['doctor_id'];
        if (!$doctor_id) {
            if ($force) { $all = CB_Doctors::for_service($svc->id) ?: CB_Doctors::all(true); $doctor_id = $all ? (int)$all[0]->id : 0; }
            else { $d = CB_Availability::assign_doctor($n['date'], $n['time'], $svc->id); $doctor_id = $d ? (int)$d->id : 0; }
        }
        if (!$doctor_id || CB_Availability::overlaps($start, $end, $doctor_id)) { self::unlock(); return new WP_Error('slot_taken', 'taken'); }

        $needs_pay = !$force && CB_Payments::required_for($svc);
        $status = $needs_pay ? 'pending' : ($a['status'] ?? (CB_Settings::get('auto_confirm') ? 'confirmed' : 'pending'));
        $pid = self::upsert_patient($n['name'], $n['phone'], $n['email']);
        $reminder_window = current_time('timestamp') + (int)CB_Settings::get('reminder_hours') * 3600;
        $hold = $needs_pay ? CB_Payments::hold_until() : null;
        $ok = $wpdb->insert(CB_DB::t('appointments'), [
            'service_id' => $svc->id, 'doctor_id' => $doctor_id, 'patient_id' => $pid, 'start_dt' => $start, 'end_dt' => $end, 'status' => $status,
            'slot_key' => $doctor_id . '_' . $start, 'token' => wp_generate_password(24, false), 'lang' => $n['lang'],
            'notes' => $n['notes'], 'source' => $n['source'], 'reminded' => strtotime($start) < $reminder_window ? 1 : 0,
            'pay_status' => $needs_pay ? 'awaiting' : 'none', 'pay_amount' => $needs_pay ? CB_Payments::amount_for($svc) : null,
            'created_at' => current_time('mysql'),
        ]);
        $id = (int)$wpdb->insert_id;
        if ($ok && $hold) $wpdb->query($wpdb->prepare('UPDATE ' . CB_DB::t('appointments') . ' SET hold_until=%s WHERE id=%d', $hold, $id));
        self::unlock();
        if (!$ok) return new WP_Error('slot_taken', 'taken');
        do_action('cb_appointment_created', $id);
        return $id;
    }

    static function can_change($row) {
        if (!in_array($row->status, ['pending', 'confirmed'], true)) return false;
        if (!CB_Settings::get('allow_cancel')) return false;
        return strtotime($row->start_dt) - current_time('timestamp') >= (int)CB_Settings::get('cancel_hours') * 3600;
    }

    static function set_status($id, $status) {
        global $wpdb;
        $row = self::get($id);
        if (!$row) return new WP_Error('nf', 'not found');
        if (!in_array($status, ['pending', 'confirmed', 'cancelled', 'completed', 'noshow'], true)) return new WP_Error('bad', 'status');
        $active = in_array($status, ['pending', 'confirmed'], true);
        $was_active = in_array($row->status, ['pending', 'confirmed'], true);
        $t = CB_DB::t('appointments');
        if ($active && !$was_active) {
            if (!self::lock()) return new WP_Error('busy', 'busy');
            if (CB_Availability::overlaps($row->start_dt, $row->end_dt, $row->doctor_id, $id)) { self::unlock(); return new WP_Error('slot_taken', 'taken'); }
            $wpdb->update($t, ['status' => $status, 'slot_key' => $row->doctor_id . '_' . $row->start_dt], ['id' => $id]);
            self::unlock();
        } elseif ($active) {
            $wpdb->update($t, ['status' => $status], ['id' => $id]);
        } else {
            $wpdb->update($t, ['status' => $status], ['id' => $id]);
            $wpdb->query($wpdb->prepare("UPDATE $t SET slot_key=NULL WHERE id=%d", $id));
        }
        do_action('cb_appointment_status', $id, $status);
        return true;
    }

    static function reschedule($id, $date, $time, $force = false, $service_id = 0, $doctor_id = 0) {
        global $wpdb;
        $row = self::get($id);
        if (!$row) return new WP_Error('nf', 'not found');
        $svc = CB_Services::get($service_id ?: $row->service_id);
        if (!$svc) return new WP_Error('service', 'service');
        $doctor_id = $doctor_id ?: (int)$row->doctor_id;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) return new WP_Error('bad_time', 'time');
        if (!$force && !in_array($time, CB_Availability::slots($date, $svc->id, $doctor_id, $id), true)) return new WP_Error('slot_taken', 'taken');
        $start = "$date $time:00";
        $end = date('Y-m-d H:i:s', strtotime($start) + $svc->duration * 60);
        if (!self::lock()) return new WP_Error('busy', 'busy');
        $active = in_array($row->status, ['pending', 'confirmed'], true);
        if ($active && CB_Availability::overlaps($start, $end, $doctor_id, $id)) { self::unlock(); return new WP_Error('slot_taken', 'taken'); }
        $data = ['service_id' => $svc->id, 'doctor_id' => $doctor_id, 'start_dt' => $start, 'end_dt' => $end, 'reminded' => 0];
        if ($active) $data['slot_key'] = $doctor_id . '_' . $start;
        $wpdb->update(CB_DB::t('appointments'), $data, ['id' => $id]);
        self::unlock();
        do_action('cb_appointment_rescheduled', $id);
        return true;
    }

    static function manage_url($token) {
        $base = get_option('cb_booking_url') ?: home_url('/');
        return add_query_arg('cb_manage', $token, $base);
    }
}
