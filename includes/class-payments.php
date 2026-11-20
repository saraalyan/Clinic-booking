<?php
if (!defined('ABSPATH')) exit;

/**
 * Manual bank-transfer payments: the patient transfers the amount, uploads a screenshot of the receipt,
 * and the clinic reviews it and approves/rejects. Receipts are stored in a private folder and are only
 * served to logged-in clinic staff.
 */
class CB_Payments {
    const MAX_BYTES = 5242880; // 5 MB
    private static $expired = false;

    static function init() {
        add_action('admin_post_cb_proof', [__CLASS__, 'h_proof']);
        add_action('cb_send_reminders', [__CLASS__, 'expire_holds']);
    }

    static function enabled() { return (int)CB_Settings::get('pay_mode') === 1; }

    /** Amount the patient must transfer for this service: deposit if set, else the full price. */
    static function amount_for($svc) {
        $d = (float)($svc->deposit ?? 0);
        if ($d > 0) return $d;
        $p = (float)($svc->price ?? 0);
        return $p > 0 ? $p : 0;
    }

    static function required_for($svc) { return self::enabled() && self::amount_for($svc) > 0; }

    static function info($lang) { return (string)CB_Settings::loc('pay_info', $lang); }

    /** Free the slots of unpaid bookings whose hold time ran out (once per request). */
    static function expire_holds() {
        global $wpdb;
        if (self::$expired) return;
        self::$expired = true;
        $a = CB_DB::t('appointments');
        $wpdb->query($wpdb->prepare("UPDATE $a SET status='cancelled', slot_key=NULL, pay_status='expired'
            WHERE pay_status IN ('awaiting','rejected') AND status='pending' AND hold_until IS NOT NULL AND hold_until < %s", current_time('mysql')));
    }

    static function hold_until() {
        $m = (int)CB_Settings::get('pay_hold');
        return $m > 0 ? date('Y-m-d H:i:s', current_time('timestamp') + $m * 60) : null;
    }

    // ---------- storage ----------
    static function dir() {
        $u = wp_upload_dir();
        $dir = trailingslashit($u['basedir']) . 'clinic-proofs';
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
            @file_put_contents($dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
            @file_put_contents($dir . '/index.php', "<?php // silence\n");
        }
        return $dir;
    }

    /** Detects the real type from the file contents (never trusts the client's name or mime). */
    static function detect($path) {
        $head = (string)@file_get_contents($path, false, null, 0, 8);
        if (strpos($head, '%PDF-') === 0) return 'pdf';
        $i = @getimagesize($path);
        $map = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
        return $i && isset($map[$i[2]]) ? $map[$i[2]] : '';
    }

    static function upload($row, $file) {
        global $wpdb;
        if (!in_array($row->pay_status, ['awaiting', 'rejected', 'submitted'], true) || $row->status !== 'pending') return new WP_Error('pay_state', 'not payable');
        if (empty($file['tmp_name']) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) return new WP_Error('pay_file', 'upload failed');
        if ((int)$file['size'] > self::MAX_BYTES || (int)$file['size'] < 100) return new WP_Error('pay_size', 'size');
        $ext = self::detect($file['tmp_name']);
        if (!$ext) return new WP_Error('pay_type', 'type');
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = self::dir() . '/' . $name;
        if (!@move_uploaded_file($file['tmp_name'], $dest)) return new WP_Error('pay_file', 'move failed');
        @chmod($dest, 0640);
        if ($row->proof) @unlink(self::dir() . '/' . basename($row->proof));
        $wpdb->update(CB_DB::t('appointments'), ['proof' => $name, 'proof_at' => current_time('mysql'), 'pay_status' => 'submitted'], ['id' => $row->id]);
        $wpdb->query($wpdb->prepare('UPDATE ' . CB_DB::t('appointments') . ' SET hold_until=NULL WHERE id=%d', $row->id)); // under review: no auto-cancel
        do_action('cb_payment_submitted', (int)$row->id);
        return true;
    }

    static function h_proof() {
        if (!current_user_can('manage_clinic')) wp_die('Forbidden', 403);
        $row = CB_Booking::get((int)($_GET['id'] ?? 0));
        if (!$row || !$row->proof) wp_die('Not found', 404);
        $path = self::dir() . '/' . basename($row->proof);
        if (!is_file($path)) wp_die('Not found', 404);
        $types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        nocache_headers();
        header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: inline; filename="receipt-' . (int)$row->id . '.' . $ext . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    // ---------- clinic decision ----------
    static function approve($id) {
        global $wpdb;
        $row = CB_Booking::get($id);
        if (!$row) return new WP_Error('nf', 'not found');
        $wpdb->update(CB_DB::t('appointments'), ['pay_status' => 'paid'], ['id' => $id]);
        $wpdb->query($wpdb->prepare('UPDATE ' . CB_DB::t('appointments') . ' SET hold_until=NULL WHERE id=%d', $id));
        return CB_Booking::set_status($id, 'confirmed');
    }

    static function reject($id) {
        global $wpdb;
        $row = CB_Booking::get($id);
        if (!$row) return new WP_Error('nf', 'not found');
        $wpdb->update(CB_DB::t('appointments'), ['pay_status' => 'rejected'], ['id' => $id]);
        $h = self::hold_until();
        $wpdb->query($wpdb->prepare('UPDATE ' . CB_DB::t('appointments') . ' SET hold_until=' . ($h ? '%s' : 'NULL') . ' WHERE id=%d', $h ? [$h, $id] : [$id]));
        do_action('cb_payment_rejected', (int)$id);
        return true;
    }

    /** Public view of the payment state (for the patient, via the secret token link). */
    static function public_view($row, $lang) {
        if ($row->pay_status === 'none') return null;
        return [
            'status' => $row->pay_status, 'amount' => CB_Settings::money($row->pay_amount), 'info' => self::info($lang),
            'can_upload' => $row->status === 'pending' && in_array($row->pay_status, ['awaiting', 'rejected', 'submitted'], true),
            'has_proof' => $row->proof !== '',
        ];
    }
}
