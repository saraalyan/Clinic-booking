/* Clinic Booking – admin: calendar (day/week/month), appointment list, modal, settings helpers */
(function () {
  var D = window.CBA; if (!D) return;
  var S = D.s;

  // ----- settings helpers (color picker + media) -----
  if (window.jQuery) {
    jQuery(function ($) {
      if ($.fn.wpColorPicker) $('.cb-color').wpColorPicker();
      $(document).on('click', '.cb-media', function (e) {
        e.preventDefault();
        var input = $(this).siblings('input');
        var frame = wp.media({ multiple: false, library: { type: 'image' } });
        frame.on('select', function () { input.val(frame.state().get('selection').first().toJSON().url); });
        frame.open();
      });
    });
  }

  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('.cb-copy'); if (!b) return;
    var code = b.getAttribute('data-code'), old = b.textContent;
    var done = function () { b.textContent = '✓'; setTimeout(function () { b.textContent = old; }, 1200); };
    if (navigator.clipboard) navigator.clipboard.writeText(code).then(done); else { var t = document.createElement('textarea'); t.value = code; document.body.appendChild(t); t.select(); document.execCommand('copy'); t.remove(); done(); }
  });

  function el(tag, attrs, kids) {
    var e = document.createElement(tag);
    for (var k in (attrs || {})) {
      if (k === 'class') e.className = attrs[k];
      else if (k.slice(0, 2) === 'on') e.addEventListener(k.slice(2), attrs[k]);
      else if (attrs[k] != null && attrs[k] !== false) e.setAttribute(k, attrs[k]);
    }
    [].concat(kids == null ? [] : kids).forEach(function (c) { e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return e;
  }
  function api(path, method, body) {
    var opt = { method: method || 'GET', headers: { 'X-WP-Nonce': D.nonce, 'Content-Type': 'application/json' }, credentials: 'same-origin' };
    if (body) opt.body = JSON.stringify(body);
    return fetch(D.rest + (D.rest.indexOf('?') > -1 ? path.replace('?', '&') : path), opt).then(function (r) { return r.json().then(function (j) { if (!r.ok) throw j; return j; }); });
  }
  var pad = function (n) { return (n < 10 ? '0' : '') + n; };
  var ymd = function (d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); };
  var parse = function (s) { var p = s.split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); };
  var addDays = function (d, n) { var x = new Date(d); x.setDate(x.getDate() + n); return x; };
  var fmt = function (d, o) { return new Intl.DateTimeFormat(D.locale, o).format(d); };
  var mins = function (t) { var p = t.split(':'); return +p[0] * 60 + +p[1]; };

  var multi = D.doctors && D.doctors.length > 1;
  function docById(id) { return (D.doctors || []).filter(function (d) { return d.id === +id; })[0]; }
  function docsFor(sid) { return (D.doctors || []).filter(function (d) { return !d.services.length || d.services.indexOf(+sid) > -1; }); }
  function payBadge(a) { return a.pay_status && a.pay_status !== 'none' ? el('span', { class: 'cb-st cb-pay-' + a.pay_status }, S['ps_' + a.pay_status] || a.pay_status) : ''; }

  // ----- modal -----
  function modal(a, onDone) {
    var isNew = !a.id;
    var bg = el('div', { class: 'cb-modal-bg', onclick: function (e) { if (e.target === bg) bg.remove(); } });
    var errBox = el('div');
    var svc = el('select', {}, D.services.map(function (s) { return el('option', { value: s.id, selected: String(s.id) === String(a.service_id) ? 'selected' : null }, s.name); }));
    var doc = el('select');
    function fillDocs() {
      var keep = doc.value || a.doctor_id; doc.innerHTML = '';
      docsFor(svc.value).forEach(function (d) { doc.appendChild(el('option', { value: d.id, selected: String(d.id) === String(keep) ? 'selected' : null }, d.name)); });
    }
    svc.addEventListener('change', fillDocs); fillDocs();
    var email = el('input', { type: 'email', value: a.email || '', dir: 'ltr', readonly: isNew ? null : 'readonly' });
    var name = el('input', { value: a.name || '', readonly: isNew ? null : 'readonly' });
    var phone = el('input', { value: a.phone ? '+' + a.phone : '', dir: 'ltr', readonly: isNew ? null : 'readonly' });
    var date = el('input', { type: 'date', value: a.date || D.today });
    var time = el('input', { type: 'time', value: a.time || '09:00' });
    var status = el('select', {}, ['pending', 'confirmed', 'completed', 'noshow', 'cancelled'].map(function (s) { return el('option', { value: s, selected: (a.status || 'confirmed') === s ? 'selected' : null }, S['st_' + s]); }));
    var notes = el('textarea', { rows: '2' }); notes.value = a.notes || '';
    function save(forceStatus) {
      var body = { service_id: +svc.value, doctor_id: +doc.value || 0, email: email.value, date: date.value, time: time.value, status: forceStatus || status.value, notes: notes.value, name: name.value, phone: phone.value };
      var req = isNew ? api('admin/appointments', 'POST', body) : api('admin/appointments/' + a.id, 'POST', body);
      req.then(function () { bg.remove(); onDone(); }).catch(function (e) {
        errBox.innerHTML = ''; errBox.appendChild(el('div', { class: 'err' }, e && e.code === 'slot_taken' ? S.a_slot_conflict : (e && e.message) || 'Error'));
      });
    }
    var acts = [el('button', { class: 'cb-btn pri', type: 'button', onclick: function () { save(); } }, S.a_save)];
    if (!isNew) {
      if (a.status === 'pending') acts.push(el('button', { class: 'cb-btn', type: 'button', onclick: function () { save('confirmed'); } }, S.a_confirm_btn));
      if (a.status === 'confirmed' || a.status === 'pending') {
        acts.push(el('button', { class: 'cb-btn', type: 'button', onclick: function () { save('completed'); } }, S.a_complete_btn));
        acts.push(el('button', { class: 'cb-btn danger', type: 'button', onclick: function () { save('cancelled'); } }, S.a_cancel_appt));
      }
      acts.push(el('button', { class: 'cb-btn danger', type: 'button', onclick: function () { if (confirm(S.a_confirm)) api('admin/appointments/' + a.id + '/delete', 'POST').then(function () { bg.remove(); onDone(); }); } }, S.a_delete));
    }
    acts.push(el('button', { class: 'cb-btn', type: 'button', onclick: function () { bg.remove(); } }, S.a_close));
    var pay = '';
    if (!isNew && a.pay_status && a.pay_status !== 'none') {
      var decide = function (what) { api('admin/appointments/' + a.id + '/pay', 'POST', { 'do': what }).then(function () { bg.remove(); onDone(); }).catch(function (e) { errBox.innerHTML = ''; errBox.appendChild(el('div', { class: 'err' }, (e && e.message) || 'Error')); }); };
      var pk = [el('b', {}, '💳 '), payBadge(a), ' ' + S.a_amount + ': ', el('b', {}, a.pay_amount || '—')];
      var pr = [];
      if (a.has_proof) pr.push(el('a', { class: 'cb-btn', href: a.proof_url, target: '_blank', rel: 'noopener' }, S.a_proof));
      if (a.pay_status === 'submitted' || a.pay_status === 'awaiting' || a.pay_status === 'rejected') pr.push(el('button', { class: 'cb-btn pri', type: 'button', onclick: function () { decide('approve'); } }, S.a_approve));
      if (a.pay_status === 'submitted') pr.push(el('button', { class: 'cb-btn danger', type: 'button', onclick: function () { decide('reject'); } }, S.a_reject));
      pay = el('div', { class: 'cb-paybox' }, [el('div', {}, pk), el('div', { class: 'acts' }, pr)]);
    }
    bg.appendChild(el('div', { class: 'cb-modal', dir: D.dir, style: 'direction:' + D.dir }, [
      el('h3', {}, isNew ? S.a_new_appt : S.a_edit), errBox, pay,
      el('label', {}, [S.a_service, svc]), multi || (D.doctors || []).length ? el('label', {}, [S.a_doctors, doc]) : '', el('label', {}, [S.a_name, name]), el('label', {}, [S.a_phone, phone]), el('label', {}, [S.a_email, email]),
      el('div', { class: 'row' }, [el('label', {}, [S.a_date, date]), el('label', {}, [S.a_time, time])]),
      el('label', {}, [S.a_status, status]), el('label', {}, [S.a_notes, notes]), el('div', { class: 'acts' }, acts)
    ]));
    document.body.appendChild(bg);
  }

  function docFilter(onChange, val) {
    if (!multi) return '';
    var sel = el('select', {}, [el('option', { value: 0 }, S.a_all_doctors)].concat(D.doctors.map(function (d) { return el('option', { value: d.id, selected: +val === d.id ? 'selected' : null }, d.name); })));
    sel.addEventListener('change', function () { onChange(+sel.value); });
    return sel;
  }

  // ----- calendar -----
  var calRoot = document.getElementById('cb-cal-app');
  if (calRoot) {
    var cur = parse(D.today), view = 'day', data = [], docF = 0;
    function range() {
      if (view === 'day') return [cur, cur];
      if (view === 'week') { var s = addDays(cur, -cur.getDay()); return [s, addDays(s, 6)]; }
      var f = new Date(cur.getFullYear(), cur.getMonth(), 1); return [f, new Date(cur.getFullYear(), cur.getMonth() + 1, 0)];
    }
    function load() {
      var r = range();
      api('admin/appointments?from=' + ymd(r[0]) + '&to=' + ymd(r[1]) + (docF ? '&doctor_id=' + docF : '')).then(function (j) {
        data = j.appointments.filter(function (a) { return a.status !== 'cancelled'; }); draw();
      });
    }
    function title() {
      if (view === 'day') return fmt(cur, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
      if (view === 'week') { var r = range(); return fmt(r[0], { day: 'numeric', month: 'short' }) + ' – ' + fmt(r[1], { day: 'numeric', month: 'short', year: 'numeric' }); }
      return fmt(cur, { month: 'long', year: 'numeric' });
    }
    function step(n) { if (view === 'day') cur = addDays(cur, n); else if (view === 'week') cur = addDays(cur, 7 * n); else cur = new Date(cur.getFullYear(), cur.getMonth() + n, 1); load(); }
    function chip(a, showTime) { return el('button', { type: 'button', class: 'cb-chip', style: '--c:' + (multi && a.doctor_color ? a.doctor_color : a.color), onclick: function (e) { e.stopPropagation(); modal(a, load); } }, (showTime ? a.time + ' ' : '') + a.name + (multi && a.doctor ? ' · ' + a.doctor : '')); }
    function drawDay() {
      var from = D.from, to = D.to, px = 56;
      data.forEach(function (a) { from = Math.min(from, Math.floor(mins(a.time) / 60)); to = Math.max(to, Math.ceil(mins(a.end) / 60)); });
      var box = el('div', { class: 'cb-day', style: 'height:' + ((to - from) * px) + 'px;background:repeating-linear-gradient(#fff 0 ' + (px - 1) + 'px,#e6edf5 ' + (px - 1) + 'px ' + px + 'px)' });
      for (var h = from; h <= to; h++) box.appendChild(el('div', { style: 'position:absolute;inset-inline:0;top:' + ((h - from) * px) + 'px' }, el('span', { style: 'position:absolute;inset-inline-end:-54px;top:-9px;font-size:12px;color:#6b7c93' }, pad(h) + ':00')));
      var cols = []; data.forEach(function (a) { if (cols.indexOf(a.doctor_id) < 0) cols.push(a.doctor_id); });
      data.forEach(function (a) {
        var top = (mins(a.time) - from * 60) * px / 60, ht = Math.max(28, (mins(a.end) - mins(a.time)) * px / 60);
        var n = cols.length, i = cols.indexOf(a.doctor_id), pos = n > 1 ? ';inset-inline:auto;inset-inline-start:calc(' + (i * 100 / n) + '% + 3px);width:calc(' + (100 / n) + '% - 6px)' : '';
        var ev = el('div', { class: 'cb-ev', style: 'top:' + top + 'px;height:' + ht + 'px;--c:' + (multi && a.doctor_color ? a.doctor_color : a.color) + pos, onclick: function () { modal(a, load); } }, [el('b', {}, a.name), ' – ' + a.service, el('small', {}, a.time + ' - ' + a.end + (multi && a.doctor ? ' · ' + a.doctor : ''))]);
        box.appendChild(ev);
      });
      return box;
    }
    function drawWeek() {
      var r = range(), g = el('div', { class: 'cb-week' });
      for (var i = 0; i < 7; i++) (function (d) {
        var k = ymd(d), col = el('div', { class: 'cb-wcol' + (k === D.today ? ' today' : '') }, [el('h4', { onclick: function () { cur = d; view = 'day'; load(); } }, fmt(d, { weekday: 'short', day: 'numeric' }))]);
        data.filter(function (a) { return a.date === k; }).forEach(function (a) { col.appendChild(chip(a, true)); });
        g.appendChild(col);
      })(addDays(r[0], i));
      return g;
    }
    function drawMonth() {
      var g = el('div', { class: 'cb-month' }), first = new Date(cur.getFullYear(), cur.getMonth(), 1), n = new Date(cur.getFullYear(), cur.getMonth() + 1, 0).getDate();
      for (var i = 0; i < 7; i++) g.appendChild(el('div', { class: 'wd' }, fmt(new Date(2023, 0, 1 + i), { weekday: 'short' })));
      for (i = 0; i < first.getDay(); i++) g.appendChild(el('span'));
      for (var d = 1; d <= n; d++) (function (dt) {
        var k = ymd(dt), c = data.filter(function (a) { return a.date === k; }).length;
        g.appendChild(el('div', { class: 'cb-mcell' + (k === D.today ? ' today' : ''), onclick: function () { cur = dt; view = 'day'; load(); } }, [el('b', {}, String(dt.getDate())), c ? el('div', {}, el('span', { class: 'n' }, c + ' ' + S.a_appt_word)) : '']));
      })(new Date(cur.getFullYear(), cur.getMonth(), d));
      return g;
    }
    function draw() {
      calRoot.innerHTML = '';
      var seg = el('div', { class: 'cb-seg' }, ['day', 'week', 'month'].map(function (v) { return el('button', { type: 'button', class: view === v ? 'on' : '', onclick: function () { view = v; load(); } }, S['a_' + v]); }));
      calRoot.appendChild(el('div', { class: 'cb-card' }, [
        el('div', { class: 'cb-toolbar' }, [
          el('button', { class: 'cb-btn', type: 'button', onclick: function () { step(-1); } }, D.dir === 'rtl' ? '›' : '‹'), el('div', { class: 'title' }, title()), el('button', { class: 'cb-btn', type: 'button', onclick: function () { step(1); } }, D.dir === 'rtl' ? '‹' : '›'),
          el('button', { class: 'cb-btn', type: 'button', onclick: function () { cur = parse(D.today); load(); } }, S.a_today), seg, docFilter(function (v) { docF = v; load(); }, docF),
          el('button', { class: 'cb-btn pri', type: 'button', onclick: function () { modal({ date: ymd(cur) }, load); } }, '+ ' + S.a_add_appt)]),
        view === 'day' ? drawDay() : view === 'week' ? drawWeek() : drawMonth()]));
    }
    load();
    if (+D.open_new) modal({ date: D.today }, load);
  }

  // ----- list -----
  var listRoot = document.getElementById('cb-list-app');
  if (listRoot) {
    var f = { status: listRoot.getAttribute('data-status') || '', from: D.today, to: '', q: '', doctor_id: 0, pay_status: '' };
    if (f.status === 'cancelled') f.from = '';
    function loadList() {
      var qs = ['status', 'from', 'to', 'q', 'doctor_id', 'pay_status'].filter(function (k) { return f[k]; }).map(function (k) { return k + '=' + encodeURIComponent(f[k]); }).join('&');
      api('admin/appointments?' + qs).then(function (j) { drawList(j.appointments); });
    }
    function setStatus(a, s) { api('admin/appointments/' + a.id, 'POST', { status: s }).then(loadList); }
    function drawList(rows) {
      listRoot.innerHTML = '';
      var inp = function (k, type, ph) { var i = el('input', { type: type, value: f[k], placeholder: ph || '' }); i.addEventListener('change', function () { f[k] = i.value; loadList(); }); return i; };
      var st = el('select', {}, [''].concat(['pending', 'confirmed', 'completed', 'noshow', 'cancelled']).map(function (s) { return el('option', { value: s, selected: f.status === s ? 'selected' : null }, s ? S['st_' + s] : S.a_all); }));
      st.addEventListener('change', function () { f.status = st.value; loadList(); });
      var heads = [S.a_date, S.a_time, S.a_name, S.a_phone, S.a_service].concat(multi ? [S.a_doctors] : [], [S.a_status], +D.pay ? [S.a_pay_col] : [], [S.a_actions]);
      var payFilter = function () {
        if (!+D.pay) return '';
        var ps = el('select', {}, [''].concat(['awaiting', 'submitted', 'paid', 'rejected', 'expired']).map(function (v) { return el('option', { value: v, selected: f.pay_status === v ? 'selected' : null }, v ? S['ps_' + v] : S.a_pay_col + ': ' + S.a_all); }));
        ps.addEventListener('change', function () { f.pay_status = ps.value; loadList(); }); return ps;
      };
      var tbl = el('table', { class: 'cb-table' }, el('tr', {}, heads.map(function (h) { return el('th', {}, h); })));
      rows.forEach(function (a) {
        var acts = [el('button', { class: 'cb-btn', type: 'button', onclick: function () { modal(a, loadList); } }, S.a_edit)];
        if (a.status === 'pending') acts.push(el('button', { class: 'cb-btn', type: 'button', onclick: function () { setStatus(a, 'confirmed'); } }, S.a_confirm_btn));
        if (a.status === 'confirmed' || a.status === 'pending') acts.push(el('button', { class: 'cb-btn danger', type: 'button', onclick: function () { setStatus(a, 'cancelled'); } }, S.a_cancel_appt));
        tbl.appendChild(el('tr', {}, [el('td', {}, a.date), el('td', {}, a.time), el('td', {}, el('b', {}, a.name)), el('td', { dir: 'ltr', style: 'text-align:right' }, '+' + a.phone), el('td', {}, a.service)]
          .concat(multi ? [el('td', {}, a.doctor)] : [], [el('td', {}, el('span', { class: 'cb-st cb-st-' + a.status }, S['st_' + a.status]))], +D.pay ? [el('td', {}, payBadge(a) || '—')] : [], [el('td', {}, acts)])));
      });
      if (!rows.length) tbl.appendChild(el('tr', {}, el('td', { colspan: String(heads.length) }, S.a_none)));
      listRoot.appendChild(el('div', { class: 'cb-card' }, [
        el('div', { class: 'cb-card-h' }, [el('h3', {}, S.a_appointments || ''), el('button', { class: 'cb-btn pri', type: 'button', onclick: function () { modal({ date: D.today }, loadList); } }, '+ ' + S.a_add_appt)]),
        el('div', { class: 'cb-toolbar' }, [st, docFilter(function (v) { f.doctor_id = v; loadList(); }, f.doctor_id), payFilter(), el('label', {}, [S.a_from + ' ', inp('from', 'date')]), el('label', {}, [S.a_to + ' ', inp('to', 'date')]), inp('q', 'search', S.a_search)]), tbl]));
    }
    loadList();
  }
})();
