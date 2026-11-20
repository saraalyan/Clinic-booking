<?php
if (!defined('ABSPATH')) exit;

class CB_Availability {
    static function to_min($hhmm) {
        $p = explode(':', $hhmm . ':0');
        return (int)$p[0] * 60 + (int)$p[1];
    }

    static function fmt($min) { return sprintf('%02d:%02d', floor($min / 60), $min % 60); }

    private static function in_holidays($text, $date) {
        foreach (preg_split('/\R/', (string)$text) as $l) {
            $l = trim($l);
            if (!$l) continue;
            if (preg_match('/^(\d{4}-\d{2}-\d{2})\s*\.\.\s*(\d{4}-\d{2}-\d{2})/', $l, $m)) {
                if ($date >= $m[1] && $date <= $m[2]) return true;
            } elseif (preg_match('/^(\d{4}-\d{2}-\d{2})/', $l, $m)) {
                if ($date === $m[1]) return true;
            }
        }
        return false;
    }

    /** Clinic-wide holiday, or the doctor's own. */
    static function is_holiday($date, $doctor = null) {
        return self::in_holidays(CB_Settings::get('holidays'), $date) || ($doctor && self::in_holidays($doctor->holidays, $date));
    }

    /** [from_min, to_min] or null when closed. $doctor = row or null for the clinic's own hours. */
    static function day_hours($date, $doctor = null) {
        if (self::is_holiday($date, $doctor)) return null;
        $w = (int)(new DateTime($date, wp_timezone()))->format('w');
        $s = $doctor ? CB_Doctors::schedule($doctor) : CB_Settings::get('schedule');
        $d = $s[$w] ?? null;
        if (!$d || empty($d['open'])) return null;
        $from = self::to_min($d['from']);
        $to = self::to_min($d['to']);
        return $to > $from ? [$from, $to] : null;
    }

    static function busy($date, $doctor_id, $exclude = 0) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT start_dt,end_dt FROM ' . CB_DB::t('appointments') . " WHERE doctor_id=%d AND status IN ('pending','confirmed') AND start_dt < %s AND end_dt > %s AND id<>%d",
            $doctor_id, "$date 23:59:59", "$date 00:00:00", $exclude));
        $out = [];
        $base = strtotime("$date 00:00:00");
        foreach ($rows as $r) {
            $out[] = [max(0, (int)((strtotime($r->start_dt) - $base) / 60)), min(1440, (int)((strtotime($r->end_dt) - $base) / 60))];
        }
        return $out;
    }

    static function overlaps($start, $end, $doctor_id, $exclude = 0) {
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . CB_DB::t('appointments') . " WHERE doctor_id=%d AND status IN ('pending','confirmed') AND start_dt < %s AND end_dt > %s AND id<>%d",
            $doctor_id, $end, $start, $exclude)) > 0;
    }

    /** Free start times for one doctor. */
    static function doctor_slots($date, $service, $doctor, $exclude = 0) {
        if (!$service || !$doctor || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return [];
        if (!CB_Doctors::serves($doctor, $service->id)) return [];
        $hours = self::day_hours($date, $doctor);
        if (!$hours) return [];
        $now = current_time('timestamp');
        $today = date('Y-m-d', $now);
        if ($date < $today || $date > date('Y-m-d', $now + (int)CB_Settings::get('max_days') * DAY_IN_SECONDS)) return [];
        $step = max(5, (int)CB_Settings::get('slot_step'));
        $dur = max(5, (int)$service->duration);
        $notice = $now + (int)CB_Settings::get('min_notice') * 3600;
        $br = CB_Doctors::break_range($doctor);
        $bf = $br[0] ? self::to_min($br[0]) : null; $bt = $br[1] ? self::to_min($br[1]) : null;
        $busy = self::busy($date, (int)$doctor->id, $exclude);
        $out = [];
        for ($t = $hours[0]; $t + $dur <= $hours[1]; $t += $step) {
            $e = $t + $dur;
            if (strtotime($date . ' ' . self::fmt($t)) < $notice) continue;
            if ($bf !== null && $bt !== null && $t < $bt && $e > $bf) continue;
            $clash = false;
            foreach ($busy as $b) { if ($t < $b[1] && $e > $b[0]) { $clash = true; break; } }
            if (!$clash) $out[] = self::fmt($t);
        }
        return $out;
    }

    /** Free start times. $doctor_id = 0 means "any doctor": the union over doctors who perform the service. */
    static function slots($date, $service_id, $doctor_id = 0, $exclude = 0) {
        CB_Payments::expire_holds();
        $svc = CB_Services::get($service_id);
        if (!$svc) return [];
        if ($doctor_id) return self::doctor_slots($date, $svc, CB_Doctors::get($doctor_id), $exclude);
        $all = [];
        foreach (CB_Doctors::for_service($svc->id) as $d) $all = array_merge($all, self::doctor_slots($date, $svc, $d, $exclude));
        $all = array_values(array_unique($all));
        sort($all);
        return $all;
    }

    /** For "any doctor": pick the free doctor with the lightest day (spreads the load). */
    static function assign_doctor($date, $time, $service_id) {
        global $wpdb;
        $svc = CB_Services::get($service_id);
        $best = null; $bestn = PHP_INT_MAX;
        foreach (CB_Doctors::for_service($service_id) as $d) {
            if (!in_array($time, self::doctor_slots($date, $svc, $d), true)) continue;
            $n = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . CB_DB::t('appointments') . " WHERE doctor_id=%d AND DATE(start_dt)=%s AND status IN ('pending','confirmed')", $d->id, $date));
            if ($n < $bestn) { $best = $d; $bestn = $n; }
        }
        return $best;
    }

    /** date => number of free slots for a month (Y-m). */
    static function month_days($month, $service_id, $doctor_id = 0) {
        $n = (int)date('t', strtotime($month . '-01'));
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $d = sprintf('%s-%02d', $month, $i);
            $out[$d] = count(self::slots($d, $service_id, $doctor_id));
        }
        return $out;
    }

    static function is_open_now() {
        $now = current_time('timestamp');
        $date = date('Y-m-d', $now);
        $h = self::day_hours($date);
        if (!$h) return false;
        $m = (int)date('G', $now) * 60 + (int)date('i', $now);
        if ($m < $h[0] || $m >= $h[1]) return false;
        $bf = CB_Settings::get('break_from'); $bt = CB_Settings::get('break_to');
        if ($bf && $bt && $m >= self::to_min($bf) && $m < self::to_min($bt)) return false;
        return true;
    }
}
