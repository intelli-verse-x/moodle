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

})();
