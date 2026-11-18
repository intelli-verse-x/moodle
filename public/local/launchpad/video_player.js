/**
 * LaunchPad VideoJS chrome: click-to-open menus, custom speed UI, quality gear.
 *
 * @module     local_launchpad
 * @copyright  2026 IntelliVerse-X
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
(function () {
    'use strict';

    var GEAR_SVG =
        '<svg class="lp-vjs-gear-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">' +
        '<path fill="currentColor" d="M19.14 12.94c.04-.31.06-.63.06-.94s-.02-.63-.06-.94l2.03-1.58a.5.5 0 0 0 .12-.64l-1.92-3.32a.5.5 0 0 0-.6-.22l-2.39.96a7.07 7.07 0 0 0-1.63-.94l-.36-2.54a.5.5 0 0 0-.5-.42h-3.84a.5.5 0 0 0-.5.42l-.36 2.54c-.58.23-1.12.54-1.63.94l-2.39-.96a.5.5 0 0 0-.6.22L2.71 8.84a.5.5 0 0 0 .12.64l2.03 1.58c-.04.31-.06.63-.06.94s.02.63.06.94L2.83 14.5a.5.5 0 0 0-.12.64l1.92 3.32c.14.24.43.34.68.24l2.39-.96c.5.4 1.05.72 1.63.94l.36 2.54c.05.24.26.42.5.42h3.84c.24 0 .45-.18.5-.42l.36-2.54c.58-.23 1.12-.54 1.63-.94l2.39.96c.25.1.54 0 .68-.24l1.92-3.32a.5.5 0 0 0-.12-.64l-2.03-1.58zM12 15.5A3.5 3.5 0 1 1 12 8.5a3.5 3.5 0 0 1 0 7z"/>' +
        '</svg>';

    function parseQualities(host) {
        var raw = host.getAttribute('data-lp-video-qualities') || '[]';
        try {
            var list = JSON.parse(raw);
            return Array.isArray(list) ? list : [];
        } catch (e) {
            return [];
        }
    }

    function heightLabel(h) {
        var n = Math.round(Number(h) || 0);
        if (n >= 2000) {
            return '2160p';
        }
        if (n >= 1300) {
            return '1440p';
        }
        if (n >= 900) {
            return '1080p';
        }
        if (n >= 700) {
            return '720p';
        }
        if (n >= 500) {
            return '480p';
        }
        if (n >= 300) {
            return '360p';
        }
        if (n > 0) {
            return n + 'p';
        }
        return '';
    }

    function closeMenus(root, except) {
        root.querySelectorAll('.vjs-menu-button-popup.vjs-lock-showing, .lp-vjs-quality.vjs-lock-showing').forEach(function (btn) {
            if (except && btn === except) {
                return;
            }
            btn.classList.remove('vjs-lock-showing', 'vjs-hover');
            btn.setAttribute('aria-expanded', 'false');
        });
    }

    function toggleMenu(btn, root) {
        var open = btn.classList.contains('vjs-lock-showing');
        closeMenus(root, null);
        if (!open) {
            btn.classList.add('vjs-lock-showing');
            btn.setAttribute('aria-expanded', 'true');
        }
    }

    function menuRoot(el) {
        // VideoJS nests a <button> inside the control div — lock class lives on the control.
        if (!el) {
            return null;
        }
        if (el.classList.contains('vjs-menu-button-popup') && el.tagName !== 'BUTTON') {
            return el;
        }
        return el.closest ? el.closest('.vjs-control.vjs-menu-button-popup') : el;
    }

    function bindClickMenus(playerEl) {
        // Hover opens menus via .vjs-workinghover — force click-only (CSS + class).
        playerEl.classList.remove('vjs-workinghover');
        playerEl.classList.add('lp-vjs-click-menus');

        if (playerEl.getAttribute('data-lp-click-menu-bound') === '1') {
            return;
        }
        playerEl.setAttribute('data-lp-click-menu-bound', '1');

        var mo = new MutationObserver(function () {
            if (playerEl.classList.contains('vjs-workinghover')) {
                playerEl.classList.remove('vjs-workinghover');
            }
        });
        mo.observe(playerEl, { attributes: true, attributeFilter: ['class'] });

        playerEl.addEventListener('click', function (e) {
            var raw = e.target.closest && e.target.closest('.vjs-menu-button-popup');
            if (!raw || !playerEl.contains(raw)) {
                return;
            }
            // Quality has its own handler.
            if (raw.classList.contains('lp-vjs-quality') || raw.closest('.lp-vjs-quality')) {
                return;
            }
            // Ignore clicks on menu items (selecting a rate).
            if (e.target.closest && e.target.closest('.vjs-menu-item')) {
                window.setTimeout(function () {
                    closeMenus(playerEl, null);
                }, 0);
                return;
            }
            var btn = menuRoot(raw);
            if (!btn) {
                return;
            }
            e.preventDefault();
            e.stopPropagation();
            toggleMenu(btn, playerEl);
        }, true);

        document.addEventListener('click', function (e) {
            if (!playerEl.contains(e.target)) {
                closeMenus(playerEl, null);
            }
        });
    }

    function withVideoJsPlayer(playerEl, cb) {
        var id = playerEl.id || (playerEl.querySelector('video.video-js, .video-js') || {}).id;
        if (!id || typeof window.require !== 'function') {
            cb(null);
            return;
        }
        window.require(['media_videojs/video-lazy'], function (videojs) {
            try {
                cb(videojs.getPlayer(id) || null);
            } catch (err) {
                cb(null);
            }
        }, function () {
            cb(null);
        });
    }

    function buildQualityButton(host, playerEl, player, qualities) {
        if (playerEl.querySelector('.lp-vjs-quality')) {
            return;
        }
        var bar = playerEl.querySelector('.vjs-control-bar');
        var fullscreen = bar && bar.querySelector('.vjs-fullscreen-control');
        if (!bar) {
            return;
        }

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'vjs-control vjs-button vjs-menu-button vjs-menu-button-popup lp-vjs-quality';
        btn.setAttribute('aria-label', 'Quality');
        btn.setAttribute('aria-expanded', 'false');
        btn.setAttribute('title', 'Quality');
        btn.innerHTML =
            '<span class="vjs-icon-placeholder" aria-hidden="true">' + GEAR_SVG + '</span>' +
            '<span class="lp-vjs-quality-label" hidden></span>' +
            '<div class="vjs-menu lp-vjs-quality-menu" role="menu">' +
            '<ul class="vjs-menu-content"></ul></div>';

        if (fullscreen) {
            bar.insertBefore(btn, fullscreen);
        } else {
            bar.appendChild(btn);
        }

        var listEl = btn.querySelector('.vjs-menu-content');
        var activeSrc = '';

        function currentSrc() {
            if (player && typeof player.currentSrc === 'function') {
                return player.currentSrc() || '';
            }
            var v = playerEl.querySelector('video');
            return (v && (v.currentSrc || v.src)) || '';
        }

        function resolveLabel(q, idx) {
            if (q.label) {
                return q.label;
            }
            return 'Source ' + (idx + 1);
        }

        function renderList() {
            var items = qualities.slice();
            if (!items.length) {
                var h = 0;
                if (player && player.videoHeight) {
                    h = player.videoHeight();
                } else {
                    var v = playerEl.querySelector('video');
                    h = v ? v.videoHeight : 0;
                }
                var detected = heightLabel(h);
                items = [{
                    src: currentSrc(),
                    type: 'video/mp4',
                    label: detected || 'Auto',
                    auto: true,
                }];
            } else {
                items = items.map(function (q, i) {
                    var copy = Object.assign({}, q);
                    if (!copy.label) {
                        copy.label = resolveLabel(q, i);
                    }
                    return copy;
                });
                // Prefer height labels once metadata known for unlabeled singles.
                if (items.length === 1 && !qualities[0].label) {
                    var hh = player && player.videoHeight ? player.videoHeight() : 0;
                    var dl = heightLabel(hh);
                    if (dl) {
                        items[0].label = dl;
                    }
                }
            }

            if (!activeSrc) {
                activeSrc = currentSrc() || (items[0] && items[0].src) || '';
            }

            listEl.innerHTML = '';
            items.forEach(function (q) {
                var li = document.createElement('li');
                li.className = 'vjs-menu-item';
                li.setAttribute('role', 'menuitemradio');
                var selected = activeSrc && q.src && (activeSrc === q.src || activeSrc.indexOf(encodeURIComponent(q.filename || '')) !== -1);
                if (!q.src && q.auto) {
                    selected = true;
                }
                if (selected) {
                    li.classList.add('vjs-selected');
                    li.setAttribute('aria-checked', 'true');
                } else {
                    li.setAttribute('aria-checked', 'false');
                }
                li.textContent = q.label || 'Auto';
                li.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    if (!q.src || !player) {
                        closeMenus(playerEl, null);
                        return;
                    }
                    if (currentSrc() === q.src) {
                        closeMenus(playerEl, null);
                        return;
                    }
                    var wasPaused = player.paused();
                    var t = player.currentTime();
                    var rate = player.playbackRate();
                    player.src({ src: q.src, type: q.type || 'video/mp4' });
                    player.one('loadedmetadata', function () {
                        try {
                            player.currentTime(t);
                            player.playbackRate(rate);
                            if (!wasPaused) {
                                player.play();
                            }
                        } catch (err) {
                            // ignore seek errors on short clips
                        }
                    });
                    activeSrc = q.src;
                    renderList();
                    closeMenus(playerEl, null);
                });
                listEl.appendChild(li);
            });
        }

        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            renderList();
            toggleMenu(btn, playerEl);
        });

        if (player) {
            player.on('loadedmetadata', function () {
                renderList();
            });
        }
        renderList();
    }

    function enhanceHost(host) {
        if (host.getAttribute('data-lp-vjs-enhanced') === '1') {
            return;
        }
        var playerEl = host.querySelector('.video-js');
        if (!playerEl || !playerEl.querySelector('.vjs-control-bar')) {
            return false;
        }
        host.setAttribute('data-lp-vjs-enhanced', '1');
        playerEl.classList.add('lp-vjs-skin');

        bindClickMenus(playerEl);

        var qualities = parseQualities(host);
        withVideoJsPlayer(playerEl, function (player) {
            buildQualityButton(host, playerEl, player, qualities);
            // Re-bind after quality button inserted.
            bindClickMenus(playerEl);
        });
        return true;
    }

    function scan() {
        document.querySelectorAll('[data-lp-video-player].lp-activity-videojs').forEach(function (host) {
            if (!enhanceHost(host)) {
                // Player still booting.
            }
        });
    }

    var tries = 0;
    var timer = window.setInterval(function () {
        tries += 1;
        scan();
        var pending = document.querySelectorAll(
            '[data-lp-video-player].lp-activity-videojs:not([data-lp-vjs-enhanced="1"])'
        );
        if (!pending.length || tries > 40) {
            window.clearInterval(timer);
        }
    }, 250);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scan);
    } else {
        scan();
    }
})();
