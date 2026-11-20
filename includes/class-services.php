<?php
if (!defined('ABSPATH')) exit;

class CB_Services {
    static function all($active_only = true) {
        global $wpdb;
        $w = $active_only ? 'WHERE active=1' : '';
        return $wpdb->get_results('SELECT * FROM ' . CB_DB::t('services') . " $w ORDER BY sort ASC, id ASC");
    }

    static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . CB_DB::t('services') . ' WHERE id=%d', $id));
    }

    private static function pick($s, $field, $lang) {
        foreach ([$lang, 'ar', 'he', 'en'] as $l) {
            $k = $field . '_' . $l;
            if (isset($s->$k) && $s->$k !== '' && $s->$k !== null) return $s->$k;
        }
        return '';
    }

    static function name($s, $lang) { return self::pick($s, 'name', $lang); }

    static function desc($s, $lang) { return self::pick($s, 'desc', $lang); }

    static function public_row($s, $lang) {
        return [
            'id' => (int)$s->id, 'name' => self::name($s, $lang), 'desc' => (string)self::desc($s, $lang),
            'duration' => (int)$s->duration, 'price' => CB_Settings::money($s->price),
            'icon' => $s->icon, 'color' => $s->color,
        ];
    }
}
