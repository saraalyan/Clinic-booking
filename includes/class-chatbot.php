<?php
if (!defined('ABSPATH')) exit;

/** Rule-based FAQ bot. No external AI service, no invented info: answers come only from clinic settings. */
class CB_Chatbot {
    static function norm($s) {
        $s = mb_strtolower(trim((string)$s));
        $s = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s);
        $s = str_replace(['أ', 'إ', 'آ', 'ى', 'ة'], ['ا', 'ا', 'ا', 'ي', 'ه'], $s);
        return $s;
    }

    static function has($msg, $words) {
        foreach ($words as $w) if (mb_strpos($msg, self::norm($w)) !== false) return true;
        return false;
    }

    static function hours_text($lang) {
        $s = CB_Settings::get('schedule');
        $lines = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $s[$i] ?? null;
            $lines[] = CB_I18n::day_name($i, $lang) . ': ' . (!empty($d['open']) ? $d['from'] . ' - ' . $d['to'] : CB_I18n::t('p_closed', $lang));
        }
        $bf = CB_Settings::get('break_from'); $bt = CB_Settings::get('break_to');
        if ($bf && $bt) $lines[] = CB_I18n::t('p_break', $lang) . ": $bf - $bt";
        return implode("\n", $lines);
    }

    static function reply($raw, $lang) {
        $m = self::norm($raw);
        $t = function ($k) use ($lang) { return CB_I18n::t($k, $lang); };
        $actions = ['book', 'human'];

        $med = [
            'he' => ['אבחון', 'תסמינים', 'כאב', 'תרופה', 'מינון', 'חום', 'האם יש לי', 'מחלה', 'דלקת', 'כואב'],
            'ar' => ['تشخيص', 'اعراض', 'وجع', 'يؤلمني', 'دواء', 'جرعه', 'هل عندي', 'مرض', 'التهاب', 'حراره', 'صداع', 'يوجعني', 'اشخص'],
            'en' => ['diagnos', 'symptom', 'pain', 'medicine', 'medication', 'dosage', 'fever', 'headache', 'do i have', 'infection', 'disease', 'hurts'],
        ];
        $medical = $med[$lang] ?? $med['en'];
        if (self::has($m, $medical)) return ['reply' => $t('c_medical'), 'actions' => $actions];

        // custom FAQ: "keyword1, keyword2 | answer"
        foreach (preg_split('/\R/', (string)CB_Settings::get('faq_' . $lang)) as $line) {
            if (strpos($line, '|') === false) continue;
            list($keys, $ans) = array_map('trim', explode('|', $line, 2));
            $words = array_filter(array_map('trim', preg_split('/[,،]/u', $keys)));
            if ($ans !== '' && $words && self::has($m, $words)) return ['reply' => $ans, 'actions' => ['book', 'human']];
        }

        $all = [
            'he' => [
                'human' => ['נציג', 'אדם', 'בן אדם', 'קבלה', 'לדבר עם'], 'book' => ['תור', 'לקבוע', 'קביעה', 'הזמנה'],
                'hours' => ['שעות', 'פתוח', 'פעילות', 'מתי', 'סגור'], 'loc' => ['כתובת', 'מיקום', 'איפה', 'להגיע', 'ניווט'],
                'price' => ['מחיר', 'עלות', 'כמה עולה', 'תשלום'], 'svc' => ['שירות', 'טיפול', 'בדיקה', 'ייעוץ'],
                'phone' => ['טלפון', 'וואטסאפ', 'ליצור קשר', 'מספר'],
            ],
            'ar' => [
                'human' => ['موظف', 'شخص', 'استقبال', 'بشري', 'اكلم', 'تحويل'], 'book' => ['حجز', 'احجز', 'موعد', 'ابغى', 'ابي', 'اريد'],
                'hours' => ['دوام', 'ساعات', 'مواعيد العمل', 'متى', 'تفتح', 'وقت العمل', 'مفتوح'], 'loc' => ['موقع', 'عنوان', 'وين', 'اين', 'فين', 'خريطه', 'لوكيشن'],
                'price' => ['سعر', 'اسعار', 'تكلف', 'بكم', 'رسوم'], 'svc' => ['خدم', 'علاج', 'فحص', 'استشار'],
                'phone' => ['رقم', 'هاتف', 'تلفون', 'واتس', 'تواصل', 'اتصال'],
            ],
            'en' => [
                'human' => ['person', 'human', 'agent', 'staff', 'receptionist', 'talk to', 'speak to', 'front desk'], 'book' => ['book', 'appointment', 'schedule', 'reserve'],
                'hours' => ['hour', 'open', 'close', 'when do', 'working time'], 'loc' => ['where', 'address', 'location', 'map', 'direction'],
                'price' => ['price', 'cost', 'fee', 'how much'], 'svc' => ['service', 'treatment', 'check', 'consult'],
                'phone' => ['phone', 'number', 'whatsapp', 'contact', 'call'],
            ],
        ];
        $kw = $all[$lang] ?? $all['en'];

        if (self::has($m, $kw['human'])) return ['reply' => $t('c_handoff'), 'actions' => ['handoff']];
        if (self::has($m, $kw['price'])) return ['reply' => self::prices($lang), 'actions' => $actions];
        if (self::has($m, $kw['hours'])) return ['reply' => $t('c_hours_title') . "\n" . self::hours_text($lang), 'actions' => $actions];
        if (self::has($m, $kw['loc'])) {
            $a = CB_Settings::loc('address', $lang);
            $r = $t('c_location_title') . "\n" . ($a ?: '—');
            if (CB_Settings::get('map_link')) $r .= "\n" . CB_Settings::get('map_link');
            return ['reply' => $r, 'actions' => $actions];
        }
        if (self::has($m, $kw['phone'])) return ['reply' => $t('c_phone_is') . ' ' . CB_Settings::get('phone'), 'actions' => $actions];
        if (self::has($m, $kw['book'])) return ['reply' => $t('c_pick_service'), 'actions' => ['start_booking']];
        if (self::has($m, $kw['svc'])) {
            $names = [];
            foreach (CB_Services::all() as $s) $names[] = '• ' . CB_Services::name($s, $lang) . ' (' . $s->duration . ' ' . $t('b_min') . ')';
            return ['reply' => $t('c_services_title') . "\n" . implode("\n", $names), 'actions' => $actions];
        }
        return ['reply' => $t('c_fallback'), 'actions' => ['services', 'hours', 'location', 'book', 'human']];
    }

    static function prices($lang) {
        $rows = [];
        foreach (CB_Services::all() as $s) {
            if ($s->price !== null && $s->price !== '') $rows[] = '• ' . CB_Services::name($s, $lang) . ': ' . CB_Settings::money($s->price);
        }
        return $rows ? CB_I18n::t('c_prices_title', $lang) . "\n" . implode("\n", $rows) : CB_I18n::t('c_no_prices', $lang);
    }
}
