/* Clinic Booking – booking widget (vanilla JS) */
(function () {
  var D = window.CBData; if (!D) return;
  var S = D.s;
  var root = document.getElementById('cb-booking'); if (!root) return;

  function el(tag, attrs, kids) {
    var e = document.createElement(tag);
    for (var k in (attrs || {})) {
      if (k === 'class') e.className = attrs[k];
      else if (k.slice(0, 2) === 'on') e.addEventListener(k.slice(2), attrs[k]);
      else if (attrs[k] !== false && attrs[k] != null) e.setAttribute(k, attrs[k]);
    }
    [].concat(kids == null ? [] : kids).forEach(function (c) { if (c == null || c === false || c === '') return; e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return e;
  }
  // D.rest may already contain a query string (plain permalinks: index.php?rest_route=/clinic/v1/)
  function full(path) { var base = D.rest, plain = base.indexOf('?') > -1; return base + (plain ? path.replace('?', '&') : path); }
  function url(path) { var u = full(path); return u + (u.indexOf('?') > -1 ? '&' : '?') + 'lang=' + D.lang; }
  function handle(r) { return r.json().then(function (j) { if (!r.ok) throw j; return j; }); }
  function api(path, body) {
    if (!body) return fetch(url(path)).then(handle);
    return fetch(full(path), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(Object.assign({ lang: D.lang }, body)) }).then(handle);
  }
  var pad = function (n) { return (n < 10 ? '0' : '') + n; };
  var fmtLong = function (s) {
    var p = s.split('-'); var d = new Date(+p[0], +p[1] - 1, +p[2]);
    return new Intl.DateTimeFormat(D.locale, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(d);
  };
  var monthName = function (d) { return new Intl.DateTimeFormat(D.locale, { month: 'long', year: 'numeric' }).format(d); };
  var wdNames = (function () { var a = []; for (var i = 0; i < 7; i++) a.push(new Intl.DateTimeFormat(D.locale, { weekday: 'short' }).format(new Date(2023, 0, 1 + i))); return a; })();
  var fmt = function (t, v) { for (var k in v) t = t.replace('{' + k + '}', v[k]); return t; };

  var st = { services: [], service: null, doctors: null, doctor: null, month: new Date(), days: null, date: null, slots: null, time: null,
    view: 'service', token: null, appt: null, err: '', form: {}, otp: null, busy: false };
  st.month.setDate(1);

  // ---------- helpers ----------
  function flow() { // visible steps of the booking wizard
    var a = [['service', S.b_step1]];
    if (st.doctors && st.doctors.length > 1) a.push(['doctor', S.b_step_doctor]);
    a.push(['when', S.b_step2], ['details', S.b_step3]);
    return a;
  }
  function steps() {
    var f = flow(), cur = st.view === 'otp' ? 'details' : st.view, idx = f.map(function (x) { return x[0]; }).indexOf(cur);
    return el('div', { class: 'cb-steps' }, f.map(function (x, i) { return el('div', { class: i === idx ? 'on' : (i < idx ? 'done' : '') }, (i + 1) + '. ' + x[1]); }));
  }
  function mount(nodes) { root.innerHTML = ''; [].concat(nodes).forEach(function (n) { if (n === '' || n == null || n === false) return; root.appendChild(typeof n === 'string' ? document.createTextNode(n) : n); }); }
  function err() { return st.err ? el('div', { class: 'cb-err', role: 'alert' }, st.err) : ''; }
  function go(v) { st.view = v; st.err = ''; render(); }
  function errText(e) {
    var c = e && e.code;
    if (c === 'slot_taken') return S.b_slot_taken;
    if (c === 'bad_phone') return S.b_bad_phone;
    if (c === 'bad_email') return S.b_bad_email;
    if (c === 'blocked') return S.b_blocked;
    if (c === 'limit') return S.b_limit;
    if (c === 'otp_bad') return S.b_otp_bad;
    if (c === 'otp_expired') return S.b_otp_expired;
    if (c === 'otp_toomany') return S.b_otp_toomany;
    if (c === 'otp_fail') return S.b_otp_fail;
    if (c === 'otp_wait') return fmt(S.b_otp_wait, { s: e.wait || 60 });
    if (c === 'pay_type' || c === 'pay_size' || c === 'pay_file') return S.b_pay_bad_file;
    return S.b_err;
  }

  // ---------- service ----------
  function viewService() {
    var list = st.services.map(function (s) {
      return el('button', { type: 'button', class: 'cb-svc-opt' + (st.service && st.service.id === s.id ? ' sel' : ''), style: '--c:' + s.color, onclick: function () { pickService(s); } }, [
        el('span', { class: 'cb-ico' }, s.icon || '🩺'),
        el('span', {}, [el('b', {}, s.name), el('small', {}, s.duration + ' ' + S.b_min + (s.price ? ' · ' + s.price : ''))])
      ]);
    });
    mount([steps(), el('h3', {}, S.b_choose_service), st.services.length ? el('div', { class: 'cb-svc-list' }, list) : el('div', { class: 'cb-hint' }, S.b_no_services)]);
  }
  function pickService(s) {
    st.service = s; st.date = st.time = null; st.days = null; st.slots = null; st.doctor = null; st.doctors = null; st.err = '';
    mount(el('div', { class: 'cb-loading' }, S.b_loading));
    api('doctors?service_id=' + s.id).then(function (r) {
      st.doctors = r.doctors;
      if (!r.doctors.length) { st.err = S.b_no_doctor; return go('service'); }
      if (r.doctors.length === 1) { st.doctor = r.doctors[0]; loadDays(); go('when'); }
      else go('doctor');
    }).catch(function () { st.err = S.b_err; go('service'); });
  }

  // ---------- doctor ----------
  function viewDoctor() {
    function opt(d) {
      var any = !d;
      return el('button', { type: 'button', class: 'cb-doc-opt' + ((st.doctor && d && st.doctor.id === d.id) || (st.doctor && st.doctor.id === 0 && any) ? ' sel' : ''), onclick: function () {
        st.doctor = d || { id: 0, name: S.b_any_doctor }; st.date = st.time = null; st.days = null; st.slots = null; loadDays(); go('when'); } }, [
        any ? el('span', { class: 'cb-avatar any' }, '★') : (d.photo ? el('img', { class: 'cb-avatar', src: d.photo, alt: '' }) : el('span', { class: 'cb-avatar', style: 'background:' + (d.color || '#1e6fd9') }, (d.name || '?').charAt(0))),
        el('span', {}, [el('b', {}, any ? S.b_any_doctor : d.name), el('small', {}, any ? S.b_any_doctor_sub : (d.spec || ''))])
      ]);
    }
    mount([steps(), el('h3', {}, S.b_choose_doctor), err(), el('div', { class: 'cb-svc-list' }, [opt(null)].concat(st.doctors.map(opt))),
      el('div', { class: 'cb-actions' }, [el('button', { class: 'cb-btn cb-btn-ghost', type: 'button', onclick: function () { go('service'); } }, S.b_back)])]);
  }

  // ---------- when ----------
  function loadDays() {
    var key = st.month.getFullYear() + '-' + pad(st.month.getMonth() + 1);
    st.days = null;
    api('days?service_id=' + st.service.id + '&doctor_id=' + (st.doctor ? st.doctor.id : 0) + '&month=' + key).then(function (r) { st.days = r.days; if (st.view === 'when') render(); }).catch(function () { st.days = {}; if (st.view === 'when') render(); });
  }
  function loadSlots() {
    st.slots = null; st.time = null; render();
    api('slots?service_id=' + st.service.id + '&doctor_id=' + (st.doctor ? st.doctor.id : 0) + '&date=' + st.date).then(function (r) { st.slots = r.slots; render(); }).catch(function () { st.slots = []; render(); });
  }
  function calendar() {
    var m = st.month, first = new Date(m.getFullYear(), m.getMonth(), 1), n = new Date(m.getFullYear(), m.getMonth() + 1, 0).getDate();
    var grid = el('div', { class: 'cb-cal-grid' });
    wdNames.forEach(function (w) { grid.appendChild(el('div', { class: 'wd' }, w)); });
    for (var i = 0; i < first.getDay(); i++) grid.appendChild(el('span'));
    for (var d = 1; d <= n; d++) (function (d) {
      var key = m.getFullYear() + '-' + pad(m.getMonth() + 1) + '-' + pad(d);
      var has = st.days && st.days[key] > 0;
      grid.appendChild(el('button', { type: 'button', disabled: !has, class: (has ? 'has ' : '') + (st.date === key ? 'sel' : ''), onclick: function () { st.date = key; loadSlots(); } }, String(d)));
    })(d);
    var prev = el('button', { type: 'button', 'aria-label': 'prev', onclick: function () { st.month = new Date(m.getFullYear(), m.getMonth() - 1, 1); loadDays(); render(); } }, D.dir === 'rtl' ? '›' : '‹');
    var next = el('button', { type: 'button', 'aria-label': 'next', onclick: function () { st.month = new Date(m.getFullYear(), m.getMonth() + 1, 1); loadDays(); render(); } }, D.dir === 'rtl' ? '‹' : '›');
    return el('div', { class: 'cb-cal' }, [el('div', { class: 'cb-cal-head' }, [prev, el('span', {}, monthName(m)), next]), st.days ? grid : el('div', { class: 'cb-loading' }, S.b_loading)]);
  }
  function slotGroups() {
    var parts = [['morning', 0, 12], ['afternoon', 12, 17], ['evening', 17, 24]], wrap = el('div', { class: 'cb-parts' });
    parts.forEach(function (p) {
      var list = st.slots.filter(function (t) { var h = +t.split(':')[0]; return h >= p[1] && h < p[2]; });
      if (!list.length) return;
      wrap.appendChild(el('div', { class: 'cb-part' }, [el('h4', {}, S['b_part_' + p[0]]),
        el('div', { class: 'cb-slots' }, list.map(function (t) { return el('button', { type: 'button', class: 'cb-slot' + (st.time === t ? ' sel' : ''), onclick: function () { st.time = t; render(); } }, t); }))]));
    });
    return wrap;
  }
  function viewWhen() {
    var right;
    if (!st.date) right = el('div', { class: 'cb-hint' }, S.b_pick_day);
    else if (st.slots === null) right = el('div', { class: 'cb-loading' }, S.b_loading);
    else if (!st.slots.length) right = el('div', { class: 'cb-hint' }, S.b_no_slots);
    else right = slotGroups();
    var resched = !!st.token;
    var back = el('button', { class: 'cb-btn cb-btn-ghost', type: 'button', onclick: function () { go(resched ? 'manage' : (st.doctors && st.doctors.length > 1 ? 'doctor' : 'service')); } }, S.b_back);
    var nxt = el('button', { class: 'cb-btn cb-btn-primary', type: 'button', disabled: !st.time, onclick: resched ? doResched : function () { go('details'); } }, resched ? S.b_save_time : S.b_next);
    var who = st.doctor && st.doctor.id ? ' · ' + st.doctor.name : '';
    mount([resched ? '' : steps(), el('h3', {}, st.service.name + who + ' · ' + S.b_choose_date), err(), el('div', { class: 'cb-when' }, [calendar(), el('div', {}, [el('h3', {}, S.b_choose_time), right])]), el('div', { class: 'cb-actions' }, [back, nxt])]);
  }

  // ---------- details ----------
  function summary() {
    return el('div', { class: 'cb-sum' }, st.service.name + (st.doctor && st.doctor.id ? ' — ' + st.doctor.name : '') + ' — ' + fmtLong(st.date) + ' — ' + st.time);
  }
  function viewDetails() {
    var emailReq = D.verify === 'email';
    var f = el('form', { class: 'cb-form', novalidate: 'novalidate', onsubmit: function (e) { e.preventDefault(); submit(f); } }, [
      el('label', {}, [S.b_name, el('input', { name: 'name', required: 'required', autocomplete: 'name', value: st.form.name || '' })]),
      el('label', {}, [S.b_phone, el('input', { name: 'phone', type: 'tel', required: 'required', autocomplete: 'tel', inputmode: 'tel', placeholder: '05xxxxxxxx', value: st.form.phone || '' })]),
      el('label', {}, [emailReq ? S.b_email_req : S.b_email, el('input', { name: 'email', type: 'email', autocomplete: 'email', inputmode: 'email', value: st.form.email || '' }), el('small', { class: 'cb-help' }, S.b_email_help)]),
      el('label', {}, [S.b_notes, el('textarea', { name: 'notes', rows: '2' }, st.form.notes || '')]),
      el('input', { name: 'website', class: 'cb-hp', tabindex: '-1', autocomplete: 'off' }),
      el('div', { class: 'cb-actions' }, [
        el('button', { type: 'button', class: 'cb-btn cb-btn-ghost', onclick: function () { keep(f); go('when'); } }, S.b_back),
        el('button', { type: 'submit', class: 'cb-btn cb-btn-accent' }, S.b_confirm)])
    ]);
    mount([steps(), el('h3', {}, S.b_details), summary(), err(), f]);
  }
  function keep(f) { ['name', 'phone', 'email', 'notes', 'website'].forEach(function (k) { st.form[k] = f.elements[k].value.trim(); }); }
  function payload() {
    return Object.assign({}, st.form, { service_id: st.service.id, doctor_id: st.doctor ? st.doctor.id : 0, date: st.date, time: st.time });
  }
  function submit(f) {
    keep(f);
    var v = st.form;
    if (v.name.length < 2) { st.err = S.b_bad_name; return viewDetails(); }
    if (v.phone.replace(/\D/g, '').length < 7) { st.err = S.b_bad_phone; return viewDetails(); }
    if (v.email && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v.email)) { st.err = S.b_bad_email; return viewDetails(); }
    if (D.verify === 'email' && !v.email) { st.err = S.b_bad_email; return viewDetails(); }
    var btn = f.querySelector('[type=submit]'); btn.disabled = true;
    if (D.verify && D.verify !== 'none') {
      api('otp', payload()).then(function (r) { st.otp = { method: r.method, to: r.to, at: Date.now() }; if (r.method === 'none') return book(); go('otp'); })
        .catch(function (e) { fail(e); });
    } else book();
  }
  function fail(e) {
    st.err = errText(e);
    if (e && e.code === 'slot_taken') { st.date = st.time = null; loadDays(); return go('when'); }
    render();
  }
  function book(code) {
    api('book', Object.assign(payload(), code ? { code: code } : {})).then(function (r) {
      st.appt = r.appointment; st.appt.manage_url = r.manage_url; st.appt.status = r.status; st.token = null; st.doneToken = r.token; st.err = ''; go('done');
    }).catch(fail);
  }

  // ---------- otp ----------
  function viewOtp() {
    var o = st.otp, wait = Math.max(0, 60 - Math.floor((Date.now() - o.at) / 1000));
    var input = el('input', { name: 'code', inputmode: 'numeric', autocomplete: 'one-time-code', maxlength: '6', class: 'cb-otp', placeholder: '••••••' });
    var resend = el('button', { type: 'button', class: 'cb-btn cb-btn-ghost', disabled: wait > 0, onclick: function () {
      api('otp', payload()).then(function (r) { st.otp = { method: r.method, to: r.to, at: Date.now() }; st.err = ''; render(); }).catch(function (e) { st.err = errText(e); render(); }); } }, wait > 0 ? fmt(S.b_otp_wait, { s: wait }) : S.b_otp_resend);
    if (wait > 0) { var t = setInterval(function () { if (st.view !== 'otp' || !resend.isConnected) return clearInterval(t); var w = Math.max(0, 60 - Math.floor((Date.now() - o.at) / 1000)); resend.textContent = w ? fmt(S.b_otp_wait, { s: w }) : S.b_otp_resend; if (!w) { resend.disabled = false; clearInterval(t); } }, 1000); }
    var f = el('form', { class: 'cb-form', onsubmit: function (e) { e.preventDefault(); var c = input.value.replace(/\D/g, ''); if (c.length < 6) { st.err = S.b_otp_bad; return render(); } f.querySelector('[type=submit]').disabled = true; book(c); } }, [
      el('label', {}, [S.b_otp_code, input]),
      el('div', { class: 'cb-actions' }, [el('button', { type: 'button', class: 'cb-btn cb-btn-ghost', onclick: function () { go('details'); } }, S.b_otp_edit), resend,
        el('button', { type: 'submit', class: 'cb-btn cb-btn-accent' }, S.b_otp_verify)])
    ]);
    mount([steps(), el('h3', {}, S.b_otp_title), el('p', {}, fmt(o.method === 'email' ? S.b_otp_email : S.b_otp_wa, { to: o.to })), err(), f]);
    input.focus();
  }

  // ---------- shared panels ----------
  function calLinks(a) {
    if (!a.cal) return '';
    return el('div', { class: 'cb-cal-links' }, [el('small', {}, S.b_cal_add),
      el('a', { class: 'cb-btn cb-btn-ghost', href: a.cal.google, target: '_blank', rel: 'noopener' }, S.b_cal_google),
      el('a', { class: 'cb-btn cb-btn-ghost', href: a.cal.ics }, S.b_cal_ics)]);
  }
  function payPanel(a, token) {
    var p = a.payment; if (!p) return '';
    var kids = [el('h4', {}, S.b_pay_title)];
    if (p.status === 'paid') return el('div', { class: 'cb-pay ok' }, [el('b', {}, '✓ ' + S.b_pay_paid)]);
    if (p.status === 'expired') return el('div', { class: 'cb-pay bad' }, S.b_pay_expired);
    kids.push(el('p', {}, [S.b_pay_amount + ': ', el('b', {}, p.amount)]));
    if (p.info) kids.push(el('div', { class: 'cb-pay-info' }, [el('small', {}, S.b_pay_info), el('pre', {}, p.info)]));
    if (p.status === 'submitted') kids.push(el('div', { class: 'cb-hint' }, S.b_pay_submitted));
    else {
      kids.push(el('p', {}, S.b_pay_hold));
      if (p.status === 'rejected') kids.push(el('div', { class: 'cb-err' }, S.b_pay_rejected));
      if (a.hold_until) kids.push(el('small', { class: 'cb-help' }, fmt(S.b_pay_until, { t: a.hold_until })));
    }
    if (p.can_upload) {
      var file = el('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp,application/pdf', class: 'cb-file' });
      var msg = el('div', { class: 'cb-err', style: 'display:none' });
      var btn = el('button', { type: 'button', class: 'cb-btn cb-btn-accent', onclick: function () {
        if (!file.files[0]) { msg.textContent = S.b_pay_choose; msg.style.display = ''; return; }
        if (file.files[0].size > 5 * 1024 * 1024) { msg.textContent = S.b_pay_bad_file; msg.style.display = ''; return; }
        btn.disabled = true; var fd = new FormData(); fd.append('proof', file.files[0]); fd.append('token', token); fd.append('lang', D.lang);
        fetch(full('pay'), { method: 'POST', body: fd }).then(handle).then(function (r) { var keepUrl = a.manage_url; Object.assign(a, r.appointment); a.manage_url = keepUrl; render(); })
          .catch(function (e) { btn.disabled = false; msg.textContent = errText(e); msg.style.display = ''; });
      } }, p.status === 'submitted' ? S.b_pay_replace : S.b_pay_send);
      kids.push(el('label', {}, [p.status === 'submitted' ? S.b_pay_replace : S.b_pay_upload, file, el('small', { class: 'cb-help' }, S.b_pay_hint)]), msg, btn);
    }
    return el('div', { class: 'cb-pay' }, kids);
  }

  // ---------- done ----------
  function viewDone() {
    var a = st.appt, waiting = a.payment && a.payment.status !== 'paid';
    var kids = [el('div', { class: 'tick' }, '✓'), el('h3', {}, S.b_ok_title),
      el('p', {}, waiting ? S.b_ok_wait_pay : (a.status === 'confirmed' ? S.b_ok_confirmed : S.b_ok_pending)),
      el('div', { class: 'cb-sum' }, a.service + (a.doctor ? ' — ' + a.doctor : '') + ' — ' + fmtLong(a.date) + ' — ' + a.time)];
    if (a.queue && a.status !== 'cancelled') kids.push(el('div', { class: 'cb-queue' }, [
      el('div', { class: 'q-box' }, [el('small', {}, S.b_queue), el('b', {}, '#' + a.queue.no), el('em', {}, S.b_queue_of.replace('{n}', a.queue.total))]),
      el('div', { class: 'q-box' }, [el('small', {}, S.b_arrive), el('b', {}, a.queue.arrive), el('em', {}, S.b_arrive_hint.replace('{m}', a.queue.minutes))])]));
    mount([el('div', { class: 'cb-ok' }, kids.concat([
      waiting ? el('div', { class: 'cb-ok-pay' }, payPanel(a, st.doneToken)) : '',
      calLinks(a),
      el('p', {}, S.b_ok_manage), el('div', { class: 'cb-link' }, a.manage_url),
      el('div', { class: 'cb-actions', style: 'justify-content:center' }, [
        el('button', { class: 'cb-btn cb-btn-ghost', type: 'button', onclick: function () { navigator.clipboard && navigator.clipboard.writeText(a.manage_url); this.textContent = S.b_copied; } }, S.b_copy),
        el('button', { class: 'cb-btn cb-btn-primary', type: 'button', onclick: function () { st.service = st.doctor = st.date = st.time = null; st.form = {}; go('service'); } }, S.b_new)])
    ]))]);
  }

  // ---------- manage (token) ----------
  function viewManage() {
    var a = st.appt, nodes = [el('h3', {}, S.b_my_appt)];
    nodes.push(el('div', { class: 'cb-appt-card' }, [el('p', {}, el('b', {}, a.service)), a.doctor ? el('p', {}, S.b_doctor + ': ' + a.doctor) : '', el('p', {}, fmtLong(a.date) + ' — ' + a.time), el('p', {}, S['b_status_' + a.status] || a.status)]));
    nodes.push(err());
    if (a.payment) nodes.push(payPanel(a, st.token));
    if (st.okmsg) nodes.push(el('div', { class: 'cb-hint' }, S.b_resched_ok || ''));
    if (a.status === 'pending' || a.status === 'confirmed') nodes.push(calLinks(a));
    if (a.can_change) {
      var acts = [];
      if (a.allow_resched) acts.push(el('button', { class: 'cb-btn cb-btn-primary', type: 'button', onclick: function () {
        st.service = st.services.filter(function (s) { return s.id === a.service_id; })[0] || { id: a.service_id, name: a.service };
        st.doctor = { id: a.doctor_id, name: a.doctor };
        st.date = st.time = null; st.days = null; st.slots = null; var p = a.date.split('-'); st.month = new Date(+p[0], +p[1] - 1, 1); loadDays(); go('when'); } }, S.b_resched));
      acts.push(el('button', { class: 'cb-btn cb-btn-ghost', type: 'button', onclick: function () {
        if (!confirm(S.b_confirm_cancel)) return;
        api('cancel', { token: st.token }).then(function () { a.status = 'cancelled'; a.can_change = false; a.cal = null; st.err = ''; render(); }).catch(function () { st.err = S.b_err; render(); }); } }, S.b_cancel));
      nodes.push(el('div', { class: 'cb-actions' }, acts));
    } else if (a.status === 'pending' || a.status === 'confirmed') {
      nodes.push(el('div', { class: 'cb-hint' }, fmt(S.b_cant_change, { h: a.cancel_hours })));
    }
    mount(nodes);
  }
  function doResched() {
    api('reschedule', { token: st.token, date: st.date, time: st.time }).then(function (r) { var m = st.appt.manage_url; st.appt = r.appointment; st.appt.manage_url = m; st.err = ''; st.okmsg = 1; go('manage'); })
      .catch(function (e) { st.err = errText(e); loadDays(); loadSlots(); });
  }

  function render() {
    var v = st.view;
    if (v === 'service') viewService();
    else if (v === 'doctor') viewDoctor();
    else if (v === 'when') viewWhen();
    else if (v === 'details') viewDetails();
    else if (v === 'otp') viewOtp();
    else if (v === 'manage') viewManage();
    else viewDone();
  }

  var token = new URLSearchParams(location.search).get('cb_manage');
  api('services').then(function (r) {
    st.services = r.services;
    if (token) {
      api('appointment?token=' + encodeURIComponent(token)).then(function (a) { st.token = token; st.appt = a.appointment; st.view = 'manage'; render(); root.scrollIntoView({ behavior: 'smooth' }); })
        .catch(function () { mount(el('div', { class: 'cb-err' }, S.b_not_found)); });
    } else render();
  }).catch(function () { mount(el('div', { class: 'cb-err' }, S.b_err)); });
})();
