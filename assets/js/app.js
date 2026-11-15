/*!
 * NextaCRM front-end — Alpine.js components.
 * No inline scripts are used anywhere (strict CSP); all behaviour lives here.
 */
(function () {
  'use strict';

  // ───────────────────────── Utilities ─────────────────────────
  var meta = function (n) { var m = document.querySelector('meta[name="' + n + '"]'); return m ? m.getAttribute('content') : ''; };
  var base = function () { return (meta('base-url') || '/').replace(/\/$/, ''); };
  var store = {
    get: function (k, d) { try { var v = localStorage.getItem(k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } },
    set: function (k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* storage unavailable */ } }
  };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var dataJson = function (el, attr, d) { try { var v = el.getAttribute(attr); return v ? JSON.parse(v) : d; } catch (e) { return d; } };

  var NX = window.NX = {
    url: function (p) { return base() + '/' + String(p || '').replace(/^\//, ''); },
    esc: esc,
    store: store,
    toast: function (message, type) { if (window.Alpine) Alpine.store('nx').toast(message, type || 'success'); },
    confirm: function (opts) { return Alpine.store('nx').ask(typeof opts === 'string' ? { message: opts } : opts); },

    /** fetch wrapper: JSON in/out, CSRF header, friendly errors. Throws {message, fields, status}. */
    api: function (url, opts) {
      opts = opts || {};
      var headers = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': meta('csrf-token') };
      var body = opts.body;
      if (body && !(body instanceof FormData) && typeof body !== 'string') { headers['Content-Type'] = 'application/json'; body = JSON.stringify(body); }
      return fetch(url, { method: opts.method || (body ? 'POST' : 'GET'), headers: headers, body: body, credentials: 'same-origin' })
        .then(function (res) {
          return res.text().then(function (t) {
            var data = {};
            try { data = t ? JSON.parse(t) : {}; } catch (e) { data = { error: { message: res.ok ? '' : 'Unexpected response from the server (' + res.status + ').' } }; }
            if (res.status === 401) { window.location.href = NX.url('/login'); throw { message: 'Session expired', status: 401 }; }
            if (!res.ok) { var err = data.error || {}; throw { message: err.message || 'Request failed', fields: err.fields || {}, status: res.status }; }
            return data;
          });
        });
    },

    /** Standard handling of a JSON action response. */
    handle: function (data) {
      if (!data) return;
      if (data.message) NX.toast(data.message, data.ok === false ? 'error' : 'success');
      if (data.warnings && data.warnings.length) data.warnings.forEach(function (w) { NX.toast(w, 'warning'); });
      if (data.redirect) { setTimeout(function () { window.location.href = data.redirect; }, data.message ? 350 : 0); return; }
      if (data.reload) setTimeout(function () { window.location.reload(); }, 400);
    },
    fail: function (e) { NX.toast((e && e.message) || 'Something went wrong', 'error'); },

    showErrors: function (form, fields) {
      form.querySelectorAll('[data-error-for]').forEach(function (el) { el.textContent = ''; el.hidden = true; });
      form.querySelectorAll('.has-error').forEach(function (el) { el.classList.remove('has-error'); });
      var first = null;
      Object.keys(fields || {}).forEach(function (name) {
        var holder = form.querySelector('[data-error-for="' + name + '"]');
        if (holder) { holder.textContent = fields[name]; holder.hidden = false; var wrap = holder.closest('[data-field]'); if (wrap) wrap.classList.add('has-error'); first = first || holder; }
      });
      if (first) first.scrollIntoView({ block: 'center', behavior: 'smooth' });
    },

    formObject: function (form) {
      var out = {};
      new FormData(form).forEach(function (v, k) {
        if (k.slice(-2) === '[]') { k = k.slice(0, -2); (out[k] = out[k] || []).push(v); }
        else out[k] = v;
      });
      return out;
    },

    timeText: function (sec) { sec = Math.max(0, Math.floor(sec)); var h = Math.floor(sec / 3600), m = Math.floor(sec % 3600 / 60), s = sec % 60; return (h ? h + ':' : '') + String(m).padStart(h ? 2 : 1, '0') + ':' + String(s).padStart(2, '0'); }
  };

  // ───────────────────────── Global delegation (no inline handlers) ─────────────────────────
  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (!(form instanceof HTMLFormElement)) return;
    var question = form.getAttribute('data-confirm');
    var ajax = form.hasAttribute('data-ajax');
    if (!question && !ajax) return;
    if (form.__confirmed) { form.__confirmed = false; if (!ajax) return; }
    ev.preventDefault();
    var run = function () {
      if (!ajax) { form.__confirmed = true; form.requestSubmit ? form.requestSubmit() : form.submit(); return; }
      var btn = form.querySelector('[type=submit]');
      if (btn) btn.disabled = true;
      NX.api(form.getAttribute('action'), { method: (form.getAttribute('method') || 'POST').toUpperCase() === 'GET' ? 'GET' : 'POST', body: new FormData(form) })
        .then(function (d) { NX.showErrors(form, {}); NX.handle(d); form.dispatchEvent(new CustomEvent('ajax:done', { detail: d, bubbles: true })); if (form.hasAttribute('data-reset')) form.reset(); })
        .catch(function (e) { NX.showErrors(form, e.fields); NX.fail(e); })
        .finally(function () { if (btn) btn.disabled = false; });
    };
    if (question) NX.confirm({ message: question, danger: form.hasAttribute('data-danger') }).then(function (ok) { if (ok) run(); });
    else run();
  });

  // Buttons: <button data-action="/url" data-method="DELETE" data-confirm="…" data-body='{"a":1}'>
  document.addEventListener('click', function (ev) {
    var el = ev.target.closest && ev.target.closest('[data-action]');
    if (!el) return;
    ev.preventDefault();
    var go = function () {
      el.setAttribute('aria-disabled', 'true');
      NX.api(el.getAttribute('data-action'), { method: el.getAttribute('data-method') || 'POST', body: dataJson(el, 'data-body', {}) })
        .then(NX.handle).catch(NX.fail).finally(function () { el.removeAttribute('aria-disabled'); });
    };
    var q = el.getAttribute('data-confirm');
    if (q) NX.confirm({ message: q, danger: el.hasAttribute('data-danger'), ok: el.getAttribute('data-ok') || 'Confirm' }).then(function (ok) { if (ok) go(); });
    else go();
  });

  // Copy-to-clipboard buttons
  document.addEventListener('click', function (ev) {
    var el = ev.target.closest && ev.target.closest('[data-copy]');
    if (!el) return;
    var text = el.getAttribute('data-copy');
    var target = text && text.charAt(0) === '#' ? document.querySelector(text) : null;
    if (target) text = target.value || target.textContent;
    (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(function () { NX.toast('Copied to clipboard'); }).catch(function () {
      if (target && target.select) { target.select(); document.execCommand('copy'); NX.toast('Copied'); }
    });
  });

  // Rows/cards that navigate: <tr data-href="…">
  document.addEventListener('click', function (ev) {
    var row = ev.target.closest && ev.target.closest('[data-href]');
    if (!row || ev.target.closest('a,button,input,select,textarea,label,[data-no-nav]')) return;
    if (ev.metaKey || ev.ctrlKey) window.open(row.getAttribute('data-href'), '_blank');
    else window.location.href = row.getAttribute('data-href');
  });

  // Auto-submit selects inside GET filter forms
  document.addEventListener('change', function (ev) {
    var el = ev.target;
    if (el.matches && el.matches('[data-autosubmit]') && el.form) el.form.requestSubmit ? el.form.requestSubmit() : el.form.submit();
  });

  // ───────────────────────── Alpine components ─────────────────────────
  document.addEventListener('alpine:init', function () {
    var A = window.Alpine;

    A.store('nx', {
      toasts: [], unread: 0, seq: 0,
      confirm: { open: false, title: '', message: '', ok: 'Confirm', danger: false, resolve: null },
      toast: function (message, type) {
        var id = ++this.seq, self = this;
        this.toasts.push({ id: id, message: message, type: type || 'success' });
        if (this.toasts.length > 4) this.toasts.shift();
        setTimeout(function () { self.dismiss(id); }, type === 'error' ? 7000 : (type === 'warning' ? 6000 : 3500));
      },
      dismiss: function (id) { this.toasts = this.toasts.filter(function (t) { return t.id !== id; }); },
      ask: function (o) {
        var self = this;
        return new Promise(function (resolve) {
          self.confirm = { open: true, title: o.title || (o.danger ? 'Are you sure?' : 'Please confirm'), message: o.message || '', ok: o.ok || (o.danger ? 'Delete' : 'Confirm'), danger: !!o.danger, resolve: resolve };
        });
      },
      answer: function (v) { var r = this.confirm.resolve; this.confirm.open = false; this.confirm.resolve = null; if (r) r(v); }
    });

    // App shell: sidebar, theme, hotkeys, timer, notification count
    A.data('shell', function () {
      return {
        collapsed: false, mobileNav: false, theme: 'system', timer: null, timerText: '', gPending: false,
        init: function () {
          var self = this;
          this.collapsed = this.$el.getAttribute('data-collapsed') === '1';
          this.theme = document.documentElement.getAttribute('data-theme') || 'system';
          dataJson(this.$el, 'data-flash', []).forEach(function (f) { A.store('nx').toast(f.message, f.type === 'success' ? 'success' : (f.type === 'warning' ? 'warning' : (f.type === 'info' ? 'info' : 'error'))); });
          this.refreshCount();
          setInterval(function () { if (!document.hidden) self.refreshCount(); }, 60000);
          this.loadTimer();
          setInterval(function () { self.tick(); }, 1000);
          window.addEventListener('timer-changed', function (e) { self.timer = e.detail; self.tick(); });
          if (window.matchMedia) window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () { if (self.theme === 'system') self.applyTheme(); });
        },
        refreshCount: function () { NX.api(NX.url('/x/notifications')).then(function (d) { A.store('nx').unread = d.unread; }).catch(function () {}); },
        loadTimer: function () { var self = this; NX.api(NX.url('/x/timer')).then(function (d) { self.timer = d.timer; self.tick(); }).catch(function () {}); },
        tick: function () { if (this.timer) this.timerText = NX.timeText(this.timer.base + (Date.now() / 1000 - this.timer.started)); },
        stopTimer: function () { var self = this; NX.api(NX.url('/x/timer/stop'), { method: 'POST' }).then(function (d) { self.timer = null; NX.handle(d); window.dispatchEvent(new CustomEvent('timer-stopped')); }).catch(NX.fail); },
        toggleSidebar: function () { this.collapsed = !this.collapsed; NX.api(NX.url('/x/preferences'), { body: { sidebar: this.collapsed ? 1 : 0 } }).catch(function () {}); },
        setTheme: function (t) { this.theme = t; document.documentElement.setAttribute('data-theme', t); this.applyTheme(); NX.api(NX.url('/x/preferences'), { body: { theme: t } }).catch(function () {}); },
        applyTheme: function () { var dark = this.theme === 'dark' || (this.theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches); document.documentElement.classList.toggle('dark', dark); window.dispatchEvent(new CustomEvent('theme-changed')); },
        get unread() { return A.store('nx').unread; },
        hotkey: function (e) {
          var t = e.target, tag = (t.tagName || '').toLowerCase();
          if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); this.$dispatch('open-palette'); return; }
          if (tag === 'input' || tag === 'textarea' || tag === 'select' || t.isContentEditable || e.ctrlKey || e.metaKey || e.altKey) return;
          // Ignore single-key shortcuts while a dialog, drawer or the palette is open.
          var overlayOpen = Array.prototype.some.call(document.querySelectorAll('.modal, .drawer'), function (el) { return getComputedStyle(el).display !== 'none'; });
          if (overlayOpen) return;
          var self = this;
          if (this.gPending) {
            this.gPending = false;
            var map = { d: '/', l: '/r/leads', p: '/r/deals', t: '/r/tasks', c: '/calendar', i: '/r/invoices', k: '/r/tickets', r: '/reports', s: '/settings', u: '/r/customers' };
            if (map[e.key]) { e.preventDefault(); window.location.href = NX.url(map[e.key]); }
            return;
          }
          if (e.key === 'g') { this.gPending = true; setTimeout(function () { self.gPending = false; }, 1200); return; }
          if (e.key === '/') { e.preventDefault(); this.$dispatch('open-palette'); return; }
          if (e.key === '?') { this.$dispatch('open-shortcuts'); return; }
          if (e.key === 'c') {
            var page = document.querySelector('[data-page-entity]');
            if (page && page.getAttribute('data-can-create') === '1') { e.preventDefault(); this.$dispatch('open-form', { entity: page.getAttribute('data-page-entity') }); }
          }
        }
      };
    });

    A.data('notifications', function () {
      return {
        open: false, items: [], loading: false,
        toggle: function () { this.open = !this.open; if (this.open) this.load(); },
        load: function () { var self = this; this.loading = true; NX.api(NX.url('/x/notifications')).then(function (d) { self.items = d.items; A.store('nx').unread = d.unread; }).catch(NX.fail).finally(function () { self.loading = false; }); },
        read: function (n) { if (!n.read) NX.api(NX.url('/x/notifications/read'), { body: { id: n.id } }).then(function (d) { A.store('nx').unread = d.unread; }).catch(function () {}); },
        markAll: function () { var self = this; NX.api(NX.url('/x/notifications/read'), { body: {} }).then(function (d) { A.store('nx').unread = d.unread; self.items.forEach(function (i) { i.read = true; }); }).catch(NX.fail); }
      };
    });

    // Command palette / global search
    A.data('palette', function () {
      return {
        open: false, q: '', loading: false, groups: [], hl: 0, recent: [], actions: [], icons: {}, seq: 0,
        init: function () { this.actions = dataJson(this.$el, 'data-actions', []); this.icons = dataJson(this.$el, 'data-icons', {}); },
        show: function () { var self = this; this.open = true; this.recent = store.get('nx.recent', []); this.hl = 0; this.$nextTick(function () { self.$refs.input.focus(); self.$refs.input.select(); }); },
        close: function () { this.open = false; },
        get recentOffset() { return !this.q ? this.recent.length : 0; },
        get flat() {
          var out = [], q = this.q.trim().toLowerCase(), self = this;
          var acts = this.actions.filter(function (a) { return !q || a.label.toLowerCase().indexOf(q) !== -1; }).slice(0, q ? 5 : 8);
          acts.forEach(function (a, i) { out.push({ key: 'a' + i, header: i === 0 ? (q ? 'Actions' : 'Quick actions') : '', title: a.label, icon: a.icon, url: a.url, create: a.create, hint: a.hint || '' }); });
          this.groups.forEach(function (g) {
            g.items.forEach(function (it, i) { out.push({ key: g.entity + it.id, header: i === 0 ? g.label + (g.total > g.items.length ? ' · ' + g.total : '') : '', title: it.title, subtitle: it.subtitle, status: it.status, icon: g.icon, url: it.url }); });
          });
          return out;
        },
        search: function () {
          var self = this, q = this.q.trim(), my = ++this.seq;
          this.hl = 0;
          if (q.length < 2) { this.groups = []; return; }
          this.loading = true;
          NX.api(NX.url('/x/search') + '?q=' + encodeURIComponent(q)).then(function (d) { if (my === self.seq) self.groups = d.groups; }).catch(function () {}).finally(function () { if (my === self.seq) self.loading = false; });
        },
        move: function (d) {
          var total = this.flat.length + this.recentOffset;
          if (!total) return;
          this.hl = (this.hl + d + total) % total;
          var self = this;
          this.$nextTick(function () { var el = self.$refs.list.querySelector('.hl'); if (el) el.scrollIntoView({ block: 'nearest' }); });
        },
        go: function () {
          if (!this.q && this.hl < this.recent.length && this.recent.length) { this.q = this.recent[this.hl]; this.search(); return; }
          var item = this.flat[this.hl - this.recentOffset];
          if (item) this.open1(item);
        },
        open1: function (item) {
          if (this.q.trim().length >= 2) { var r = store.get('nx.recent', []).filter(function (x) { return x !== this.q.trim(); }, this); r.unshift(this.q.trim()); store.set('nx.recent', r.slice(0, 6)); }
          this.close();
          if (item.create) { this.$dispatch('open-form', { entity: item.create, quick: true }); return; }
          if (item.url) window.location.href = item.url;
        }
      };
    });

    // Drawer that hosts server-rendered create/edit forms
    A.data('drawerForm', function () {
      return {
        open: false, loading: false, ctx: {},
        openForm: function (d) {
          var self = this;
          this.ctx = d || {};
          this.open = true; this.loading = true;
          var qs = new URLSearchParams();
          if (d.id) qs.set('id', d.id);
          if (d.quick) qs.set('quick', '1');
          Object.keys(d.prefill || {}).forEach(function (k) { qs.set('prefill[' + k + ']', d.prefill[k]); });
          NX.api(NX.url('/x/r/' + d.entity + '/form') + '?' + qs.toString()).then(function (res) {
            self.$refs.body.innerHTML = res.html;
            self.loading = false;
            self.$nextTick(function () { var f = self.$refs.body.querySelector('input:not([type=hidden]):not([readonly]),select,textarea'); if (f) f.focus(); });
          }).catch(function (e) { self.open = false; self.loading = false; NX.fail(e); });
        },
        close: function () { this.open = false; var b = this.$refs.body; setTimeout(function () { b.innerHTML = ''; }, 200); },
        init: function () {
          var self = this;
          window.addEventListener('drawer-close', function () { self.close(); });
          window.addEventListener('record-saved', function (e) {
            var d = e.detail;
            self.close();
            var page = document.querySelector('[data-page-entity]');
            var onList = page && page.getAttribute('data-page-entity') === d.entity && page.hasAttribute('data-list');
            NX.toast(d.message || 'Saved');
            (d.warnings || []).forEach(function (w) { NX.toast(w, 'warning'); });
            if (self.ctx.onSaved === 'reload' || (d.mode === 'edit' && !onList)) { setTimeout(function () { window.location.reload(); }, 300); return; }
            if (onList) { window.dispatchEvent(new CustomEvent('records-changed')); return; }
            if (d.mode === 'create' && d.redirect && !self.ctx.stay) setTimeout(function () { window.location.href = d.redirect; }, 300);
          });
        }
      };
    });

    // The form inside the drawer
    A.data('recordForm', function () {
      return {
        saving: false,
        submit: function () {
          var self = this, form = this.$el.tagName === 'FORM' ? this.$el : this.$el.querySelector('form');
          this.saving = true;
          NX.api(form.getAttribute('action'), { method: 'POST', body: new FormData(form) })
            .then(function (d) {
              window.dispatchEvent(new CustomEvent('record-saved', { detail: { entity: form.getAttribute('data-entity'), mode: form.getAttribute('data-mode'), id: d.id, message: d.message, warnings: d.warnings, redirect: d.redirect } }));
            })
            .catch(function (e) { NX.showErrors(form, e.fields); NX.toast(e.status === 422 && e.fields && Object.keys(e.fields).length ? 'Please fix the highlighted fields' : e.message, 'error'); })
            .finally(function () { self.saving = false; });
        }
      };
    });

    // Searchable relation picker (combobox)
    A.data('relationPicker', function (cfg) {
      return {
        entity: cfg.entity, value: cfg.value || '', label: cfg.label || '', q: '', items: [], open: false, hl: 0, loading: false, seq: 0,
        get display() { return this.label; },
        focus: function () { this.open = true; this.q = ''; this.fetch(); },
        fetch: function () {
          var self = this, my = ++this.seq, qs = new URLSearchParams({ q: this.q });
          (cfg.where || []).forEach(function (w) {
            var form = self.$el.closest('form'); var el = form && form.querySelector('[name="' + w + '"]');
            if (el && el.value) qs.set('where[' + w + ']', el.value);
          });
          this.loading = true;
          NX.api(NX.url('/x/lookup/' + this.entity) + '?' + qs).then(function (d) { if (my === self.seq) { self.items = d.items; self.hl = 0; } }).catch(function () {}).finally(function () { if (my === self.seq) self.loading = false; });
        },
        pick: function (it) { this.value = String(it.id); this.label = it.title; this.open = false; var self = this; this.$nextTick(function () { self.$refs.hidden.dispatchEvent(new Event('change', { bubbles: true })); }); },
        clear: function () { this.value = ''; this.label = ''; var self = this; this.$nextTick(function () { self.$refs.hidden.dispatchEvent(new Event('change', { bubbles: true })); }); },
        key: function (e) {
          if (e.key === 'ArrowDown') { e.preventDefault(); this.open = true; this.hl = Math.min(this.hl + 1, this.items.length - 1); }
          else if (e.key === 'ArrowUp') { e.preventDefault(); this.hl = Math.max(this.hl - 1, 0); }
          else if (e.key === 'Enter') { if (this.open && this.items[this.hl]) { e.preventDefault(); this.pick(this.items[this.hl]); } }
          else if (e.key === 'Escape') { this.open = false; }
        }
      };
    });

    // Click-to-edit field on record pages and list cells
    A.data('inlineField', function (cfg) {
      return {
        editing: false, saving: false, value: cfg.value == null ? '' : String(cfg.value), html: '',
        init: function () { this.html = this.$refs.display ? this.$refs.display.innerHTML : ''; },
        start: function () {
          if (!cfg.editable) return;
          var self = this; this.editing = true;
          this.$nextTick(function () { var i = self.$el.querySelector('[data-inline-input]'); if (i) { i.focus(); if (i.select && i.type !== 'date') try { i.select(); } catch (e) {} } });
        },
        cancel: function () { this.editing = false; this.value = cfg.value == null ? '' : String(cfg.value); },
        save: function () {
          var self = this;
          if (this.saving) return;
          if (String(cfg.value == null ? '' : cfg.value) === String(this.value)) { this.editing = false; return; }
          this.saving = true;
          NX.api(cfg.url, { method: 'PATCH', body: { field: cfg.field, value: cfg.type === 'bool' ? (this.value === '1' || this.value === true ? 1 : 0) : this.value } })
            .then(function (d) {
              cfg.value = d.value; self.value = d.value == null ? '' : String(d.value); self.$refs.display.innerHTML = d.html; self.editing = false;
              (d.warnings || []).forEach(function (w) { NX.toast(w, 'warning'); });
              NX.toast('Saved');
              if (cfg.reload) setTimeout(function () { window.location.reload(); }, 300);
            })
            .catch(function (e) { NX.toast((e.fields && e.fields[cfg.field]) || e.message, 'error'); })
            .finally(function () { self.saving = false; });
        }
      };
    });

    // List / table page
    A.data('recordList', function (cfg) {
      return {
        entity: cfg.entity, view: cfg.view, q: cfg.q || '', filters: cfg.filters || [], sort: cfg.sort || '', dir: cfg.dir || '', page: cfg.page || 1,
        scope: cfg.scope || '', pipeline: cfg.pipeline || '', perPage: cfg.perPage || 25,
        fields: cfg.fields || {}, columns: cfg.columns || [], loading: false, selected: [], total: cfg.total || 0,
        filterOpen: false, nf: { field: '', op: 'eq', value: '', value2: '' }, colsOpen: false, bulkField: '', bulkValue: '', seq: 0,
        init: function () {
          var self = this;
          window.addEventListener('records-changed', function () { self.load(); });
          window.addEventListener('popstate', function () { window.location.reload(); });
        },
        query: function (extra) {
          var p = new URLSearchParams();
          if (this.view) p.set('view', this.view);
          if (this.q) p.set('q', this.q);
          if (this.scope) p.set('scope', this.scope);
          if (this.pipeline && this.view === 'board') p.set('pipeline', this.pipeline);
          this.filters.forEach(function (f) { p.append('f[]', f.field + ':' + f.op + ':' + (f.op === 'between' ? (f.value || '') + '..' + (f.value2 || '') : (f.value == null ? '' : f.value))); });
          if (this.sort) { p.set('sort', this.sort); p.set('dir', this.dir || 'asc'); }
          if (this.page > 1) p.set('page', this.page);
          Object.keys(extra || {}).forEach(function (k) { p.set(k, extra[k]); });
          return p.toString();
        },
        load: function () {
          var self = this, my = ++this.seq, qs = this.query();
          history.replaceState(null, '', window.location.pathname + (qs ? '?' + qs : ''));
          this.loading = true;
          NX.api(NX.url('/x/r/' + this.entity) + '?' + qs).then(function (d) {
            if (my !== self.seq) return;
            self.$refs.results.innerHTML = d.html; self.total = d.total; self.selected = [];
          }).catch(NX.fail).finally(function () { if (my === self.seq) self.loading = false; });
        },
        setView: function (v) { this.view = v; this.page = 1; window.location.href = window.location.pathname + '?' + this.query(); },
        setScope: function (s) { this.scope = s; this.page = 1; this.load(); },
        setPipeline: function (p) { this.pipeline = p; this.load(); },
        doSearch: function () { this.page = 1; this.load(); },
        sortBy: function (f) { if (this.sort === f) { this.dir = this.dir === 'asc' ? 'desc' : 'asc'; } else { this.sort = f; this.dir = 'asc'; } this.load(); },
        goPage: function (n) { this.page = n; this.load(); var top = this.$el.getBoundingClientRect().top + window.scrollY - 70; if (window.scrollY > top) window.scrollTo({ top: top, behavior: 'smooth' }); },
        // Filters
        fieldMeta: function (n) { return this.fields[n] || { type: 'text', label: n, options: [] }; },
        opsFor: function (n) {
          var t = this.fieldMeta(n).type;
          if (['date', 'datetime'].indexOf(t) !== -1) return [['eq', 'is'], ['gt', 'after'], ['lt', 'before'], ['between', 'between'], ['today', 'today'], ['this_week', 'this week'], ['this_month', 'this month'], ['overdue', 'is overdue'], ['last_days', 'in last N days'], ['next_days', 'in next N days'], ['empty', 'is empty'], ['not_empty', 'is set']];
          if (['number', 'decimal', 'money', 'percent', 'duration'].indexOf(t) !== -1) return [['eq', '='], ['gt', '>'], ['gte', '≥'], ['lt', '<'], ['lte', '≤'], ['between', 'between'], ['empty', 'is empty'], ['not_empty', 'is set']];
          if (t === 'bool') return [['is_true', 'is yes'], ['is_false', 'is no']];
          if (t === 'user') return [['me', 'is me'], ['eq', 'is'], ['neq', 'is not'], ['empty', 'is unassigned']];
          if (['select', 'stage', 'pipeline', 'currency', 'tax', 'multiselect'].indexOf(t) !== -1) return [['eq', 'is'], ['neq', 'is not'], ['in', 'is any of'], ['empty', 'is empty'], ['not_empty', 'is set']];
          if (t === 'relation') return [['eq', 'is (ID)'], ['empty', 'is empty'], ['not_empty', 'is set']];
          if (t === 'tags') return [['eq', 'has tag'], ['contains', 'contains'], ['empty', 'is empty']];
          return [['contains', 'contains'], ['eq', 'is'], ['neq', 'is not'], ['starts', 'starts with'], ['not_contains', 'does not contain'], ['empty', 'is empty'], ['not_empty', 'is set']];
        },
        needsValue: function (op) { return ['empty', 'not_empty', 'is_true', 'is_false', 'today', 'this_week', 'this_month', 'overdue', 'me'].indexOf(op) === -1; },
        inputType: function (n) { var t = this.fieldMeta(n).type; return t === 'date' || t === 'datetime' ? 'date' : (['number', 'decimal', 'money', 'percent', 'duration', 'relation'].indexOf(t) !== -1 ? 'number' : 'text'); },
        pickField: function () { this.nf.op = this.opsFor(this.nf.field)[0][0]; this.nf.value = ''; this.nf.value2 = ''; },
        addFilter: function () {
          if (!this.nf.field) return;
          if (this.needsValue(this.nf.op) && this.nf.value === '') { NX.toast('Enter a value for the filter', 'warning'); return; }
          var v = this.nf.value; if (this.nf.op === 'in' && Array.isArray(v)) v = v.join(',');
          this.filters.push({ field: this.nf.field, op: this.nf.op, value: v, value2: this.nf.value2 });
          this.nf = { field: '', op: 'eq', value: '', value2: '' }; this.filterOpen = false; this.page = 1; this.load();
        },
        removeFilter: function (i) { this.filters.splice(i, 1); this.page = 1; this.load(); },
        clearAll: function () { this.filters = []; this.q = ''; this.scope = ''; this.page = 1; this.load(); },
        chipText: function (f) {
          var m = this.fieldMeta(f.field), op = (this.opsFor(f.field).filter(function (o) { return o[0] === f.op; })[0] || [f.op, f.op])[1];
          var val = f.value;
          if (m.options && m.options.length) { var lookup = {}; m.options.forEach(function (o) { lookup[o.value] = o.label; }); val = String(val).split(',').map(function (x) { return lookup[x] || x; }).join(', '); }
          return m.label + ' ' + op + (this.needsValue(f.op) ? ' ' + val + (f.op === 'between' ? ' – ' + f.value2 : '') : '');
        },
        // Selection & bulk
        toggleAll: function (ev) { var ids = Array.prototype.map.call(this.$refs.results.querySelectorAll('[data-row-id]'), function (r) { return r.getAttribute('data-row-id'); }); ids = ids.filter(function (v, i) { return ids.indexOf(v) === i; }); this.selected = ev.target.checked ? ids : []; },
        isSel: function (id) { return this.selected.indexOf(String(id)) !== -1; },
        bulk: function (action) {
          var self = this;
          var run = function () {
            NX.api(NX.url('/r/' + self.entity + '/bulk'), { body: { ids: self.selected, action: action, field: self.bulkField, value: self.bulkValue } })
              .then(function (d) { NX.handle(d); self.selected = []; self.bulkField = ''; self.bulkValue = ''; self.load(); }).catch(NX.fail);
          };
          if (action === 'delete') NX.confirm({ message: 'Delete ' + this.selected.length + ' selected record(s)? They can be restored from the database backup only.', danger: true }).then(function (ok) { if (ok) run(); });
          else { if (!this.bulkField) { NX.toast('Choose a field to update', 'warning'); return; } run(); }
        },
        exportUrl: function (fmt) { var qs = this.query({ format: fmt }); if (this.selected.length) qs += '&ids=' + this.selected.join(','); return NX.url('/r/' + this.entity + '/export') + '?' + qs; },
        // Columns
        toggleCol: function (c) { var i = this.columns.indexOf(c); if (i === -1) this.columns.push(c); else if (this.columns.length > 1) this.columns.splice(i, 1); },
        saveCols: function () { var self = this; NX.api(NX.url('/x/preferences'), { body: { entity: this.entity, columns: this.columns } }).then(function () { self.colsOpen = false; self.load(); }).catch(NX.fail); },
        // Saved views
        saveView: function () {
          var self = this, name = window.prompt('Name this view');
          if (!name) return;
          NX.api(NX.url('/x/views/' + this.entity), { body: { name: name, query: this.query(), shared: cfg.canShare && window.confirm('Share this view with everyone?') ? 1 : 0 } }).then(function (d) { NX.handle(d); setTimeout(function () { window.location.reload(); }, 400); }).catch(NX.fail);
        },
        deleteView: function (id) { NX.confirm({ message: 'Delete this saved view?', danger: true }).then(function (ok) { if (ok) NX.api(NX.url('/x/views/' + id), { method: 'DELETE' }).then(function (d) { NX.handle(d); window.location.href = window.location.pathname; }).catch(NX.fail); }); },
        create: function () { this.$dispatch('open-form', { entity: this.entity, quick: false }); }
      };
    });

    // Kanban board with pointer-based drag & drop (mouse, pen and touch with long-press)
    A.data('board', function (cfg) {
      return {
        drag: null,
        init: function () {
          var self = this;
          this.onMove = function (e) { self.move(e); };
          this.onUp = function (e) { self.up(e); };
          this.onTouchMove = function (e) { if (self.drag && self.drag.active) e.preventDefault(); };
          this.$el.addEventListener('pointerdown', function (e) { self.down(e); });
          document.addEventListener('touchmove', this.onTouchMove, { passive: false });
        },
        down: function (e) {
          var card = e.target.closest('.kanban-card');
          if (!card || e.button > 0 || e.target.closest('a,button,input,select,[data-no-drag]') || !cfg.canEdit) return;
          var self = this;
          this.drag = { card: card, x: e.clientX, y: e.clientY, active: false, touch: e.pointerType === 'touch', from: card.closest('[data-col]'), next: card.nextElementSibling, pid: e.pointerId };
          if (this.drag.touch) this.drag.timer = setTimeout(function () { if (self.drag) self.start(self.drag.x, self.drag.y); }, 280);
          document.addEventListener('pointermove', this.onMove);
          document.addEventListener('pointerup', this.onUp);
          document.addEventListener('pointercancel', this.onUp);
        },
        start: function (x, y) {
          var d = this.drag, r = d.card.getBoundingClientRect();
          d.active = true;
          d.dx = x - r.left; d.dy = y - r.top;
          d.ghost = d.card.cloneNode(true);
          d.ghost.classList.add('kanban-ghost');
          d.ghost.style.width = r.width + 'px';
          d.ghost.style.left = r.left + 'px'; d.ghost.style.top = r.top + 'px';
          document.body.appendChild(d.ghost);
          d.ph = document.createElement('div'); d.ph.className = 'kanban-placeholder'; d.ph.style.height = r.height + 'px';
          d.card.parentNode.insertBefore(d.ph, d.card);
          d.card.classList.add('dragging'); d.card.style.display = 'none';
          if (navigator.vibrate && d.touch) navigator.vibrate(15);
        },
        move: function (e) {
          var d = this.drag; if (!d) return;
          if (!d.active) {
            var dist = Math.abs(e.clientX - d.x) + Math.abs(e.clientY - d.y);
            if (d.touch) { if (dist > 10) { clearTimeout(d.timer); this.cleanup(); } return; }
            if (dist < 6) return;
            this.start(d.x, d.y);
          }
          d.ghost.style.left = (e.clientX - d.dx) + 'px'; d.ghost.style.top = (e.clientY - d.dy) + 'px';
          var el = document.elementFromPoint(e.clientX, e.clientY), col = el && el.closest('[data-col]');
          this.$el.querySelectorAll('.drop-target').forEach(function (c) { c.classList.remove('drop-target'); });
          // Auto-scroll the board near its edges
          var wrap = this.$el.querySelector('.kanban'); if (wrap) { var wr = wrap.getBoundingClientRect(); if (e.clientX > wr.right - 50) wrap.scrollLeft += 14; else if (e.clientX < wr.left + 50) wrap.scrollLeft -= 14; }
          if (!col) return;
          col.classList.add('drop-target');
          var list = col.querySelector('.kanban-cards'), after = null;
          var cards = Array.prototype.filter.call(list.querySelectorAll('.kanban-card'), function (c) { return c !== d.card; });
          for (var i = 0; i < cards.length; i++) { var cr = cards[i].getBoundingClientRect(); if (e.clientY < cr.top + cr.height / 2) { after = cards[i]; break; } }
          var lr = list.getBoundingClientRect(); if (e.clientY > lr.bottom - 40) list.scrollTop += 10; else if (e.clientY < lr.top + 40) list.scrollTop -= 10;
          if (after) list.insertBefore(d.ph, after); else list.appendChild(d.ph);
        },
        up: function () {
          var d = this.drag; if (!d) return;
          clearTimeout(d.timer);
          if (!d.active) { this.cleanup(); return; }
          var list = d.ph.parentNode, col = list.closest('[data-col]');
          list.insertBefore(d.card, d.ph);
          d.card.style.display = ''; d.card.classList.remove('dragging');
          var moved = col !== d.from || d.card.nextElementSibling !== d.next;
          this.cleanup();
          if (!moved) return;
          var self = this, value = col.getAttribute('data-col');
          var order = Array.prototype.map.call(list.querySelectorAll('.kanban-card'), function (c) { return c.getAttribute('data-id'); });
          this.recount();
          NX.api(NX.url('/r/' + cfg.entity + '/' + d.card.getAttribute('data-id') + '/move'), { body: { value: value, order: order } })
            .then(function (res) { NX.toast(res.message || 'Moved'); (res.warnings || []).forEach(function (w) { NX.toast(w, 'warning'); }); })
            .catch(function (e) { NX.fail(e); window.dispatchEvent(new CustomEvent('records-changed')); });
        },
        cleanup: function () {
          var d = this.drag;
          document.removeEventListener('pointermove', this.onMove);
          document.removeEventListener('pointerup', this.onUp);
          document.removeEventListener('pointercancel', this.onUp);
          if (d) { if (d.ghost) d.ghost.remove(); if (d.ph) d.ph.remove(); if (d.card) { d.card.style.display = ''; d.card.classList.remove('dragging'); } }
          this.$el.querySelectorAll('.drop-target').forEach(function (c) { c.classList.remove('drop-target'); });
          this.drag = null;
        },
        recount: function () { this.$el.querySelectorAll('[data-col]').forEach(function (c) { var n = c.querySelector('[data-count]'); if (n) n.textContent = c.querySelectorAll('.kanban-card').length; }); },
        destroy: function () { document.removeEventListener('touchmove', this.onTouchMove); }
      };
    });

    // Activity timeline composer
    A.data('timeline', function (cfg) {
      return {
        type: 'note', body: '', subject: '', when: '', saving: false,
        submit: function () {
          var self = this;
          if (!this.body.trim()) { NX.toast('Write something first', 'warning'); return; }
          this.saving = true;
          NX.api(cfg.url, { body: { type: this.type, body: this.body, subject: this.subject, occurred_at: this.when } })
            .then(function (d) { self.$refs.list.insertAdjacentHTML('afterbegin', d.html); self.body = ''; self.subject = ''; self.when = ''; NX.toast(d.message); var empty = self.$refs.list.querySelector('[data-empty]'); if (empty) empty.remove(); })
            .catch(NX.fail).finally(function () { self.saving = false; });
        }
      };
    });

    // File uploader (drag & drop, progress, multiple)
    A.data('uploader', function (cfg) {
      return {
        over: false, uploads: [],
        pick: function () { this.$refs.input.click(); },
        drop: function (e) { this.over = false; this.send(e.dataTransfer.files); },
        changed: function (e) { this.send(e.target.files); e.target.value = ''; },
        send: function (files) {
          var self = this;
          Array.prototype.forEach.call(files || [], function (file) {
            var up = { name: file.name, pct: 0, error: '' };
            self.uploads.push(up);
            var fd = new FormData(); fd.append('file', file); fd.append('_token', meta('csrf-token'));
            Object.keys(cfg.extra || {}).forEach(function (k) { fd.append(k, cfg.extra[k]); });
            var xhr = new XMLHttpRequest();
            xhr.open('POST', cfg.url);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest'); xhr.setRequestHeader('Accept', 'application/json'); xhr.setRequestHeader('X-CSRF-Token', meta('csrf-token'));
            xhr.upload.onprogress = function (e) { if (e.lengthComputable) up.pct = Math.round(e.loaded / e.total * 100); self.uploads = self.uploads.slice(); };
            xhr.onload = function () {
              var d = {}; try { d = JSON.parse(xhr.responseText); } catch (e) {}
              if (xhr.status >= 200 && xhr.status < 300) {
                self.uploads = self.uploads.filter(function (u) { return u !== up; });
                NX.toast(d.message || 'Uploaded');
                if (d.html && self.$refs.list) { self.$refs.list.insertAdjacentHTML('afterbegin', d.html); var empty = self.$refs.list.querySelector('[data-empty]'); if (empty) empty.remove(); }
                else if (cfg.reload) setTimeout(function () { window.location.reload(); }, 300);
              } else { up.error = (d.error && d.error.message) || 'Upload failed'; self.uploads = self.uploads.slice(); NX.toast(file.name + ': ' + up.error, 'error'); }
            };
            xhr.onerror = function () { up.error = 'Network error'; self.uploads = self.uploads.slice(); };
            xhr.send(fd);
          });
        }
      };
    });

    // Quote / invoice editor
    A.data('docEditor', function (cfg) {
      return {
        items: (cfg.items && cfg.items.length ? cfg.items : [{}]).map(function (i) { return { product_id: i.product_id || '', description: i.description || '', quantity: i.quantity != null ? Number(i.quantity) : 1, unit: i.unit || '', unit_price: i.unit_price != null ? Number(i.unit_price) : 0, discount_percent: Number(i.discount_percent || 0), tax_id: i.tax_id ? String(i.tax_id) : (cfg.defaultTax || ''), time_entry_ids: i.time_entry_ids || '' }; }),
        discountType: cfg.discountType || 'percent', discountValue: Number(cfg.discountValue || 0), adjustment: Number(cfg.adjustment || 0),
        currency: cfg.currency, saving: false, productQ: '',
        taxRate: function (id) { var t = cfg.taxes.filter(function (x) { return String(x.id) === String(id); })[0]; return t ? Number(t.rate) : 0; },
        lineNet: function (it) { return Math.round(Number(it.quantity || 0) * Number(it.unit_price || 0) * (1 - Number(it.discount_percent || 0) / 100) * 100) / 100; },
        get subtotal() { var s = 0, self = this; this.items.forEach(function (i) { s += self.lineNet(i); }); return Math.round(s * 100) / 100; },
        get discount() { var s = this.subtotal; return this.discountType === 'fixed' ? Math.min(this.discountValue, s) : Math.round(s * Math.min(this.discountValue, 100)) / 100; },
        get taxTotal() { var t = 0, self = this; this.items.forEach(function (i) { t += self.lineNet(i) * self.taxRate(i.tax_id) / 100; }); var ratio = this.subtotal > 0 ? (this.subtotal - this.discount) / this.subtotal : 1; return Math.round(t * ratio * 100) / 100; },
        get total() { return Math.round((this.subtotal - this.discount + this.taxTotal + Number(this.adjustment || 0)) * 100) / 100; },
        fmt: function (n) { try { return new Intl.NumberFormat(this.currency === 'INR' ? 'en-IN' : undefined, { style: 'currency', currency: this.currency || 'INR' }).format(n || 0); } catch (e) { return (this.currency || '') + ' ' + Number(n || 0).toFixed(2); } },
        add: function (p) {
          var row = { product_id: '', description: '', quantity: 1, unit: '', unit_price: 0, discount_percent: 0, tax_id: cfg.defaultTax || '', time_entry_ids: '' };
          if (p) { row.product_id = p.id; row.description = p.name + (p.description ? '\n' + p.description : ''); row.unit = p.unit; row.unit_price = Number(p.price); if (p.tax_id) row.tax_id = String(p.tax_id); }
          var empty = this.items.length === 1 && !this.items[0].description && !Number(this.items[0].unit_price);
          if (empty && p) this.items = [row]; else this.items.push(row);
          this.productQ = '';
        },
        get productMatches() { var q = this.productQ.toLowerCase(); return cfg.products.filter(function (p) { return !q || p.name.toLowerCase().indexOf(q) !== -1; }).slice(0, 8); },
        remove: function (i) { this.items.splice(i, 1); if (!this.items.length) this.add(); },
        moveItem: function (i, d) { var j = i + d; if (j < 0 || j >= this.items.length) return; var t = this.items[i]; this.items[i] = this.items[j]; this.items[j] = t; this.items = this.items.slice(); },
        save: function (status) {
          var self = this, form = this.$refs.form, data = NX.formObject(form);
          data.items = this.items; data.discount_type = this.discountType; data.discount_value = this.discountValue; data.adjustment = this.adjustment;
          if (status) data.status = status;
          this.saving = true;
          NX.api(cfg.saveUrl, { body: data }).then(NX.handle).catch(function (e) { NX.showErrors(form, e.fields); NX.fail(e); }).finally(function () { self.saving = false; });
        }
      };
    });

    // Chart.js wrapper with theme-aware colours
    A.data('chart', function (cfg) {
      return {
        chart: null,
        init: function () {
          var self = this;
          var draw = function () {
            if (!window.Chart) { setTimeout(draw, 60); return; }
            var dark = document.documentElement.classList.contains('dark');
            var grid = dark ? 'rgba(255,255,255,.07)' : 'rgba(0,0,0,.06)', text = dark ? '#a3a3ad' : '#71717a';
            var brand = getComputedStyle(document.documentElement).getPropertyValue('--brand').trim() || '#4f46e5';
            var palette = [brand, '#0ea5e9', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#14b8a6', '#ec4899', '#64748b', '#84cc16'];
            var ds = (cfg.datasets || []).map(function (d, i) {
              var c = d.color || palette[i % palette.length];
              var o = Object.assign({ borderWidth: cfg.type === 'line' ? 2 : 0, borderRadius: cfg.type === 'bar' ? 5 : 0, tension: 0.35, pointRadius: 0, pointHoverRadius: 4, maxBarThickness: 36 }, d);
              if (cfg.type === 'doughnut') { o.backgroundColor = palette; o.borderWidth = 2; o.borderColor = dark ? '#141417' : '#fff'; }
              else { o.backgroundColor = cfg.type === 'line' ? c + '22' : (d.muted ? c + '55' : c); o.borderColor = c; o.fill = cfg.type === 'line' && i === 0; }
              return o;
            });
            if (self.chart) self.chart.destroy();
            var money = cfg.money;
            self.chart = new Chart(self.$refs.canvas, {
              type: cfg.type, data: { labels: cfg.labels, datasets: ds },
              options: {
                responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
                plugins: { legend: { display: ds.length > 1 || cfg.type === 'doughnut', position: cfg.type === 'doughnut' ? 'right' : 'bottom', labels: { color: text, boxWidth: 10, boxHeight: 10, usePointStyle: true, font: { size: 11 } } },
                  tooltip: { callbacks: money ? { label: function (c) { var v = c.parsed.y != null ? c.parsed.y : c.parsed; return ' ' + c.dataset.label + ': ' + Number(v).toLocaleString(undefined, { maximumFractionDigits: 0 }); } } : {} } },
                scales: cfg.type === 'doughnut' ? {} : {
                  x: { grid: { display: false }, ticks: { color: text, font: { size: 11 } }, border: { display: false } },
                  y: { grid: { color: grid }, border: { display: false }, ticks: { color: text, font: { size: 11 }, callback: function (v) { return money ? (v >= 1e7 ? (v / 1e7).toFixed(1) + 'Cr' : v >= 1e5 ? (v / 1e5).toFixed(1) + 'L' : v >= 1e3 ? (v / 1e3).toFixed(0) + 'k' : v) : v; } }, beginAtZero: true }
                },
                cutout: cfg.type === 'doughnut' ? '68%' : undefined
              }
            });
          };
          draw();
          window.addEventListener('theme-changed', draw);
        }
      };
    });

    // Calendar (month / week / agenda)
    A.data('calendar', function (cfg) {
      return {
        mode: store.get('nx.calmode', window.innerWidth < 640 ? 'agenda' : 'month'), cursor: new Date(), items: [], loading: false, mine: false,
        layers: store.get('nx.callayers', ['events', 'tasks', 'leave', 'invoices', 'deals']), weekStart: cfg.weekStart || 1,
        init: function () { this.load(); var self = this; window.addEventListener('records-changed', function () { self.load(); }); },
        ymd: function (d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); },
        get range() {
          var c = new Date(this.cursor), s, e;
          if (this.mode === 'month') { s = new Date(c.getFullYear(), c.getMonth(), 1); var off = (s.getDay() - this.weekStart + 7) % 7; s.setDate(s.getDate() - off); e = new Date(s); e.setDate(e.getDate() + 41); }
          else if (this.mode === 'week') { s = new Date(c); s.setDate(s.getDate() - ((s.getDay() - this.weekStart + 7) % 7)); e = new Date(s); e.setDate(e.getDate() + 6); }
          else { s = new Date(c.getFullYear(), c.getMonth(), c.getDate()); e = new Date(s); e.setDate(e.getDate() + 30); }
          return { start: s, end: e };
        },
        get title() {
          var c = this.cursor;
          if (this.mode === 'month') return c.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
          var r = this.range; return r.start.toLocaleDateString(undefined, { day: 'numeric', month: 'short' }) + ' – ' + r.end.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
        },
        get days() { var out = [], r = this.range, d = new Date(r.start); while (d <= r.end) { out.push(new Date(d)); d.setDate(d.getDate() + 1); } return out; },
        get weekdays() { var out = [], base = new Date(2024, 0, 7 + this.weekStart); for (var i = 0; i < 7; i++) { var d = new Date(base); d.setDate(base.getDate() + i); out.push(d.toLocaleDateString(undefined, { weekday: 'short' })); } return out; },
        itemsOn: function (d) { var k = this.ymd(d); return this.items.filter(function (i) { var s = String(i.start).slice(0, 10), e = String(i.end || i.start).slice(0, 10); return s <= k && k <= e; }); },
        get agenda() { var self = this; return this.days.map(function (d) { return { day: d, items: self.itemsOn(d) }; }).filter(function (g) { return g.items.length; }); },
        isToday: function (d) { return this.ymd(d) === this.ymd(new Date()); },
        inMonth: function (d) { return d.getMonth() === this.cursor.getMonth(); },
        time: function (i) { return i.allDay || String(i.start).length <= 10 ? '' : String(i.start).slice(11, 16); },
        load: function () {
          var self = this, r = this.range;
          store.set('nx.calmode', this.mode); store.set('nx.callayers', this.layers);
          this.loading = true;
          NX.api(NX.url('/x/calendar') + '?start=' + this.ymd(r.start) + '&end=' + this.ymd(r.end) + '&layers=' + this.layers.join(',') + (this.mine ? '&mine=1' : '')).then(function (d) { self.items = d.items; }).catch(NX.fail).finally(function () { self.loading = false; });
        },
        shift: function (n) { var c = new Date(this.cursor); if (this.mode === 'month') c.setMonth(c.getMonth() + n, 1); else c.setDate(c.getDate() + (this.mode === 'week' ? 7 : 30) * n); this.cursor = c; this.load(); },
        today: function () { this.cursor = new Date(); this.load(); },
        setMode: function (m) { this.mode = m; this.load(); },
        toggleLayer: function (l) { var i = this.layers.indexOf(l); if (i === -1) this.layers.push(l); else this.layers.splice(i, 1); this.load(); },
        newOn: function (d) { if (!cfg.canCreate) return; this.$dispatch('open-form', { entity: 'events', prefill: { starts_at: this.ymd(d) + 'T10:00' }, onSaved: 'list' }); },
        color: function (c) { return { indigo: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-950 dark:text-indigo-300', green: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300', red: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300', amber: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300', teal: 'bg-teal-100 text-teal-800 dark:bg-teal-950 dark:text-teal-300', slate: 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300', rose: 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300', violet: 'bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-300' }[c] || ''; }
      };
    });

    // Timer start button on task/project pages
    A.data('timerButton', function (cfg) {
      return {
        running: !!cfg.running,
        init: function () { var self = this; window.addEventListener('timer-stopped', function () { self.running = false; }); },
        toggle: function () {
          var self = this;
          if (this.running) { NX.api(NX.url('/x/timer/stop'), { method: 'POST' }).then(function (d) { self.running = false; NX.handle(d); window.dispatchEvent(new CustomEvent('timer-changed', { detail: null })); setTimeout(function () { window.location.reload(); }, 500); }).catch(NX.fail); return; }
          NX.api(NX.url('/x/timer/start'), { body: { task_id: cfg.taskId || '', project_id: cfg.projectId || '' } }).then(function (d) { self.running = true; NX.handle(d); window.dispatchEvent(new CustomEvent('timer-changed', { detail: d.timer })); }).catch(NX.fail);
        }
      };
    });

    // ───── Settings editors ─────
    A.data('pipelineEditor', function (cfg) {
      return {
        pipelines: cfg.pipelines, saving: false, colors: ['slate', 'blue', 'violet', 'amber', 'green', 'red', 'teal', 'rose', 'indigo'],
        addPipeline: function () { this.pipelines.push({ id: null, name: 'New pipeline', stages: [{ name: 'New', probability: 10, color: 'slate' }, { name: 'Won', probability: 100, color: 'green', is_won: 1 }, { name: 'Lost', probability: 0, color: 'red', is_lost: 1 }] }); },
        addStage: function (p) { var at = Math.max(0, p.stages.length - 2); p.stages.splice(at, 0, { name: 'New stage', probability: 50, color: 'blue' }); },
        move: function (arr, i, d) { var j = i + d; if (j < 0 || j >= arr.length) return; var t = arr[i]; arr.splice(i, 1); arr.splice(j, 0, t); },
        save: function () { var self = this; this.saving = true; NX.api(NX.url('/settings/pipelines'), { body: { pipelines: JSON.stringify(this.pipelines) } }).then(NX.handle).catch(NX.fail).finally(function () { self.saving = false; }); }
      };
    });

    A.data('rowsEditor', function (cfg) {
      return {
        rows: cfg.rows || [], saving: false,
        add: function () { this.rows.push(JSON.parse(JSON.stringify(cfg.blank || {}))); },
        remove: function (i) { this.rows.splice(i, 1); },
        save: function () { var self = this, body = {}; body[cfg.key] = JSON.stringify(this.rows); this.saving = true; NX.api(cfg.url, { body: body }).then(NX.handle).catch(NX.fail).finally(function () { self.saving = false; }); }
      };
    });

    A.data('workflowBuilder', function (cfg) {
      var wf = cfg.wf || {};
      return {
        entities: cfg.entities, users: cfg.users, saving: false,
        id: wf.id || null, name: wf.name || '', entity: wf.entity || 'leads', trigger: wf.trigger_type || 'created', active: wf.is_active == null ? true : !!Number(wf.is_active),
        tcfg: wf.trigger_config ? JSON.parse(wf.trigger_config) : {}, conditions: wf.conditions ? JSON.parse(wf.conditions) : [], actions: wf.actions ? JSON.parse(wf.actions) : [{ type: 'notify', to: 'owner', title: '', body: '' }],
        get fields() { return (this.entities[this.entity] || { fields: [] }).fields; },
        get writable() { return this.fields.filter(function (f) { return !f.readonly; }); },
        get dateFields() { return this.fields.filter(function (f) { return f.type === 'date' || f.type === 'datetime'; }); },
        field: function (n) { return this.fields.filter(function (f) { return f.name === n; })[0] || { options: [] }; },
        addCondition: function () { this.conditions.push({ field: this.fields[0] ? this.fields[0].name : '', op: 'eq', value: '' }); },
        addAction: function (t) { var a = { type: t || 'notify' }; if (a.type === 'round_robin') a.user_ids = []; this.actions.push(a); },
        toggleRR: function (a, id) { a.user_ids = a.user_ids || []; var i = a.user_ids.indexOf(id); if (i === -1) a.user_ids.push(id); else a.user_ids.splice(i, 1); },
        placeholder: function () { return '{{' + (this.fields[0] ? this.fields[0].name : 'name') + '}}'; },
        save: function () {
          var self = this; this.saving = true;
          NX.api(NX.url('/settings/workflows'), { body: { id: this.id, name: this.name, entity: this.entity, trigger_type: this.trigger, trigger_config: this.tcfg, conditions: this.conditions, actions: this.actions, is_active: this.active ? 1 : 0 } })
            .then(NX.handle).catch(NX.fail).finally(function () { self.saving = false; });
        }
      };
    });

    // QR code for authenticator setup (qrcode-generator, MIT)
    A.data('qr', function (cfg) {
      return {
        init: function () {
          var self = this;
          var draw = function () {
            if (!window.qrcode) { setTimeout(draw, 80); return; }
            var q = window.qrcode(0, 'M'); q.addData(cfg.text); q.make();
            self.$el.innerHTML = q.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
            var svg = self.$el.querySelector('svg'); if (svg) { svg.setAttribute('role', 'img'); svg.setAttribute('aria-label', 'QR code for authenticator app'); }
          };
          draw();
        }
      };
    });

    A.data('roleMatrix', function () {
      return {
        setAll: function (action, value) {
          this.$root.querySelectorAll('[data-action-col="' + action + '"]').forEach(function (el) {
            if (el.tagName === 'SELECT') el.value = value; else el.checked = !!value;
          });
        }
      };
    });

    A.data('storageForm', function (cfg) {
      return {
        driver: cfg.driver, testing: false, result: null,
        test: function () {
          var self = this, fd = new FormData(this.$refs.form); fd.set('driver', this.driver);
          this.testing = true; this.result = null;
          NX.api(NX.url('/settings/storage/test'), { body: fd }).then(function (d) { self.result = d; }).catch(function (e) { self.result = { ok: false, message: e.message }; }).finally(function () { self.testing = false; });
        }
      };
    });

    // ───── Installer wizard ─────
    A.data('installer', function (cfg) {
      return {
        step: 0, steps: ['Welcome', 'Requirements', 'Extensions', 'Database', 'Connection', 'Application URL', 'Administrator', 'Company', 'Modules', 'Install', 'Done'],
        checks: cfg.checks, dbOk: false, dbMsg: '', testing: false, installing: false, error: '', progress: 0, redirect: '',
        db: { host: 'localhost', port: 3306, database: '', username: '', password: '' },
        url: cfg.url, admin: { name: '', email: '', password: '', password_confirmation: '' },
        company: { name: '', app_name: 'NextaCRM', currency: 'INR', timezone: 'Asia/Kolkata', country: 'India', tax_id: '', phone: '' },
        modules: cfg.modules, disabled: [], demo: true,
        get reqOk() { return this.checks.every(function (c) { return !c.required || c.ok; }); },
        group: function (g) { return this.checks.filter(function (c) { return c.group === g; }); },
        post: function (action, body) { return NX.api(window.location.pathname + '?action=' + action, { body: Object.assign({ action: action }, body || {}) }); },
        recheck: function () { var self = this; this.post('requirements').then(function (d) { self.checks = d.checks; }).catch(NX.fail); },
        test: function () {
          var self = this; this.testing = true; this.dbMsg = '';
          this.post('test_db', { db: this.db }).then(function (d) { self.dbOk = d.ok; self.dbMsg = d.message; if (d.ok) self.step = 5; }).catch(function (e) { self.dbOk = false; self.dbMsg = e.message; }).finally(function () { self.testing = false; });
        },
        next: function () {
          this.error = '';
          if (this.step === 1 && !this.reqOk) { this.error = 'Fix the failed requirements first.'; return; }
          if (this.step === 3) { this.step = 4; this.test(); return; }
          if (this.step === 6) {
            if (!this.admin.name || !this.admin.email) { this.error = 'Name and e-mail are required.'; return; }
            if (this.admin.password.length < 10) { this.error = 'Use at least 10 characters for the password.'; return; }
            if (this.admin.password !== this.admin.password_confirmation) { this.error = 'Passwords do not match.'; return; }
          }
          if (this.step === 7 && !this.company.name) { this.error = 'Company name is required.'; return; }
          this.step++;
          if (this.step === 9) this.install();
        },
        back: function () { this.error = ''; if (this.step > 0 && !this.installing) this.step--; if (this.step === 4) this.step = 3; },
        install: function () {
          var self = this; this.installing = true; this.progress = 10;
          var t = setInterval(function () { if (self.progress < 90) self.progress += 4; }, 400);
          this.post('install', { db: this.db, url: this.url, admin: this.admin, company: this.company, modules_disabled: this.disabled, demo: this.demo ? 1 : 0 })
            .then(function (d) { clearInterval(t); if (d.ok) { self.progress = 100; self.redirect = d.redirect; self.step = 10; } else { self.error = d.message; self.installing = false; self.step = { database: 3, url: 5, admin: 6, company: 7 }[d.step] || 8; } })
            .catch(function (e) { clearInterval(t); self.error = e.message; self.installing = false; self.step = 8; });
        }
      };
    });
  });
})();
