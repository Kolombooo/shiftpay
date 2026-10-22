(function () {
    'use strict';

    var state = { wage: 0, symbol: 'Kč', brk: 30, shifts: [], summary: null };
    var view = new Date(); view.setDate(1); view.setHours(0, 0, 0, 0);
    var editingId = null, statusTouched = false, status = 'worked', deleteArmed = false;
    var bulkStatus = null;
    var loadToken = 0, lastFocus = null;
    var OVERLAYS = ['bulkOverlay', 'shiftOverlay', 'setOverlay'];

    function $(id) { return document.getElementById(id); }
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
    function monthKey(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1); }
    function todayStr() { return ymd(new Date()); }
    function parseDate(str) { var p = str.split('-').map(Number); return new Date(p[0], p[1] - 1, p[2]); }
    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined) n.textContent = text;
        return n;
    }

    /* Czech number, time and date formats; wording stays English. */
    function money(n) {
        return Number(n).toLocaleString('cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + state.symbol;
    }
    function fmtMinutes(m) {
        var hh = Math.floor(m / 60), mm = m % 60;
        if (!hh) return mm + ' min';
        return mm ? hh + ' h ' + mm + ' min' : hh + ' h';
    }
    function fmtTime(t) { var p = t.split(':'); return Number(p[0]) + ':' + p[1]; }
    function fmtDateTime(iso) {
        var d = new Date(iso);
        return d.getDate() + '. ' + (d.getMonth() + 1) + '. ' + d.getFullYear() + ' ' + d.getHours() + ':' + pad(d.getMinutes());
    }
    function monthLong(d) { return d.toLocaleString('en-GB', { month: 'long' }); }
    function minutesBetween(start, end, brk) {
        var a = start.split(':').map(Number), b = end.split(':').map(Number);
        var m = (b[0] * 60 + b[1]) - (a[0] * 60 + a[1]);
        if (m <= 0) m += 1440;
        return Math.max(0, m - (Number(brk) || 0));
    }
    function baseAmount(minutes, rate) { return Math.round(minutes * Math.round(rate * 100) / 60) / 100; }

    /* date field: DD. MM. YYYY */
    function fmtDateField(iso) { var p = iso.split('-'); return p[2] + '. ' + p[1] + '. ' + p[0]; }
    function parseDateField(str) {
        var m = /^\s*(\d{1,2})\s*[.\/\-\s]\s*(\d{1,2})\s*[.\/\-\s]\s*(\d{4})\s*\.?\s*$/.exec(str);
        if (!m) return '';
        var d = Number(m[1]), mo = Number(m[2]), y = Number(m[3]);
        var dt = new Date(y, mo - 1, d);
        if (dt.getFullYear() !== y || dt.getMonth() !== mo - 1 || dt.getDate() !== d) return '';
        return y + '-' + pad(mo) + '-' + pad(d);
    }
    function maskDateField(input) {
        var digits = input.value.replace(/\D/g, '').slice(0, 8), out = digits.slice(0, 2);
        if (digits.length > 2) out += '. ' + digits.slice(2, 4);
        if (digits.length > 4) out += '. ' + digits.slice(4);
        input.value = out;
    }
    function normalizeDateField(input) {
        var iso = parseDateField(input.value);
        if (iso) input.value = fmtDateField(iso);
    }

    /* time field: 24-hour HH:MM */
    function parseTimeField(str) {
        var s = str.trim(), h, m;
        var c = /^(\d{1,2})[:.](\d{2})$/.exec(s);
        if (c) { h = Number(c[1]); m = Number(c[2]); }
        else if (/^\d{1,2}$/.test(s)) { h = Number(s); m = 0; }
        else if (/^\d{3,4}$/.test(s)) { h = Number(s.slice(0, -2)); m = Number(s.slice(-2)); }
        else return '';
        if (h > 23 || m > 59) return '';
        return pad(h) + ':' + pad(m);
    }
    function maskTimeField(input) {
        var digits = input.value.replace(/\D/g, '').slice(0, 4);
        input.value = digits.length > 2 ? digits.slice(0, 2) + ':' + digits.slice(2) : digits;
    }
    function normalizeTimeField(input) {
        var t = parseTimeField(input.value);
        if (t) input.value = t;
    }
    function bindMasks(dateIds, timeIds, onChange) {
        dateIds.forEach(function (id) {
            var n = $(id);
            n.addEventListener('input', function (e) {
                if ((e.inputType || '').indexOf('delete') !== 0) maskDateField(n);
                onChange(id);
            });
            n.addEventListener('blur', function () { normalizeDateField(n); onChange(id); });
        });
        timeIds.forEach(function (id) {
            var n = $(id);
            n.addEventListener('input', function (e) {
                if ((e.inputType || '').indexOf('delete') !== 0) maskTimeField(n);
                onChange(id);
            });
            n.addEventListener('blur', function () { normalizeTimeField(n); onChange(id); });
        });
    }

    /* calendar: single or multi select, Monday first */
    function Calendar(root, opts) {
        var cal = { month: new Date(opts.month.getFullYear(), opts.month.getMonth(), 1), selected: opts.selected || new Set() };
        var dows = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];
        cal.render = function () {
            root.textContent = '';
            var head = el('div', 'cal-head');
            var prev = el('button', 'icon-btn'); prev.type = 'button'; prev.setAttribute('aria-label', 'Previous month');
            prev.innerHTML = '<svg viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg>';
            var next = el('button', 'icon-btn'); next.type = 'button'; next.setAttribute('aria-label', 'Next month');
            next.innerHTML = '<svg viewBox="0 0 24 24"><path d="M9 5l7 7-7 7"/></svg>';
            prev.onclick = function () { cal.month = new Date(cal.month.getFullYear(), cal.month.getMonth() - 1, 1); cal.render(); };
            next.onclick = function () { cal.month = new Date(cal.month.getFullYear(), cal.month.getMonth() + 1, 1); cal.render(); };
            head.appendChild(prev);
            head.appendChild(el('b', '', cal.month.toLocaleString('en-GB', { month: 'long', year: 'numeric' })));
            head.appendChild(next);
            root.appendChild(head);
            var grid = el('div', 'cal-grid');
            dows.forEach(function (d) { grid.appendChild(el('span', 'cal-dow', d)); });
            var y = cal.month.getFullYear(), m = cal.month.getMonth();
            var lead = (new Date(y, m, 1).getDay() + 6) % 7, days = new Date(y, m + 1, 0).getDate(), today = todayStr();
            for (var i = 0; i < lead; i++) grid.appendChild(el('span', 'cal-day blank'));
            for (var d = 1; d <= days; d++) {
                var iso = y + '-' + pad(m + 1) + '-' + pad(d);
                var b = el('button', 'cal-day' + (iso === today ? ' today' : ''), String(d));
                b.type = 'button';
                b.dataset.date = iso;
                b.setAttribute('aria-pressed', String(cal.selected.has(iso)));
                b.onclick = (function (iso) { return function () { opts.onPick(iso, cal); }; })(iso);
                grid.appendChild(b);
            }
            root.appendChild(grid);
        };
        cal.render();
        return cal;
    }

    /* api */
    function api(method, path, body) {
        var opts = { method: method, credentials: 'same-origin', headers: {} };
        if (method !== 'GET') {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body === undefined ? {} : body);
        }
        return fetch('/api' + path, opts).then(function (res) {
            if (res.status === 401) { location.href = '/login'; throw new Error('signed out'); }
            return res.json().catch(function () { return {}; }).then(function (data) {
                if (!res.ok) { var err = new Error(data.error || 'Something went wrong.'); err.status = res.status; throw err; }
                return data;
            });
        });
    }

    /* month view */
    function load() {
        var token = ++loadToken, key = monthKey(view);
        return Promise.all([api('GET', '/shifts?month=' + key), api('GET', '/summary?month=' + key)]).then(function (r) {
            if (token !== loadToken) return;
            state.shifts = r[0].shifts;
            state.summary = r[1];
            $('loadWarn').hidden = true;
            render();
        }, function (e) {
            if (token !== loadToken || e.message === 'signed out') return;
            $('loadWarn').textContent = 'Couldn’t load your shifts. ' + e.message;
            $('loadWarn').hidden = false;
        });
    }

    /* shrink a figure until it fits its box (small screens, very large amounts) */
    function fit(node) {
        node.style.fontSize = '';
        var size = parseFloat(getComputedStyle(node).fontSize);
        while (node.scrollWidth > node.clientWidth + 1 && size > 14) {
            size -= 1;
            node.style.fontSize = size + 'px';
        }
    }
    function fitHero() { ['heroTotal', 'workedPay', 'plannedPay'].forEach(function (id) { fit($(id)); }); }

    function render() {
        $('monthName').textContent = view.toLocaleString('en-GB', { month: 'long', year: 'numeric' });
        $('heroLabel').textContent = monthLong(view) + ' payout';
        var s = state.summary;
        if (s) {
            var all = s.payout;
            $('heroTotal').textContent = money(all);
            $('workedPay').textContent = money(s.worked.pay);
            $('plannedPay').textContent = money(s.planned.pay);
            $('workedHrs').textContent = fmtMinutes(s.worked.minutes);
            $('plannedHrs').textContent = fmtMinutes(s.planned.minutes);
            $('bonusTotal').textContent = money(s.bonuses);
            $('barW').style.width = all ? (s.worked.pay / all * 100) + '%' : '0';
            $('barP').style.width = all ? (s.planned.pay / all * 100) + '%' : '0';
            $('heroBar').style.display = all ? 'flex' : 'none';
            fitHero();
        }

        var list = $('list'), today = todayStr();
        list.textContent = '';
        if (!state.shifts.length) {
            var empty = el('div', 'empty');
            empty.appendChild(el('strong', '', 'No shifts in ' + monthLong(view)));
            empty.appendChild(document.createTextNode('Add a worked or planned shift to see your payout.'));
            list.appendChild(empty);
            return;
        }
        state.shifts.forEach(function (sh) {
            var d = parseDate(sh.date), planned = sh.status === 'planned';
            var row = el('div', 'row ' + (planned ? 'planned' : 'worked'));
            row.setAttribute('role', 'button');
            row.tabIndex = 0;
            row.dataset.id = sh.id;

            var date = el('div', 'date');
            date.appendChild(el('span', 'dow', d.toLocaleString('en-GB', { weekday: 'short' })));
            date.appendChild(el('span', 'dn', String(d.getDate())));

            var info = el('div', 'info');
            var t = el('p', 't', sh.note || (planned ? 'Planned shift' : 'Shift'));
            if (planned) t.appendChild(el('span', 'tag planned', 'Planned'));
            info.appendChild(t);
            info.appendChild(el('p', 'm', fmtTime(sh.start) + ' – ' + fmtTime(sh.end) + ' · ' + fmtMinutes(sh.minutes)));

            var pay = el('div', 'pay');
            pay.appendChild(el('p', 'a', money(sh.total)));
            if (sh.bonus > 0) pay.appendChild(el('span', 'b', '+' + money(sh.bonus) + ' bonus'));
            if (planned && sh.date <= today) {
                pay.appendChild(document.createElement('br'));
                var mark = el('button', 'mark', 'Mark worked');
                mark.type = 'button';
                mark.dataset.act = 'done';
                mark.dataset.id = sh.id;
                pay.appendChild(mark);
            }
            row.appendChild(date); row.appendChild(info); row.appendChild(pay);
            list.appendChild(row);
        });
    }

    /* dialogs */
    function openOverlay(id, focusId) {
        lastFocus = document.activeElement;
        $(id).hidden = false;
        // On touch devices focusing a field opens the keyboard, so focus the dialog itself.
        var touch = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
        var f = touch ? $(id).querySelector('.sheet') : $(focusId);
        if (f) { f.tabIndex = -1; f.focus({ preventScroll: true }); }
    }
    function closeOverlay(id) {
        $(id).hidden = true;
        if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) {} }
    }
    function openOverlayId() {
        for (var i = 0; i < OVERLAYS.length; i++) if (!$(OVERLAYS[i]).hidden) return OVERLAYS[i];
        return null;
    }
    function trapTab(e, overlay) {
        var nodes = Array.prototype.filter.call(
            overlay.querySelectorAll('a[href], button, input, textarea, summary'),
            function (n) { return !n.disabled && !n.hidden && n.offsetParent !== null; });
        if (!nodes.length) return;
        var first = nodes[0], last = nodes[nodes.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }

    /* shift sheet */
    var dateCal = null;
    function setStatus(v) {
        status = v;
        $('stWorked').setAttribute('aria-pressed', String(v === 'worked'));
        $('stPlanned').setAttribute('aria-pressed', String(v === 'planned'));
    }
    function currentForm() {
        return {
            date: parseDateField($('fDate').value), start: parseTimeField($('fStart').value), end: parseTimeField($('fEnd').value),
            brk: parseFloat($('fBreak').value) || 0,
            rate: parseFloat($('fRate').value),
            bonus: parseFloat($('fBonus').value) || 0,
            note: $('fNote').value.trim(), status: status
        };
    }
    function updatePreview() {
        var f = currentForm(), p = $('preview');
        p.textContent = '';
        if (!f.start || !f.end || isNaN(f.rate)) { p.textContent = 'Enter times and a rate to see the pay.'; return; }
        var m = minutesBetween(f.start, f.end, f.brk), base = baseAmount(m, f.rate);
        p.appendChild(document.createTextNode(fmtMinutes(m) + ' × ' + money(f.rate) + ' = ' + money(base) +
            (f.bonus ? ' + ' + money(f.bonus) + ' bonus' : '')));
        p.appendChild(document.createElement('br'));
        p.appendChild(el('b', '', money(base + f.bonus)));
    }
    function hideDateCal() { $('fDateCal').hidden = true; }
    function toggleDateCal() {
        var pop = $('fDateCal');
        if (!pop.hidden) { hideDateCal(); return; }
        var cur = parseDateField($('fDate').value) || todayStr();
        dateCal = Calendar(pop, {
            month: parseDate(cur), selected: new Set([cur]),
            onPick: function (iso) {
                $('fDate').value = fmtDateField(iso);
                hideDateCal();
                afterDateChange();
                $('fDate').focus();
            }
        });
        pop.hidden = false;
    }
    function afterDateChange() {
        var d = parseDateField($('fDate').value);
        if (!editingId && !statusTouched && d) setStatus(d > todayStr() ? 'planned' : 'worked');
        updatePreview();
    }
    function openShift(id) {
        editingId = id || null;
        deleteArmed = false;
        hideDateCal();
        $('shiftErr').textContent = '';
        $('shiftDelete').textContent = 'Delete shift';
        var s = id ? state.shifts.filter(function (x) { return String(x.id) === String(id); })[0] : null;
        $('shiftTitle').textContent = s ? 'Edit shift' : 'Add shift';
        $('shiftDelete').hidden = !s;
        $('toBulk').hidden = !!s;
        if (s) {
            $('fDate').value = fmtDateField(s.date); $('fStart').value = s.start; $('fEnd').value = s.end;
            $('fBreak').value = s.break; $('fRate').value = s.rate; $('fBonus').value = s.bonus || '';
            $('fNote').value = s.note || ''; setStatus(s.status); statusTouched = true;
        } else {
            var t = todayStr(), key = monthKey(view);
            var d = t.indexOf(key) === 0 ? t : key + '-01';
            $('fDate').value = fmtDateField(d); $('fStart').value = '09:00'; $('fEnd').value = '17:00';
            $('fBreak').value = state.brk; $('fRate').value = state.wage || ''; $('fBonus').value = ''; $('fNote').value = '';
            statusTouched = false; setStatus(d > t ? 'planned' : 'worked');
        }
        updatePreview();
        openOverlay('shiftOverlay', 'fDate');
    }
    function closeShift() { hideDateCal(); closeOverlay('shiftOverlay'); editingId = null; }
    function saveShift() {
        var f = currentForm(), err = '';
        if (!f.date) err = 'Enter the date as DD. MM. YYYY.';
        else if (!f.start || !f.end) err = 'Enter a start and end time, for example 09:00.';
        else if (isNaN(f.rate) || f.rate < 0) err = 'Enter an hourly rate.';
        else if (minutesBetween(f.start, f.end, f.brk) <= 0) err = 'The break is longer than the shift.';
        if (err) { $('shiftErr').textContent = err; return; }
        var body = { date: f.date, start: f.start, end: f.end, break: f.brk, rate: f.rate, bonus: f.bonus, note: f.note, status: f.status };
        var req = editingId ? api('PUT', '/shifts/' + editingId, body) : api('POST', '/shifts', body);
        req.then(function () {
            var d = parseDate(f.date); view = new Date(d.getFullYear(), d.getMonth(), 1);
            closeShift();
            return load();
        }, function (e) { $('shiftErr').textContent = e.message; });
    }
    function deleteShift() {
        if (!deleteArmed) { deleteArmed = true; $('shiftDelete').textContent = 'Tap again to delete'; return; }
        api('DELETE', '/shifts/' + editingId).then(function () { closeShift(); return load(); },
            function (e) { $('shiftErr').textContent = e.message; });
    }

    /* bulk sheet */
    var bulkCal = null, bulkSel = new Set();
    function setBulkStatus(v) {
        bulkStatus = v;
        $('bWorked').setAttribute('aria-pressed', String(v === 'worked'));
        $('bPlanned').setAttribute('aria-pressed', String(v === 'planned'));
        $('bStatusHint').hidden = v !== null;
    }
    function bulkForm() {
        return {
            start: parseTimeField($('bStart').value), end: parseTimeField($('bEnd').value),
            brk: parseFloat($('bBreak').value) || 0, rate: parseFloat($('bRate').value),
            bonus: parseFloat($('bBonus').value) || 0, note: $('bNote').value.trim()
        };
    }
    function bulkDates() { return Array.from(bulkSel).sort(); }
    function updateBulkPreview() {
        var f = bulkForm(), p = $('bPreview'), n = bulkSel.size;
        p.textContent = '';
        $('bSave').textContent = n ? 'Add ' + n + ' shift' + (n === 1 ? '' : 's') : 'Add shifts';
        if (!n) { p.textContent = 'Tap the days you worked or will work.'; return; }
        if (!f.start || !f.end || isNaN(f.rate)) { p.textContent = n + ' day' + (n === 1 ? '' : 's') + ' chosen. Enter times and a rate to see the pay.'; return; }
        var m = minutesBetween(f.start, f.end, f.brk), each = baseAmount(m, f.rate) + f.bonus;
        p.appendChild(document.createTextNode(n + ' × ' + fmtMinutes(m) + ' · ' + money(each) + ' each'));
        p.appendChild(document.createElement('br'));
        p.appendChild(el('b', '', money(Math.round(each * 100) * n / 100)));
    }
    function openBulk() {
        var t = todayStr(), key = monthKey(view);
        var shown = t.indexOf(key) === 0 ? parseDate(t) : view;
        bulkSel = new Set();
        bulkCal = Calendar($('bCal'), {
            month: shown, selected: bulkSel,
            onPick: function (iso, cal) {
                if (bulkSel.has(iso)) bulkSel.delete(iso); else bulkSel.add(iso);
                cal.render();
                updateBulkPreview();
            }
        });
        $('bStart').value = '09:00'; $('bEnd').value = '17:00';
        $('bBreak').value = state.brk; $('bRate').value = state.wage || ''; $('bBonus').value = ''; $('bNote').value = '';
        $('bErr').textContent = '';
        setBulkStatus(null);
        updateBulkPreview();
        openOverlay('bulkOverlay', 'bStart');
    }
    function saveBulk() {
        var f = bulkForm(), err = '';
        if (!bulkSel.size) err = 'Choose at least one day.';
        else if (!f.start || !f.end) err = 'Enter a start and end time, for example 09:00.';
        else if (isNaN(f.rate) || f.rate < 0) err = 'Enter an hourly rate.';
        else if (minutesBetween(f.start, f.end, f.brk) <= 0) err = 'The break is longer than the shift.';
        if (err) { $('bErr').textContent = err; return; }
        var t = todayStr();
        var shifts = bulkDates().map(function (d) {
            return {
                date: d, start: f.start, end: f.end, break: f.brk, rate: f.rate, bonus: f.bonus, note: f.note,
                status: bulkStatus || (d > t ? 'planned' : 'worked')
            };
        });
        api('POST', '/shifts/bulk', { shifts: shifts }).then(function () {
            var first = parseDate(shifts[0].date);
            view = new Date(first.getFullYear(), first.getMonth(), 1);
            closeOverlay('bulkOverlay');
            return load();
        }, function (e) { $('bErr').textContent = e.message; });
    }
    function pickWeekdays() {
        if (!bulkCal) return;
        var y = bulkCal.month.getFullYear(), m = bulkCal.month.getMonth(), days = new Date(y, m + 1, 0).getDate();
        for (var d = 1; d <= days; d++) {
            var wd = new Date(y, m, d).getDay();
            if (wd !== 0 && wd !== 6) bulkSel.add(y + '-' + pad(m + 1) + '-' + pad(d));
        }
        bulkCal.render();
        updateBulkPreview();
    }

    /* settings sheet */
    function openSettings(firstRun) {
        $('sWage').value = state.wage || '';
        $('sSymbol').value = state.symbol;
        $('sBreak').value = state.brk;
        $('sApply').checked = false;
        $('setErr').textContent = ''; $('dataMsg').textContent = ''; $('pwErr').textContent = '';
        $('pwCur').value = ''; $('pwNew').value = ''; $('pwNew2').value = '';
        $('setTitle').textContent = firstRun ? 'Set your hourly wage' : 'Settings';
        openOverlay('setOverlay', 'sWage');
        loadDevices();
    }
    function applySettings(s) { state.wage = s.wage; state.symbol = s.symbol || 'Kč'; state.brk = s.break; }
    function saveSettings() {
        var w = parseFloat($('sWage').value), b = parseFloat($('sBreak').value);
        if (isNaN(w) || w <= 0) { $('setErr').textContent = 'Enter your hourly wage.'; return; }
        if ($('sBreak').value === '') b = 0;
        if (isNaN(b) || b < 0 || b > 1440 || Math.floor(b) !== b) { $('setErr').textContent = 'Enter the default break in whole minutes.'; return; }
        api('PUT', '/settings', { wage: w, symbol: $('sSymbol').value.trim() || 'Kč', break: b, applyToPlanned: $('sApply').checked })
            .then(function (s) {
                applySettings(s);
                closeOverlay('setOverlay');
                return load();
            }, function (e) { $('setErr').textContent = e.message; });
    }
    function loadDevices() {
        api('GET', '/devices').then(function (r) {
            var ul = $('devices'); ul.textContent = '';
            r.devices.forEach(function (d) {
                var li = el('li');
                var info = el('div', 'dl');
                var name = el('p', '', d.label);
                if (d.current) name.appendChild(el('span', 'tag here', 'This device'));
                info.appendChild(name);
                info.appendChild(el('p', 's', 'Last seen ' + fmtDateTime(d.lastSeen)));
                var b = el('button', 'btn', 'Sign out');
                b.type = 'button';
                b.setAttribute('aria-label', 'Sign out ' + d.label);
                b.addEventListener('click', function () {
                    api('DELETE', '/devices/' + d.id).then(function () {
                        if (d.current) location.href = '/login'; else loadDevices();
                    }, function (e) { $('pwErr').textContent = e.message; });
                });
                li.appendChild(info); li.appendChild(b);
                ul.appendChild(li);
            });
        }, function () {});
    }
    function changePassword() {
        var cur = $('pwCur').value, n = $('pwNew').value, n2 = $('pwNew2').value, err = $('pwErr');
        err.classList.remove('success');
        if (!cur) { err.textContent = 'Enter your current password.'; return; }
        if (n.length < 10) { err.textContent = 'Use at least 10 characters for the new password.'; return; }
        if (n !== n2) { err.textContent = 'The two new passwords don’t match.'; return; }
        api('POST', '/password', { current: cur, new: n }).then(function () {
            $('pwCur').value = ''; $('pwNew').value = ''; $('pwNew2').value = '';
            err.classList.add('success');
            err.textContent = 'Password changed. Other devices were signed out.';
            loadDevices();
        }, function (e) { err.textContent = e.message; });
    }
    function importBackup(file) {
        var msg = $('dataMsg');
        var reader = new FileReader();
        reader.onload = function () {
            var data;
            try { data = JSON.parse(reader.result); } catch (e) { data = null; }
            if (!data || !Array.isArray(data.shifts)) { msg.textContent = 'That doesn’t look like a backup.'; return; }
            var n = data.shifts.length;
            if (!confirm('Replace all existing shifts with the ' + n + ' shift' + (n === 1 ? '' : 's') + ' from this backup?')) return;
            api('POST', '/import', data).then(function (r) {
                applySettings(r.settings);
                msg.textContent = 'Imported ' + r.imported + ' shift' + (r.imported === 1 ? '' : 's') + '.';
                return load();
            }, function (e) { msg.textContent = e.message; });
        };
        reader.readAsText(file);
    }

    /* wiring */
    function shiftMonth(delta) { view = new Date(view.getFullYear(), view.getMonth() + delta, 1); load(); }
    $('prev').onclick = function () { shiftMonth(-1); };
    $('next').onclick = function () { shiftMonth(1); };
    $('addBtn').onclick = $('addBtnDesk').onclick = function () { openShift(null); };
    $('bulkBtnDesk').onclick = openBulk;
    $('toBulk').onclick = function () { closeShift(); openBulk(); };
    $('settingsBtn').onclick = function () { openSettings(false); };
    $('shiftCancel').onclick = closeShift;
    $('shiftSave').onclick = saveShift;
    $('shiftDelete').onclick = deleteShift;
    $('fDateBtn').onclick = toggleDateCal;
    $('bCancel').onclick = function () { closeOverlay('bulkOverlay'); };
    $('bSave').onclick = saveBulk;
    $('bWeekdays').onclick = pickWeekdays;
    $('bClear').onclick = function () { bulkSel.clear(); if (bulkCal) bulkCal.render(); updateBulkPreview(); };
    $('bWorked').onclick = function () { setBulkStatus('worked'); };
    $('bPlanned').onclick = function () { setBulkStatus('planned'); };
    $('setCancel').onclick = function () { closeOverlay('setOverlay'); };
    $('setSave').onclick = saveSettings;
    $('pwSave').onclick = changePassword;
    $('logoutBtn').onclick = function () {
        api('POST', '/logout').then(function () { location.href = '/login'; }, function () { location.href = '/login'; });
    };
    $('importBtn').onclick = function () { $('importFile').click(); };
    $('importFile').onchange = function () {
        if (this.files[0]) importBackup(this.files[0]);
        this.value = '';
    };
    $('stWorked').onclick = function () { statusTouched = true; setStatus('worked'); };
    $('stPlanned').onclick = function () { statusTouched = true; setStatus('planned'); };

    bindMasks(['fDate'], ['fStart', 'fEnd'], function (id) { if (id === 'fDate') afterDateChange(); else updatePreview(); });
    bindMasks([], ['bStart', 'bEnd'], updateBulkPreview);
    ['fBreak', 'fRate', 'fBonus'].forEach(function (id) { $(id).addEventListener('input', updatePreview); });
    ['bBreak', 'bRate', 'bBonus'].forEach(function (id) { $(id).addEventListener('input', updateBulkPreview); });

    $('shiftOverlay').addEventListener('click', function (e) {
        if (e.target === this) closeShift();
        else if (!e.target.closest('.cal-wrap')) hideDateCal();
    });
    $('bulkOverlay').addEventListener('click', function (e) { if (e.target === this) closeOverlay('bulkOverlay'); });
    $('setOverlay').addEventListener('click', function (e) { if (e.target === this) closeOverlay('setOverlay'); });
    $('list').addEventListener('click', function (e) {
        var done = e.target.closest('[data-act="done"]');
        if (done) {
            e.stopPropagation();
            api('POST', '/shifts/' + done.dataset.id + '/worked').then(load, function (er) {
                $('loadWarn').textContent = er.message; $('loadWarn').hidden = false;
            });
            return;
        }
        var row = e.target.closest('.row');
        if (row) openShift(row.dataset.id);
    });
    $('list').addEventListener('keydown', function (e) {
        if ((e.key === 'Enter' || e.key === ' ') && e.target.classList.contains('row')) {
            e.preventDefault(); openShift(e.target.dataset.id);
        }
    });
    document.addEventListener('keydown', function (e) {
        var open = openOverlayId();
        if (e.key === 'Escape' && open) {
            e.preventDefault();
            if (!$('fDateCal').hidden) { hideDateCal(); $('fDate').focus(); return; }
            if (open === 'shiftOverlay') closeShift(); else closeOverlay(open);
            return;
        }
        if (e.key === 'Tab' && open) { trapTab(e, $(open)); return; }
        if (e.key === 'Enter' && e.target.tagName === 'INPUT' && e.target.type !== 'checkbox' && e.target.type !== 'file') {
            if (open === 'shiftOverlay') { e.preventDefault(); saveShift(); return; }
            if (open === 'bulkOverlay') { e.preventDefault(); saveBulk(); return; }
        }
        if (open || e.ctrlKey || e.metaKey || e.altKey) return;
        var tag = e.target.tagName;
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
        if (e.key === 'n' || e.key === 'N') { e.preventDefault(); openShift(null); }
        else if (e.key === 'ArrowLeft') shiftMonth(-1);
        else if (e.key === 'ArrowRight') shiftMonth(1);
    });
    window.addEventListener('resize', fitHero);

    /* swipe left or right on the month view to change month */
    var swipe = null;
    document.addEventListener('touchstart', function (e) {
        swipe = e.touches.length === 1 && !openOverlayId() ? { x: e.touches[0].clientX, y: e.touches[0].clientY } : null;
    }, { passive: true });
    document.addEventListener('touchend', function (e) {
        if (!swipe || openOverlayId()) return;
        var t = e.changedTouches[0], dx = t.clientX - swipe.x, dy = t.clientY - swipe.y;
        swipe = null;
        if (Math.abs(dx) < 60 || Math.abs(dx) < Math.abs(dy) * 1.5) return;
        shiftMonth(dx < 0 ? 1 : -1);
    }, { passive: true });
    document.addEventListener('touchcancel', function () { swipe = null; }, { passive: true });

    api('GET', '/me').then(function (me) {
        applySettings(me.settings);
        return load().then(function () { if (!state.wage) openSettings(true); });
    }, function () {});
})();
