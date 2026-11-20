/* Clinic Booking – chatbot widget (vanilla JS). Booking is only confirmed after the server saves it. */
(function () {
  var D = window.CBChat; if (!D) return;
  var S = D.s, host = document.getElementById('cb-chat'); if (!host) return;

  function el(tag, attrs, kids) {
    var e = document.createElement(tag);
    for (var k in (attrs || {})) {
      if (k === 'class') e.className = attrs[k];
      else if (k.slice(0, 2) === 'on') e.addEventListener(k.slice(2), attrs[k]);
      else if (attrs[k] != null) e.setAttribute(k, attrs[k]);
    }
    [].concat(kids == null ? [] : kids).forEach(function (c) { e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return e;
  }
  function api(path, body) {
    var opt = body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(Object.assign({ lang: D.lang }, body)) } : {};
    var url = D.rest + (D.rest.indexOf('?') > -1 ? path.replace('?', '&') : path); if (!body) url += (url.indexOf('?') > -1 ? '&' : '?') + 'lang=' + D.lang;
    return fetch(url, opt).then(function (r) { return r.json().then(function (j) { if (!r.ok) throw j; return j; }); });
  }
  var pad = function (n) { return (n < 10 ? '0' : '') + n; };
  var fmtDay = function (s) { var p = s.split('-'); return new Intl.DateTimeFormat(D.locale, { weekday: 'short', day: 'numeric', month: 'short' }).format(new Date(+p[0], +p[1] - 1, +p[2])); };

  var msgs = el('div', { class: 'cb-msgs' });
  var input = el('input', { type: 'text', placeholder: S.c_placeholder, maxlength: '300' });
  var panel = el('div', { class: 'cb-panel' }, [
    el('div', { class: 'cb-chat-head' }, [el('span', {}, '💬 ' + S.c_title), el('button', { type: 'button', 'aria-label': 'close', onclick: toggle }, '×')]),
    msgs,
    el('form', { class: 'cb-chat-in', onsubmit: function (e) { e.preventDefault(); onSend(); } }, [input, el('button', { type: 'submit' }, S.c_send)])
  ]);
  var fab = el('button', { class: 'cb-fab', type: 'button', 'aria-label': S.c_title, onclick: toggle }, '💬');
  host.appendChild(panel); host.appendChild(fab);

  var started = false, flow = null;
  function toggle() {
    host.classList.toggle('open');
    if (host.classList.contains('open') && !started) { started = true; bot(S.c_greeting); chips(['services', 'hours', 'location', 'prices', 'book', 'human']); }
    if (host.classList.contains('open')) input.focus();
  }
  function scroll() { msgs.scrollTop = msgs.scrollHeight; }
  function bot(t) { msgs.appendChild(el('div', { class: 'cb-m bot' }, t)); scroll(); }
  function me(t) { msgs.appendChild(el('div', { class: 'cb-m me' }, t)); scroll(); }
  function clearChips() { [].forEach.call(msgs.querySelectorAll('.cb-chips'), function (c) { c.remove(); }); }

  var LABEL = { services: S.c_services, hours: S.c_hours, location: S.c_location, prices: S.c_prices, book: S.c_book, start_booking: S.c_book, human: S.c_human, handoff: S.c_human };
  var ASK = { services: 'services', hours: 'hours', location: 'location', prices: 'prices' };
  function chips(actions) {
    var box = el('div', { class: 'cb-chips' });
    actions.forEach(function (a) {
      box.appendChild(el('button', { type: 'button', class: 'cb-chip', onclick: function () { clickAction(a); } }, LABEL[a] || a));
    });
    msgs.appendChild(box); scroll();
  }
  function clickAction(a) {
    clearChips(); me(LABEL[a] || a);
    if (a === 'book' || a === 'start_booking') return startBooking();
    if (a === 'human' || a === 'handoff') return startHandoff();
    ask(LABEL[a]);
  }
  function ask(text) {
    api('chat', { message: text }).then(function (r) { bot(r.reply); chips(r.actions || []); }).catch(function () { bot(S.b_err); });
  }

  function onSend() {
    var v = input.value.trim(); if (!v) return; input.value = ''; clearChips(); me(v);
    if (flow && flow.step === 'name') { if (v.length < 2) return bot(S.b_bad_name); flow.name = v; flow.step = 'phone'; return bot(S.c_ask_phone); }
    if (flow && flow.step === 'phone') { if (v.replace(/\D/g, '').length < 7) return bot(S.b_bad_phone); flow.phone = v; return flow.kind === 'handoff' ? sendHandoff() : finishBooking(); }
    ask(v);
  }

  // ---------- booking flow ----------
  function cancelChip() { return el('button', { type: 'button', class: 'cb-chip alt', onclick: function () { clearChips(); flow = null; me(S.c_cancel_flow); bot(S.c_greeting); chips(['services', 'hours', 'book', 'human']); } }, S.c_cancel_flow); }
  function choices(items, onPick) {
    var box = el('div', { class: 'cb-chips' });
    items.forEach(function (it) { box.appendChild(el('button', { type: 'button', class: 'cb-chip', onclick: function () { clearChips(); me(it.label); onPick(it); } }, it.label)); });
    box.appendChild(cancelChip()); msgs.appendChild(box); scroll();
  }
  function startBooking() {
    flow = { kind: 'book', step: 'service' };
    api('services').then(function (r) {
      if (!r.services.length) { flow = null; return bot(S.b_no_services); }
      bot(S.c_pick_service);
      choices(r.services.map(function (s) { return { label: s.name, s: s }; }), function (it) { flow.service = it.s; pickDate(); });
    }).catch(function () { flow = null; bot(S.b_err); });
  }
  function pickDate() {
    flow.step = 'date';
    var now = new Date(), m1 = now.getFullYear() + '-' + pad(now.getMonth() + 1), n2 = new Date(now.getFullYear(), now.getMonth() + 1, 1), m2 = n2.getFullYear() + '-' + pad(n2.getMonth() + 1);
    Promise.all([api('days?service_id=' + flow.service.id + '&month=' + m1), api('days?service_id=' + flow.service.id + '&month=' + m2)]).then(function (r) {
      var all = Object.assign({}, r[0].days, r[1].days), days = Object.keys(all).filter(function (k) { return all[k] > 0; }).sort().slice(0, 8);
      if (!days.length) { flow = null; return bot(S.c_no_days); }
      bot(S.c_pick_date);
      choices(days.map(function (d) { return { label: fmtDay(d), d: d }; }), function (it) { flow.date = it.d; pickTime(); });
    }).catch(function () { flow = null; bot(S.b_err); });
  }
  function pickTime() {
    flow.step = 'time';
    api('slots?service_id=' + flow.service.id + '&date=' + flow.date).then(function (r) {
      if (!r.slots.length) { bot(S.b_no_slots); return pickDate(); }
      bot(S.c_pick_time);
      choices(r.slots.map(function (t) { return { label: t, t: t }; }), function (it) { flow.time = it.t; flow.step = 'name'; bot(S.c_ask_name); });
    }).catch(function () { flow = null; bot(S.b_err); });
  }
  function finishBooking() {
    var f = flow; flow.step = 'saving';
    api('book', { service_id: f.service.id, date: f.date, time: f.time, name: f.name, phone: f.phone, source: 'chatbot' }).then(function (r) {
      flow = null;
      var pay = r.appointment.payment && r.appointment.payment.status === 'awaiting';
      bot((pay ? S.b_ok_wait_pay : (r.status === 'confirmed' ? S.c_booked : S.c_booked_pending)) + '\n' + r.appointment.service + (r.appointment.doctor ? ' — ' + r.appointment.doctor : '') + '\n' + fmtDay(r.appointment.date) + ' — ' + r.appointment.time);
      if (pay) bot(S.c_pay_wait + '\n' + r.manage_url);
      else bot(S.b_ok_manage + '\n' + r.manage_url);
    }).catch(function (e) {
      if (e && e.code === 'slot_taken') { bot(S.b_slot_taken); flow.step = 'date'; pickTime(); }
      else if (e && e.code === 'bad_phone') { bot(S.b_bad_phone); flow.step = 'phone'; }
      else if (e && e.code === 'otp_required') { flow = null; bot(S.c_use_form + (D.bookUrl ? '\n' + D.bookUrl : '')); }
      else if (e && (e.code === 'blocked' || e.code === 'limit')) { flow = null; bot(e.code === 'blocked' ? S.b_blocked : S.b_limit); }
      else { flow = null; bot(S.b_err); }
    });
  }

  // ---------- human handoff ----------
  function startHandoff() {
    var links = [];
    if (D.phone) links.push('📞 ' + D.phone);
    if (D.wa) links.push('https://wa.me/' + D.wa);
    bot(S.c_handoff + (links.length ? '\n' + links.join('\n') : ''));
    flow = { kind: 'handoff', step: 'name' }; bot(S.c_ask_name);
  }
  function sendHandoff() {
    var f = flow; flow = null;
    api('handoff', { name: f.name, phone: f.phone, message: 'chatbot' }).then(function () { bot(S.c_handoff_sent); }).catch(function () { bot(S.b_err); });
  }
})();
