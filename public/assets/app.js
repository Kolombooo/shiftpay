(function () {
    'use strict';

    var state = { wage: 0, symbol: 'Kč', shifts: [], summary: null };
    var view = new Date(); view.setDate(1); view.setHours(0, 0, 0, 0);
    var editingId = null, statusTouched = false, status = 'worked', deleteArmed = false;
    var loadToken = 0, lastFocus = null;

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
    function minutesOf(f) {
        var a = f.start.split(':').map(Number), b = f.end.split(':').map(Number);
        var m = (b[0] * 60 + b[1]) - (a[0] * 60 + a[1]);
        if (m <= 0) m += 1440;
        return Math.max(0, m - (Number(f.brk) || 0));
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
        var f = $(focusId);
        if (f) f.focus();
    }
    function closeOverlay(id) {
        $(id).hidden = true;
        if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) {} }
    }
    function openOverlayId() {
        if (!$('shiftOverlay').hidden) return 'shiftOverlay';
        if (!$('setOverlay').hidden) return 'setOverlay';
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
    function setStatus(v) {
        status = v;
        $('stWorked').setAttribute('aria-pressed', String(v === 'worked'));
        $('stPlanned').setAttribute('aria-pressed', String(v === 'planned'));
    }
    function currentForm() {
        return {
            date: $('fDate').value, start: $('fStart').value, end: $('fEnd').value,
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
        var m = minutesOf(f), base = Math.round(m * Math.round(f.rate * 100) / 60) / 100;
        p.appendChild(document.createTextNode(fmtMinutes(m) + ' × ' + money(f.rate) + ' = ' + money(base) +
            (f.bonus ? ' + ' + money(f.bonus) + ' bonus' : '')));
        p.appendChild(document.createElement('br'));
        p.appendChild(el('b', '', money(base + f.bonus)));
    }
    function openShift(id) {
        editingId = id || null;
        deleteArmed = false;
        $('shiftErr').textContent = '';
        $('shiftDelete').textContent = 'Delete shift';
        var s = id ? state.shifts.filter(function (x) { return String(x.id) === String(id); })[0] : null;
        $('shiftTitle').textContent = s ? 'Edit shift' : 'Add shift';
        $('shiftDelete').hidden = !s;
        if (s) {
            $('fDate').value = s.date; $('fStart').value = s.start; $('fEnd').value = s.end;
            $('fBreak').value = s.break; $('fRate').value = s.rate; $('fBonus').value = s.bonus || '';
            $('fNote').value = s.note || ''; setStatus(s.status); statusTouched = true;
        } else {
            var t = todayStr(), key = monthKey(view);
            var d = t.indexOf(key) === 0 ? t : key + '-01';
            $('fDate').value = d; $('fStart').value = '09:00'; $('fEnd').value = '17:00';
            $('fBreak').value = 30; $('fRate').value = state.wage || ''; $('fBonus').value = ''; $('fNote').value = '';
            statusTouched = false; setStatus(d > t ? 'planned' : 'worked');
        }
        updatePreview();
        openOverlay('shiftOverlay', 'fDate');
    }
    function closeShift() { closeOverlay('shiftOverlay'); editingId = null; }
    function saveShift() {
        var f = currentForm(), err = '';
        if (!f.date) err = 'Choose a date.';
        else if (!f.start || !f.end) err = 'Enter a start and end time.';
        else if (isNaN(f.rate) || f.rate < 0) err = 'Enter an hourly rate.';
        else if (minutesOf(f) <= 0) err = 'The break is longer than the shift.';
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

    /* settings sheet */
    function openSettings(firstRun) {
        $('sWage').value = state.wage || '';
        $('sSymbol').value = state.symbol;
        $('sApply').checked = false;
        $('setErr').textContent = ''; $('dataMsg').textContent = ''; $('pwErr').textContent = '';
        $('pwCur').value = ''; $('pwNew').value = ''; $('pwNew2').value = '';
        $('setTitle').textContent = firstRun ? 'Set your hourly wage' : 'Settings';
        openOverlay('setOverlay', 'sWage');
        loadDevices();
    }
    function saveSettings() {
        var w = parseFloat($('sWage').value);
        if (isNaN(w) || w <= 0) { $('setErr').textContent = 'Enter your hourly wage.'; return; }
        api('PUT', '/settings', { wage: w, symbol: $('sSymbol').value.trim() || 'Kč', applyToPlanned: $('sApply').checked })
            .then(function (s) {
                state.wage = s.wage; state.symbol = s.symbol;
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
                state.wage = r.settings.wage; state.symbol = r.settings.symbol;
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
    $('settingsBtn').onclick = function () { openSettings(false); };
    $('shiftCancel').onclick = closeShift;
    $('shiftSave').onclick = saveShift;
    $('shiftDelete').onclick = deleteShift;
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
    ['fDate', 'fStart', 'fEnd', 'fBreak', 'fRate', 'fBonus'].forEach(function (id) {
        $(id).addEventListener('input', function () {
            if (id === 'fDate' && !editingId && !statusTouched && $('fDate').value) {
                setStatus($('fDate').value > todayStr() ? 'planned' : 'worked');
            }
            updatePreview();
        });
    });
    $('shiftOverlay').addEventListener('click', function (e) { if (e.target === this) closeShift(); });
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
            if (open === 'shiftOverlay') closeShift(); else closeOverlay('setOverlay');
            return;
        }
        if (e.key === 'Tab' && open) { trapTab(e, $(open)); return; }
        if (e.key === 'Enter' && open === 'shiftOverlay' && e.target.tagName === 'INPUT') {
            e.preventDefault(); saveShift(); return;
        }
        if (open || e.ctrlKey || e.metaKey || e.altKey) return;
        var tag = e.target.tagName;
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
        if (e.key === 'n' || e.key === 'N') { e.preventDefault(); openShift(null); }
        else if (e.key === 'ArrowLeft') shiftMonth(-1);
        else if (e.key === 'ArrowRight') shiftMonth(1);
    });

    api('GET', '/me').then(function (me) {
        state.wage = me.settings.wage;
        state.symbol = me.settings.symbol || 'Kč';
        return load().then(function () { if (!state.wage) openSettings(true); });
    }, function () {});
})();
