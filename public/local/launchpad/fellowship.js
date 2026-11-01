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
    function getCalScope() {
        var checked = calForm ? calForm.querySelector('[data-lp-cal-form-scope]:checked') : null;
        if (checked) {
            return checked.value === 'course' ? 'course' : 'personal';
        }
        var fixed = calForm ? calForm.querySelector('[data-lp-cal-form-scope-fixed]') : null;
        if (fixed && fixed.value) {
            return fixed.value === 'course' ? 'course' : 'personal';
        }
        return (calRoot && calRoot.getAttribute('data-default-scope') === 'course') ? 'course' : 'personal';
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
        var isCourse = scope === 'course';
        if (courseWrap) {
            if (isCourse) {
                courseWrap.removeAttribute('hidden');
            } else {
                courseWrap.setAttribute('hidden', 'hidden');
            }
        }
        if (helpPersonal) {
            if (isCourse) {
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
    function syncDurationModeUi(mode) {
        if (!calForm) {
            return;
        }
        var untilWrap = calForm.querySelector('[data-lp-cal-form-until-wrap]');
        var minutesWrap = calForm.querySelector('[data-lp-cal-form-minutes-wrap]');
        if (untilWrap) {
            if (mode === 'until') {
                untilWrap.removeAttribute('hidden');
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
    function syncRepeatUi(isCreate) {
        if (!calForm) {
            return;
        }
        var wrap = calForm.querySelector('[data-lp-cal-form-repeat-wrap]');
        var modeEl = calForm.querySelector('[data-lp-cal-form-repeat-mode]');
        var countWrap = calForm.querySelector('[data-lp-cal-form-repeat-count-wrap]');
        if (!wrap) {
            return;
        }
        if (!isCreate) {
            wrap.setAttribute('hidden', 'hidden');
            return;
        }
        wrap.removeAttribute('hidden');
        var weekly = modeEl && modeEl.value === 'weekly';
        if (countWrap) {
            if (weekly) {
                countWrap.removeAttribute('hidden');
            } else {
                countWrap.setAttribute('hidden', 'hidden');
            }
        }
    }
    function getRepeatCount() {
        if (!calForm) {
            return 1;
        }
        var modeEl = calForm.querySelector('[data-lp-cal-form-repeat-mode]');
        if (!modeEl || modeEl.value !== 'weekly') {
            return 1;
        }
        var countEl = calForm.querySelector('[data-lp-cal-form-repeats]');
        var n = countEl ? parseInt(countEl.value || '1', 10) : 1;
        if (isNaN(n) || n < 1) {
            return 1;
        }
        return Math.min(52, n);
    }

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
            if (durEl) {
                durEl.value = sourceBtn.getAttribute('data-duration') || '0';
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
            var editScope = sourceBtn.getAttribute('data-scope') === 'course' ? 'course' : 'personal';
            setCalScope(editScope, true);
            if (courseEl && editScope === 'course') {
                courseEl.value = sourceBtn.getAttribute('data-courseid') || courseEl.value;
                courseEl.disabled = false;
            }
            if (delBtn) {
                delBtn.removeAttribute('hidden');
            }
            syncRepeatUi(false);
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
            var repeatMode = calForm.querySelector('[data-lp-cal-form-repeat-mode]');
            if (repeatMode) {
                repeatMode.value = 'none';
            }
            var repeatCount = calForm.querySelector('[data-lp-cal-form-repeats]');
            if (repeatCount) {
                repeatCount.value = '4';
            }
            syncRepeatUi(true);
            var defaultScope = (calRoot && calRoot.getAttribute('data-default-scope') === 'course')
                ? 'course'
                : 'personal';
            setCalScope(defaultScope, false);
            if (courseEl) {
                courseEl.disabled = false;
            }
            if (delBtn) {
                delBtn.setAttribute('hidden', 'hidden');
            }
        }
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
        var repeatModeEl = calForm.querySelector('[data-lp-cal-form-repeat-mode]');
        if (repeatModeEl) {
            repeatModeEl.addEventListener('change', function () {
                var idEl = calForm.querySelector('[data-lp-cal-form-id]');
                syncRepeatUi(!(idEl && idEl.value));
            });
        }
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
                    duration: durEl ? durEl.value : '0',
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
        var filter = e.target.closest('[data-lp-cal-filter]');
        if (!filter) {
            return;
        }
        var opt = filter.options[filter.selectedIndex];
        var url = opt ? opt.getAttribute('data-url') : '';
        if (url) {
            window.location.href = url;
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

    // Sidebar XP bar + track weight bars (same pattern as HTML x-xpbar / x-bar).
    if (!reduce) {
        shell.querySelectorAll('.lp-side-xpbar i, .lp-weight-row .lp-bar i').forEach(function (bar) {
            var w = bar.style.width;
            bar.style.width = '0';
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
    var msgScroll = document.querySelector('[data-lp-msg-scroll]');
    if (msgScroll) {
        msgScroll.scrollTop = msgScroll.scrollHeight;
    }

    // Message select + multi-delete (Moodle soft-delete for current user).
    (function initMsgSelectDelete() {
        var root = document.querySelector('[data-lp-msg-bubbles]');
        var bar = document.querySelector('[data-lp-msg-select-bar]');
        var form = document.querySelector('[data-lp-msg-delete-form]');
        var idsHost = form ? form.querySelector('[data-lp-msg-delete-ids]') : null;
        var countEl = bar ? bar.querySelector('[data-lp-msg-select-count]') : null;
        var deleteBtn = bar ? bar.querySelector('[data-lp-msg-select-delete]') : null;
        var cancelBtn = bar ? bar.querySelector('[data-lp-msg-select-cancel]') : null;
        var compose = document.querySelector('[data-lp-msg-compose]');
        var hint = document.querySelector('[data-lp-msg-select-hint]');
        if (!root || !bar || !form || !idsHost || !deleteBtn) {
            return;
        }

        var selected = Object.create(null);
        var selecting = false;
        var countTpl = bar.getAttribute('data-count-tpl') || '__N__ selected';
        var confirmMsg = bar.getAttribute('data-confirm') || 'Delete selected messages?';

        function selectedIds() {
            return Object.keys(selected).filter(function (id) {
                return selected[id];
            });
        }

        function syncUi() {
            var ids = selectedIds();
            var n = ids.length;
            // Select mode stays on until Cancel (even if last box unchecked).
            bar.hidden = !selecting;
            if (hint) {
                hint.hidden = selecting;
            }
            if (compose) {
                compose.hidden = selecting;
            }
            root.classList.toggle('is-selecting', selecting);
            if (countEl) {
                countEl.textContent = countTpl.replace(/__N__/g, String(n));
            }
            deleteBtn.disabled = n === 0;
            idsHost.innerHTML = '';
            ids.forEach(function (id) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'messageids[]';
                input.value = id;
                idsHost.appendChild(input);
            });
            root.querySelectorAll('[data-lp-msg-bubble].is-selectable').forEach(function (bubble) {
                var id = bubble.getAttribute('data-id');
                var on = !!(id && selected[id]);
                bubble.classList.toggle('is-selected', on);
                bubble.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
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
                syncUi();
                return;
            }
            if (selected[id]) {
                delete selected[id];
            } else {
                selected[id] = true;
            }
            syncUi();
        }

        function clearSelection() {
            selected = Object.create(null);
            selecting = false;
            syncUi();
        }

        root.addEventListener('click', function (e) {
            var bubble = e.target.closest('[data-lp-msg-bubble].is-selectable');
            if (!bubble || !root.contains(bubble)) {
                return;
            }
            e.preventDefault();
            toggleBubble(bubble);
        });

        root.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' && e.key !== ' ') {
                return;
            }
            var bubble = e.target.closest('[data-lp-msg-bubble].is-selectable');
            if (!bubble || !root.contains(bubble)) {
                return;
            }
            e.preventDefault();
            toggleBubble(bubble);
        });

        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                clearSelection();
            });
        }

        form.addEventListener('submit', function (e) {
            if (selectedIds().length === 0) {
                e.preventDefault();
                return;
            }
            if (!window.confirm(confirmMsg)) {
                e.preventDefault();
            }
        });

        syncUi();
    })();
})();
