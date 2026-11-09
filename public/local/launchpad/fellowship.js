/**
 * Fellowship shell: mobile nav toggle only (no role switching).
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
            var portal = 'fellow';
            if (region.indexOf('admin') !== -1) {
                portal = 'admin';
            } else if (region.indexOf('mentor') !== -1) {
                portal = 'mentor';
            } else if (region.indexOf('employer') !== -1) {
                portal = 'employee';
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
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') {
                return;
            }
            var openMenu = profileMenus.find(function (menu) {
                return menu.classList.contains('is-open');
            });
            if (!openMenu) {
                return;
            }
            setProfileOpen(openMenu, false);
            var toggle = openMenu.querySelector('[data-lp-profile-toggle]');
            if (toggle) {
                toggle.focus();
            }
        });
    }

    var calRoot = shell.querySelector('[data-lp-cal-root]');
    var calDetail = shell.querySelector('[data-lp-cal-detail]');
    var calForm = shell.querySelector('[data-lp-cal-form]');
    var calFormEl = shell.querySelector('[data-lp-cal-form-el]');
    var calFlash = shell.querySelector('[data-lp-cal-flash]');
    var calDayPanel = shell.querySelector('[data-lp-cal-daypanel]');
    var calMonthFetch = null;
    var calDetailSource = null;
    var calDragId = null;
    var calDragMoved = false;
    var calStrings = {
        addTitle: 'Add event',
        editTitle: 'Edit event',
        saved: 'Event saved.',
        deleted: 'Event deleted.',
        deleteConfirm: 'Delete this event? It will also leave your Moodle calendar.',
        network: 'Network error — try again.',
        courseRequired: 'Choose a course for this event.'
    };
    if (calRoot) {
        calStrings.addTitle = calRoot.getAttribute('data-str-add-title') || calStrings.addTitle;
        calStrings.editTitle = calRoot.getAttribute('data-str-edit-title') || calStrings.editTitle;
        calStrings.saved = calRoot.getAttribute('data-str-saved') || calStrings.saved;
        calStrings.deleted = calRoot.getAttribute('data-str-deleted') || calStrings.deleted;
        calStrings.deleteConfirm = calRoot.getAttribute('data-str-delete-confirm') || calStrings.deleteConfirm;
        calStrings.network = calRoot.getAttribute('data-str-network') || calStrings.network;
        calStrings.courseRequired = calRoot.getAttribute('data-str-course-required') || calStrings.courseRequired;
    }
    function normalizeCalScope(value) {
        if (value === 'course' || value === 'site') {
            return value;
        }
        return 'personal';
    }
    function getCalScope() {
        var checked = calForm ? calForm.querySelector('[data-lp-cal-form-scope]:checked') : null;
        if (checked) {
            return normalizeCalScope(checked.value);
        }
        var fixed = calForm ? calForm.querySelector('[data-lp-cal-form-scope-fixed]') : null;
        if (fixed && fixed.value) {
            return normalizeCalScope(fixed.value);
        }
        return normalizeCalScope(calRoot ? calRoot.getAttribute('data-default-scope') : 'personal');
    }
    function setCalScope(scope, lock) {
        var radios = calForm ? calForm.querySelectorAll('[data-lp-cal-form-scope]') : [];
        radios.forEach(function (r) {
            r.checked = (r.value === scope);
            r.disabled = !!lock;
        });
        var fixed = calForm ? calForm.querySelector('[data-lp-cal-form-scope-fixed]') : null;
        if (fixed) {
            fixed.value = scope;
        }
        syncCalScopeUi(scope);
    }
    function syncCalScopeUi(scope) {
        if (!calForm) {
            return;
        }
        var courseWrap = calForm.querySelector('[data-lp-cal-form-course-wrap]');
        var helpPersonal = calForm.querySelector('[data-lp-cal-form-help]');
        var helpCourse = calForm.querySelector('[data-lp-cal-form-help-course]');
        var helpSite = calForm.querySelector('[data-lp-cal-form-help-site]');
        var isCourse = scope === 'course';
        var isSite = scope === 'site';
        if (courseWrap) {
            if (isCourse) {
                courseWrap.removeAttribute('hidden');
            } else {
                courseWrap.setAttribute('hidden', 'hidden');
            }
        }
        if (helpPersonal) {
            if (isCourse || isSite) {
                helpPersonal.setAttribute('hidden', 'hidden');
            } else {
                helpPersonal.removeAttribute('hidden');
            }
        }
        if (helpCourse) {
            if (isCourse) {
                helpCourse.removeAttribute('hidden');
            } else {
                helpCourse.setAttribute('hidden', 'hidden');
            }
        }
        if (helpSite) {
            if (isSite) {
                helpSite.removeAttribute('hidden');
            } else {
                helpSite.setAttribute('hidden', 'hidden');
            }
        }
        syncRepeatHelp(scope);
    }
    function getDurationMode() {
        var checked = calForm ? calForm.querySelector('[data-lp-cal-form-duration-mode]:checked') : null;
        return checked ? checked.value : 'none';
    }
    function setDurationMode(mode) {
        if (!calForm) {
            return;
        }
        calForm.querySelectorAll('[data-lp-cal-form-duration-mode]').forEach(function (r) {
            r.checked = (r.value === mode);
        });
        syncDurationModeUi(mode);
    }
    function addOneHourToTime(timeStr) {
        var m = /^(\d{1,2}):(\d{2})/.exec(String(timeStr || ''));
        if (!m) {
            return '10:00';
        }
        var h = parseInt(m[1], 10) + 1;
        var min = m[2];
        if (h > 23) {
            h = 23;
        }
        return String(h).padStart(2, '0') + ':' + min;
    }
    function ensureUntilDefaults() {
        if (!calForm) {
            return;
        }
        var dateEl = calForm.querySelector('[data-lp-cal-form-date]');
        var timeEl = calForm.querySelector('[data-lp-cal-form-time]');
        var endDateEl = calForm.querySelector('[data-lp-cal-form-end-date]');
        var endTimeEl = calForm.querySelector('[data-lp-cal-form-end-time]');
        if (endDateEl && !endDateEl.value && dateEl && dateEl.value) {
            endDateEl.value = dateEl.value;
        }
        if (endTimeEl && !endTimeEl.value) {
            endTimeEl.value = addOneHourToTime(timeEl ? timeEl.value : '09:00');
        }
    }
    function syncMinutesCustomUi(isCustom) {
        if (!calForm) {
            return;
        }
        var customWrap = calForm.querySelector('[data-lp-cal-form-minutes-custom]');
        if (!customWrap) {
            return;
        }
        if (isCustom) {
            customWrap.removeAttribute('hidden');
        } else {
            customWrap.setAttribute('hidden', 'hidden');
        }
    }
    function setMinutesSelectValue(minutes, preferCustom) {
        var root = calForm ? calForm.querySelector('[data-lp-select-minutes]') : null;
        if (!root) {
            return;
        }
        var presets = {15: 1, 30: 1, 45: 1, 60: 1, 90: 1, 120: 1};
        var n = parseInt(String(minutes || '0'), 10);
        if (isNaN(n) || n < 1) {
            n = 60;
        }
        var value = (preferCustom || !presets[n]) ? 'custom' : String(n);
        setLpSelectValue(root, value);
        var hidden = root.querySelector('[data-lp-select-input]');
        var customInput = calForm.querySelector('[data-lp-cal-form-duration-custom]');
        if (value === 'custom') {
            if (customInput) {
                customInput.value = String(n);
            }
            if (hidden) {
                hidden.value = String(n);
            }
            syncMinutesCustomUi(true);
        } else {
            if (hidden) {
                hidden.value = value;
            }
            syncMinutesCustomUi(false);
        }
    }
    function getDurationMinutes() {
        if (!calForm) {
            return 0;
        }
        var mode = getDurationMode();
        if (mode !== 'minutes') {
            return 0;
        }
        var customWrap = calForm.querySelector('[data-lp-cal-form-minutes-custom]');
        var customInput = calForm.querySelector('[data-lp-cal-form-duration-custom]');
        if (customWrap && !customWrap.hasAttribute('hidden') && customInput) {
            return parseInt(customInput.value || '0', 10) || 0;
        }
        var durEl = calForm.querySelector('[data-lp-cal-form-duration]');
        return parseInt(durEl ? durEl.value : '0', 10) || 0;
    }
    function syncDurationModeUi(mode) {
        if (!calForm) {
            return;
        }
        var untilWrap = calForm.querySelector('[data-lp-cal-form-until-wrap]');
        var minutesWrap = calForm.querySelector('[data-lp-cal-form-minutes-wrap]');
        if (untilWrap) {
            if (mode === 'until') {
                untilWrap.removeAttribute('hidden');
                ensureUntilDefaults();
            } else {
                untilWrap.setAttribute('hidden', 'hidden');
            }
        }
        if (minutesWrap) {
            if (mode === 'minutes') {
                minutesWrap.removeAttribute('hidden');
            } else {
                minutesWrap.setAttribute('hidden', 'hidden');
            }
        }
    }
    function syncRepeatHelp(scope) {
        if (!calForm) {
            return;
        }
        var helpCourse = calForm.querySelector('[data-lp-cal-form-repeat-help]');
        var helpSite = calForm.querySelector('[data-lp-cal-form-repeat-help-site]');
        var repeatEl = calForm.querySelector('[data-lp-cal-form-repeat]');
        var weekly = !!(repeatEl && repeatEl.checked);
        var wrapVisible = calForm.querySelector('[data-lp-cal-form-repeat-wrap]');
        var creating = wrapVisible && !wrapVisible.hasAttribute('hidden');
        if (helpCourse) {
            if (creating && weekly && scope === 'course') {
                helpCourse.removeAttribute('hidden');
            } else {
                helpCourse.setAttribute('hidden', 'hidden');
            }
        }
        if (helpSite) {
            if (creating && weekly && scope === 'site') {
                helpSite.removeAttribute('hidden');
            } else {
                helpSite.setAttribute('hidden', 'hidden');
            }
        }
    }
    function syncRepeatUi(isCreate) {
        if (!calForm) {
            return;
        }
        var wrap = calForm.querySelector('[data-lp-cal-form-repeat-wrap]');
        var repeatEl = calForm.querySelector('[data-lp-cal-form-repeat]');
        var countWrap = calForm.querySelector('[data-lp-cal-form-repeat-count-wrap]');
        if (!wrap) {
            return;
        }
        if (!isCreate) {
            wrap.setAttribute('hidden', 'hidden');
            syncRepeatHelp(getCalScope());
            return;
        }
        wrap.removeAttribute('hidden');
        var weekly = !!(repeatEl && repeatEl.checked);
        if (countWrap) {
            if (weekly) {
                countWrap.removeAttribute('hidden');
            } else {
                countWrap.setAttribute('hidden', 'hidden');
            }
        }
        syncRepeatHelp(getCalScope());
    }
    function getRepeatCount() {
        if (!calForm) {
            return 1;
        }
        var repeatEl = calForm.querySelector('[data-lp-cal-form-repeat]');
        if (!repeatEl || !repeatEl.checked) {
            return 1;
        }
        var countEl = calForm.querySelector('[data-lp-cal-form-repeats]');
        var n = countEl ? parseInt(countEl.value || '1', 10) : 1;
        if (isNaN(n) || n < 1) {
            return 1;
        }
        return Math.min(52, n);
    }
    function closeAllLpSelects(except) {
        shell.querySelectorAll('[data-lp-select]').forEach(function (root) {
            if (except && root === except) {
                return;
            }
            closeLpSelect(root);
        });
    }
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
    function openLpSelect(root) {
        if (!root) {
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
            if (input.tagName === 'SELECT') {
                input.value = String(value);
            } else if (String(value) !== 'custom') {
                input.value = String(value);
            }
        }
        if (root.hasAttribute('data-lp-select-minutes')) {
            syncMinutesCustomUi(String(value) === 'custom');
        }
    }
    function syncLpSelectFromInput(root) {
        if (!root) {
            return;
        }
        var input = root.querySelector('[data-lp-select-input]');
        if (!input) {
            return;
        }
        setLpSelectValue(root, input.value || '');
    }
    function initLpSelects() {
        shell.querySelectorAll('[data-lp-select]').forEach(function (root) {
            if (root.getAttribute('data-lp-select-ready') === '1') {
                syncLpSelectFromInput(root);
                return;
            }
            root.setAttribute('data-lp-select-ready', '1');
            var trigger = root.querySelector('[data-lp-select-trigger]');
            var menu = root.querySelector('[data-lp-select-menu]');
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
                    var value = opt.getAttribute('data-value') || '';
                    setLpSelectValue(root, value);
                    if (root.hasAttribute('data-lp-select-minutes') && value === 'custom' && calForm) {
                        var customInput = calForm.querySelector('[data-lp-cal-form-duration-custom]');
                        var hidden = root.querySelector('[data-lp-select-input]');
                        if (customInput && hidden) {
                            hidden.value = customInput.value || '60';
                        }
                    }
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
            syncLpSelectFromInput(root);
        });
    }

    // Profile + any shell selects (calendar form also calls init again safely).
    initLpSelects();
    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-lp-select]')) {
            return;
        }
        closeAllLpSelects(null);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') {
            return;
        }
        var openSelect = shell.querySelector('[data-lp-select].is-open');
        if (!openSelect) {
            return;
        }
        e.preventDefault();
        closeLpSelect(openSelect);
        var openTrigger = openSelect.querySelector('[data-lp-select-trigger]');
        if (openTrigger) {
            openTrigger.focus();
        }
    });

    function anyCalOverlayOpen() {
        return (calDetail && calDetail.classList.contains('is-open'))
            || (calForm && calForm.classList.contains('is-open'))
            || (calDayPanel && calDayPanel.classList.contains('is-open'));
    }
    function closeCalDetail() {
        if (!calDetail) {
            return;
        }
        calDetail.classList.remove('is-open');
        calDetail.setAttribute('hidden', 'hidden');
        if (!anyCalOverlayOpen()) {
            document.body.classList.remove('lp-cal-detail-lock');
        }
        calDetailSource = null;
    }
    function closeCalDayPanel() {
        if (!calDayPanel) {
            return;
        }
        calDayPanel.classList.remove('is-open');
        calDayPanel.setAttribute('hidden', 'hidden');
        var list = calDayPanel.querySelector('[data-lp-cal-daypanel-list]');
        if (list) {
            list.innerHTML = '';
        }
        if (!anyCalOverlayOpen()) {
            document.body.classList.remove('lp-cal-detail-lock');
        }
    }
    function openCalDayPanel(trigger) {
        if (!calDayPanel || !trigger) {
            return;
        }
        var day = trigger.closest('.lp-cal-day');
        if (!day) {
            return;
        }
        var source = day.querySelector('[data-lp-cal-day-source]');
        var list = calDayPanel.querySelector('[data-lp-cal-daypanel-list]');
        var title = calDayPanel.querySelector('[data-lp-cal-daypanel-title]');
        if (!source || !list) {
            return;
        }
        list.innerHTML = source.innerHTML;
        if (title) {
            var dayNum = day.querySelector('.lp-cal-daynum');
            title.textContent = trigger.getAttribute('data-day-label')
                || (dayNum ? dayNum.textContent : '')
                || '';
        }
        calDayPanel.classList.add('is-open');
        calDayPanel.removeAttribute('hidden');
        document.body.classList.add('lp-cal-detail-lock');
    }
    function closeCalForm() {
        if (!calForm) {
            return;
        }
        closeAllLpSelects(null);
        calForm.classList.remove('is-open');
        calForm.setAttribute('hidden', 'hidden');
        if (!anyCalOverlayOpen()) {
            document.body.classList.remove('lp-cal-detail-lock');
        }
        if (calFormEl) {
            calFormEl.reset();
        }
        var err = shell.querySelector('[data-lp-cal-form-error]');
        if (err) {
            err.textContent = '';
            err.setAttribute('hidden', 'hidden');
        }
    }
    function showCalFlash(msg) {
        if (!calFlash || !msg) {
            return;
        }
        calFlash.textContent = msg;
        calFlash.removeAttribute('hidden');
        window.setTimeout(function () {
            calFlash.setAttribute('hidden', 'hidden');
        }, 3200);
    }
    function openCalDetail(btn) {
        if (!calDetail || !btn) {
            return;
        }
        calDetailSource = btn;
        var title = calDetail.querySelector('[data-lp-cal-detail-title]');
        var when = calDetail.querySelector('[data-lp-cal-detail-when]');
        var course = calDetail.querySelector('[data-lp-cal-detail-course]');
        var desc = calDetail.querySelector('[data-lp-cal-detail-desc]');
        var moduleNote = calDetail.querySelector('[data-lp-cal-detail-module]');
        var locEl = calDetail.querySelector('[data-lp-cal-detail-location]');
        var htmlEl = calDetail.querySelector('[data-lp-cal-detail-html]');
        var editBtn = calDetail.querySelector('[data-lp-cal-detail-edit]');
        if (title) {
            title.textContent = btn.getAttribute('data-name') || '';
        }
        if (when) {
            var whenText = btn.getAttribute('data-when') || '';
            var whenEnd = btn.getAttribute('data-when-end') || '';
            when.textContent = whenEnd ? (whenText + ' – ' + whenEnd) : whenText;
        }
        if (course) {
            course.textContent = btn.getAttribute('data-course') || '';
        }
        if (locEl) {
            var loc = btn.getAttribute('data-location') || '';
            if (loc) {
                locEl.textContent = loc;
                locEl.removeAttribute('hidden');
            } else {
                locEl.textContent = '';
                locEl.setAttribute('hidden', 'hidden');
            }
        }
        if (htmlEl) {
            var rich = btn.getAttribute('data-description-html') || '';
            var plain = btn.getAttribute('data-description') || btn.getAttribute('data-desc') || '';
            if (rich) {
                htmlEl.innerHTML = rich;
                htmlEl.removeAttribute('hidden');
            } else if (plain) {
                htmlEl.textContent = plain;
                htmlEl.removeAttribute('hidden');
            } else {
                htmlEl.innerHTML = '';
                htmlEl.setAttribute('hidden', 'hidden');
            }
        }
        if (desc) {
            desc.setAttribute('hidden', 'hidden');
        }
        if (moduleNote) {
            if (btn.getAttribute('data-module') === '1') {
                moduleNote.removeAttribute('hidden');
            } else {
                moduleNote.setAttribute('hidden', 'hidden');
            }
        }
        if (editBtn) {
            if (btn.getAttribute('data-can-edit') === '1') {
                editBtn.removeAttribute('hidden');
            } else {
                editBtn.setAttribute('hidden', 'hidden');
            }
        }
        calDetail.classList.add('is-open');
        calDetail.removeAttribute('hidden');
        document.body.classList.add('lp-cal-detail-lock');
    }
    function openCalForm(mode, sourceBtn, presetDate) {
        if (!calForm || !calFormEl) {
            return;
        }
        closeCalDetail();
        closeCalDayPanel();
        var titleEl = calForm.querySelector('[data-lp-cal-form-title]');
        var idEl = calForm.querySelector('[data-lp-cal-form-id]');
        var nameEl = calForm.querySelector('[data-lp-cal-form-name]');
        var dateEl = calForm.querySelector('[data-lp-cal-form-date]');
        var timeEl = calForm.querySelector('[data-lp-cal-form-time]');
        var durEl = calForm.querySelector('[data-lp-cal-form-duration]');
        var descEl = calForm.querySelector('[data-lp-cal-form-description]');
        var delBtn = calForm.querySelector('[data-lp-cal-form-delete]');
        var err = calForm.querySelector('[data-lp-cal-form-error]');
        if (err) {
            err.textContent = '';
            err.setAttribute('hidden', 'hidden');
        }
        var courseEl = calForm.querySelector('[data-lp-cal-form-course]');
        var locEl = calForm.querySelector('[data-lp-cal-form-location]');
        var endDateEl = calForm.querySelector('[data-lp-cal-form-end-date]');
        var endTimeEl = calForm.querySelector('[data-lp-cal-form-end-time]');
        if (mode === 'edit' && sourceBtn) {
            if (titleEl) {
                titleEl.textContent = calStrings.editTitle;
            }
            if (idEl) {
                idEl.value = sourceBtn.getAttribute('data-id') || '';
            }
            if (nameEl) {
                var fromData = sourceBtn.getAttribute('data-name') || '';
                var fromBold = sourceBtn.querySelector('b');
                var fromText = fromBold
                    ? (fromBold.textContent || '')
                    : (sourceBtn.textContent || '');
                nameEl.value = fromData || fromText.replace(/\s+/g, ' ').trim();
            }
            if (dateEl) {
                dateEl.value = sourceBtn.getAttribute('data-date') || '';
            }
            if (timeEl) {
                timeEl.value = sourceBtn.getAttribute('data-time') || '';
            }
            if (endDateEl) {
                endDateEl.value = sourceBtn.getAttribute('data-end-date') || '';
            }
            if (endTimeEl) {
                endTimeEl.value = sourceBtn.getAttribute('data-end-time') || '';
            }
            if (locEl) {
                locEl.value = sourceBtn.getAttribute('data-location') || '';
            }
            if (descEl) {
                descEl.value = sourceBtn.getAttribute('data-description') || '';
            }
            var durMode = sourceBtn.getAttribute('data-duration-mode') || 'none';
            if (durMode !== 'until' && durMode !== 'minutes') {
                durMode = (parseInt(sourceBtn.getAttribute('data-duration') || '0', 10) > 0) ? 'until' : 'none';
            }
            setDurationMode(durMode);
            if (durMode === 'minutes') {
                setMinutesSelectValue(sourceBtn.getAttribute('data-duration') || '60', false);
            }
            var editScope = normalizeCalScope(sourceBtn.getAttribute('data-scope') || 'personal');
            setCalScope(editScope, true);
            if (courseEl && editScope === 'course') {
                courseEl.value = sourceBtn.getAttribute('data-courseid') || courseEl.value;
                courseEl.disabled = false;
                var courseSelect = calForm.querySelector('[data-lp-select-course]');
                if (courseSelect) {
                    setLpSelectValue(courseSelect, courseEl.value);
                }
            }
            if (delBtn) {
                delBtn.removeAttribute('hidden');
            }
            syncRepeatUi(false);
            var moreEl = calForm.querySelector('[data-lp-cal-form-more]');
            if (moreEl && ((locEl && locEl.value) || (descEl && descEl.value))) {
                moreEl.open = true;
            }
        } else {
            calFormEl.reset();
            if (titleEl) {
                titleEl.textContent = calStrings.addTitle;
            }
            if (idEl) {
                idEl.value = '';
            }
            if (dateEl) {
                if (presetDate && /^\d{4}-\d{2}-\d{2}$/.test(presetDate)) {
                    dateEl.value = presetDate;
                } else if (!dateEl.value) {
                    var now = new Date();
                    var m = String(now.getMonth() + 1).padStart(2, '0');
                    var d = String(now.getDate()).padStart(2, '0');
                    dateEl.value = now.getFullYear() + '-' + m + '-' + d;
                }
            }
            if (timeEl && !timeEl.value) {
                timeEl.value = '09:00';
            }
            if (endDateEl && presetDate && /^\d{4}-\d{2}-\d{2}$/.test(presetDate)) {
                endDateEl.value = presetDate;
            }
            setDurationMode('none');
            setMinutesSelectValue(60, false);
            var repeatEl = calForm.querySelector('[data-lp-cal-form-repeat]');
            if (repeatEl) {
                repeatEl.checked = false;
            }
            var repeatCount = calForm.querySelector('[data-lp-cal-form-repeats]');
            if (repeatCount) {
                repeatCount.value = '1';
            }
            syncRepeatUi(true);
            var moreCreate = calForm.querySelector('[data-lp-cal-form-more]');
            if (moreCreate) {
                moreCreate.open = false;
            }
            var defaultScope = (calRoot && calRoot.getAttribute('data-default-scope') === 'course')
                ? 'course'
                : 'personal';
            setCalScope(defaultScope, false);
            if (courseEl) {
                courseEl.disabled = false;
                var preferredCourse = calRoot
                    ? (calRoot.getAttribute('data-preferred-courseid') || '')
                    : '';
                if (preferredCourse && preferredCourse !== '0') {
                    var hasPreferred = false;
                    for (var pi = 0; pi < courseEl.options.length; pi++) {
                        if (courseEl.options[pi].value === preferredCourse) {
                            hasPreferred = true;
                            break;
                        }
                    }
                    if (hasPreferred) {
                        courseEl.value = preferredCourse;
                    }
                }
                var courseSelectCreate = calForm.querySelector('[data-lp-select-course]');
                if (courseSelectCreate) {
                    syncLpSelectFromInput(courseSelectCreate);
                }
            }
            if (delBtn) {
                delBtn.setAttribute('hidden', 'hidden');
            }
        }
        initLpSelects();
        closeAllLpSelects(null);
        calForm.classList.add('is-open');
        calForm.removeAttribute('hidden');
        document.body.classList.add('lp-cal-detail-lock');
        if (nameEl) {
            nameEl.focus();
        }
    }
    function refreshCalMonth() {
        var month = shell.querySelector('[data-lp-cal-month]');
        if (!month) {
            window.location.reload();
            return;
        }
        loadCalMonth(month.getAttribute('data-time'), null, false);
    }
    function postPersonal(payload) {
        if (!calRoot) {
            return Promise.reject(new Error('no root'));
        }
        var url = calRoot.getAttribute('data-personal-ajax');
        var sesskey = calRoot.getAttribute('data-sesskey');
        if (!url || !sesskey) {
            return Promise.reject(new Error('no ajax'));
        }
        var body = new URLSearchParams();
        Object.keys(payload).forEach(function (k) {
            body.set(k, payload[k] == null ? '' : String(payload[k]));
        });
        body.set('sesskey', sesskey);
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
            },
            body: body.toString()
        }).then(function (res) {
            return res.json().then(function (data) {
                if (!res.ok || !data || !data.ok) {
                    var err = (data && data.error) ? data.error : calStrings.network;
                    throw new Error(err);
                }
                return data;
            });
        });
    }
    function loadCalMonth(time, fallbackHref, pushHistory) {
        var month = shell.querySelector('[data-lp-cal-month]');
        if (!month || !time) {
            if (fallbackHref) {
                window.location.href = fallbackHref;
            }
            return;
        }
        var ajaxUrl = month.getAttribute('data-ajax-url');
        var sesskey = month.getAttribute('data-sesskey');
        if (!ajaxUrl || !sesskey) {
            if (fallbackHref) {
                window.location.href = fallbackHref;
            }
            return;
        }
        if (calMonthFetch && typeof calMonthFetch.abort === 'function') {
            calMonthFetch.abort();
        }
        var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        calMonthFetch = controller;
        month.classList.add('is-loading');
        month.setAttribute('aria-busy', 'true');
        var sep = ajaxUrl.indexOf('?') >= 0 ? '&' : '?';
        var reqUrl = ajaxUrl + sep + 'time=' + encodeURIComponent(time)
            + '&sesskey=' + encodeURIComponent(sesskey);
        fetch(reqUrl, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
            signal: controller ? controller.signal : undefined
        }).then(function (res) {
            if (!res.ok) {
                throw new Error('calendar month http ' + res.status);
            }
            return res.json();
        }).then(function (data) {
            if (!data || !data.html) {
                throw new Error('calendar month empty');
            }
            if (!month.parentNode) {
                throw new Error('calendar month host');
            }
            month.insertAdjacentHTML('afterend', data.html);
            month.remove();
            if (pushHistory !== false && data.pageurl) {
                history.pushState({ lpCalTime: String(data.time) }, '', data.pageurl);
            }
        }).catch(function (err) {
            if (err && err.name === 'AbortError') {
                return;
            }
            if (fallbackHref) {
                window.location.href = fallbackHref;
            }
        }).finally(function () {
            var next = shell.querySelector('[data-lp-cal-month]');
            if (next) {
                next.classList.remove('is-loading');
                next.removeAttribute('aria-busy');
            }
        });
    }
    if (calDetail) {
        calDetail.querySelectorAll('[data-lp-cal-detail-close]').forEach(function (el) {
            el.addEventListener('click', closeCalDetail);
        });
        var detailEdit = calDetail.querySelector('[data-lp-cal-detail-edit]');
        if (detailEdit) {
            detailEdit.addEventListener('click', function () {
                if (calDetailSource) {
                    openCalForm('edit', calDetailSource);
                }
            });
        }
    }
    if (calDayPanel) {
        calDayPanel.querySelectorAll('[data-lp-cal-daypanel-close]').forEach(function (el) {
            el.addEventListener('click', closeCalDayPanel);
        });
    }
    if (calForm) {
        calForm.querySelectorAll('[data-lp-cal-form-close]').forEach(function (el) {
            el.addEventListener('click', closeCalForm);
        });
        calForm.querySelectorAll('[data-lp-cal-form-scope]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                syncCalScopeUi(getCalScope());
            });
        });
        calForm.querySelectorAll('[data-lp-cal-form-duration-mode]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                syncDurationModeUi(getDurationMode());
            });
        });
        var repeatEl = calForm.querySelector('[data-lp-cal-form-repeat]');
        if (repeatEl) {
            repeatEl.addEventListener('change', function () {
                var idEl = calForm.querySelector('[data-lp-cal-form-id]');
                syncRepeatUi(!(idEl && idEl.value));
            });
        }
        var minutesCustomInput = calForm.querySelector('[data-lp-cal-form-duration-custom]');
        if (minutesCustomInput) {
            minutesCustomInput.addEventListener('input', function () {
                var hidden = calForm.querySelector('[data-lp-select-minutes] [data-lp-select-input]');
                if (hidden) {
                    hidden.value = minutesCustomInput.value || '0';
                }
            });
        }
        initLpSelects();
        document.addEventListener('click', function (e) {
            if (!calForm || !calForm.classList.contains('is-open')) {
                return;
            }
            if (e.target.closest('[data-lp-select]')) {
                return;
            }
            closeAllLpSelects(null);
        });
        document.addEventListener('keydown', function (e) {
            if (!calForm || !calForm.classList.contains('is-open')) {
                return;
            }
            if (e.key === 'Escape') {
                var openSelect = calForm.querySelector('[data-lp-select].is-open');
                if (openSelect) {
                    e.preventDefault();
                    closeLpSelect(openSelect);
                }
            }
        });
        var delBtn = calForm.querySelector('[data-lp-cal-form-delete]');
        if (delBtn) {
            delBtn.addEventListener('click', function () {
                var idEl = calForm.querySelector('[data-lp-cal-form-id]');
                var id = idEl ? idEl.value : '';
                if (!id) {
                    return;
                }
                if (!window.confirm(calStrings.deleteConfirm)) {
                    return;
                }
                delBtn.disabled = true;
                postPersonal({ action: 'delete', id: id, scope: getCalScope() }).then(function () {
                    closeCalForm();
                    showCalFlash(calStrings.deleted);
                    if (shell.querySelector('[data-lp-cal-month]')) {
                        refreshCalMonth();
                    } else {
                        window.location.reload();
                    }
                }).catch(function (err) {
                    var box = calForm.querySelector('[data-lp-cal-form-error]');
                    if (box) {
                        box.textContent = err.message || calStrings.network;
                        box.removeAttribute('hidden');
                    }
                }).finally(function () {
                    delBtn.disabled = false;
                });
            });
        }
        if (calFormEl) {
            calFormEl.addEventListener('submit', function (e) {
                e.preventDefault();
                var idEl = calForm.querySelector('[data-lp-cal-form-id]');
                var nameEl = calForm.querySelector('[data-lp-cal-form-name]');
                var dateEl = calForm.querySelector('[data-lp-cal-form-date]');
                var timeEl = calForm.querySelector('[data-lp-cal-form-time]');
                var durEl = calForm.querySelector('[data-lp-cal-form-duration]');
                var descEl = calForm.querySelector('[data-lp-cal-form-description]');
                var err = calForm.querySelector('[data-lp-cal-form-error]');
                var id = idEl ? idEl.value : '';
                var scope = getCalScope();
                var courseEl = calForm.querySelector('[data-lp-cal-form-course]');
                var courseid = courseEl ? courseEl.value : '0';
                var locField = calForm.querySelector('[data-lp-cal-form-location]');
                var endDateField = calForm.querySelector('[data-lp-cal-form-end-date]');
                var endTimeField = calForm.querySelector('[data-lp-cal-form-end-time]');
                var payload = {
                    action: id ? 'update' : 'create',
                    id: id || '0',
                    scope: scope,
                    courseid: courseid || '0',
                    name: nameEl ? nameEl.value : '',
                    date: dateEl ? dateEl.value : '',
                    time: timeEl ? timeEl.value : '',
                    duration_mode: getDurationMode(),
                    duration: String(getDurationMinutes()),
                    end_date: endDateField ? endDateField.value : '',
                    end_time: endTimeField ? endTimeField.value : '',
                    location: locField ? locField.value : '',
                    description: descEl ? descEl.value : '',
                    repeats: id ? '1' : String(getRepeatCount())
                };
                if (!String(payload.name || '').trim()) {
                    if (err) {
                        err.textContent = 'Enter a title for the event.';
                        err.removeAttribute('hidden');
                    }
                    if (nameEl) {
                        nameEl.focus();
                    }
                    return;
                }
                if (scope === 'course' && (!courseid || courseid === '0')) {
                    if (err) {
                        err.textContent = calStrings.courseRequired;
                        err.removeAttribute('hidden');
                    }
                    return;
                }
                var saveBtn = calForm.querySelector('[data-lp-cal-form-save]');
                if (saveBtn) {
                    saveBtn.disabled = true;
                }
                if (err) {
                    err.setAttribute('hidden', 'hidden');
                }
                postPersonal(payload).then(function () {
                    closeCalForm();
                    showCalFlash(calStrings.saved);
                    if (shell.querySelector('[data-lp-cal-month]')) {
                        refreshCalMonth();
                    } else {
                        window.location.reload();
                    }
                }).catch(function (ex) {
                    if (err) {
                        err.textContent = ex.message || calStrings.network;
                        err.removeAttribute('hidden');
                    }
                }).finally(function () {
                    if (saveBtn) {
                        saveBtn.disabled = false;
                    }
                });
            });
        }
    }
    shell.addEventListener('change', function (e) {
        var filter = e.target.closest('[data-lp-cal-filter], [data-lp-cal-track-filter]');
        if (filter) {
            var opt = filter.options[filter.selectedIndex];
            var url = opt ? opt.getAttribute('data-url') : '';
            if (url) {
                window.location.href = url;
            }
            return;
        }
        var orient = e.target.closest('[data-lp-cal-orient-toggle]');
        if (orient) {
            var orientUrl = orient.checked
                ? orient.getAttribute('data-url-on')
                : orient.getAttribute('data-url-off');
            if (orientUrl) {
                window.location.href = orientUrl;
            }
        }
    });
    shell.addEventListener('click', function (e) {
        var nav = e.target.closest('[data-lp-cal-nav]');
        if (nav) {
            e.preventDefault();
            loadCalMonth(nav.getAttribute('data-time'), nav.getAttribute('href'), true);
            return;
        }
        if (e.target.closest('[data-lp-cal-add]')) {
            openCalForm('create', null);
            return;
        }
        var dayAdd = e.target.closest('[data-lp-cal-day-add]');
        if (dayAdd) {
            if (calDragMoved) {
                calDragMoved = false;
                return;
            }
            openCalForm('create', null, dayAdd.getAttribute('data-day-key') || '');
            return;
        }
        var emptyDay = e.target.closest('.lp-cal-day.is-addable');
        if (emptyDay && !e.target.closest('[data-lp-cal-open],[data-lp-cal-day-open],[data-lp-cal-day-add]')) {
            if (calDragMoved) {
                calDragMoved = false;
                return;
            }
            openCalForm('create', null, emptyDay.getAttribute('data-day-key') || '');
            return;
        }
        var dayOpen = e.target.closest('[data-lp-cal-day-open]');
        if (dayOpen) {
            openCalDayPanel(dayOpen);
            return;
        }
        var moreUpcoming = e.target.closest('[data-lp-cal-upcoming-more]');
        if (moreUpcoming) {
            var list = shell.querySelector('[data-lp-cal-upcoming-list]');
            if (!list) {
                return;
            }
            var pageSize = parseInt(list.getAttribute('data-page-size') || '25', 10) || 25;
            var hidden = list.querySelectorAll('li.is-paged-out[hidden]');
            var show = Math.min(pageSize, hidden.length);
            for (var i = 0; i < show; i++) {
                hidden[i].removeAttribute('hidden');
                hidden[i].classList.remove('is-paged-out');
            }
            var left = list.querySelectorAll('li.is-paged-out[hidden]').length;
            if (left === 0) {
                moreUpcoming.setAttribute('hidden', 'hidden');
            } else {
                moreUpcoming.setAttribute('data-remaining', String(left));
                moreUpcoming.textContent = 'Show more (' + left + ')';
            }
            return;
        }
        var openBtn = e.target.closest('[data-lp-cal-open]');
        if (openBtn) {
            if (calDragMoved) {
                calDragMoved = false;
                return;
            }
            openCalDetail(openBtn);
        }
    });
    shell.addEventListener('dragstart', function (e) {
        var chip = e.target.closest('[data-lp-cal-open][draggable="true"]');
        if (!chip) {
            return;
        }
        calDragId = chip.getAttribute('data-id') || '';
        calDragMoved = false;
        if (e.dataTransfer) {
            e.dataTransfer.setData('text/plain', calDragId);
            e.dataTransfer.effectAllowed = 'move';
        }
    });
    shell.addEventListener('dragend', function () {
        calDragId = null;
        shell.querySelectorAll('[data-lp-cal-drop].is-drop-target').forEach(function (el) {
            el.classList.remove('is-drop-target');
        });
        window.setTimeout(function () {
            calDragMoved = false;
        }, 0);
    });
    shell.addEventListener('dragover', function (e) {
        var drop = e.target.closest('[data-lp-cal-drop]');
        if (!drop || !calDragId) {
            return;
        }
        e.preventDefault();
        drop.classList.add('is-drop-target');
    });
    shell.addEventListener('dragleave', function (e) {
        var drop = e.target.closest('[data-lp-cal-drop]');
        if (drop) {
            drop.classList.remove('is-drop-target');
        }
    });
    shell.addEventListener('drop', function (e) {
        var drop = e.target.closest('[data-lp-cal-drop]');
        if (!drop) {
            return;
        }
        e.preventDefault();
        drop.classList.remove('is-drop-target');
        var dayKey = drop.getAttribute('data-day-key');
        var eventId = calDragId || (e.dataTransfer ? e.dataTransfer.getData('text/plain') : '');
        calDragId = null;
        calDragMoved = true;
        if (!dayKey || !eventId || !calRoot) {
            return;
        }
        var url = calRoot.getAttribute('data-reschedule-ajax');
        var sesskey = calRoot.getAttribute('data-sesskey');
        if (!url || !sesskey) {
            return;
        }
        var body = new URLSearchParams();
        body.set('id', eventId);
        body.set('day_key', dayKey);
        body.set('sesskey', sesskey);
        fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
            },
            body: body.toString()
        }).then(function (res) {
            return res.json();
        }).then(function (data) {
            if (!data || !data.ok) {
                throw new Error((data && data.error) || calStrings.network);
            }
            showCalFlash(calStrings.saved);
            refreshCalMonth();
        }).catch(function (err) {
            showCalFlash(err.message || calStrings.network);
        });
    });
    var initialMonth = shell.querySelector('[data-lp-cal-month]');
    if (initialMonth && initialMonth.getAttribute('data-time')) {
        history.replaceState(
            { lpCalTime: String(initialMonth.getAttribute('data-time')) },
            '',
            window.location.href
        );
    }
    window.addEventListener('popstate', function (e) {
        if (!shell.querySelector('[data-lp-cal-month]')) {
            return;
        }
        var t = e.state && e.state.lpCalTime ? e.state.lpCalTime : null;
        if (!t) {
            return;
        }
        loadCalMonth(t, null, false);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            if (calForm && calForm.classList.contains('is-open')) {
                closeCalForm();
                return;
            }
            if (calDetail && calDetail.classList.contains('is-open')) {
                closeCalDetail();
                return;
            }
            if (calDayPanel && calDayPanel.classList.contains('is-open')) {
                closeCalDayPanel();
                return;
            }
            setOpen(false);
        }
    });

    // Animate journey path + completion ring (respect reduced motion).
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

    // Sidebar XP bar + quest / weight bars (Employer home parity).
    if (!reduce) {
        shell.querySelectorAll('.lp-side-xpbar i, .lp-bar i, .lp-weight-row .lp-bar i').forEach(function (bar) {
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

    // Dot Dot orbit (bottom-right companion).
    var orbit = document.getElementById('lp-orbit');
    var orbitHide = document.querySelector('[data-lp-orbit-hide]');
    var orbitSay = document.getElementById('lp-orbit-say');
    var STORAGE_KEY = 'lp-orbit-hidden';
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
        // Show label from a sibling data attr if present; fallback English matched to lang default.
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
                orbit.classList.remove('is-hop');
                void orbit.offsetWidth;
                orbit.classList.add('is-hop');
                if (orbitSay) {
                    orbitSay.classList.remove('is-hide');
                }
            });
        }
        var dismiss = orbit.querySelector('[data-lp-orbit-dismiss]');
        if (dismiss && orbitSay) {
            dismiss.addEventListener('click', function (e) {
                e.stopPropagation();
                orbitSay.classList.add('is-hide');
            });
        }
    }
    // LaunchPad messages: AJAX send/delete, select mode, emoji picker.
    (function initMessages() {
        var layout = document.querySelector('[data-lp-msg-root]');
        if (!layout) {
            return;
        }

        var msgScroll = layout.querySelector('[data-lp-msg-scroll]');
        var bubbles = layout.querySelector('[data-lp-msg-bubbles]');
        var compose = layout.querySelector('[data-lp-msg-compose]');
        var deleteForm = layout.querySelector('[data-lp-msg-delete-form]');
        var bar = layout.querySelector('[data-lp-msg-select-bar]');
        var hint = layout.querySelector('[data-lp-msg-select-hint]');
        var ajaxUrl = layout.getAttribute('data-ajax-url') || '';
        var sesskey = layout.getAttribute('data-sesskey') || '';
        var portal = layout.getAttribute('data-portal') || 'fellow';
        var otherId = layout.getAttribute('data-other-id') || '';
        var canDelete = layout.getAttribute('data-can-delete') === '1';

        if (msgScroll) {
            msgScroll.scrollTop = msgScroll.scrollHeight;
        }

        function postAction(action, fields) {
            if (!ajaxUrl || !sesskey || !otherId) {
                return Promise.reject(new Error('no ajax'));
            }
            var body = new URLSearchParams();
            body.set('sesskey', sesskey);
            body.set('portal', portal);
            body.set('id', otherId);
            body.set('action', action);
            Object.keys(fields || {}).forEach(function (key) {
                var val = fields[key];
                if (Array.isArray(val)) {
                    val.forEach(function (item) {
                        body.append(key + '[]', String(item));
                    });
                } else if (val !== undefined && val !== null) {
                    body.set(key, String(val));
                }
            });
            return fetch(ajaxUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin',
                body: body.toString()
            }).then(function (res) {
                return res.json();
            });
        }

        function scrollThread() {
            if (msgScroll) {
                msgScroll.scrollTop = msgScroll.scrollHeight;
            }
        }

        function buildBubble(msg) {
            var el = document.createElement('article');
            var selectable = !!(msg.can_delete && canDelete);
            el.className = 'lp-msg-bubble is-mine' + (selectable ? ' is-selectable' : '');
            el.setAttribute('data-lp-msg-bubble', '');
            el.setAttribute('data-id', String(msg.id || ''));
            if (selectable) {
                el.setAttribute('tabindex', '0');
                el.setAttribute('role', 'button');
                el.setAttribute('aria-pressed', 'false');
                var check = document.createElement('span');
                check.className = 'lp-msg-check';
                check.setAttribute('aria-hidden', 'true');
                el.appendChild(check);
            }
            var text = document.createElement('div');
            text.className = 'lp-msg-bubble-text';
            text.textContent = msg.text || '';
            el.appendChild(text);
            if (msg.when) {
                var when = document.createElement('time');
                when.className = 'lp-msg-bubble-when';
                when.textContent = msg.when;
                el.appendChild(when);
            }
            return el;
        }

        // --- select + delete ---
        var selected = Object.create(null);
        var selecting = false;
        var idsHost = deleteForm ? deleteForm.querySelector('[data-lp-msg-delete-ids]') : null;
        var countEl = bar ? bar.querySelector('[data-lp-msg-select-count]') : null;
        var deleteBtn = bar ? bar.querySelector('[data-lp-msg-select-delete]') : null;
        var cancelBtn = bar ? bar.querySelector('[data-lp-msg-select-cancel]') : null;
        var confirmModal = layout.querySelector('[data-lp-msg-confirm]');
        var confirmOk = confirmModal ? confirmModal.querySelector('[data-lp-msg-confirm-ok]') : null;
        var countTpl = bar ? (bar.getAttribute('data-count-tpl') || '__N__ selected') : '__N__ selected';
        var deleting = false;

        function selectedIds() {
            return Object.keys(selected).filter(function (id) {
                return selected[id];
            });
        }

        function setConfirmOpen(open) {
            if (!confirmModal) {
                return;
            }
            confirmModal.hidden = !open;
            confirmModal.classList.toggle('is-open', open);
            if (open && confirmOk) {
                confirmOk.focus();
            }
        }

        function syncSelectUi() {
            if (!bubbles || !bar || !deleteBtn || !idsHost) {
                return;
            }
            var ids = selectedIds();
            var n = ids.length;
            bar.hidden = !selecting;
            if (hint) {
                hint.hidden = selecting;
            }
            if (compose) {
                compose.hidden = selecting;
            }
            bubbles.classList.toggle('is-selecting', selecting);
            if (countEl) {
                countEl.textContent = countTpl.replace(/__N__/g, String(n));
            }
            deleteBtn.disabled = n === 0 || deleting;
            idsHost.innerHTML = '';
            ids.forEach(function (id) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'messageids[]';
                input.value = id;
                idsHost.appendChild(input);
            });
            bubbles.querySelectorAll('[data-lp-msg-bubble].is-selectable').forEach(function (bubble) {
                var id = bubble.getAttribute('data-id');
                var on = !!(id && selected[id]);
                bubble.classList.toggle('is-selected', on);
                bubble.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
        }

        function clearSelection() {
            selected = Object.create(null);
            selecting = false;
            setConfirmOpen(false);
            syncSelectUi();
        }

        function toggleBubble(bubble) {
            if (!bubble || !bubble.classList.contains('is-selectable')) {
                return;
            }
            var id = bubble.getAttribute('data-id');
            if (!id) {
                return;
            }
            if (!selecting) {
                selecting = true;
                selected[id] = true;
                syncSelectUi();
                return;
            }
            if (selected[id]) {
                delete selected[id];
            } else {
                selected[id] = true;
            }
            syncSelectUi();
        }

        function runDelete() {
            var ids = selectedIds();
            if (ids.length === 0 || deleting) {
                return;
            }
            setConfirmOpen(false);
            if (!ajaxUrl) {
                if (deleteForm) {
                    deleteForm.submit();
                }
                return;
            }
            deleting = true;
            if (deleteBtn) {
                deleteBtn.disabled = true;
            }
            if (confirmOk) {
                confirmOk.disabled = true;
            }
            postAction('delete', {messageids: ids}).then(function (data) {
                deleting = false;
                if (confirmOk) {
                    confirmOk.disabled = false;
                }
                if (!data || !data.ok) {
                    if (deleteBtn) {
                        deleteBtn.disabled = false;
                    }
                    window.alert((data && data.error) || 'Could not delete');
                    return;
                }
                (data.deleted_ids || ids).forEach(function (id) {
                    var node = bubbles.querySelector('[data-lp-msg-bubble][data-id="' + id + '"]');
                    if (node) {
                        node.remove();
                    }
                });
                if (!bubbles.querySelector('[data-lp-msg-bubble]')) {
                    var empty = document.createElement('p');
                    empty.className = 'lp-sm lp-muted lp-msg-empty';
                    empty.textContent = 'No messages yet. Say hello below.';
                    bubbles.appendChild(empty);
                }
                clearSelection();
            }).catch(function () {
                deleting = false;
                if (confirmOk) {
                    confirmOk.disabled = false;
                }
                if (deleteBtn) {
                    deleteBtn.disabled = false;
                }
                window.alert('Could not delete');
            });
        }

        if (bubbles && bar && deleteForm && idsHost && deleteBtn) {
            bubbles.addEventListener('click', function (e) {
                var bubble = e.target.closest('[data-lp-msg-bubble].is-selectable');
                if (!bubble || !bubbles.contains(bubble)) {
                    return;
                }
                e.preventDefault();
                toggleBubble(bubble);
            });
            bubbles.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter' && e.key !== ' ') {
                    return;
                }
                var bubble = e.target.closest('[data-lp-msg-bubble].is-selectable');
                if (!bubble || !bubbles.contains(bubble)) {
                    return;
                }
                e.preventDefault();
                toggleBubble(bubble);
            });
            if (cancelBtn) {
                cancelBtn.addEventListener('click', clearSelection);
            }
            deleteBtn.addEventListener('click', function () {
                if (selectedIds().length === 0 || deleting) {
                    return;
                }
                setConfirmOpen(true);
            });
            if (confirmModal) {
                confirmModal.querySelectorAll('[data-lp-msg-confirm-cancel]').forEach(function (el) {
                    el.addEventListener('click', function () {
                        setConfirmOpen(false);
                    });
                });
                if (confirmOk) {
                    confirmOk.addEventListener('click', runDelete);
                }
                document.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape' && confirmModal.classList.contains('is-open')) {
                        setConfirmOpen(false);
                    }
                });
            }
            syncSelectUi();
        }

        // --- send (AJAX) ---
        if (compose) {
            compose.addEventListener('submit', function (e) {
                if (!ajaxUrl) {
                    return;
                }
                e.preventDefault();
                var bodyEl = compose.querySelector('[data-lp-msg-body]');
                var sendBtn = compose.querySelector('[data-lp-msg-send]');
                var text = bodyEl ? String(bodyEl.value || '').trim() : '';
                if (!text) {
                    return;
                }
                if (sendBtn) {
                    sendBtn.disabled = true;
                }
                postAction('send', {message: text}).then(function (data) {
                    if (sendBtn) {
                        sendBtn.disabled = false;
                    }
                    if (!data || !data.ok || !data.message) {
                        window.alert((data && data.error) || 'Could not send');
                        return;
                    }
                    if (bodyEl) {
                        bodyEl.value = '';
                    }
                    var empty = bubbles ? bubbles.querySelector('.lp-msg-empty') : null;
                    if (empty) {
                        empty.remove();
                    }
                    if (bubbles) {
                        bubbles.appendChild(buildBubble(data.message));
                    }
                    scrollThread();
                }).catch(function () {
                    if (sendBtn) {
                        sendBtn.disabled = false;
                    }
                    window.alert('Could not send');
                });
            });
        }

        // --- emoji picker (lightweight, LaunchPad-styled) ---
        var emojiToggle = layout.querySelector('[data-lp-msg-emoji-toggle]');
        var emojiPanel = layout.querySelector('[data-lp-msg-emoji-panel]');
        var bodyField = layout.querySelector('[data-lp-msg-body]');
        if (emojiToggle && emojiPanel && bodyField) {
            var EMOJIS = [
                '😀', '😁', '😂', '🤣', '😊', '😍', '🤩', '😘', '😜', '🤔',
                '😎', '🙌', '👍', '👎', '👏', '🙏', '💪', '🔥', '✨', '🎉',
                '❤️', '💙', '💚', '💛', '💜', '🖤', '💯', '✅', '❌', '⭐',
                '😢', '😭', '😤', '😴', '🤗', '🤝', '👋', '👀', '💡', '📌'
            ];
            EMOJIS.forEach(function (emoji) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'lp-msg-emoji-item';
                btn.textContent = emoji;
                btn.setAttribute('aria-label', emoji);
                btn.addEventListener('click', function () {
                    var start = bodyField.selectionStart || 0;
                    var end = bodyField.selectionEnd || 0;
                    var val = bodyField.value || '';
                    bodyField.value = val.slice(0, start) + emoji + val.slice(end);
                    var pos = start + emoji.length;
                    bodyField.focus();
                    bodyField.setSelectionRange(pos, pos);
                });
                emojiPanel.appendChild(btn);
            });

            function setEmojiOpen(open) {
                emojiPanel.hidden = !open;
                emojiToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            }

            emojiToggle.addEventListener('click', function (e) {
                e.preventDefault();
                setEmojiOpen(emojiPanel.hidden);
            });

            document.addEventListener('click', function (e) {
                if (emojiPanel.hidden) {
                    return;
                }
                if (e.target.closest('[data-lp-msg-emoji-panel]') || e.target.closest('[data-lp-msg-emoji-toggle]')) {
                    return;
                }
                setEmojiOpen(false);
            });
        }
    })();

    // Admin People tabs: filter in-place (no full-page reload).
    (function peopleTabs() {
        var root = shell.querySelector('[data-lp-people]');
        if (!root) {
            return;
        }
        var tabs = root.querySelectorAll('[data-lp-people-tab]');
        var rows = root.querySelectorAll('[data-lp-people-role]');
        var emptyEl = root.querySelector('[data-lp-people-empty]');
        var table = root.querySelector('[data-lp-people-table]');
        if (!tabs.length) {
            return;
        }

        function applyTab(tab, pushUrl) {
            var key = tab || 'all';
            if (key !== 'all' && key !== 'fellow' && key !== 'mentor' && key !== 'admin') {
                key = 'all';
            }
            root.setAttribute('data-lp-people-active', key);

            var visible = 0;
            rows.forEach(function (row) {
                var show = key === 'all' || row.getAttribute('data-lp-people-role') === key;
                row.hidden = !show;
                if (show) {
                    visible += 1;
                }
            });

            tabs.forEach(function (el) {
                var on = el.getAttribute('data-lp-people-tab') === key;
                el.classList.toggle('is-on', on);
                el.setAttribute('aria-selected', on ? 'true' : 'false');
            });

            if (emptyEl) {
                emptyEl.style.display = visible === 0 ? '' : 'none';
            }
            if (table) {
                table.style.display = visible === 0 ? 'none' : '';
            }

            if (pushUrl && window.history && window.history.replaceState) {
                try {
                    var url = new URL(window.location.href);
                    url.searchParams.set('view', 'people');
                    if (key === 'all') {
                        url.searchParams.delete('tab');
                    } else {
                        url.searchParams.set('tab', key);
                    }
                    window.history.replaceState({}, '', url.pathname + url.search + url.hash);
                } catch (err) {
                    // Ignore URL sync failures (older browsers / file URLs).
                }
            }
        }

        root.addEventListener('click', function (e) {
            var tab = e.target.closest('[data-lp-people-tab]');
            if (!tab || !root.contains(tab)) {
                return;
            }
            e.preventDefault();
            applyTab(tab.getAttribute('data-lp-people-tab'), true);
        });

        applyTab(root.getAttribute('data-lp-people-active') || 'all', false);
    })();

    // Admin Course builder: switch tracks in-place (no full-page reload).
    (function builderTabs() {
        var root = shell.querySelector('[data-lp-builder]');
        if (!root) {
            return;
        }
        var tabs = root.querySelectorAll('[data-lp-builder-tab]');
        var panels = root.querySelectorAll('[data-lp-builder-panel]');
        if (!tabs.length || !panels.length) {
            return;
        }

        function applyTrack(trackId, pushUrl) {
            var id = String(trackId || '');
            if (!id) {
                return;
            }
            root.setAttribute('data-lp-builder-active', id);

            tabs.forEach(function (el) {
                var on = el.getAttribute('data-lp-builder-tab') === id;
                el.classList.toggle('is-on', on);
                el.setAttribute('aria-selected', on ? 'true' : 'false');
            });

            panels.forEach(function (panel) {
                var on = panel.getAttribute('data-lp-builder-panel') === id;
                panel.hidden = !on;
            });

            if (pushUrl && window.history && window.history.replaceState) {
                try {
                    var url = new URL(window.location.href);
                    url.searchParams.set('view', 'builder');
                    url.searchParams.set('trackid', id);
                    window.history.replaceState({}, '', url.pathname + url.search + url.hash);
                } catch (err) {
                    // Ignore URL sync failures.
                }
            }
        }

        root.addEventListener('click', function (e) {
            var tab = e.target.closest('[data-lp-builder-tab]');
            if (!tab || !root.contains(tab)) {
                return;
            }
            e.preventDefault();
            applyTrack(tab.getAttribute('data-lp-builder-tab'), true);
        });

        applyTrack(root.getAttribute('data-lp-builder-active') || tabs[0].getAttribute('data-lp-builder-tab'), false);
    })();

    // Admin phase manage: Edit mode toggle + add chooser + drag reorder (Save/Cancel).
    (function manageEditMode() {
        var root = shell.querySelector('[data-lp-manage]');
        if (!root) {
            return;
        }
        var toggle = root.querySelector('[data-lp-manage-edit-toggle]');
        var chooser = root.querySelector('[data-lp-manage-chooser]');
        var addOpen = root.querySelector('[data-lp-manage-add-open]');
        var editOnly = root.querySelectorAll('.lp-manage-edit-only');
        var actlist = root.querySelector('[data-lp-manage-actlist]');
        var reorderForm = root.querySelector('[data-lp-manage-reorder]');
        var reorderInputs = reorderForm ? reorderForm.querySelector('[data-lp-reorder-inputs]') : null;
        var reorderCsv = reorderForm ? reorderForm.querySelector('[data-lp-reorder-csv]') : null;
        var saveBtn = root.querySelector('[data-lp-reorder-save]');
        var cancelBtn = root.querySelector('[data-lp-reorder-cancel]');
        var dragCmid = 0;
        var dropRow = null;
        var dropAfter = false;
        var savedOrder = [];

        function rowByCmid(cmid) {
            return actlist ? actlist.querySelector('[data-lp-manage-row][data-cmid="' + cmid + '"]') : null;
        }

        function currentOrder() {
            if (!actlist) {
                return [];
            }
            return Array.prototype.map.call(actlist.querySelectorAll('[data-lp-manage-row]'), function (row) {
                return row.getAttribute('data-cmid') || '';
            }).filter(Boolean);
        }

        function writeOrderFields(order) {
            if (reorderCsv) {
                reorderCsv.value = order.join(',');
            }
            if (!reorderInputs) {
                return;
            }
            reorderInputs.innerHTML = '';
            order.forEach(function (cmid) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'cmorder[]';
                input.value = cmid;
                reorderInputs.appendChild(input);
            });
        }

        function ordersEqual(a, b) {
            if (a.length !== b.length) {
                return false;
            }
            for (var i = 0; i < a.length; i++) {
                if (String(a[i]) !== String(b[i])) {
                    return false;
                }
            }
            return true;
        }

        function setDirty(dirty) {
            root.classList.toggle('is-reorder-dirty', dirty);
            if (saveBtn) {
                saveBtn.disabled = !dirty;
            }
            if (cancelBtn) {
                cancelBtn.disabled = !dirty;
            }
        }

        function syncDirty() {
            var order = currentOrder();
            writeOrderFields(order);
            setDirty(!ordersEqual(order, savedOrder));
        }

        function restoreOrder() {
            if (!actlist || !savedOrder.length) {
                return;
            }
            savedOrder.forEach(function (cmid) {
                var row = rowByCmid(cmid);
                if (row) {
                    actlist.appendChild(row);
                }
            });
            setDirty(false);
        }

        function clearDropMarks() {
            if (!actlist) {
                return;
            }
            actlist.querySelectorAll('.lp-actrow.is-drop-before, .lp-actrow.is-drop-after').forEach(function (row) {
                row.classList.remove('is-drop-before', 'is-drop-after');
            });
            dropRow = null;
            dropAfter = false;
        }

        function setEdit(on) {
            if (!on && root.classList.contains('is-reorder-dirty')) {
                restoreOrder();
            }
            root.classList.toggle('is-editing', on);
            if (toggle) {
                toggle.setAttribute('aria-pressed', on ? 'true' : 'false');
                toggle.classList.toggle('lp-btn-solid', on);
                toggle.classList.toggle('lp-btn-light', !on);
                var onLabel = toggle.getAttribute('data-label-on');
                var offLabel = toggle.getAttribute('data-label-off');
                if (onLabel && offLabel) {
                    toggle.textContent = on ? onLabel : offLabel;
                }
            }
            editOnly.forEach(function (el) {
                el.hidden = !on;
            });
            if (!on && chooser) {
                chooser.hidden = true;
            }
            if (!on) {
                clearDropMarks();
                dragCmid = 0;
            } else {
                savedOrder = currentOrder();
                setDirty(false);
            }
        }

        if (toggle) {
            toggle.addEventListener('click', function () {
                setEdit(!root.classList.contains('is-editing'));
            });
        }
        if (addOpen && chooser) {
            addOpen.addEventListener('click', function () {
                chooser.hidden = !chooser.hidden;
            });
        }
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                restoreOrder();
                clearDropMarks();
            });
        }
        if (reorderForm) {
            reorderForm.addEventListener('submit', function (e) {
                if (!root.classList.contains('is-reorder-dirty')) {
                    e.preventDefault();
                    return;
                }
                writeOrderFields(currentOrder());
            });
        }

        if (actlist) {
            actlist.addEventListener('dragstart', function (e) {
                if (!root.classList.contains('is-editing')) {
                    return;
                }
                var handle = e.target.closest('[data-lp-manage-drag]');
                if (!handle) {
                    e.preventDefault();
                    return;
                }
                var row = handle.closest('[data-lp-manage-row]');
                if (!row) {
                    e.preventDefault();
                    return;
                }
                dragCmid = parseInt(row.getAttribute('data-cmid') || '0', 10) || 0;
                row.classList.add('is-dragging');
                if (e.dataTransfer) {
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', String(dragCmid));
                    try {
                        e.dataTransfer.setDragImage(row, 16, 16);
                    } catch (err) {
                        // Some browsers reject setDragImage; ignore.
                    }
                }
            });

            actlist.addEventListener('dragend', function () {
                var dragging = actlist.querySelector('.lp-actrow.is-dragging');
                if (dragging) {
                    dragging.classList.remove('is-dragging');
                }
                clearDropMarks();
                dragCmid = 0;
            });

            actlist.addEventListener('dragover', function (e) {
                if (!dragCmid || !root.classList.contains('is-editing')) {
                    return;
                }
                var row = e.target.closest('[data-lp-manage-row]');
                if (!row || parseInt(row.getAttribute('data-cmid') || '0', 10) === dragCmid) {
                    return;
                }
                e.preventDefault();
                if (e.dataTransfer) {
                    e.dataTransfer.dropEffect = 'move';
                }
                var rect = row.getBoundingClientRect();
                var after = (e.clientY - rect.top) > (rect.height / 2);
                clearDropMarks();
                row.classList.add(after ? 'is-drop-after' : 'is-drop-before');
                dropRow = row;
                dropAfter = after;
            });

            actlist.addEventListener('dragleave', function (e) {
                var row = e.target.closest('[data-lp-manage-row]');
                if (!row || row.contains(e.relatedTarget)) {
                    return;
                }
                row.classList.remove('is-drop-before', 'is-drop-after');
            });

            actlist.addEventListener('drop', function (e) {
                if (!dragCmid || !root.classList.contains('is-editing')) {
                    return;
                }
                e.preventDefault();
                var target = dropRow || e.target.closest('[data-lp-manage-row]');
                var after = dropRow ? dropAfter : false;
                clearDropMarks();
                var dragged = rowByCmid(dragCmid);
                if (!target || !dragged || target === dragged) {
                    return;
                }
                if (after) {
                    if (target.nextSibling) {
                        actlist.insertBefore(dragged, target.nextSibling);
                    } else {
                        actlist.appendChild(dragged);
                    }
                } else {
                    actlist.insertBefore(dragged, target);
                }
                syncDirty();
            });
        }

        savedOrder = currentOrder();
        setEdit(false);
    })();

    // Confirm before deleting a whole activity (Manage + activity settings).
    document.querySelectorAll('form[data-lp-delete-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            var msg = form.getAttribute('data-lp-delete-confirm') || '';
            if (msg && !window.confirm(msg)) {
                e.preventDefault();
            }
        });
    });

    // Admin File settings: pending picks with ×, mark attached files for removal on Save.
    document.querySelectorAll('[data-lp-file-picker]').forEach(function (root) {
        var input = root.querySelector('[data-lp-file-input]');
        var status = root.querySelector('[data-lp-file-status]');
        var pendingWrap = root.querySelector('[data-lp-pending-wrap]');
        var pendingList = root.querySelector('[data-lp-pending-list]');
        var removeBox = root.querySelector('[data-lp-remove-inputs]');
        var removeName = root.getAttribute('data-lp-remove-name') || 'removeresourcefiles[]';
        var removeAria = root.getAttribute('data-lp-remove-label')
            || (root.querySelector('[data-lp-attached-remove]')
                ? (root.querySelector('[data-lp-attached-remove]').getAttribute('aria-label') || 'Remove file')
                : 'Remove file');
        if (!input || !status) {
            return;
        }
        var emptyLabel = status.textContent;

        function syncRemoveInputs() {
            if (!removeBox) {
                return;
            }
            removeBox.innerHTML = '';
            root.querySelectorAll('[data-lp-attached-card].is-removing').forEach(function (card) {
                var name = card.getAttribute('data-filename') || '';
                if (!name) {
                    return;
                }
                var hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = removeName;
                hidden.value = name;
                removeBox.appendChild(hidden);
            });
        }

        root.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-lp-attached-remove]');
            if (!btn || !root.contains(btn)) {
                return;
            }
            e.preventDefault();
            var card = btn.closest('[data-lp-attached-card]');
            if (!card) {
                return;
            }
            card.classList.toggle('is-removing');
            syncRemoveInputs();
        });

        function setInputFiles(fileList) {
            var dt = new DataTransfer();
            for (var i = 0; i < fileList.length; i++) {
                dt.items.add(fileList[i]);
            }
            input.files = dt.files;
        }

        function renderPending() {
            var files = input.files ? Array.prototype.slice.call(input.files) : [];
            if (!pendingWrap || !pendingList) {
                if (!files.length) {
                    status.textContent = emptyLabel;
                } else if (files.length === 1) {
                    status.textContent = files[0].name + ' — save to attach';
                } else {
                    status.textContent = files.length + ' selected — save to attach';
                }
                return;
            }
            pendingList.innerHTML = '';
            if (!files.length) {
                pendingWrap.hidden = true;
                status.textContent = emptyLabel;
                return;
            }
            pendingWrap.hidden = false;
            status.textContent = files.length === 1
                ? (files[0].name + ' — save to attach')
                : (files.length + ' selected — save to attach');
            files.forEach(function (file, index) {
                var li = document.createElement('li');
                li.className = 'lp-admin-file-card';
                li.title = file.name;
                var ext = (file.name.split('.').pop() || 'FILE').toUpperCase();
                if (ext.length > 5) {
                    ext = 'FILE';
                }
                li.innerHTML =
                    '<span class="lp-admin-file-ico" aria-hidden="true"></span>' +
                    '<span class="lp-admin-file-body">' +
                    '<span class="lp-admin-file-name"></span>' +
                    '<span class="lp-admin-file-meta"></span>' +
                    '</span>' +
                    '<button type="button" class="lp-file-remove" data-lp-pending-remove aria-label=""></button>';
                li.querySelector('.lp-admin-file-ico').textContent = ext;
                li.querySelector('.lp-admin-file-name').textContent = file.name;
                li.querySelector('.lp-admin-file-meta').textContent = file.size
                    ? (Math.max(1, Math.round(file.size / 1024)) + ' KB')
                    : '';
                var rm = li.querySelector('[data-lp-pending-remove]');
                rm.setAttribute('aria-label', removeAria);
                rm.title = removeAria;
                rm.textContent = '×';
                rm.addEventListener('click', function () {
                    var next = Array.prototype.slice.call(input.files || []).filter(function (_f, i) {
                        return i !== index;
                    });
                    setInputFiles(next);
                    renderPending();
                });
                pendingList.appendChild(li);
            });
        }

        input.addEventListener('change', function () {
            renderPending();
        });
    });

    // Admin announcements: All tracks + edit/delete helpers.
    var annPage = shell.querySelector('[data-lp-ann-page]');
    var annForm = shell.querySelector('[data-lp-ann-form]');
    if (annForm) {
        var allTracks = annForm.querySelector('[data-lp-ann-all-tracks]');
        var trackBoxes = annForm.querySelectorAll('[data-lp-ann-track]');
        var tracksWrap = annForm.querySelector('[data-lp-ann-tracks-wrap]');
        var actionEl = annForm.querySelector('[data-lp-ann-action]');
        var discussionEl = annForm.querySelector('[data-lp-ann-discussionid]');
        var subjectEl = annForm.querySelector('[data-lp-ann-subject]');
        var messageEl = annForm.querySelector('[data-lp-ann-message]');
        var customTagEl = annForm.querySelector('[data-lp-ann-customtag]');
        var submitEl = annForm.querySelector('[data-lp-ann-submit]');
        var cancelEditEl = annForm.querySelector('[data-lp-ann-cancel-edit]');
        var formTitleEl = shell.querySelector('[data-lp-ann-form-title]');
        var filesEl = annForm.querySelector('[data-lp-ann-files]');
        var annFilePicker = annForm.querySelector('[data-lp-ann-file-picker]');
        var attachedList = annFilePicker ? annFilePicker.querySelector('[data-lp-attached-files]') : null;
        var removeBox = annFilePicker ? annFilePicker.querySelector('[data-lp-remove-inputs]') : null;
        var pendingWrap = annFilePicker ? annFilePicker.querySelector('[data-lp-pending-wrap]') : null;
        var pendingList = annFilePicker ? annFilePicker.querySelector('[data-lp-pending-list]') : null;
        var fileStatus = annFilePicker ? annFilePicker.querySelector('[data-lp-file-status]') : null;
        var removeAria = annFilePicker
            ? (annFilePicker.getAttribute('data-lp-remove-label') || 'Remove file')
            : 'Remove file';
        var pickerEmpty = fileStatus ? fileStatus.textContent : 'No new file chosen';
        var strSubmit = annPage ? (annPage.getAttribute('data-str-submit') || 'Post announcement') : 'Post announcement';
        var strSave = annPage ? (annPage.getAttribute('data-str-save') || 'Save changes') : 'Save changes';
        var strEditing = annPage ? (annPage.getAttribute('data-str-editing') || 'Edit announcement') : 'Edit announcement';
        var strCompose = annPage ? (annPage.getAttribute('data-str-compose') || 'Compose') : 'Compose';
        var strDeleteConfirm = annPage
            ? (annPage.getAttribute('data-str-delete-confirm') || 'Delete this announcement?')
            : 'Delete this announcement?';

        function clearAnnFiles() {
            if (filesEl) {
                filesEl.value = '';
            }
            if (attachedList) {
                attachedList.innerHTML = '';
                attachedList.setAttribute('hidden', 'hidden');
            }
            if (removeBox) {
                removeBox.innerHTML = '';
            }
            if (pendingList) {
                pendingList.innerHTML = '';
            }
            if (pendingWrap) {
                pendingWrap.hidden = true;
            }
            if (fileStatus) {
                fileStatus.textContent = pickerEmpty;
            }
        }
        function renderExistingFiles(raw) {
            if (!attachedList) {
                return;
            }
            attachedList.innerHTML = '';
            if (removeBox) {
                removeBox.innerHTML = '';
            }
            var rows = [];
            try {
                rows = JSON.parse(raw || '[]') || [];
            } catch (err) {
                rows = [];
            }
            if (!Array.isArray(rows) || !rows.length) {
                attachedList.setAttribute('hidden', 'hidden');
                return;
            }
            rows.forEach(function (row) {
                var name = String((row && (row.name || row.filename)) || '').trim();
                if (!name) {
                    return;
                }
                var display = String((row && row.displayname) || name);
                var size = String((row && row.size) || (row && row.filesize) || '');
                var ext = String((row && row.ext) || '').toUpperCase() || 'FILE';
                if (ext.length > 5) {
                    ext = 'FILE';
                }
                var preview = String((row && row.previewurl) || '');
                var li = document.createElement('li');
                li.className = 'lp-admin-file-card' + (preview ? ' has-thumb' : '');
                li.title = name;
                li.setAttribute('data-lp-attached-card', '');
                li.setAttribute('data-filename', name);
                if (preview) {
                    li.innerHTML =
                        '<span class="lp-admin-file-thumb" aria-hidden="true"><img alt=""></span>' +
                        '<span class="lp-admin-file-body">' +
                        '<span class="lp-admin-file-name"></span>' +
                        '<span class="lp-admin-file-meta"></span>' +
                        '</span>' +
                        '<button type="button" class="lp-file-remove" data-lp-attached-remove></button>';
                    li.querySelector('img').src = preview;
                } else {
                    li.innerHTML =
                        '<span class="lp-admin-file-ico" aria-hidden="true"></span>' +
                        '<span class="lp-admin-file-body">' +
                        '<span class="lp-admin-file-name"></span>' +
                        '<span class="lp-admin-file-meta"></span>' +
                        '</span>' +
                        '<button type="button" class="lp-file-remove" data-lp-attached-remove></button>';
                    li.querySelector('.lp-admin-file-ico').textContent = ext;
                }
                li.querySelector('.lp-admin-file-name').textContent = display;
                li.querySelector('.lp-admin-file-meta').textContent = size;
                var rm = li.querySelector('[data-lp-attached-remove]');
                rm.setAttribute('aria-label', removeAria);
                rm.title = removeAria;
                rm.textContent = '×';
                attachedList.appendChild(li);
            });
            if (attachedList.children.length) {
                attachedList.removeAttribute('hidden');
            } else {
                attachedList.setAttribute('hidden', 'hidden');
            }
        }

        function syncAnnTracks() {
            var on = !!(allTracks && allTracks.checked);
            trackBoxes.forEach(function (box) {
                box.checked = on;
                box.disabled = on;
            });
        }
        function clearAnnTagChecks() {
            annForm.querySelectorAll('[data-lp-ann-tag]').forEach(function (box) {
                box.checked = false;
            });
            if (customTagEl) {
                customTagEl.value = '';
            }
        }
        function setAnnTagChecks(csv) {
            clearAnnTagChecks();
            var known = {};
            annForm.querySelectorAll('[data-lp-ann-tag]').forEach(function (box) {
                known[String(box.value || '').toLowerCase()] = box;
            });
            var extras = [];
            String(csv || '').split(',').forEach(function (raw) {
                var name = String(raw || '').trim();
                if (!name) {
                    return;
                }
                var box = known[name.toLowerCase()];
                if (box) {
                    box.checked = true;
                } else {
                    extras.push(name);
                }
            });
            if (customTagEl && extras.length) {
                customTagEl.value = extras.join(', ');
            }
        }
        function resetAnnCompose() {
            if (actionEl) {
                actionEl.value = 'post_announcement';
            }
            if (discussionEl) {
                discussionEl.value = '';
            }
            if (subjectEl) {
                subjectEl.value = '';
            }
            if (messageEl) {
                messageEl.value = '';
            }
            clearAnnTagChecks();
            if (tracksWrap) {
                tracksWrap.removeAttribute('hidden');
            }
            if (allTracks) {
                allTracks.checked = false;
                allTracks.disabled = false;
            }
            trackBoxes.forEach(function (box) {
                box.checked = false;
                box.disabled = false;
            });
            var pinElReset = annForm.querySelector('[data-lp-ann-pinned]');
            if (pinElReset) {
                pinElReset.checked = false;
            }
            var publishElReset = annForm.querySelector('[data-lp-ann-publish-at]');
            if (publishElReset) {
                publishElReset.value = '';
            }
            clearAnnFiles();
            if (submitEl) {
                submitEl.textContent = strSubmit;
            }
            if (cancelEditEl) {
                cancelEditEl.setAttribute('hidden', 'hidden');
            }
            if (formTitleEl) {
                formTitleEl.textContent = strCompose;
            }
            annForm.classList.remove('is-editing');
        }
        function startAnnEdit(item) {
            if (!item) {
                return;
            }
            if (actionEl) {
                actionEl.value = 'update_announcement';
            }
            if (discussionEl) {
                discussionEl.value = item.getAttribute('data-id') || '';
            }
            if (subjectEl) {
                subjectEl.value = item.getAttribute('data-subject') || '';
            }
            if (messageEl) {
                messageEl.value = item.getAttribute('data-message') || '';
            }
            setAnnTagChecks(item.getAttribute('data-tags') || '');
            var pinEl = annForm.querySelector('[data-lp-ann-pinned]');
            if (pinEl) {
                pinEl.checked = item.getAttribute('data-pinned') === '1';
            }
            var publishEl = annForm.querySelector('[data-lp-ann-publish-at]');
            if (publishEl) {
                publishEl.value = item.getAttribute('data-publish-at') || '';
            }
            clearAnnFiles();
            renderExistingFiles(item.getAttribute('data-attachments') || '[]');
            if (tracksWrap) {
                tracksWrap.setAttribute('hidden', 'hidden');
            }
            if (submitEl) {
                submitEl.textContent = strSave;
            }
            if (cancelEditEl) {
                cancelEditEl.removeAttribute('hidden');
            }
            if (formTitleEl) {
                formTitleEl.textContent = strEditing;
            }
            annForm.classList.add('is-editing');
            if (subjectEl) {
                subjectEl.focus();
            }
            annForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        var selectAllBtn = annForm.querySelector('[data-lp-ann-select-all]');
        var annList = shell.querySelector('[data-lp-ann-list]');
        var annFilterMode = 'all';
        var showMorePattern = annPage
            ? (annPage.getAttribute('data-str-show-more') || 'Show more ({n})')
            : 'Show more ({n})';

        function applyAnnFilter(mode) {
            annFilterMode = mode || 'all';
            if (!annList) {
                return;
            }
            var moreWrap = shell.querySelector('.lp-ann-more-wrap, .lp-ann-archive-wrap');
            annList.querySelectorAll('[data-lp-ann-item]').forEach(function (li) {
                var match = annFilterMode === 'all'
                    || (annFilterMode === 'pinned' && li.classList.contains('is-pinned'))
                    || (annFilterMode === 'scheduled' && li.classList.contains('is-scheduled'));
                if (!match) {
                    li.hidden = true;
                    return;
                }
                if (annFilterMode === 'all') {
                    li.hidden = li.classList.contains('is-paged-out');
                } else {
                    li.hidden = false;
                }
            });
            if (moreWrap) {
                moreWrap.hidden = annFilterMode !== 'all'
                    || !annList.querySelector('li.is-paged-out[hidden]');
            }
            shell.querySelectorAll('[data-lp-ann-filter]').forEach(function (btn) {
                var on = btn.getAttribute('data-lp-ann-filter') === annFilterMode;
                btn.classList.toggle('is-active', on);
                btn.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
        }

        if (allTracks) {
            allTracks.addEventListener('change', syncAnnTracks);
            syncAnnTracks();
        }
        if (selectAllBtn && allTracks) {
            selectAllBtn.addEventListener('click', function () {
                allTracks.checked = !allTracks.checked;
                syncAnnTracks();
            });
        }
        if (cancelEditEl) {
            cancelEditEl.addEventListener('click', function () {
                resetAnnCompose();
            });
        }
        annForm.addEventListener('submit', function (e) {
            if (actionEl && actionEl.value === 'update_announcement') {
                return;
            }
            var any = allTracks && allTracks.checked;
            if (!any) {
                trackBoxes.forEach(function (box) {
                    if (box.checked) {
                        any = true;
                    }
                });
            }
            if (!any) {
                e.preventDefault();
                window.alert('Select at least one track, or choose All tracks.');
            }
        });
        shell.addEventListener('click', function (e) {
            var refreshBtn = e.target.closest('[data-lp-ann-refresh]');
            if (refreshBtn && annPage && annPage.contains(refreshBtn)) {
                e.preventDefault();
                window.location.reload();
                return;
            }
            var filterBtn = e.target.closest('[data-lp-ann-filter]');
            if (filterBtn && annPage && annPage.contains(filterBtn)) {
                e.preventDefault();
                applyAnnFilter(filterBtn.getAttribute('data-lp-ann-filter') || 'all');
                return;
            }
            var editBtn = e.target.closest('[data-lp-ann-edit]');
            if (editBtn) {
                e.preventDefault();
                startAnnEdit(editBtn.closest('[data-lp-ann-item]'));
                return;
            }
            var moreAnn = e.target.closest('[data-lp-ann-more]');
            if (!moreAnn) {
                return;
            }
            if (!annList) {
                return;
            }
            if (annFilterMode !== 'all') {
                applyAnnFilter('all');
            }
            var revealAll = moreAnn.hasAttribute('data-lp-ann-archive');
            var pageSize = parseInt(annList.getAttribute('data-page-size') || '8', 10) || 8;
            var hidden = annList.querySelectorAll('li.is-paged-out[hidden]');
            var show = revealAll ? hidden.length : Math.min(pageSize, hidden.length);
            for (var ai = 0; ai < show; ai++) {
                hidden[ai].removeAttribute('hidden');
                hidden[ai].classList.remove('is-paged-out');
            }
            var left = annList.querySelectorAll('li.is-paged-out[hidden]').length;
            if (left === 0) {
                moreAnn.setAttribute('hidden', 'hidden');
                var archiveWrap = moreAnn.closest('.lp-ann-archive-wrap, .lp-ann-more-wrap');
                if (archiveWrap) {
                    archiveWrap.setAttribute('hidden', 'hidden');
                }
            } else if (!revealAll) {
                moreAnn.setAttribute('data-remaining', String(left));
                moreAnn.textContent = showMorePattern.replace('{n}', String(left)).replace('{$a}', String(left));
            }
        });
        shell.addEventListener('submit', function (e) {
            var delForm = e.target.closest('[data-lp-ann-delete-form]');
            if (!delForm) {
                return;
            }
            if (!window.confirm(strDeleteConfirm)) {
                e.preventDefault();
            }
        });
    }

    // Admin reviews: queue filters (state / track / search) + soft ping-target.
    (function initReviewPingTargets() {
        var page = shell.querySelector('[data-lp-reviews-page]');
        if (!page) {
            return;
        }
        var actionUrl = page.getAttribute('data-ping-action-url') || '';
        var sesskey = page.getAttribute('data-sesskey') || '';

        (function initReviewQueueFilters() {
            var tabs = page.querySelectorAll('[data-lp-ro-state]');
            var trackSel = page.querySelector('[data-lp-ro-track]');
            var searchInput = page.querySelector('[data-lp-ro-search]');
            var rows = page.querySelectorAll('[data-lp-review-row]');
            var emptyEl = page.querySelector('[data-lp-ro-empty]');
            var countEl = page.querySelector('[data-lp-ro-count]');
            if (!tabs.length || !rows.length) {
                return;
            }
            var state = 'all';

            function applyFilters() {
                var track = trackSel ? String(trackSel.value || '') : '';
                var q = searchInput ? String(searchInput.value || '').toLowerCase().trim() : '';
                var shown = 0;
                rows.forEach(function (row) {
                    var rowState = row.getAttribute('data-lp-ro-state') || '';
                    var rowTrack = row.getAttribute('data-lp-ro-track') || '';
                    var blob = row.getAttribute('data-lp-ro-search') || '';
                    var okState = state === 'all' || rowState === state;
                    var okTrack = !track || rowTrack === track;
                    var okSearch = !q || blob.indexOf(q) !== -1;
                    var show = okState && okTrack && okSearch;
                    row.hidden = !show;
                    if (show) {
                        shown += 1;
                    }
                });
                if (emptyEl) {
                    emptyEl.hidden = shown > 0;
                }
                if (countEl) {
                    countEl.textContent = 'Showing ' + shown;
                }
            }

            tabs.forEach(function (tab) {
                tab.addEventListener('click', function () {
                    state = tab.getAttribute('data-lp-ro-state') || 'all';
                    tabs.forEach(function (t) {
                        var on = t === tab;
                        t.classList.toggle('is-active', on);
                        t.setAttribute('aria-selected', on ? 'true' : 'false');
                    });
                    applyFilters();
                });
            });
            if (trackSel) {
                trackSel.addEventListener('change', applyFilters);
            }
            if (searchInput) {
                searchInput.addEventListener('input', applyFilters);
            }
        })();

        function placeReviewMenu(root) {
            var menu = root ? root.querySelector('[data-lp-select-menu]') : null;
            var trigger = root ? root.querySelector('[data-lp-select-trigger]') : null;
            if (!menu || !trigger) {
                return;
            }
            var rect = trigger.getBoundingClientRect();
            var pad = 12;
            var width = Math.round(rect.width);
            var maxWidth = Math.max(160, window.innerWidth - pad * 2);
            if (width > maxWidth) {
                width = maxWidth;
            }
            var left = Math.round(rect.left);
            if (left + width > window.innerWidth - pad) {
                left = Math.max(pad, window.innerWidth - width - pad);
            }
            if (left < pad) {
                left = pad;
            }
            var top = rect.bottom + 6;
            menu.classList.add('is-fixed');
            menu.style.width = width + 'px';
            menu.style.maxWidth = width + 'px';
            menu.style.left = left + 'px';
            menu.style.top = top + 'px';
            menu.style.right = 'auto';
            menu.style.minWidth = '0';
            // Flip up if near bottom of viewport.
            requestAnimationFrame(function () {
                var menuRect = menu.getBoundingClientRect();
                if (menuRect.bottom > window.innerHeight - 8) {
                    var upTop = rect.top - menuRect.height - 6;
                    if (upTop >= 8) {
                        menu.style.top = upTop + 'px';
                    }
                }
            });
        }

        function clearReviewMenuPlace(menu) {
            if (!menu) {
                return;
            }
            menu.classList.remove('is-fixed');
            menu.style.width = '';
            menu.style.maxWidth = '';
            menu.style.minWidth = '';
            menu.style.left = '';
            menu.style.top = '';
            menu.style.right = '';
        }

        function closeReviewSelects(except) {
            page.querySelectorAll('[data-lp-ping-target]').forEach(function (root) {
                if (except && root === except) {
                    return;
                }
                var menu = root.querySelector('[data-lp-select-menu]');
                var trigger = root.querySelector('[data-lp-select-trigger]');
                root.classList.remove('is-open');
                if (menu) {
                    menu.setAttribute('hidden', 'hidden');
                    clearReviewMenuPlace(menu);
                }
                if (trigger) {
                    trigger.setAttribute('aria-expanded', 'false');
                }
            });
        }

        function syncPingUi(select) {
            if (!select) {
                return;
            }
            var form = select.form || page.querySelector('#' + (select.getAttribute('form') || ''));
            if (!form) {
                return;
            }
            var opt = select.options[select.selectedIndex];
            var cooled = !!(opt && opt.getAttribute('data-cooled') === '1');
            var btn = form.querySelector('[data-lp-ping-btn]');
            var cooledEl = form.querySelector('[data-lp-ping-cooled]');
            if (btn) {
                if (cooled) {
                    btn.setAttribute('hidden', 'hidden');
                } else {
                    btn.removeAttribute('hidden');
                }
            }
            if (cooledEl) {
                if (cooled) {
                    cooledEl.removeAttribute('hidden');
                } else {
                    cooledEl.setAttribute('hidden', 'hidden');
                }
            }
        }

        function persistTarget(select) {
            if (!actionUrl || !sesskey || !select) {
                return;
            }
            var row = select.closest('[data-lp-review-row]');
            var submissionid = row ? (row.getAttribute('data-submissionid') || '') : '';
            var teacherid = select.value || '';
            if (!submissionid || !teacherid || typeof window.fetch !== 'function') {
                return;
            }
            var body = new URLSearchParams();
            body.set('sesskey', sesskey);
            body.set('action', 'set_ping_target');
            body.set('submissionid', submissionid);
            body.set('teacherid', teacherid);
            body.set('ajax', '1');
            window.fetch(actionUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body.toString()
            }).catch(function () {
                // Soft preference only — ping still posts the selected teacherid.
            });
        }

        function setPingTargetValue(root, value, persist) {
            if (!root) {
                return;
            }
            var options = root.querySelectorAll('[data-lp-select-option]');
            var labelEl = root.querySelector('[data-lp-select-label]');
            var input = root.querySelector('[data-lp-select-input]');
            var matched = null;
            options.forEach(function (opt) {
                var on = String(opt.getAttribute('data-value') || '') === String(value);
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
                if (input.tagName === 'SELECT') {
                    Array.prototype.forEach.call(input.options, function (opt) {
                        opt.selected = String(opt.value) === String(value);
                    });
                }
                syncPingUi(input);
                if (persist) {
                    persistTarget(input);
                }
            }
        }

        page.querySelectorAll('[data-lp-ping-target]').forEach(function (root) {
            if (root.getAttribute('data-lp-select-ready') === '1') {
                return;
            }
            root.setAttribute('data-lp-select-ready', '1');
            var trigger = root.querySelector('[data-lp-select-trigger]');
            var menu = root.querySelector('[data-lp-select-menu]');
            var input = root.querySelector('[data-lp-select-input]');
            if (input) {
                setPingTargetValue(root, input.value || '', false);
            }
            if (trigger) {
                trigger.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    if (root.classList.contains('is-open')) {
                        closeReviewSelects(null);
                    } else {
                        closeReviewSelects(root);
                        root.classList.add('is-open');
                        if (menu) {
                            menu.removeAttribute('hidden');
                            placeReviewMenu(root);
                        }
                        trigger.setAttribute('aria-expanded', 'true');
                        var selected = root.querySelector('[data-lp-select-option].is-selected')
                            || root.querySelector('[data-lp-select-option]');
                        if (selected) {
                            selected.focus();
                        }
                    }
                });
            }
            if (menu) {
                menu.addEventListener('click', function (e) {
                    var opt = e.target.closest('[data-lp-select-option]');
                    if (!opt || !menu.contains(opt)) {
                        return;
                    }
                    e.preventDefault();
                    setPingTargetValue(root, opt.getAttribute('data-value') || '', true);
                    closeReviewSelects(null);
                    if (trigger) {
                        trigger.focus();
                    }
                });
            }
        });

        document.addEventListener('click', function (e) {
            if (!e.target.closest('[data-lp-ping-target]')) {
                closeReviewSelects(null);
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeReviewSelects(null);
            }
        });
        window.addEventListener('resize', function () {
            closeReviewSelects(null);
        });
        window.addEventListener('scroll', function () {
            closeReviewSelects(null);
        }, true);
    })();

    // Admin Mentors & capacity: All / Needs mentor / Over capacity filters.
    (function initMentorsCapacityFilters() {
        var page = shell.querySelector('[data-lp-mentors-page]');
        if (!page) {
            return;
        }
        var tabs = page.querySelectorAll('[data-lp-mc-filter]');
        var cards = page.querySelectorAll('[data-lp-mc-card]');
        var emptyEl = page.querySelector('[data-lp-mc-empty]');
        var builder = page.querySelector('.lp-mc-builder-tile');
        if (!tabs.length || !cards.length) {
            return;
        }
        var mode = 'all';

        function applyFilter(next) {
            mode = next || 'all';
            var shown = 0;
            cards.forEach(function (card) {
                var status = card.getAttribute('data-lp-mc-status') || '';
                var show = mode === 'all' || status === mode;
                card.hidden = !show;
                if (show) {
                    shown += 1;
                }
            });
            if (builder) {
                builder.hidden = mode !== 'all';
            }
            if (emptyEl) {
                emptyEl.hidden = shown > 0;
            }
            tabs.forEach(function (tab) {
                var on = tab.getAttribute('data-lp-mc-filter') === mode;
                tab.classList.toggle('is-active', on);
                tab.setAttribute('aria-selected', on ? 'true' : 'false');
            });
        }

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                applyFilter(tab.getAttribute('data-lp-mc-filter') || 'all');
            });
        });
    })();
})();
