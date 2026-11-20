<?php
if (!defined('ABSPATH')) exit;

class CB_DB {
    static function t($n) { global $wpdb; return $wpdb->prefix . 'cb_' . $n; }

    static function activate() {
        self::install();
        self::roles();
        if (!wp_next_scheduled('cb_send_reminders')) wp_schedule_event(time() + 300, 'hourly', 'cb_send_reminders');
        global $wpdb;
        if (!(int)$wpdb->get_var('SELECT COUNT(*) FROM ' . self::t('services'))) {
            $seed = [
                ['استشارة عامة', 'ייעוץ כללי', '🩺', '#1e6fd9', 'General consultation'], ['متابعة علاج', 'מעקב טיפול', '💊', '#8b5cf6', 'Treatment follow-up'],
                ['فحص الأطفال', 'בדיקת ילדים', '🧸', '#10b981', 'Pediatric check-up'], ['استشارة جلدية', 'ייעוץ עור', '✨', '#f59e0b', 'Dermatology consultation'],
            ];
            foreach ($seed as $i => $s) {
                $wpdb->insert(self::t('services'), ['name_ar' => $s[0], 'name_he' => $s[1], 'name_en' => $s[4] ?? '', 'duration' => 30, 'icon' => $s[2], 'color' => $s[3], 'active' => 1, 'sort' => $i]);
            }
        }
    }

    static function maybe_upgrade() {
        if (get_option('cb_db_ver') !== CB_VERSION) {
            self::install();
            self::roles();
            if (!wp_next_scheduled('cb_send_reminders')) wp_schedule_event(time() + 300, 'hourly', 'cb_send_reminders');
        }
    }

    static function roles() {
        add_role('clinic_doctor', 'طبيب العيادة', ['read' => true, 'manage_clinic' => true, 'manage_clinic_staff' => true]);
        add_role('clinic_reception', 'موظف استقبال العيادة', ['read' => true, 'manage_clinic' => true]);
        foreach (['administrator', 'clinic_doctor'] as $r) { $ro = get_role($r); if ($ro) $ro->add_cap('manage_clinic_staff'); }
        $a = get_role('administrator'); if ($a) $a->add_cap('manage_clinic');
    }

    static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE " . self::t('services') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name_ar varchar(190) NOT NULL DEFAULT '',
  name_he varchar(190) NOT NULL DEFAULT '',
  name_en varchar(190) NOT NULL DEFAULT '',
  desc_ar text,
  desc_he text,
  desc_en text,
  duration int(11) NOT NULL DEFAULT 30,
  price decimal(10,2) DEFAULT NULL,
  deposit decimal(10,2) DEFAULT NULL,
  icon varchar(20) NOT NULL DEFAULT '',
  color varchar(9) NOT NULL DEFAULT '#1e6fd9',
  active tinyint(1) NOT NULL DEFAULT 1,
  sort int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id)
) $c;");
        dbDelta("CREATE TABLE " . self::t('doctors') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name_ar varchar(190) NOT NULL DEFAULT '',
  name_he varchar(190) NOT NULL DEFAULT '',
  name_en varchar(190) NOT NULL DEFAULT '',
  spec_ar varchar(190) NOT NULL DEFAULT '',
  spec_he varchar(190) NOT NULL DEFAULT '',
  spec_en varchar(190) NOT NULL DEFAULT '',
  photo varchar(255) NOT NULL DEFAULT '',
  color varchar(9) NOT NULL DEFAULT '#1e6fd9',
  active tinyint(1) NOT NULL DEFAULT 1,
  sort int(11) NOT NULL DEFAULT 0,
  schedule longtext,
  break_from varchar(5) NOT NULL DEFAULT '',
  break_to varchar(5) NOT NULL DEFAULT '',
  holidays text,
  service_ids text,
  PRIMARY KEY  (id)
) $c;");
        dbDelta("CREATE TABLE " . self::t('patients') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL DEFAULT '',
  phone varchar(20) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  blocked tinyint(1) NOT NULL DEFAULT 0,
  notes text,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY phone (phone)
) $c;");
        dbDelta("CREATE TABLE " . self::t('appointments') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  service_id bigint(20) unsigned NOT NULL DEFAULT 0,
  doctor_id bigint(20) unsigned NOT NULL DEFAULT 0,
  patient_id bigint(20) unsigned NOT NULL DEFAULT 0,
  start_dt datetime NOT NULL,
  end_dt datetime NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  slot_key varchar(40) DEFAULT NULL,
  token varchar(40) NOT NULL DEFAULT '',
  lang varchar(5) NOT NULL DEFAULT 'ar',
  notes text,
  source varchar(20) NOT NULL DEFAULT 'web',
  reminded tinyint(1) NOT NULL DEFAULT 0,
  pay_status varchar(12) NOT NULL DEFAULT 'none',
  pay_amount decimal(10,2) DEFAULT NULL,
  proof varchar(120) NOT NULL DEFAULT '',
  proof_at datetime DEFAULT NULL,
  hold_until datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slot_key (slot_key),
  KEY doctor_id (doctor_id),
  KEY start_dt (start_dt),
  KEY patient_id (patient_id),
  KEY token (token)
) $c;");
        CB_Doctors::migrate();
        update_option('cb_db_ver', CB_VERSION);
    }
}
