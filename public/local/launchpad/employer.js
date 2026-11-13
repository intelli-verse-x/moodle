/**
 * Employer shell: mobile nav, journey bars, Dot Dot orbit (Fellowship parity).
 *
 * @module     local_launchpad
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
(function () {
    'use strict';

    // Wrong-portal modal: already on the correct home; dismiss stays here.
    var mismatch = document.querySelector('[data-lp-portal-mismatch]');
    if (mismatch) {
        function closeMismatch() {
            mismatch.classList.remove('is-open');
            mismatch.setAttribute('hidden', 'hidden');
            document.body.classList.remove('lp-portal-mismatch-lock');
        }
        document.body.classList.add('lp-portal-mismatch-lock');
        mismatch.querySelectorAll('[data-lp-portal-mismatch-dismiss]').forEach(function (el) {
            el.addEventListener('click', closeMismatch);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && mismatch.classList.contains('is-open')) {
                closeMismatch();
            }
        });
    }

    var shell = document.getElementById('lp-shell');
    if (!shell) {
        return;
    }

    var toggle = shell.querySelector('[data-lp-nav-toggle]');
    var scrim = shell.querySelector('[data-lp-nav-close]');
    var side = document.getElementById('lp-side');

    function setOpen(open) {
        shell.classList.toggle('is-nav-open', open);
        if (toggle) {
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
        if (scrim) {
            if (open) {
                scrim.removeAttribute('hidden');
            } else {
                scrim.setAttribute('hidden', 'hidden');
            }
        }
    }

    if (toggle) {
        toggle.addEventListener('click', function () {
            setOpen(!shell.classList.contains('is-nav-open'));
        });
    }
    if (scrim) {
        scrim.addEventListener('click', function () {
            setOpen(false);
        });
    }
    if (side) {
        side.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                if (window.matchMedia('(max-width: 900px)').matches) {
                    setOpen(false);
                }
            });
        });

        // Keep #lp-side scroll + collapsed groups across full-page tab loads.
        (function persistSide() {
            var region = shell.getAttribute('data-region') || '';
            var portal = 'employee';
            if (region.indexOf('mentor') !== -1) {
                portal = 'mentor';
            } else if (region.indexOf('fellowship') !== -1) {
                portal = 'fellow';
            }
            var key = 'lp.side.' + portal;

            function readState() {
                try {
                    var raw = window.sessionStorage.getItem(key);
                    return raw ? JSON.parse(raw) : {};
                } catch (err) {
                    return {};
                }
            }

            function writeState(patch) {
                try {
                    var next = readState();
                    Object.keys(patch).forEach(function (k) {
                        next[k] = patch[k];
                    });
                    window.sessionStorage.setItem(key, JSON.stringify(next));
                } catch (err) {
                    // Private mode / quota — ignore.
                }
            }

            var state = readState();
            var groups = [];

            side.querySelectorAll('.lp-nl').forEach(function (nl) {
                var id = (nl.textContent || '').replace(/\s+/g, ' ').trim();
                if (!id) {
                    return;
                }
                var items = [];
                var el = nl.nextElementSibling;
                while (el && !el.classList.contains('lp-nl')
                        && !el.classList.contains('lp-me')
                        && !el.classList.contains('lp-side-foot')) {
                    if (el.classList.contains('lp-nav')) {
                        items.push(el);
                    }
                    el = el.nextElementSibling;
                }
                groups.push({id: id, nl: nl, items: items});
                nl.style.cursor = 'pointer';
                nl.setAttribute('role', 'button');
                nl.tabIndex = 0;
            });

            function setGroupCollapsed(group, collapsed) {
                group.nl.classList.toggle('is-collapsed', collapsed);
                group.nl.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                group.items.forEach(function (item) {
                    if (collapsed) {
                        item.setAttribute('hidden', 'hidden');
                    } else {
                        item.removeAttribute('hidden');
                    }
                });
            }

            function saveCollapsed() {
                var map = {};
                groups.forEach(function (g) {
                    if (g.nl.classList.contains('is-collapsed')) {
                        map[g.id] = true;
                    }
                });
                writeState({collapsed: map});
            }

            var collapsed = state.collapsed || {};
            groups.forEach(function (g) {
                var hasCurrent = g.items.some(function (item) {
                    return item.classList.contains('is-current');
                });
                // Never hide the active tab's section after a navigation.
                setGroupCollapsed(g, !hasCurrent && !!collapsed[g.id]);
                function toggleGroup() {
                    setGroupCollapsed(g, !g.nl.classList.contains('is-collapsed'));
                    saveCollapsed();
                }
                g.nl.addEventListener('click', toggleGroup);
                g.nl.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        toggleGroup();
                    }
                });
            });

            function restoreScroll() {
                if (typeof state.scroll === 'number') {
                    side.scrollTop = state.scroll;
                }
            }
            restoreScroll();
            requestAnimationFrame(restoreScroll);

            var scrollTimer = null;
            side.addEventListener('scroll', function () {
                if (scrollTimer) {
                    window.clearTimeout(scrollTimer);
                }
                scrollTimer = window.setTimeout(function () {
                    writeState({scroll: side.scrollTop});
                }, 50);
            }, {passive: true});
        })();
    }
    // Account menus (topbar avatar + sidebar foot).
    var profileMenus = Array.prototype.slice.call(shell.querySelectorAll('[data-lp-profile-menu]'));

    function setProfileOpen(menu, open) {
        if (!menu) {
            return;
        }
        var toggle = menu.querySelector('[data-lp-profile-toggle]');
        var panel = menu.querySelector('[data-lp-profile-panel]');
        if (!toggle || !panel) {
            return;
        }
        menu.classList.toggle('is-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) {
            panel.removeAttribute('hidden');
        } else {
            panel.setAttribute('hidden', 'hidden');
        }
    }

    function closeAllProfileMenus(except) {
        profileMenus.forEach(function (menu) {
            if (except && menu === except) {
                return;
            }
            setProfileOpen(menu, false);
        });
    }

    profileMenus.forEach(function (menu) {
        var toggle = menu.querySelector('[data-lp-profile-toggle]');
        var panel = menu.querySelector('[data-lp-profile-panel]');
        if (!toggle || !panel) {
            return;
        }
        toggle.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var willOpen = !menu.classList.contains('is-open');
            closeAllProfileMenus(menu);
            setProfileOpen(menu, willOpen);
        });
    });

    if (profileMenus.length) {
        document.addEventListener('click', function (e) {
            var openMenu = profileMenus.find(function (menu) {
                return menu.classList.contains('is-open');
            });
            if (!openMenu) {
                return;
            }
            if (!openMenu.contains(e.target)) {
                setProfileOpen(openMenu, false);
            }
        });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            var openMenu = profileMenus.find(function (menu) {
                return menu.classList.contains('is-open');
            });
            if (openMenu) {
                setProfileOpen(openMenu, false);
                var openToggle = openMenu.querySelector('[data-lp-profile-toggle]');
                if (openToggle) {
                    openToggle.focus();
                }
                return;
            }
            setOpen(false);
        }
    });

    var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    function animateDash(el) {
        var to = el.getAttribute('data-to');
        if (to === null || to === '') {
            return;
        }
        if (reduce) {
            el.style.strokeDashoffset = to;
            return;
        }
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                el.style.strokeDashoffset = to;
            });
        });
    }
    shell.querySelectorAll('.lp-journey-progress, .lp-ring-arc').forEach(animateDash);

    if (!reduce) {
        shell.querySelectorAll('.lp-side-xpbar i, .lp-bar i').forEach(function (bar) {
            var w = bar.style.width;
            if (!w) {
                return;
            }
            bar.style.width = '0%';
            requestAnimationFrame(function () {
                requestAnimationFrame(function () {
                    bar.style.width = w;
                });
            });
        });
    }

    // Dot Dot orbit (bottom-right companion) — same behaviour as Fellowship.
    var orbit = document.getElementById('lp-orbit');
    var orbitHide = document.querySelector('[data-lp-orbit-hide]');
    var orbitSay = document.getElementById('lp-orbit-say');
    var STORAGE_KEY = 'lp-employer-orbit-hidden';

    function setOrbitHidden(hidden) {
        document.body.classList.toggle('lp-orbit-is-hidden', hidden);
        if (orbitHide) {
            orbitHide.textContent = hidden
                ? (orbitHide.getAttribute('data-show-label') || 'Show Dot Dot')
                : (orbitHide.getAttribute('data-hide-label') || 'Hide Dot Dot');
        }
        try {
            window.localStorage.setItem(STORAGE_KEY, hidden ? '1' : '0');
        } catch (e) {
            // Ignore private-mode storage failures.
        }
    }

    if (orbitHide) {
        orbitHide.setAttribute('data-hide-label', orbitHide.textContent);
        orbitHide.setAttribute('data-show-label', 'Show Dot Dot');
        var stored = null;
        try {
            stored = window.localStorage.getItem(STORAGE_KEY);
        } catch (e) {
            stored = null;
        }
        if (stored === '1') {
            setOrbitHidden(true);
        }
        orbitHide.addEventListener('click', function () {
            setOrbitHidden(!document.body.classList.contains('lp-orbit-is-hidden'));
        });
    }

    if (orbit) {
        var hopBtn = orbit.querySelector('[data-lp-orbit-hop]');
        if (hopBtn) {
            hopBtn.addEventListener('click', function () {
                if (reduce) {
                    return;
                }
                orbit.classList.remove('is-hop');
                // Force reflow so the hop can replay.
                void orbit.offsetWidth;
                orbit.classList.add('is-hop');
            });
        }
    }

    var dismiss = document.querySelector('[data-lp-orbit-dismiss]');
    if (dismiss && orbitSay) {
        dismiss.addEventListener('click', function () {
            orbitSay.classList.add('is-hide');
        });
    }

    // Messages: keep thread scrolled to latest; Ctrl/Cmd+Enter sends.
    var msgScroll = shell.querySelector('[data-region="employer-msg-scroll"]');
    if (msgScroll) {
        msgScroll.scrollTop = msgScroll.scrollHeight;
    }
    var msgForm = shell.querySelector('[data-region="employer-msg-composer"]');
    if (msgForm) {
        var msgBox = msgForm.querySelector('textarea[name="message"]');
        if (msgBox) {
            msgBox.focus();
            msgBox.addEventListener('keydown', function (e) {
                if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                    e.preventDefault();
                    if (typeof msgForm.requestSubmit === 'function') {
                        msgForm.requestSubmit();
                    } else {
                        msgForm.submit();
                    }
                }
            });
        }
    }

    // Badges: detail popup (Moodle-backed payload in JSON script tag).
    (function initBadgeDialog() {
        var page = shell.querySelector('[data-region="employer-badges-page"]');
        if (!page) {
            return;
        }
        var dataNode = page.querySelector('[data-region="employer-badges-data"]');
        var dialog = page.querySelector('[data-region="employer-badge-dialog"]');
        if (!dataNode || !dialog || typeof dialog.showModal !== 'function') {
            return;
        }
        var catalog = {};
        try {
            catalog = JSON.parse(dataNode.textContent || '{}') || {};
        } catch (err) {
            catalog = {};
        }

        function fill(badge) {
            var img = dialog.querySelector('[data-lp-badge-img]');
            var name = dialog.querySelector('[data-lp-badge-name]');
            var detail = dialog.querySelector('[data-lp-badge-detail]');
            var desc = dialog.querySelector('[data-lp-badge-desc]');
            var status = dialog.querySelector('[data-lp-badge-status]');
            var scope = dialog.querySelector('[data-lp-badge-scope]');
            var moodle = dialog.querySelector('[data-lp-badge-moodle]');
            if (img) {
                img.onerror = function () {
                    img.onerror = null;
                    img.src = 'data:image/svg+xml,' + encodeURIComponent(
                        '<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96" viewBox="0 0 96 96">' +
                        '<rect width="96" height="96" rx="24" fill="#E8F0FF"/>' +
                        '<text x="48" y="56" text-anchor="middle" font-size="36">★</text></svg>'
                    );
                };
                img.src = badge.imageurl || '';
                img.alt = badge.name || '';
            }
            if (name) {
                name.textContent = badge.name || '';
            }
            if (detail) {
                detail.textContent = badge.detail || '';
                detail.hidden = !badge.detail;
            }
            if (desc) {
                desc.textContent = badge.description || '';
                desc.hidden = !badge.description;
            }
            if (status) {
                status.textContent = badge.status_pill || '';
                status.classList.toggle('is-earned', !!badge.earned);
            }
            if (scope) {
                scope.textContent = badge.scope_label || '';
                scope.hidden = !badge.scope_label;
            }
            if (moodle) {
                moodle.href = badge.moodle_url || '#';
                moodle.hidden = !badge.moodle_url;
            }
        }

        function openBadge(id) {
            var badge = catalog[String(id)];
            if (!badge) {
                return;
            }
            fill(badge);
            if (!dialog.open) {
                dialog.showModal();
            }
        }

        page.querySelectorAll('.lp-bdg img, .lp-badge-recent-img img, .lp-badge-next-btn img').forEach(function (el) {
            el.addEventListener('error', function onImgErr() {
                el.removeEventListener('error', onImgErr);
                el.src = 'data:image/svg+xml,' + encodeURIComponent(
                    '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">' +
                    '<rect width="64" height="64" rx="16" fill="#EEF1F6"/>' +
                    '<text x="32" y="40" text-anchor="middle" font-size="26">★</text></svg>'
                );
            });
        });

        page.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-lp-badge-open]');
            if (!btn || !page.contains(btn)) {
                return;
            }
            e.preventDefault();
            openBadge(btn.getAttribute('data-lp-badge-open'));
        });

        dialog.addEventListener('click', function (e) {
            if (e.target === dialog) {
                dialog.close();
            }
        });
    })();

    // Custom selects (profile Country / Timezone / Language).
    function closeLpSelect(root) {
        if (!root) {
            return;
        }
        var menu = root.querySelector('[data-lp-select-menu]');
        var trigger = root.querySelector('[data-lp-select-trigger]');
        root.classList.remove('is-open');
        if (menu) {
            menu.setAttribute('hidden', 'hidden');
        }
        if (trigger) {
            trigger.setAttribute('aria-expanded', 'false');
        }
    }
    function closeAllLpSelects(except) {
        shell.querySelectorAll('[data-lp-select]').forEach(function (root) {
            if (except && root === except) {
                return;
            }
            closeLpSelect(root);
        });
    }
    function setLpSelectValue(root, value) {
        if (!root) {
            return;
        }
        var options = root.querySelectorAll('[data-lp-select-option]');
        var labelEl = root.querySelector('[data-lp-select-label]');
        var input = root.querySelector('[data-lp-select-input]');
        var matched = null;
        options.forEach(function (opt) {
            var on = opt.getAttribute('data-value') === String(value);
            opt.classList.toggle('is-selected', on);
            if (on) {
                opt.setAttribute('aria-selected', 'true');
                matched = opt;
            } else {
                opt.removeAttribute('aria-selected');
            }
        });
        if (!matched && options.length) {
            matched = options[0];
            matched.classList.add('is-selected');
            matched.setAttribute('aria-selected', 'true');
            value = matched.getAttribute('data-value');
        }
        if (labelEl && matched) {
            labelEl.textContent = (matched.textContent || '').replace(/\s+/g, ' ').trim();
        }
        if (input) {
            input.value = String(value);
        }
    }
    function openLpSelect(root) {
        if (!root || root.classList.contains('is-locked')) {
            return;
        }
        closeAllLpSelects(root);
        var menu = root.querySelector('[data-lp-select-menu]');
        var trigger = root.querySelector('[data-lp-select-trigger]');
        root.classList.add('is-open');
        if (menu) {
            menu.removeAttribute('hidden');
        }
        if (trigger) {
            trigger.setAttribute('aria-expanded', 'true');
        }
        var selected = root.querySelector('[data-lp-select-option].is-selected')
            || root.querySelector('[data-lp-select-option]');
        if (selected) {
            selected.focus();
        }
    }
    shell.querySelectorAll('[data-lp-select]').forEach(function (root) {
        if (root.getAttribute('data-lp-select-ready') === '1') {
            return;
        }
        root.setAttribute('data-lp-select-ready', '1');
        var trigger = root.querySelector('[data-lp-select-trigger]');
        var menu = root.querySelector('[data-lp-select-menu]');
        var input = root.querySelector('[data-lp-select-input]');
        if (input) {
            setLpSelectValue(root, input.value || '');
        }
        if (trigger) {
            trigger.addEventListener('click', function (e) {
                e.preventDefault();
                if (trigger.disabled || root.classList.contains('is-locked')) {
                    return;
                }
                if (root.classList.contains('is-open')) {
                    closeLpSelect(root);
                } else {
                    openLpSelect(root);
                }
            });
        }
        if (menu) {
            menu.addEventListener('click', function (e) {
                var opt = e.target.closest('[data-lp-select-option]');
                if (!opt || !menu.contains(opt)) {
                    return;
                }
                setLpSelectValue(root, opt.getAttribute('data-value') || '');
                closeLpSelect(root);
                if (trigger) {
                    trigger.focus();
                }
            });
            menu.addEventListener('keydown', function (e) {
                var options = Array.prototype.slice.call(menu.querySelectorAll('[data-lp-select-option]'));
                if (!options.length) {
                    return;
                }
                var current = document.activeElement;
                var idx = options.indexOf(current);
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    options[Math.min(options.length - 1, Math.max(0, idx) + 1)].focus();
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    options[Math.max(0, (idx < 0 ? 0 : idx) - 1)].focus();
                } else if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    if (current && current.hasAttribute('data-lp-select-option')) {
                        current.click();
                    }
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    closeLpSelect(root);
                    if (trigger) {
                        trigger.focus();
                    }
                }
            });
        }
    });
    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-lp-select]')) {
            return;
        }
        closeAllLpSelects(null);
    });

    // Profile tabs: switch Overview / Edit / Preferences in-place (no full-page reload).
    (function profileTabs() {
        var root = shell.querySelector('[data-lp-profile]');
        if (!root) {
            return;
        }
        var tabs = root.querySelectorAll('[data-lp-profile-tab]');
        var panels = root.querySelectorAll('[data-lp-profile-panel]');
        if (!tabs.length || !panels.length) {
            return;
        }

        function applyTab(tab, pushUrl) {
            var key = tab || 'overview';
            if (key !== 'overview' && key !== 'edit' && key !== 'preferences') {
                key = 'overview';
            }
            root.setAttribute('data-lp-profile-active', key);

            tabs.forEach(function (el) {
                var on = el.getAttribute('data-lp-profile-tab') === key;
                el.classList.toggle('is-on', on);
                el.setAttribute('aria-selected', on ? 'true' : 'false');
            });

            panels.forEach(function (panel) {
                panel.hidden = panel.getAttribute('data-lp-profile-panel') !== key;
            });

            if (pushUrl) {
                var hub = root.querySelector('[data-lp-profile-pref-hub]');
                var detail = root.querySelector('[data-lp-profile-pref-detail]');
                if (hub && detail) {
                    hub.hidden = false;
                    detail.hidden = true;
                }
                if (window.history && window.history.replaceState) {
                    try {
                        var url = new URL(window.location.href);
                        url.searchParams.set('view', 'profile');
                        if (key === 'overview') {
                            url.searchParams.delete('ptab');
                        } else {
                            url.searchParams.set('ptab', key);
                        }
                        url.searchParams.delete('pref');
                        url.searchParams.delete('saved');
                        url.searchParams.delete('err');
                        window.history.replaceState({}, '', url.pathname + url.search + url.hash);
                    } catch (err) {
                        // Ignore URL sync failures.
                    }
                }
            }
        }

        root.addEventListener('click', function (e) {
            var tab = e.target.closest('[data-lp-profile-tab]');
            if (!tab || !root.contains(tab)) {
                return;
            }
            e.preventDefault();
            applyTab(tab.getAttribute('data-lp-profile-tab'), true);
        });

        applyTab(root.getAttribute('data-lp-profile-active') || 'overview', false);
    })();

})();

/* LaunchPad notification dropdown (shared behaviour with Fellowship). */
(function () {
    'use strict';
    var root = document.querySelector('[data-region="lp-notif-dropdown"]');
    if (!root) {
        return;
    }
    var toggle = root.querySelector('[data-lp-notif-toggle]');
    var panel = root.querySelector('#lp-notif-panel');
    var list = root.querySelector('[data-lp-notif-list]');
    var empty = root.querySelector('[data-lp-notif-empty]');
    var loading = root.querySelector('[data-lp-notif-loading]');
    var countEl = root.querySelector('[data-lp-notif-count]');
    var markAllBtn = root.querySelector('[data-lp-notif-markall]');
    var closeBtn = root.querySelector('[data-lp-notif-close]');
    var scrollEl = root.querySelector('[data-lp-notif-scroll]');
    var liveEl = root.querySelector('[data-lp-notif-live]');
    var ajaxUrl = root.getAttribute('data-ajax') || '';
    var sesskey = root.getAttribute('data-sesskey') || '';
    var portal = root.getAttribute('data-portal') || 'employer';
    var limit = parseInt(root.getAttribute('data-limit') || '20', 10) || 20;
    var unreadCount = parseInt(root.getAttribute('data-unread') || '0', 10) || 0;
    var offset = 0;
    var loadedAll = false;
    var initialLoad = false;
    var isLoading = false;
    var lastQueried = 0;
    var viewFullLabel = 'View full notification';

    function setOpen(open) {
        if (!panel || !toggle) {
            return;
        }
        if (open) {
            panel.removeAttribute('hidden');
            toggle.setAttribute('aria-expanded', 'true');
            if (countEl) {
                countEl.classList.add('is-hidden');
            }
            if (!initialLoad) {
                loadMore();
            }
        } else {
            panel.setAttribute('hidden', 'hidden');
            toggle.setAttribute('aria-expanded', 'false');
            renderCount();
        }
    }
    function isOpen() {
        return panel && !panel.hasAttribute('hidden');
    }
    function renderCount() {
        if (!countEl) {
            return;
        }
        if (unreadCount > 0) {
            countEl.textContent = String(unreadCount);
            countEl.classList.remove('is-hidden');
        } else {
            countEl.classList.add('is-hidden');
        }
        root.setAttribute('data-unread', String(unreadCount));
    }
    function setBusy(busy) {
        isLoading = busy;
        if (list) {
            list.setAttribute('aria-busy', busy ? 'true' : 'false');
        }
        if (loading) {
            if (busy) {
                loading.removeAttribute('hidden');
            } else {
                loading.setAttribute('hidden', 'hidden');
            }
        }
    }
    function escapeHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
    function renderItems(items) {
        if (!list || !items || !items.length) {
            return;
        }
        var html = items.map(function (n) {
            var unread = !!n.unread;
            var open = n.openurl || n.contexturl || n.viewmoreurl || '#';
            var more = n.viewmoreurl
                ? '<a class="lp-notif-item-more" href="' + escapeHtml(n.viewmoreurl) + '" data-lp-notif-more>' +
                    escapeHtml(viewFullLabel) + '</a>'
                : '';
            return '<a class="lp-notif-item' + (unread ? ' is-unread' : '') + '"' +
                ' href="' + escapeHtml(open) + '"' +
                ' data-id="' + escapeHtml(n.id) + '"' +
                ' data-lp-notif-item role="listitem">' +
                '<span class="lp-notif-item-subject">' + escapeHtml(n.shortenedsubject || n.subject) + '</span>' +
                '<span class="lp-notif-item-meta">' +
                '<span class="lp-notif-item-when">' + escapeHtml(n.timecreatedpretty || '') + '</span>' +
                more + '</span></a>';
        }).join('');
        list.insertAdjacentHTML('beforeend', html);
    }
    function updateEmpty() {
        if (!empty || !list) {
            return;
        }
        if (list.children.length > 0) {
            empty.setAttribute('hidden', 'hidden');
        } else if (initialLoad && !isLoading) {
            empty.removeAttribute('hidden');
        }
    }
    function loadMore() {
        if (isLoading || loadedAll || !ajaxUrl) {
            return Promise.resolve();
        }
        setBusy(true);
        var url = ajaxUrl +
            '?action=list&limit=' + encodeURIComponent(limit) +
            '&offset=' + encodeURIComponent(offset) +
            '&portal=' + encodeURIComponent(portal) +
            '&sesskey=' + encodeURIComponent(sesskey);
        return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                initialLoad = true;
                lastQueried = Math.floor(Date.now() / 1000);
                if (!data || !data.ok) {
                    loadedAll = true;
                    updateEmpty();
                    return;
                }
                unreadCount = parseInt(data.unreadcount || 0, 10) || 0;
                var items = data.notifications || [];
                if (items.length) {
                    renderItems(items);
                    offset += items.length;
                }
                if (!items.length || items.length < limit) {
                    loadedAll = true;
                }
                updateEmpty();
            })
            .catch(function () {
                initialLoad = true;
                loadedAll = true;
                updateEmpty();
            })
            .finally(function () {
                setBusy(false);
                if (isOpen() && countEl) {
                    countEl.classList.add('is-hidden');
                } else {
                    renderCount();
                }
            });
    }
    function markAll() {
        if (!ajaxUrl || unreadCount <= 0) {
            return;
        }
        if (markAllBtn) {
            markAllBtn.classList.add('is-loading');
        }
        var body = new URLSearchParams();
        body.set('action', 'markall');
        body.set('sesskey', sesskey);
        body.set('timecreatedto', String(lastQueried || Math.floor(Date.now() / 1000)));
        fetch(ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body.toString()
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.ok) {
                    unreadCount = parseInt(data.unreadcount || 0, 10) || 0;
                    root.querySelectorAll('.lp-notif-item.is-unread').forEach(function (el) {
                        el.classList.remove('is-unread');
                    });
                    if (liveEl) {
                        liveEl.textContent = 'Notifications marked as read';
                    }
                }
            })
            .finally(function () {
                if (markAllBtn) {
                    markAllBtn.classList.remove('is-loading');
                }
                if (isOpen() && countEl) {
                    countEl.classList.add('is-hidden');
                } else {
                    renderCount();
                }
            });
    }
    if (toggle) {
        toggle.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            setOpen(!isOpen());
        });
    }
    if (closeBtn) {
        closeBtn.addEventListener('click', function (e) {
            e.preventDefault();
            setOpen(false);
            if (toggle) {
                toggle.focus();
            }
        });
    }
    if (markAllBtn) {
        markAllBtn.addEventListener('click', function (e) {
            e.preventDefault();
            markAll();
        });
    }
    if (list) {
        list.addEventListener('click', function (e) {
            var item = e.target.closest('[data-lp-notif-item]');
            if (!item || e.target.closest('[data-lp-notif-more]')) {
                return;
            }
            if (item.classList.contains('is-unread')) {
                unreadCount = Math.max(0, unreadCount - 1);
                item.classList.remove('is-unread');
                root.setAttribute('data-unread', String(unreadCount));
            }
        });
    }
    if (scrollEl) {
        scrollEl.addEventListener('scroll', function () {
            if (isLoading || loadedAll) {
                return;
            }
            if (scrollEl.scrollTop + scrollEl.clientHeight >= scrollEl.scrollHeight - 48) {
                loadMore();
            }
        });
    }
    document.addEventListener('click', function (e) {
        if (isOpen() && !root.contains(e.target)) {
            setOpen(false);
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && isOpen()) {
            setOpen(false);
            if (toggle) {
                toggle.focus();
            }
        }
    });
})();

/* Notifications inbox: select row → right pane via AJAX (no full reload). */
(function () {
    'use strict';
    var root = document.querySelector('[data-region="portal-notifications"]');
    if (!root) {
        return;
    }
    var ajaxUrl = root.getAttribute('data-ajax') || '';
    var sesskey = root.getAttribute('data-sesskey') || '';
    var portal = root.getAttribute('data-portal') || 'employer';
    var ntab = root.getAttribute('data-ntab') || 'all';
    var unreadCount = parseInt(root.getAttribute('data-unread') || '0', 10) || 0;
    var layout = root.querySelector('[data-lp-notif-layout]');
    var detail = root.querySelector('[data-lp-notif-detail]');
    var list = root.querySelector('[data-lp-notif-inbox-list]');
    var unreadEl = root.querySelector('[data-lp-notif-unread]');
    var unreadRow = root.querySelector('[data-lp-notif-unread-row]');
    var tabUnread = root.querySelector('[data-lp-notif-tab-unread]');
    var markAllForm = root.querySelector('[data-lp-notif-markall-form]');
    var labelBack = root.getAttribute('data-label-back') || 'Back';
    var labelUnread = root.getAttribute('data-label-unread') || 'Unread';
    var labelEmptyTitle = root.getAttribute('data-label-empty-title') || 'Select a notification';
    var labelEmptyBody = root.getAttribute('data-label-empty-body') || '';
    var labelOpen = root.getAttribute('data-label-open') || 'Open related';
    var loadingId = 0;

    function escapeHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function setUnread(count) {
        unreadCount = Math.max(0, count);
        root.setAttribute('data-unread', String(unreadCount));
        if (unreadEl) {
            unreadEl.textContent = String(unreadCount);
        }
        if (tabUnread) {
            tabUnread.textContent = String(unreadCount);
        }
        if (unreadRow) {
            unreadRow.classList.toggle('is-hot', unreadCount > 0);
        }
        if (markAllForm) {
            if (unreadCount > 0) {
                markAllForm.removeAttribute('hidden');
            } else {
                markAllForm.setAttribute('hidden', 'hidden');
            }
        }
        var shellDot = document.querySelector('[data-region="lp-notif-dropdown"] [data-lp-notif-count]');
        var shellRoot = document.querySelector('[data-region="lp-notif-dropdown"]');
        if (shellRoot) {
            shellRoot.setAttribute('data-unread', String(unreadCount));
        }
        if (shellDot) {
            if (unreadCount > 0) {
                shellDot.textContent = String(unreadCount);
                shellDot.classList.remove('is-hidden');
            } else {
                shellDot.textContent = '0';
                shellDot.classList.add('is-hidden');
            }
        }
    }

    function emptyDetailHtml() {
        return '<div class="lp-notif-detail-empty">' +
            '<div class="lp-empty-mark" aria-hidden="true">' +
            '<iconify-icon icon="hugeicons:notification-01" width="26" height="26"></iconify-icon>' +
            '</div>' +
            '<h2>' + escapeHtml(labelEmptyTitle) + '</h2>' +
            '<p>' + escapeHtml(labelEmptyBody) + '</p>' +
            '</div>';
    }

    function detailHtml(selected) {
        var back = selected.back_url || ('?view=notifications' + (ntab !== 'all' ? '&ntab=' + encodeURIComponent(ntab) : ''));
        var when = selected.has_when && selected.when
            ? '<p class="lp-muted lp-sm">' + escapeHtml(selected.when) + '</p>'
            : '';
        var unreadChip = selected.unread
            ? '<span class="lp-chip is-hot">' + escapeHtml(labelUnread) + '</span>'
            : '';
        var actions = selected.has_context && selected.context_url
            ? '<div class="lp-notif-detail-actions">' +
                '<a class="lp-btn lp-btn-primary" href="' + escapeHtml(selected.context_url) + '">' +
                escapeHtml(selected.context_label || labelOpen) + '</a></div>'
            : '<div class="lp-notif-detail-actions"></div>';
        return '<div class="lp-notif-detail-head">' +
            '<a class="lp-notif-back" href="' + escapeHtml(back) + '" data-lp-notif-back>' +
            escapeHtml(labelBack) + '</a>' +
            '<div class="lp-notif-detail-meta"><div class="lp-theme-hero-heading">' +
            '<h3>' + escapeHtml(selected.name || '') + '</h3>' + unreadChip +
            '</div>' + when + '</div></div>' +
            '<div class="lp-notif-scroll lp-scroll"><div class="lp-notif-content">' +
            (selected.body_html || '') +
            '</div></div>' + actions;
    }

    function buildUrl(notifid) {
        var url = new URL(window.location.href);
        url.searchParams.set('view', 'notifications');
        if (ntab !== 'all') {
            url.searchParams.set('ntab', ntab);
        } else {
            url.searchParams.delete('ntab');
        }
        if (notifid > 0) {
            url.searchParams.set('notifid', String(notifid));
        } else {
            url.searchParams.delete('notifid');
        }
        url.searchParams.delete('notifdone');
        url.searchParams.delete('notiferr');
        return url.pathname + url.search + url.hash;
    }

    function clearSelectionUi() {
        if (list) {
            list.querySelectorAll('[data-lp-notif-row].is-selected').forEach(function (el) {
                el.classList.remove('is-selected');
            });
        }
        if (layout) {
            layout.classList.remove('is-split');
        }
        if (detail) {
            detail.innerHTML = emptyDetailHtml();
        }
    }

    function showDetail(selected, row) {
        if (!detail || !selected) {
            return;
        }
        if (list) {
            list.querySelectorAll('[data-lp-notif-row].is-selected').forEach(function (el) {
                el.classList.remove('is-selected');
            });
        }
        if (row) {
            row.classList.add('is-selected');
            if (row.classList.contains('is-unread')) {
                row.classList.remove('is-unread');
                var chip = row.querySelector('[data-lp-notif-unread-chip]');
                if (chip) {
                    chip.remove();
                }
                if (ntab === 'unread') {
                    var li = row.closest('[data-lp-notif-li]');
                    if (li) {
                        li.remove();
                    }
                    if (list && !list.querySelector('[data-lp-notif-row]')) {
                        list.remove();
                    }
                }
            }
        }
        if (layout) {
            layout.classList.add('is-split');
        }
        detail.innerHTML = detailHtml(selected);
        if (selected.unread) {
            var chipEl = detail.querySelector('.lp-chip.is-hot');
            if (chipEl) {
                chipEl.remove();
            }
        }
    }

    function fetchDetail(notifid, row) {
        if (!ajaxUrl || !sesskey || notifid <= 0) {
            return;
        }
        var req = ++loadingId;
        if (detail) {
            detail.setAttribute('aria-busy', 'true');
        }
        var qs = 'action=detail&sesskey=' + encodeURIComponent(sesskey) +
            '&notifid=' + encodeURIComponent(String(notifid)) +
            '&portal=' + encodeURIComponent(portal) +
            '&ntab=' + encodeURIComponent(ntab);
        fetch(ajaxUrl + (ajaxUrl.indexOf('?') >= 0 ? '&' : '?') + qs, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }).then(function (res) {
            return res.json();
        }).then(function (data) {
            if (req !== loadingId) {
                return;
            }
            if (detail) {
                detail.removeAttribute('aria-busy');
            }
            if (!data || !data.ok || !data.selected) {
                return;
            }
            if (typeof data.unreadcount === 'number') {
                setUnread(data.unreadcount);
            }
            showDetail(data.selected, row);
            if (window.history && window.history.pushState) {
                window.history.pushState({ lpNotifId: notifid }, '', buildUrl(notifid));
            }
        }).catch(function () {
            if (req === loadingId && detail) {
                detail.removeAttribute('aria-busy');
            }
        });
    }

    root.addEventListener('click', function (e) {
        var back = e.target.closest('[data-lp-notif-back]');
        if (back && root.contains(back)) {
            e.preventDefault();
            clearSelectionUi();
            if (window.history && window.history.pushState) {
                window.history.pushState({ lpNotifId: 0 }, '', buildUrl(0));
            }
            return;
        }
        var row = e.target.closest('[data-lp-notif-row]');
        if (!row || !root.contains(row)) {
            return;
        }
        e.preventDefault();
        if (row.classList.contains('is-selected') && layout && layout.classList.contains('is-split')) {
            return;
        }
        var id = parseInt(row.getAttribute('data-id') || '0', 10) || 0;
        fetchDetail(id, row);
    });

    window.addEventListener('popstate', function (e) {
        var id = 0;
        if (e.state && typeof e.state.lpNotifId === 'number') {
            id = e.state.lpNotifId;
        } else {
            try {
                id = parseInt(new URL(window.location.href).searchParams.get('notifid') || '0', 10) || 0;
            } catch (err) {
                id = 0;
            }
        }
        if (id <= 0) {
            clearSelectionUi();
            return;
        }
        var row = list ? list.querySelector('[data-lp-notif-row][data-id="' + id + '"]') : null;
        fetchDetail(id, row);
    });
})();
