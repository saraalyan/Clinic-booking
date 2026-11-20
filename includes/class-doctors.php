<?php
if (!defined('ABSPATH')) exit;

/** Doctors: each one can have own working hours, break, holidays and a list of services he/she performs. */
class CB_Doctors {
    static function all($active_only = true) {
        global $wpdb;
        $w = $active_only ? 'WHERE active=1' : '';
        return $wpdb->get_results('SELECT * FROM ' . CB_DB::t('doctors') . " $w ORDER BY sort ASC, id ASC");
    }

    static function get($id) {
        global $wpdb;
        $id = (int)$id;
        return $id ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . CB_DB::t('doctors') . ' WHERE id=%d', $id)) : null;
    }

    private static function pick($d, $field, $lang) {
        foreach ([$lang, 'ar', 'he', 'en'] as $l) {
            $k = $field . '_' . $l;
            if (isset($d->$k) && $d->$k !== '' && $d->$k !== null) return $d->$k;
        }
        return '';
    }

    static function name($d, $lang) { return $d ? self::pick($d, 'name', $lang) : ''; }
    static function spec($d, $lang) { return $d ? self::pick($d, 'spec', $lang) : ''; }

    /** Service ids this doctor performs; empty array = all services. */
    static function service_ids($d) {
        $ids = array_filter(array_map('intval', explode(',', (string)($d->service_ids ?? ''))));
        return array_values($ids);
    }

    static function serves($d, $service_id) {
        $ids = self::service_ids($d);
        return !$ids || in_array((int)$service_id, $ids, true);
    }

    /** Active doctors who perform the service. */
    static function for_service($service_id) {
        return array_values(array_filter(self::all(true), function ($d) use ($service_id) { return self::serves($d, $service_id); }));
    }

    /** Weekly schedule (0=Sun..6=Sat). Falls back to the clinic schedule when the doctor has none. */
    static function schedule($d) {
        $own = $d && !empty($d->schedule) ? json_decode($d->schedule, true) : null;
        return is_array($own) && $own ? $own : CB_Settings::get('schedule');
    }

    static function break_range($d) {
        if ($d && $d->break_from && $d->break_to) return [$d->break_from, $d->break_to];
        return [CB_Settings::get('break_from'), CB_Settings::get('break_to')];
    }

    static function public_row($d, $lang) {
        return [
            'id' => (int)$d->id, 'name' => self::name($d, $lang), 'spec' => self::spec($d, $lang),
            'photo' => $d->photo, 'color' => $d->color,
        ];
    }

    /** Idempotent: make sure one doctor exists and every appointment belongs to a doctor (upgrade from 1.1). */
    static function migrate() {
        global $wpdb;
        $t = CB_DB::t('doctors'); $a = CB_DB::t('appointments');
        if (!(int)$wpdb->get_var("SELECT COUNT(*) FROM $t")) {
            $wpdb->insert($t, ['name_ar' => 'الطبيب المعالج', 'name_he' => 'הרופא המטפל', 'name_en' => 'Doctor', 'color' => '#1e6fd9', 'active' => 1, 'sort' => 0]);
        }
        $first = (int)$wpdb->get_var("SELECT id FROM $t ORDER BY sort ASC, id ASC LIMIT 1");
        if ($first) {
            $wpdb->query($wpdb->prepare("UPDATE $a SET doctor_id=%d WHERE doctor_id=0", $first));
            // old keys were the bare start time (19 chars); new keys are "<doctor>_<start>"
            $wpdb->query("UPDATE $a SET slot_key=CONCAT(doctor_id,'_',slot_key) WHERE slot_key IS NOT NULL AND CHAR_LENGTH(slot_key)=19");
        }
    }
}
