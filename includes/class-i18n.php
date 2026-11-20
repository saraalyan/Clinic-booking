<?php
if (!defined('ABSPATH')) exit;

/** Arabic / Hebrew / English strings (front + admin). */
class CB_I18n {
    private static $lang = null;

    const LANGS = ['ar' => 'العربية', 'he' => 'עברית', 'en' => 'English'];

    static function langs() { return self::LANGS; }
    static function dir($lang) { return $lang === 'en' ? 'ltr' : 'rtl'; }
    static function locale($lang) { return ['ar' => 'ar-u-nu-latn', 'he' => 'he-IL', 'en' => 'en-US'][$lang] ?? 'ar-u-nu-latn'; }
    static function ok($l) { return isset(self::LANGS[$l]); }
    static function guess() {
        $loc = get_locale();
        return strpos($loc, 'he') === 0 ? 'he' : (strpos($loc, 'ar') === 0 ? 'ar' : 'en');
    }

    static function init() {
        add_action('init', function () {
            if (isset($_GET['cb_lang']) && self::ok($_GET['cb_lang']) && !headers_sent()) {
                setcookie('cb_lang', $_GET['cb_lang'], time() + YEAR_IN_SECONDS, COOKIEPATH ?: '/');
                $_COOKIE['cb_lang'] = $_GET['cb_lang'];
            }
        });
    }

    static function front_lang() {
        if (self::$lang) return self::$lang;
        $l = '';
        if (isset($_GET['cb_lang'])) $l = sanitize_key($_GET['cb_lang']);
        elseif (isset($_COOKIE['cb_lang'])) $l = sanitize_key($_COOKIE['cb_lang']);
        if (!self::ok($l)) {
            $l = CB_Settings::get('default_lang');
            if (!self::ok($l)) $l = self::guess();
        }
        return self::$lang = $l;
    }

    static function admin_lang() {
        if (isset($_GET['cb_admin_lang']) && self::ok($_GET['cb_admin_lang']) && get_current_user_id()) {
            update_user_meta(get_current_user_id(), 'cb_admin_lang', $_GET['cb_admin_lang']);
            return $_GET['cb_admin_lang'];
        }
        $l = get_user_meta(get_current_user_id(), 'cb_admin_lang', true);
        if (self::ok($l)) return $l;
        $loc = get_user_locale();
        return strpos($loc, 'he') === 0 ? 'he' : (strpos($loc, 'en') === 0 ? 'en' : 'ar');
    }

    static function t($key, $lang = null) {
        $d = self::dict();
        $lang = $lang ?: self::front_lang();
        return $d[$lang][$key] ?? ($d['en'][$key] ?? ($d['ar'][$key] ?? $key));
    }

    /** All strings of a language that start with given prefixes (sent to JS). */
    static function bundle($lang, $prefixes) {
        $d = self::dict();
        $out = [];
        foreach ($d['ar'] as $k => $v) {
            foreach ($prefixes as $p) {
                if (strpos($k, $p) === 0) { $out[$k] = $d[$lang][$k] ?? $v; break; }
            }
        }
        return $out;
    }

    static function day_name($w, $lang) { return self::t('d' . (int)$w, $lang); }

    static function dict() {
        static $d = null;
        if ($d) return $d;
        $d = [
        'ar' => [
            // booking
            'b_title' => 'احجز موعدك', 'b_step1' => 'الخدمة', 'b_step2' => 'الموعد', 'b_step3' => 'البيانات',
            'b_choose_service' => 'اختر الخدمة', 'b_choose_date' => 'اختر اليوم', 'b_choose_time' => 'الأوقات المتاحة',
            'b_details' => 'بياناتك', 'b_name' => 'اسم المريض', 'b_phone' => 'رقم الهاتف', 'b_notes' => 'ملاحظات (اختياري)',
            'b_confirm' => 'تأكيد الحجز', 'b_next' => 'التالي', 'b_back' => 'رجوع', 'b_min' => 'دقيقة',
            'b_no_slots' => 'لا توجد أوقات متاحة في هذا اليوم', 'b_pick_day' => 'اختر يومًا لعرض الأوقات المتاحة',
            'b_no_services' => 'لا توجد خدمات متاحة حاليًا', 'b_ok_title' => 'تم حجز موعدك',
            'b_ok_pending' => 'تم استلام طلبك وهو قيد المراجعة، وسنؤكده قريبًا.',
            'b_ok_confirmed' => 'تم تأكيد موعدك. نراك قريبًا!',
            'b_ok_manage' => 'احتفظ بهذا الرابط لإلغاء الموعد أو تعديله:',
            'b_slot_taken' => 'عذرًا، هذا الموعد لم يعد متاحًا. اختر وقتًا آخر.',
            'b_bad_phone' => 'رقم الهاتف غير صحيح', 'b_bad_name' => 'يرجى إدخال الاسم',
            'b_err' => 'حدث خطأ، حاول مرة أخرى', 'b_loading' => 'جارٍ التحميل…',
            'b_my_appt' => 'موعدك', 'b_cancel' => 'إلغاء الموعد', 'b_resched' => 'تعديل الموعد',
            'b_cancelled_ok' => 'تم إلغاء الموعد', 'b_resched_ok' => 'تم تعديل الموعد',
            'b_cant_change' => 'لا يمكن إلغاء الموعد أو تعديله قبل أقل من {h} ساعة من موعده.',
            'b_confirm_cancel' => 'هل تريد إلغاء الموعد؟', 'b_not_found' => 'الموعد غير موجود',
            'b_new' => 'حجز موعد آخر', 'b_save_time' => 'حفظ الموعد الجديد', 'b_price' => 'السعر',
            'b_status_pending' => 'قيد المراجعة', 'b_status_confirmed' => 'مؤكد', 'b_status_cancelled' => 'ملغي',
            'b_status_completed' => 'مكتمل', 'b_status_noshow' => 'لم يحضر', 'b_copy' => 'نسخ الرابط', 'b_copied' => 'تم النسخ',
            // page
            'p_cta' => 'احجز موعدك الآن', 'p_services' => 'خدماتنا', 'p_hours' => 'ساعات العمل',
            'p_location' => 'الموقع والتواصل', 'p_closed' => 'مغلق', 'p_call' => 'اتصل بنا', 'p_wa' => 'واتساب',
            'p_map' => 'افتح الخريطة', 'p_email' => 'البريد الإلكتروني', 'p_address' => 'العنوان', 'p_follow' => 'تابعنا',
            'p_break' => 'استراحة', 'p_about' => 'عن العيادة', 'p_open_now' => 'العيادة مفتوحة', 'p_closed_now' => 'العيادة مغلقة الآن',
            // chat
            'c_title' => 'مساعد العيادة', 'c_placeholder' => 'اكتب سؤالك…', 'c_send' => 'إرسال',
            'c_greeting' => 'مرحبًا بك! أنا مساعد العيادة الآلي. يمكنني الإجابة عن الخدمات وساعات الدوام والموقع، ومساعدتك في حجز موعد.',
            'c_book' => 'احجز موعد', 'c_human' => 'التحدث مع موظف', 'c_services' => 'الخدمات', 'c_hours' => 'ساعات الدوام',
            'c_location' => 'الموقع', 'c_prices' => 'الأسعار',
            'c_fallback' => 'لم أفهم سؤالك تمامًا. يمكنك اختيار أحد الخيارات أدناه أو التحدث مع موظف الاستقبال.',
            'c_medical' => 'لا أستطيع تقديم تشخيص أو نصيحة طبية. يمكنك حجز موعد مع الطبيب أو التحدث مع موظف الاستقبال.',
            'c_pick_service' => 'اختر الخدمة التي تريدها:', 'c_pick_date' => 'اختر اليوم المناسب:', 'c_pick_time' => 'اختر الوقت:',
            'c_ask_name' => 'ما اسم المريض؟', 'c_ask_phone' => 'ما رقم هاتفك؟',
            'c_booked' => 'تم حفظ الحجز بنجاح ✅', 'c_booked_pending' => 'تم استلام طلبك وهو قيد المراجعة ✅',
            'c_no_days' => 'لا توجد أيام متاحة حاليًا. يرجى التواصل مع الاستقبال.',
            'c_handoff' => 'سنحوّلك إلى موظف الاستقبال. اترك اسمك ورقمك وسنتواصل معك، أو تواصل مباشرة:',
            'c_handoff_sent' => 'تم إرسال طلبك للموظف وسيتواصل معك قريبًا.', 'c_no_prices' => 'لا توجد أسعار معتمدة منشورة حاليًا. يرجى التواصل مع الاستقبال.',
            'c_cancel_flow' => 'إلغاء', 'c_hours_title' => 'ساعات الدوام:', 'c_services_title' => 'خدماتنا:',
            'c_location_title' => 'موقعنا:', 'c_prices_title' => 'الأسعار المعتمدة:', 'c_phone_is' => 'رقم التواصل:',
            // days
            'd0' => 'الأحد', 'd1' => 'الاثنين', 'd2' => 'الثلاثاء', 'd3' => 'الأربعاء', 'd4' => 'الخميس', 'd5' => 'الجمعة', 'd6' => 'السبت',
            // admin
            'a_dashboard' => 'الرئيسية', 'a_calendar' => 'المواعيد', 'a_appointments' => 'قائمة المواعيد', 'a_patients' => 'المرضى',
            'a_services' => 'الخدمات', 'a_hours' => 'ساعات العمل', 'a_settings' => 'الإعدادات', 'a_welcome' => 'مرحبًا',
            'a_sub' => 'نظام إدارة مواعيد العيادة', 'a_sub2' => 'يمكنك من هنا متابعة المواعيد وإدارة المرضى والخدمات بكل سهولة.',
            'a_total_patients' => 'إجمالي المرضى', 'a_today_appts' => 'المواعيد اليوم', 'a_upcoming' => 'المواعيد القادمة',
            'a_cancelled' => 'المواعيد الملغاة (30 يومًا)', 'a_view_all' => 'عرض الكل', 'a_details' => 'عرض التفاصيل',
            'a_nearest' => 'أقرب المواعيد', 'a_popular' => 'الخدمات الأكثر طلبًا', 'a_clinic_info' => 'معلومات العيادة',
            'a_quick' => 'إجراءات سريعة', 'a_add_appt' => 'إضافة موعد جديد', 'a_add_patient' => 'إضافة مريض جديد',
            'a_manage_services' => 'إدارة الخدمات', 'a_edit_hours' => 'تعديل أوقات الدوام',
            'a_day' => 'يوم', 'a_week' => 'أسبوع', 'a_month' => 'شهر', 'a_today' => 'اليوم',
            'st_pending' => 'قيد الانتظار', 'st_confirmed' => 'مؤكد', 'st_cancelled' => 'ملغي', 'st_completed' => 'مكتمل', 'st_noshow' => 'لم يحضر',
            'a_save' => 'حفظ', 'a_delete' => 'حذف', 'a_edit' => 'تعديل', 'a_cancel_appt' => 'إلغاء الموعد', 'a_search' => 'بحث',
            'a_name' => 'الاسم', 'a_phone' => 'الهاتف', 'a_service' => 'الخدمة', 'a_date' => 'التاريخ', 'a_time' => 'الوقت',
            'a_status' => 'الحالة', 'a_notes' => 'ملاحظات', 'a_actions' => 'إجراءات', 'a_none' => 'لا توجد بيانات',
            'a_confirm' => 'هل أنت متأكد؟', 'a_saved' => 'تم الحفظ', 'a_history' => 'سجل المواعيد', 'a_visits' => 'عدد المواعيد',
            'a_last_visit' => 'آخر موعد', 'a_open' => 'العيادة مفتوحة', 'a_closed' => 'العيادة مغلقة', 'a_appt_word' => 'موعد',
            'a_all' => 'الكل', 'a_from' => 'من', 'a_to' => 'إلى', 'a_new_appt' => 'موعد جديد', 'a_close' => 'إغلاق',
            'a_slot_conflict' => 'هذا الوقت محجوز بالفعل', 'a_back' => 'رجوع', 'a_duration' => 'المدة (دقيقة)', 'a_price' => 'السعر',
            'a_color' => 'اللون', 'a_icon' => 'أيقونة (إيموجي)', 'a_active' => 'مفعّلة', 'a_name_ar' => 'الاسم بالعربية', 'a_name_he' => 'الاسم بالعبرية', 'a_name_en' => 'الاسم بالإنجليزية', 'a_desc_en' => 'الوصف بالإنجليزية',
            'a_desc_ar' => 'الوصف بالعربية', 'a_desc_he' => 'الوصف بالعبرية', 'a_order' => 'الترتيب', 'a_add_service' => 'إضافة خدمة',
            'a_logout' => 'تسجيل الخروج', 'a_source' => 'المصدر', 'a_confirm_btn' => 'تأكيد', 'a_complete_btn' => 'إكمال',
            'a_patient_notes' => 'ملاحظات داخلية عن المريض',
            'a_staff' => 'الموظفون', 'a_email' => 'البريد الإلكتروني', 'a_username' => 'اسم المستخدم', 'a_role' => 'الدور', 'a_password' => 'كلمة المرور (اختياري)',
            'a_pw_help' => 'اتركها فارغة وسيصل الموظف بريد برابط لتعيين كلمة المرور بنفسه. أو اكتب كلمة مرور من 8 أحرف على الأقل.',
            'a_doctor' => 'طبيب', 'a_reception' => 'موظف استقبال', 'a_add_staff' => 'إضافة موظف', 'a_err_exists' => 'اسم المستخدم أو البريد مستخدم من قبل',
            'a_err_email' => 'تأكد من الاسم والبريد واسم المستخدم', 'a_err_pass' => 'كلمة المرور قصيرة (8 أحرف على الأقل)',
            'a_staff_note' => 'الطبيب وموظف الاستقبال يرون المواعيد والمرضى والخدمات فقط. الإعدادات للمدير فقط.',
            'a_shortcodes' => 'الشورتكودات', 'a_no_access' => 'ليس لديك صلاحية دخول لوحة العيادة', 'a_login_title' => 'تسجيل دخول فريق العيادة', 'a_login_user' => 'اسم المستخدم أو البريد', 'a_login_pass' => 'كلمة المرور', 'a_login_remember' => 'تذكرني', 'a_login_btn' => 'دخول',
            'a_copy' => 'نسخ',
        ],
        'he' => [
            'b_title' => 'קביעת תור', 'b_step1' => 'שירות', 'b_step2' => 'מועד', 'b_step3' => 'פרטים',
            'b_choose_service' => 'בחר שירות', 'b_choose_date' => 'בחר יום', 'b_choose_time' => 'שעות פנויות',
            'b_details' => 'הפרטים שלך', 'b_name' => 'שם המטופל', 'b_phone' => 'מספר טלפון', 'b_notes' => 'הערות (אופציונלי)',
            'b_confirm' => 'אישור התור', 'b_next' => 'הבא', 'b_back' => 'חזרה', 'b_min' => 'דקות',
            'b_no_slots' => 'אין שעות פנויות ביום זה', 'b_pick_day' => 'בחרו יום כדי לראות שעות פנויות',
            'b_no_services' => 'אין שירותים זמינים כרגע', 'b_ok_title' => 'התור נקבע',
            'b_ok_pending' => 'הבקשה התקבלה וממתינה לאישור. נאשר בהקדם.',
            'b_ok_confirmed' => 'התור אושר. נתראה בקרוב!',
            'b_ok_manage' => 'שמרו את הקישור לביטול או לשינוי התור:',
            'b_slot_taken' => 'מצטערים, המועד כבר לא זמין. בחרו שעה אחרת.',
            'b_bad_phone' => 'מספר הטלפון אינו תקין', 'b_bad_name' => 'נא להזין שם',
            'b_err' => 'אירעה שגיאה, נסו שוב', 'b_loading' => 'טוען…',
            'b_my_appt' => 'התור שלך', 'b_cancel' => 'ביטול התור', 'b_resched' => 'שינוי התור',
            'b_cancelled_ok' => 'התור בוטל', 'b_resched_ok' => 'התור עודכן',
            'b_cant_change' => 'לא ניתן לבטל או לשנות תור פחות מ-{h} שעות לפני מועדו.',
            'b_confirm_cancel' => 'לבטל את התור?', 'b_not_found' => 'התור לא נמצא',
            'b_new' => 'קביעת תור נוסף', 'b_save_time' => 'שמירת המועד החדש', 'b_price' => 'מחיר',
            'b_status_pending' => 'ממתין לאישור', 'b_status_confirmed' => 'מאושר', 'b_status_cancelled' => 'בוטל',
            'b_status_completed' => 'הושלם', 'b_status_noshow' => 'לא הגיע', 'b_copy' => 'העתקת קישור', 'b_copied' => 'הועתק',
            'p_cta' => 'קבעו תור עכשיו', 'p_services' => 'השירותים שלנו', 'p_hours' => 'שעות פעילות',
            'p_location' => 'מיקום ויצירת קשר', 'p_closed' => 'סגור', 'p_call' => 'התקשרו אלינו', 'p_wa' => 'וואטסאפ',
            'p_map' => 'פתיחת המפה', 'p_email' => 'אימייל', 'p_address' => 'כתובת', 'p_follow' => 'עקבו אחרינו',
            'p_break' => 'הפסקה', 'p_about' => 'על המרפאה', 'p_open_now' => 'המרפאה פתוחה', 'p_closed_now' => 'המרפאה סגורה כעת',
            'c_title' => 'עוזר המרפאה', 'c_placeholder' => 'כתבו את שאלתכם…', 'c_send' => 'שליחה',
            'c_greeting' => 'שלום! אני העוזר האוטומטי של המרפאה. אפשר לשאול אותי על שירותים, שעות פעילות ומיקום, ואעזור לקבוע תור.',
            'c_book' => 'קביעת תור', 'c_human' => 'שיחה עם נציג', 'c_services' => 'שירותים', 'c_hours' => 'שעות פעילות',
            'c_location' => 'מיקום', 'c_prices' => 'מחירים',
            'c_fallback' => 'לא הבנתי את השאלה עד הסוף. אפשר לבחור אחת מהאפשרויות או לדבר עם נציג.',
            'c_medical' => 'איני יכול לתת אבחנה או ייעוץ רפואי. אפשר לקבוע תור אצל הרופא או לדבר עם נציג הקבלה.',
            'c_pick_service' => 'בחרו שירות:', 'c_pick_date' => 'בחרו יום:', 'c_pick_time' => 'בחרו שעה:',
            'c_ask_name' => 'מה שם המטופל?', 'c_ask_phone' => 'מה מספר הטלפון שלכם?',
            'c_booked' => 'התור נשמר בהצלחה ✅', 'c_booked_pending' => 'הבקשה התקבלה וממתינה לאישור ✅',
            'c_no_days' => 'אין ימים פנויים כרגע. נא לפנות לקבלה.',
            'c_handoff' => 'נעביר אתכם לנציג. השאירו שם ומספר ונחזור אליכם, או צרו קשר ישירות:',
            'c_handoff_sent' => 'הבקשה נשלחה לנציג והוא יצור קשר בהקדם.', 'c_no_prices' => 'אין כרגע מחירים מאושרים לפרסום. נא לפנות לקבלה.',
            'c_cancel_flow' => 'ביטול', 'c_hours_title' => 'שעות פעילות:', 'c_services_title' => 'השירותים שלנו:',
            'c_location_title' => 'המיקום שלנו:', 'c_prices_title' => 'מחירים מאושרים:', 'c_phone_is' => 'טלפון ליצירת קשר:',
            'd0' => 'ראשון', 'd1' => 'שני', 'd2' => 'שלישי', 'd3' => 'רביעי', 'd4' => 'חמישי', 'd5' => 'שישי', 'd6' => 'שבת',
            'a_dashboard' => 'ראשי', 'a_calendar' => 'יומן', 'a_appointments' => 'רשימת תורים', 'a_patients' => 'מטופלים',
            'a_services' => 'שירותים', 'a_hours' => 'שעות פעילות', 'a_settings' => 'הגדרות', 'a_welcome' => 'שלום',
            'a_sub' => 'מערכת לניהול תורים במרפאה', 'a_sub2' => 'מכאן אפשר לעקוב אחרי תורים ולנהל מטופלים ושירותים בקלות.',
            'a_total_patients' => 'סה"כ מטופלים', 'a_today_appts' => 'תורים היום', 'a_upcoming' => 'תורים קרובים',
            'a_cancelled' => 'תורים שבוטלו (30 יום)', 'a_view_all' => 'הצג הכל', 'a_details' => 'הצג פרטים',
            'a_nearest' => 'התורים הקרובים', 'a_popular' => 'השירותים המבוקשים', 'a_clinic_info' => 'פרטי המרפאה',
            'a_quick' => 'פעולות מהירות', 'a_add_appt' => 'הוספת תור', 'a_add_patient' => 'הוספת מטופל',
            'a_manage_services' => 'ניהול שירותים', 'a_edit_hours' => 'עריכת שעות פעילות',
            'a_day' => 'יום', 'a_week' => 'שבוע', 'a_month' => 'חודש', 'a_today' => 'היום',
            'st_pending' => 'ממתין', 'st_confirmed' => 'מאושר', 'st_cancelled' => 'בוטל', 'st_completed' => 'הושלם', 'st_noshow' => 'לא הגיע',
            'a_save' => 'שמירה', 'a_delete' => 'מחיקה', 'a_edit' => 'עריכה', 'a_cancel_appt' => 'ביטול תור', 'a_search' => 'חיפוש',
            'a_name' => 'שם', 'a_phone' => 'טלפון', 'a_service' => 'שירות', 'a_date' => 'תאריך', 'a_time' => 'שעה',
            'a_status' => 'סטטוס', 'a_notes' => 'הערות', 'a_actions' => 'פעולות', 'a_none' => 'אין נתונים',
            'a_confirm' => 'האם אתם בטוחים?', 'a_saved' => 'נשמר', 'a_history' => 'היסטוריית תורים', 'a_visits' => 'מספר תורים',
            'a_last_visit' => 'תור אחרון', 'a_open' => 'המרפאה פתוחה', 'a_closed' => 'המרפאה סגורה', 'a_appt_word' => 'תורים',
            'a_all' => 'הכל', 'a_from' => 'מ', 'a_to' => 'עד', 'a_new_appt' => 'תור חדש', 'a_close' => 'סגירה',
            'a_slot_conflict' => 'השעה כבר תפוסה', 'a_back' => 'חזרה', 'a_duration' => 'משך (דקות)', 'a_price' => 'מחיר',
            'a_color' => 'צבע', 'a_icon' => 'אייקון (אימוג׳י)', 'a_active' => 'פעיל', 'a_name_ar' => 'שם בערבית', 'a_name_en' => 'שם באנגלית', 'a_desc_en' => 'תיאור באנגלית', 'a_name_he' => 'שם בעברית',
            'a_desc_ar' => 'תיאור בערבית', 'a_desc_he' => 'תיאור בעברית', 'a_order' => 'סדר', 'a_add_service' => 'הוספת שירות',
            'a_logout' => 'התנתקות', 'a_source' => 'מקור', 'a_confirm_btn' => 'אישור', 'a_complete_btn' => 'סיום',
            'a_patient_notes' => 'הערות פנימיות על המטופל',
            'a_staff' => 'צוות', 'a_email' => 'אימייל', 'a_username' => 'שם משתמש', 'a_role' => 'תפקיד', 'a_password' => 'סיסמה (אופציונלי)',
            'a_pw_help' => 'השאירו ריק והעובד יקבל אימייל עם קישור לבחירת סיסמה. או הזינו סיסמה של 8 תווים לפחות.',
            'a_doctor' => 'רופא', 'a_reception' => 'פקיד קבלה', 'a_add_staff' => 'הוספת עובד', 'a_err_exists' => 'שם המשתמש או האימייל כבר קיימים',
            'a_err_email' => 'בדקו שם, אימייל ושם משתמש', 'a_err_pass' => 'הסיסמה קצרה (8 תווים לפחות)',
            'a_staff_note' => 'רופא ופקיד קבלה רואים תורים, מטופלים ושירותים בלבד. ההגדרות למנהל בלבד.',
            'a_shortcodes' => 'קודי קיצור', 'a_no_access' => 'אין לך הרשאה לגשת ללוח המרפאה', 'a_login_title' => 'התחברות צוות המרפאה', 'a_login_user' => 'שם משתמש או אימייל', 'a_login_pass' => 'סיסמה', 'a_login_remember' => 'זכור אותי', 'a_login_btn' => 'כניסה',
            'a_copy' => 'העתקה',
        ],
        'en' => [
            'b_title' => 'Book your appointment', 'b_step1' => 'Service', 'b_step2' => 'Date & time', 'b_step3' => 'Your details',
            'b_choose_service' => 'Choose a service', 'b_choose_date' => 'Choose a day', 'b_choose_time' => 'Available times',
            'b_details' => 'Your details', 'b_name' => 'Patient name', 'b_phone' => 'Phone number', 'b_notes' => 'Notes (optional)',
            'b_confirm' => 'Confirm booking', 'b_next' => 'Next', 'b_back' => 'Back', 'b_min' => 'min',
            'b_no_slots' => 'No available times on this day', 'b_pick_day' => 'Pick a day to see available times',
            'b_no_services' => 'No services are available right now', 'b_ok_title' => 'Your appointment is booked',
            'b_ok_pending' => 'Your request was received and is awaiting review. We will confirm it shortly.',
            'b_ok_confirmed' => 'Your appointment is confirmed. See you soon!',
            'b_ok_manage' => 'Keep this link to cancel or change your appointment:',
            'b_slot_taken' => 'Sorry, this time is no longer available. Please choose another.',
            'b_bad_phone' => 'Invalid phone number', 'b_bad_name' => 'Please enter your name',
            'b_err' => 'Something went wrong, please try again', 'b_loading' => 'Loading…',
            'b_my_appt' => 'Your appointment', 'b_cancel' => 'Cancel appointment', 'b_resched' => 'Change appointment',
            'b_cancelled_ok' => 'Appointment cancelled', 'b_resched_ok' => 'Appointment updated',
            'b_cant_change' => 'An appointment cannot be cancelled or changed less than {h} hours before it starts.',
            'b_confirm_cancel' => 'Cancel this appointment?', 'b_not_found' => 'Appointment not found',
            'b_new' => 'Book another appointment', 'b_save_time' => 'Save new time', 'b_price' => 'Price',
            'b_status_pending' => 'Pending', 'b_status_confirmed' => 'Confirmed', 'b_status_cancelled' => 'Cancelled',
            'b_status_completed' => 'Completed', 'b_status_noshow' => 'No-show', 'b_copy' => 'Copy link', 'b_copied' => 'Copied',
            'p_cta' => 'Book now', 'p_services' => 'Our services', 'p_hours' => 'Opening hours',
            'p_location' => 'Location & contact', 'p_closed' => 'Closed', 'p_call' => 'Call us', 'p_wa' => 'WhatsApp',
            'p_map' => 'Open map', 'p_email' => 'Email', 'p_address' => 'Address', 'p_follow' => 'Follow us',
            'p_break' => 'Break', 'p_about' => 'About the clinic', 'p_open_now' => 'Open now', 'p_closed_now' => 'Closed now',
            'c_title' => 'Clinic assistant', 'c_placeholder' => 'Type your question…', 'c_send' => 'Send',
            'c_greeting' => 'Hello! I am the clinic assistant. I can answer questions about services, opening hours and location, and help you book an appointment.',
            'c_book' => 'Book appointment', 'c_human' => 'Talk to a person', 'c_services' => 'Services', 'c_hours' => 'Opening hours',
            'c_location' => 'Location', 'c_prices' => 'Prices',
            'c_fallback' => 'I did not quite understand. Pick one of the options below or talk to the front desk.',
            'c_medical' => 'I cannot give a diagnosis or medical advice. You can book an appointment with the doctor or talk to the front desk.',
            'c_pick_service' => 'Choose a service:', 'c_pick_date' => 'Choose a day:', 'c_pick_time' => 'Choose a time:',
            'c_ask_name' => 'What is the patient name?', 'c_ask_phone' => 'What is your phone number?',
            'c_booked' => 'Your booking was saved ✅', 'c_booked_pending' => 'Your request was received and is awaiting review ✅',
            'c_no_days' => 'No days are available right now. Please contact the front desk.',
            'c_handoff' => 'I will pass you to the front desk. Leave your name and number and we will contact you, or reach us directly:',
            'c_handoff_sent' => 'Your request was sent. A team member will contact you soon.',
            'c_no_prices' => 'No official prices are published at the moment. Please contact the front desk.',
            'c_cancel_flow' => 'Cancel', 'c_hours_title' => 'Opening hours:', 'c_services_title' => 'Our services:',
            'c_location_title' => 'Our location:', 'c_prices_title' => 'Official prices:', 'c_phone_is' => 'Phone:',
            'd0' => 'Sunday', 'd1' => 'Monday', 'd2' => 'Tuesday', 'd3' => 'Wednesday', 'd4' => 'Thursday', 'd5' => 'Friday', 'd6' => 'Saturday',
            'a_dashboard' => 'Home', 'a_calendar' => 'Calendar', 'a_appointments' => 'Appointments', 'a_patients' => 'Patients',
            'a_services' => 'Services', 'a_hours' => 'Working hours', 'a_settings' => 'Settings', 'a_welcome' => 'Welcome',
            'a_sub' => 'Clinic appointment management', 'a_sub2' => 'Follow appointments and manage patients and services from here.',
            'a_total_patients' => 'Total patients', 'a_today_appts' => 'Today', 'a_upcoming' => 'Upcoming',
            'a_cancelled' => 'Cancelled (30 days)', 'a_view_all' => 'View all', 'a_details' => 'View details',
            'a_nearest' => 'Next appointments', 'a_popular' => 'Most requested services', 'a_clinic_info' => 'Clinic info',
            'a_quick' => 'Quick actions', 'a_add_appt' => 'New appointment', 'a_add_patient' => 'New patient',
            'a_manage_services' => 'Manage services', 'a_edit_hours' => 'Edit working hours',
            'a_day' => 'Day', 'a_week' => 'Week', 'a_month' => 'Month', 'a_today' => 'Today',
            'st_pending' => 'Pending', 'st_confirmed' => 'Confirmed', 'st_cancelled' => 'Cancelled', 'st_completed' => 'Completed', 'st_noshow' => 'No-show',
            'a_save' => 'Save', 'a_delete' => 'Delete', 'a_edit' => 'Edit', 'a_cancel_appt' => 'Cancel appointment', 'a_search' => 'Search',
            'a_name' => 'Name', 'a_phone' => 'Phone', 'a_service' => 'Service', 'a_date' => 'Date', 'a_time' => 'Time',
            'a_status' => 'Status', 'a_notes' => 'Notes', 'a_actions' => 'Actions', 'a_none' => 'No data',
            'a_confirm' => 'Are you sure?', 'a_saved' => 'Saved', 'a_history' => 'Appointment history', 'a_visits' => 'Appointments',
            'a_last_visit' => 'Last appointment', 'a_open' => 'Clinic is open', 'a_closed' => 'Clinic is closed', 'a_appt_word' => 'appts',
            'a_all' => 'All', 'a_from' => 'From', 'a_to' => 'To', 'a_new_appt' => 'New appointment', 'a_close' => 'Close',
            'a_slot_conflict' => 'This time is already booked', 'a_back' => 'Back', 'a_duration' => 'Duration (min)', 'a_price' => 'Price',
            'a_color' => 'Color', 'a_icon' => 'Icon (emoji)', 'a_active' => 'Active', 'a_name_ar' => 'Name (Arabic)', 'a_name_he' => 'Name (Hebrew)',
            'a_name_en' => 'Name (English)', 'a_desc_ar' => 'Description (Arabic)', 'a_desc_he' => 'Description (Hebrew)', 'a_desc_en' => 'Description (English)',
            'a_order' => 'Order', 'a_add_service' => 'Add service', 'a_logout' => 'Log out', 'a_source' => 'Source',
            'a_confirm_btn' => 'Confirm', 'a_complete_btn' => 'Complete', 'a_patient_notes' => 'Internal notes about the patient',
            'a_staff' => 'Team', 'a_email' => 'Email', 'a_username' => 'Username', 'a_role' => 'Role', 'a_password' => 'Password (optional)',
            'a_pw_help' => 'Leave empty and the person will get an email with a link to set their own password, or type one of at least 8 characters.',
            'a_doctor' => 'Doctor', 'a_reception' => 'Receptionist', 'a_add_staff' => 'Add team member',
            'a_err_exists' => 'That username or email is already in use', 'a_err_email' => 'Check the name, email and username',
            'a_err_pass' => 'Password is too short (min. 8 characters)',
            'a_staff_note' => 'Doctors and receptionists see appointments, patients and services only. Settings are for the administrator.',
            'a_shortcodes' => 'Shortcodes', 'a_no_access' => 'You do not have access to the clinic dashboard',
            'a_login_title' => 'Clinic team sign-in', 'a_login_user' => 'Username or email', 'a_login_pass' => 'Password',
            'a_login_remember' => 'Remember me', 'a_login_btn' => 'Sign in', 'a_copy' => 'Copy',
        ],
        ];
        $extra = require CB_DIR . 'includes/lang-v12.php';
        foreach ($extra as $l => $kv) $d[$l] = array_merge($d[$l] ?? [], $kv);
        return $d;
    }
}
