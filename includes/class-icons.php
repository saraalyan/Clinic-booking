<?php
if (!defined('ABSPATH')) exit;

/**
 * Professional line icons (inline SVG, 24x24, drawn with currentColor) used instead of emoji.
 * Paths follow the open "Lucide" icon style (ISC licence).
 */
class CB_Icons {
    const DEFAULT_KEY = 'stethoscope';

    /** key => [label, svg inner] */
    static function all() {
        return [
            'stethoscope' => ['Stethoscope', '<path d="M11 2v2"/><path d="M5 2v2"/><path d="M5 3H4a2 2 0 0 0-2 2v4a6 6 0 0 0 12 0V5a2 2 0 0 0-2-2h-1"/><path d="M8 15a6 6 0 0 0 12 0v-3"/><circle cx="20" cy="10" r="2"/>'],
            'pill' => ['Pill', '<path d="m10.5 20.5 10-10a4.95 4.95 0 1 0-7-7l-10 10a4.95 4.95 0 1 0 7 7Z"/><path d="m8.5 8.5 7 7"/>'],
            'baby' => ['Baby', '<path d="M9 12h.01"/><path d="M15 12h.01"/><path d="M10 16c.5.3 1.2.5 2 .5s1.5-.2 2-.5"/><path d="M19 6.3a9 9 0 0 1 1.8 3.9 2 2 0 0 1 0 3.6 9 9 0 0 1-17.6 0 2 2 0 0 1 0-3.6A9 9 0 0 1 12 3c2 0 3.5 1.1 3.5 2.5s-.9 2.5-2 2.5c-.8 0-1.5-.4-1.5-1"/>'],
            'sparkles' => ['Skin / care', '<path d="M9.94 15.5A2 2 0 0 0 8.5 14.06l-6.14-1.58a.5.5 0 0 1 0-.96L8.5 9.94A2 2 0 0 0 9.94 8.5l1.58-6.14a.5.5 0 0 1 .96 0L14.06 8.5a2 2 0 0 0 1.44 1.44l6.14 1.58a.5.5 0 0 1 0 .96l-6.14 1.58a2 2 0 0 0-1.44 1.44l-1.58 6.14a.5.5 0 0 1-.96 0z"/><path d="M20 3v4"/><path d="M22 5h-4"/><path d="M4 17v2"/><path d="M5 18H3"/>'],
            'tooth' => ['Dental', '<path d="M7.5 3C5 3 3.5 5 3.5 7.3c0 2.3 1.1 3.6 1.7 5.4.6 1.9.6 4.1 1.3 6 .3.9 1 1.3 1.7 1.3 1.2 0 1.4-2.6 2.2-4 .4-.7 1.2-.7 1.6 0 .8 1.4 1 4 2.2 4 .7 0 1.4-.4 1.7-1.3.7-1.9.7-4.1 1.3-6 .6-1.8 1.7-3.1 1.7-5.4C20.5 5 19 3 16.5 3c-1.7 0-2.9.7-4.5.7S9.2 3 7.5 3Z"/>'],
            'heart' => ['Heart', '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/><path d="M3.22 12H9.5l.5-1 2 4.5 2-7 1.5 3.5h5.27"/>'],
            'eye' => ['Eye', '<path d="M2.06 12.35a1 1 0 0 1 0-.7 10.75 10.75 0 0 1 19.88 0 1 1 0 0 1 0 .7 10.75 10.75 0 0 1-19.88 0"/><circle cx="12" cy="12" r="3"/>'],
            'bone' => ['Bones', '<path d="M17 10c.7-.7 1.69 0 2.5 0a2.5 2.5 0 1 0 0-5 .5.5 0 0 1-.5-.5 2.5 2.5 0 1 0-5 0c0 .81.7 1.8 0 2.5l-7 7c-.7.7-1.69 0-2.5 0a2.5 2.5 0 0 0 0 5c.28 0 .5.22.5.5a2.5 2.5 0 1 0 5 0c0-.81-.7-1.8 0-2.5Z"/>'],
            'activity' => ['Activity', '<path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/>'],
            'shield' => ['Shield', '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>'],
            // interface icons
            'calendar' => ['', '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>'],
            'pin' => ['', '<path d="M20 10c0 4.99-5.54 10.19-7.4 11.8a1 1 0 0 1-1.2 0C9.54 20.19 4 14.99 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>'],
            'phone' => ['', '<path d="M13.83 16.57a1 1 0 0 0 1.21-.3l.36-.47A2 2 0 0 1 17 15h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2A18 18 0 0 1 2 4a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v3a2 2 0 0 1-.8 1.6l-.47.35a1 1 0 0 0-.29 1.23 14 14 0 0 0 6.39 6.39"/>'],
            'mail' => ['', '<path d="m22 7-8.99 5.73a2 2 0 0 1-2.01 0L2 7"/><rect x="2" y="4" width="20" height="16" rx="2"/>'],
            'chat' => ['', '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>'],
            'clock' => ['', '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>'],
            'map' => ['', '<path d="M14.1 4.6 9 2 3.6 4.7A1 1 0 0 0 3 5.6V21l6-3 6 3 5.4-2.7a1 1 0 0 0 .6-.9V3l-6 3"/><path d="M9 2v16"/><path d="M15 6v16"/>'],
            'check' => ['', '<path d="M20 6 9 17l-5-5"/>'],
        ];
    }

    /** Keys a service may use (the ones with a label). */
    static function choices() {
        return array_filter(self::all(), function ($v) { return $v[0] !== ''; });
    }

    private static $legacy = ['🩺' => 'stethoscope', '💊' => 'pill', '🧸' => 'baby', '✨' => 'sparkles', '🦷' => 'tooth', '❤️' => 'heart', '❤' => 'heart', '👁' => 'eye', '👁️' => 'eye', '🦴' => 'bone'];

    /** Accepts a key or an old emoji value and returns a valid key. */
    static function key($v) {
        $v = trim((string)$v);
        $all = self::all();
        if (isset($all[$v])) return $v;
        return self::$legacy[$v] ?? self::DEFAULT_KEY;
    }

    static function svg($key, $size = 24, $cls = '') {
        $all = self::all();
        $k = self::key($key);
        return '<svg class="cb-svg ' . esc_attr($cls) . '" xmlns="http://www.w3.org/2000/svg" width="' . (int)$size . '" height="' . (int)$size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $all[$k][1] . '</svg>';
    }
}
