'use strict';

/**
 * Simply Static (Cloudflare) - Push button for the post editor.
 *
 * Shows whether the page being edited is on the live site and publishes pending changes.
 * States: Pushed (up to date) | Push (changed since it went live) | Unsaved (edits not saved yet)
 *         | Pushing... (publishing) | Not published (draft).
 * Works in Gutenberg and the Classic editor.
 */
(function () {
    var cfg = window.ssCloudflarePush;
    if (!cfg) return;

    var T = cfg.text;
    var current = { state: 'pending' };
    var buttons = [];
    var timer = null;

    function url(path, params) {
        var u = cfg.api + path;
        if (params) {
            u += (u.indexOf('?') === -1 ? '?' : '&') + params;
        }
        return u;
    }

    function call(path, method, params) {
        return fetch(url(path, params), {
            method: method,
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': cfg.nonce }
        }).then(function (r) {
            if (!r.ok) throw new Error('http ' + r.status);
            return r.json();
        });
    }

    // Gutenberg only: true while the editor holds changes that are not saved yet.
    function isDirty() {
        try {
            var ed = window.wp.data.select('core/editor');
            return !!(ed && ed.isEditedPostDirty && ed.isEditedPostDirty());
        } catch (e) { return false; }
    }

    function view() {
        var s = current.state;
        if (s !== 'running' && s !== 'unpublished' && isDirty()) {
            return { label: T.unsaved, tip: T.tipUnsaved, disabled: true, key: 'unsaved' };
        }
        switch (s) {
            case 'synced': return { label: T.pushed + ' ✓', tip: T.tipPushed, disabled: true, key: 'synced' };
            case 'running': return { label: T.pushing, tip: T.tipPushing, disabled: true, key: 'running' };
            case 'unpublished': return { label: T.unpublished, tip: T.tipUnpub, disabled: true, key: 'unpublished' };
            case 'error': return { label: T.push, tip: T.tipError, disabled: false, key: 'pending' };
            default: return { label: T.push, tip: T.tipPush, disabled: false, key: 'pending' };
        }
    }

    function render() {
        var v = view();
        buttons.forEach(function (b) {
            if (b.textContent !== v.label) b.textContent = v.label;
            b.title = v.tip;
            b.disabled = v.disabled;
            b.setAttribute('data-state', v.key);
        });
    }

    function schedule() {
        clearTimeout(timer);
        var fast = current.state === 'running' || current.state === 'pending' || current.state === 'never';
        timer = setTimeout(refresh, fast ? 4000 : 30000);
    }

    function refresh() {
        call('post-status', 'GET', 'post_id=' + cfg.postId).then(function (r) {
            current = r;
            render();
            schedule();
        }).catch(function () { schedule(); });
    }

    function push() {
        current = { state: 'running' };
        render();
        call('push', 'POST').then(function () {
            setTimeout(refresh, 1500);
        }).catch(function () {
            current = { state: 'error' };
            render();
        });
    }

    function makeButton(cls) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = cls + ' ss-cf-push';
        btn.addEventListener('click', push);
        buttons.push(btn);
        return btn;
    }

    function inject() {
        buttons = buttons.filter(function (b) { return document.body.contains(b); });

        // Gutenberg (selector names changed across WordPress versions).
        var g = document.querySelector('.editor-header__settings, .edit-post-header__settings, .edit-post-header__toolbar');
        if (g && !g.querySelector('.ss-cf-push')) {
            g.insertBefore(makeButton('components-button is-primary'), g.firstChild);
            render();
        }
        // Classic editor.
        var actions = document.querySelector('#major-publishing-actions');
        if (actions && !actions.querySelector('.ss-cf-push')) {
            var btn = makeButton('button');
            btn.style.marginLeft = '8px';
            var publish = actions.querySelector('#publish');
            if (publish) publish.insertAdjacentElement('afterend', btn); else actions.appendChild(btn);
            render();
        }
    }

    function styles() {
        var css = '.ss-cf-push[data-state="synced"]{background:#1a7f37!important;border-color:#1a7f37!important;color:#fff!important;opacity:.85}'
            + '.ss-cf-push[data-state="pending"]{background:#6804cc!important;border-color:#6804cc!important;color:#fff!important}'
            + '.ss-cf-push[data-state="unsaved"],.ss-cf-push[data-state="running"],.ss-cf-push[data-state="unpublished"]{opacity:.7;cursor:not-allowed}';
        var style = document.createElement('style');
        style.appendChild(document.createTextNode(css));
        document.head.appendChild(style);
    }

    document.addEventListener('DOMContentLoaded', function () {
        styles();
        inject();
        try { new MutationObserver(inject).observe(document.body, { childList: true, subtree: true }); } catch (e) {}

        // Gutenberg: re-check right after a save finishes and when edits make the page dirty/clean.
        try {
            var wasSaving = false;
            var wasDirty = false;
            window.wp.data.subscribe(function () {
                var ed = window.wp.data.select('core/editor');
                if (!ed) return;
                var saving = ed.isSavingPost && ed.isSavingPost();
                var dirty = isDirty();
                if (wasSaving && !saving) setTimeout(refresh, 1000);
                if (dirty !== wasDirty) render();
                wasSaving = saving;
                wasDirty = dirty;
            });
        } catch (e) {}

        refresh();
    });
})();
