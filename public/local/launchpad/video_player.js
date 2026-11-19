/**
 * LaunchPad VideoJS chrome: Stitch control bar, boot poster/loader, quality gear.
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
            var menu = btn.querySelector('.vjs-menu');
            if (menu) {
                menu.classList.remove('vjs-lock-showing');
            }
        });
    }

    function toggleMenu(btn, root) {
        var open = btn.classList.contains('vjs-lock-showing');
        closeMenus(root, null);
        if (!open) {
            btn.classList.add('vjs-lock-showing');
            btn.setAttribute('aria-expanded', 'true');
            // VideoJS shows popup when .vjs-menu has lock-showing (not only the button).
            var menu = btn.querySelector('.vjs-menu');
            if (menu) {
                menu.classList.add('vjs-lock-showing');
            }
        }
    }

    function menuRoot(el) {
        if (!el) {
            return null;
        }
        if (el.classList.contains('vjs-menu-button-popup') && el.tagName !== 'BUTTON') {
            return el;
        }
        return el.closest ? el.closest('.vjs-control.vjs-menu-button-popup') : el;
    }

    var DEFAULT_RATES = [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2];

    function rateList(player) {
        var rates = null;
        try {
            rates = player && player.options_ && player.options_.playbackRates;
        } catch (err) {
            rates = null;
        }
        if (!Array.isArray(rates) || !rates.length) {
            rates = DEFAULT_RATES;
        }
        return rates.map(function (r) {
            return Number(r);
        }).filter(function (r) {
            return r > 0;
        });
    }

    function formatRateLabel(rate) {
        var n = Number(rate) || 1;
        if (Math.abs(n - Math.round(n)) < 0.001) {
            return Math.round(n) + 'x';
        }
        return String(n).replace(/\.?0+$/, '') + 'x';
    }

    function currentVideoRate(playerEl) {
        var video = playerEl.querySelector('video');
        if (video && video.playbackRate) {
            return Number(video.playbackRate) || 1;
        }
        var player = playerEl.__lpVjsPlayer;
        try {
            if (player && typeof player.playbackRate === 'function') {
                return Number(player.playbackRate()) || 1;
            }
        } catch (err) {
            // ignore
        }
        return 1;
    }

    /** Apply speed on the real <video> first — does not depend on VideoJS player handle. */
    function setPlaybackRate(playerEl, rate) {
        var n = Number(rate);
        if (!(n > 0)) {
            return false;
        }
        var video = playerEl.querySelector('video');
        if (video) {
            try {
                video.playbackRate = n;
            } catch (err) {
                // ignore
            }
        }
        var player = playerEl.__lpVjsPlayer;
        if (player && typeof player.playbackRate === 'function') {
            try {
                player.playbackRate(n);
            } catch (err2) {
                // ignore
            }
        }
        var label = playerEl.querySelector('.vjs-playback-rate .vjs-playback-rate-value');
        if (label) {
            label.textContent = formatRateLabel(n);
        }
        playerEl.querySelectorAll('.lp-rate-popover [data-lp-rate]').forEach(function (btn) {
            var br = Number(btn.getAttribute('data-lp-rate'));
            if (Math.abs(br - n) < 0.001) {
                btn.classList.add('is-selected');
                btn.setAttribute('aria-checked', 'true');
            } else {
                btn.classList.remove('is-selected');
                btn.setAttribute('aria-checked', 'false');
            }
        });
        return true;
    }

    function closeRatePopover(playerEl) {
        var pop = playerEl.querySelector('.lp-rate-popover');
        if (pop) {
            pop.hidden = true;
        }
        var wrap = playerEl.querySelector('.vjs-playback-rate');
        if (wrap) {
            wrap.classList.remove('lp-rate-open');
            wrap.setAttribute('aria-expanded', 'false');
        }
    }

    function positionRatePopover(playerEl, wrap, pop) {
        var bar = playerEl.querySelector('.vjs-control-bar');
        if (!bar || !wrap || !pop) {
            return;
        }
        var barRect = bar.getBoundingClientRect();
        var btnRect = wrap.getBoundingClientRect();
        // Sit above the 1x control, inside the control bar stacking context.
        var left = btnRect.left - barRect.left + (btnRect.width / 2) - 36;
        left = Math.max(8, Math.min(left, barRect.width - 80));
        pop.style.left = left + 'px';
        pop.style.bottom = (barRect.height + 6) + 'px';
        pop.style.right = 'auto';
    }

    /**
     * Custom rate popup — VideoJS native menu uses height:0 + absolute content and
     * is unreliable (desktop clip / mobile taps not applying). Own UI is intentional.
     */
    function ensureRatePopover(playerEl, player) {
        var wrap = playerEl.querySelector('.vjs-playback-rate');
        var bar = playerEl.querySelector('.vjs-control-bar');
        if (!wrap || !bar) {
            return null;
        }

        // Kill native VideoJS rate menu so it cannot intercept / confuse taps.
        wrap.querySelectorAll('.vjs-menu').forEach(function (m) {
            m.setAttribute('hidden', '');
            m.style.display = 'none';
            m.style.pointerEvents = 'none';
        });

        var pop = playerEl.querySelector('.lp-rate-popover');
        if (!pop) {
            pop = document.createElement('div');
            pop.className = 'lp-rate-popover';
            pop.setAttribute('role', 'menu');
            pop.hidden = true;
            bar.appendChild(pop);
        }

        var rates = rateList(player || playerEl.__lpVjsPlayer);
        var cur = currentVideoRate(playerEl);
        pop.innerHTML = '';
        rates.forEach(function (rate) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'lp-rate-option';
            btn.setAttribute('role', 'menuitemradio');
            btn.setAttribute('data-lp-rate', String(rate));
            btn.textContent = formatRateLabel(rate);
            if (Math.abs(rate - cur) < 0.001) {
                btn.classList.add('is-selected');
                btn.setAttribute('aria-checked', 'true');
            } else {
                btn.setAttribute('aria-checked', 'false');
            }
            pop.appendChild(btn);
        });
        return pop;
    }

    function openRatePopover(playerEl) {
        var wrap = playerEl.querySelector('.vjs-playback-rate');
        var pop = ensureRatePopover(playerEl, playerEl.__lpVjsPlayer);
        if (!wrap || !pop) {
            return;
        }
        closeMenus(playerEl, null);
        pop.hidden = false;
        wrap.classList.add('lp-rate-open');
        wrap.setAttribute('aria-expanded', 'true');
        positionRatePopover(playerEl, wrap, pop);
    }

    function bindClickMenus(playerEl, player) {
        if (player) {
            playerEl.__lpVjsPlayer = player;
            ensureRatePopover(playerEl, player);
        }
        playerEl.classList.remove('vjs-workinghover');
        playerEl.classList.add('lp-vjs-click-menus');

        if (playerEl.getAttribute('data-lp-ratechange-bound') !== '1') {
            playerEl.setAttribute('data-lp-ratechange-bound', '1');
            // Prefer the real <video> rate — VideoJS label sync used to fight videowatch.
            var syncLabel = function () {
                var label = playerEl.querySelector('.vjs-playback-rate .vjs-playback-rate-value');
                if (label) {
                    label.textContent = formatRateLabel(currentVideoRate(playerEl));
                }
            };
            var video = playerEl.querySelector('video');
            if (video) {
                video.addEventListener('ratechange', syncLabel);
            }
            if (player) {
                player.on('ratechange', syncLabel);
            }
        }

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

        // pointerdown beats touch quirks; applies rate before click synthesizes elsewhere.
        playerEl.addEventListener('pointerdown', function (e) {
            var opt = e.target.closest && e.target.closest('.lp-rate-option');
            if (!opt || !playerEl.contains(opt)) {
                return;
            }
            e.preventDefault();
            e.stopPropagation();
            var rate = Number(opt.getAttribute('data-lp-rate'));
            setPlaybackRate(playerEl, rate);
            closeRatePopover(playerEl);
        }, true);

        playerEl.addEventListener('click', function (e) {
            // Custom rate option (fallback if pointerdown missed).
            var opt = e.target.closest && e.target.closest('.lp-rate-option');
            if (opt && playerEl.contains(opt)) {
                e.preventDefault();
                e.stopPropagation();
                var rate = Number(opt.getAttribute('data-lp-rate'));
                setPlaybackRate(playerEl, rate);
                closeRatePopover(playerEl);
                return;
            }

            var rateWrap = e.target.closest && e.target.closest('.vjs-playback-rate');
            if (rateWrap && playerEl.contains(rateWrap)) {
                e.preventDefault();
                e.stopPropagation();
                var pop = playerEl.querySelector('.lp-rate-popover');
                if (pop && !pop.hidden) {
                    closeRatePopover(playerEl);
                } else {
                    openRatePopover(playerEl);
                }
                return;
            }

            // Tap elsewhere inside player closes rate popover.
            if (!(e.target.closest && e.target.closest('.lp-rate-popover'))) {
                closeRatePopover(playerEl);
            }

            var raw = e.target.closest && e.target.closest('.vjs-menu-button-popup');
            if (!raw || !playerEl.contains(raw)) {
                return;
            }
            if (raw.classList.contains('lp-vjs-quality') || raw.closest('.lp-vjs-quality')) {
                return;
            }
            // Ignore native playback-rate (we own it).
            if (raw.classList.contains('vjs-playback-rate') || raw.closest('.vjs-playback-rate')) {
                return;
            }
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
                closeRatePopover(playerEl);
            }
        });
    }

    function withVideoJsPlayer(playerEl, cb) {
        var candidates = [];
        if (playerEl.id) {
            candidates.push(playerEl.id);
        }
        var vid = playerEl.querySelector('video');
        if (vid && vid.id) {
            candidates.push(vid.id);
        }
        if (!candidates.length || typeof window.require !== 'function') {
            cb(null);
            return;
        }
        window.require(['media_videojs/video-lazy'], function (videojs) {
            var found = null;
            try {
                for (var i = 0; i < candidates.length; i++) {
                    found = videojs.getPlayer(candidates[i]);
                    if (found) {
                        break;
                    }
                }
                if (!found && typeof videojs.getPlayers === 'function') {
                    var all = videojs.getPlayers();
                    Object.keys(all || {}).some(function (key) {
                        var p = all[key];
                        if (p && p.el_ && playerEl.contains(p.el_)) {
                            found = p;
                            return true;
                        }
                        return false;
                    });
                }
            } catch (err) {
                found = null;
            }
            cb(found);
        }, function () {
            cb(null);
        });
    }

    function findVideoEl(host) {
        return host.querySelector('video.video-js, .video-js video, video[data-lp-video-el], video');
    }

    function findPlayerRoot(host) {
        return host.querySelector('.video-js') || findVideoEl(host);
    }

    function stripNativeChrome(host) {
        host.querySelectorAll('video').forEach(function (v) {
            v.removeAttribute('controls');
            v.setAttribute('preload', 'metadata');
            v.setAttribute('playsinline', '');
            v.setAttribute('controlslist', 'nodownload');
        });
    }

    function capturePreview(video, previewEl) {
        if (!video || !previewEl || previewEl.getAttribute('data-ready') === '1') {
            return;
        }
        if (!video.videoWidth || !video.videoHeight) {
            return;
        }
        try {
            var canvas = document.createElement('canvas');
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
            previewEl.style.backgroundImage = 'url(' + canvas.toDataURL('image/jpeg', 0.72) + ')';
            previewEl.setAttribute('data-ready', '1');
        } catch (err) {
            // Cross-origin or empty frame — keep navy fallback.
        }
    }

    function hideBoot(host) {
        host.classList.remove('is-booting', 'is-ready');
        host.classList.add('is-playing');
        var boot = host.querySelector('[data-lp-vjs-boot]');
        if (boot) {
            boot.setAttribute('hidden', '');
        }
    }

    function showReady(host) {
        host.classList.remove('is-booting');
        host.classList.add('is-ready');
        var loader = host.querySelector('[data-lp-vjs-loader]');
        var playBtn = host.querySelector('[data-lp-vjs-play]');
        if (loader) {
            loader.setAttribute('hidden', '');
        }
        if (playBtn) {
            playBtn.removeAttribute('hidden');
        }
    }

    function bindBoot(host, playerEl, player) {
        // Keep a live player handle — early boot runs before VideoJS upgrades.
        host.__lpVjsPlayer = player || host.__lpVjsPlayer || null;

        if (host.getAttribute('data-lp-boot-bound') === '1') {
            if (player && host.getAttribute('data-lp-boot-player') !== '1') {
                host.setAttribute('data-lp-boot-player', '1');
                player.on('play', function () {
                    hideBoot(host);
                });
                player.on('loadeddata', function () {
                    var preview = host.querySelector('[data-lp-vjs-preview]');
                    capturePreview(findVideoEl(host), preview);
                    if (!host.classList.contains('is-playing')) {
                        showReady(host);
                    }
                });
                player.on('loadedmetadata', function () {
                    if (!host.classList.contains('is-playing')) {
                        showReady(host);
                    }
                });
            }
            return;
        }
        host.setAttribute('data-lp-boot-bound', '1');
        host.classList.add('is-booting');

        var preview = host.querySelector('[data-lp-vjs-preview]');
        var playBtn = host.querySelector('[data-lp-vjs-play]');
        var video = findVideoEl(host);

        function tryReady() {
            if (host.classList.contains('is-playing')) {
                return;
            }
            if (video) {
                capturePreview(video, preview);
            }
            // Prefer first frame; still unlock play once metadata exists.
            if (!video || video.readyState >= 2 || video.videoWidth > 0) {
                showReady(host);
            }
        }

        if (video) {
            ['loadeddata', 'loadedmetadata', 'canplay', 'seeked'].forEach(function (ev) {
                video.addEventListener(ev, tryReady);
            });
            if (video.readyState >= 2) {
                tryReady();
            } else {
                try {
                    video.load();
                } catch (err) {
                    // ignore
                }
            }
            // Fallback so loader never sticks forever.
            window.setTimeout(function () {
                if (!host.classList.contains('is-playing')) {
                    showReady(host);
                }
            }, 4000);
            video.addEventListener('play', function () {
                hideBoot(host);
            });
        } else {
            showReady(host);
        }

        function startPlayback(e) {
            if (e) {
                e.preventDefault();
                e.stopPropagation();
            }
            hideBoot(host);
            var active = host.__lpVjsPlayer;
            if (active && typeof active.play === 'function') {
                var p = active.play();
                if (p && typeof p.catch === 'function') {
                    p.catch(function () {});
                }
                return;
            }
            var v = findVideoEl(host);
            if (v) {
                var p2 = v.play();
                if (p2 && typeof p2.catch === 'function') {
                    p2.catch(function () {});
                }
            }
        }

        if (playBtn) {
            playBtn.addEventListener('click', startPlayback);
        }

        if (player) {
            host.setAttribute('data-lp-boot-player', '1');
            player.on('play', function () {
                hideBoot(host);
            });
            player.on('loadeddata', tryReady);
            player.on('loadedmetadata', tryReady);
        }
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

    function firstMatch(bar, selectors) {
        for (var i = 0; i < selectors.length; i++) {
            var el = bar.querySelector(selectors[i]);
            if (el) {
                return el;
            }
        }
        return null;
    }

    function layoutControlBar(playerEl) {
        var bar = playerEl.querySelector('.vjs-control-bar');
        if (!bar) {
            return;
        }

        // Remove chrome we never want (PiP was landing left of Play after reorder).
        bar.querySelectorAll([
            '.vjs-picture-in-picture-control',
            '.vjs-live-control',
            '.vjs-seek-to-live-control',
            '.vjs-live-display',
            '.vjs-remaining-time',
            '.vjs-chapters-button',
            '.vjs-descriptions-button',
            '.vjs-audio-button',
            '.vjs-skip-backward-undefined',
            '.vjs-skip-forward-undefined',
        ].join(',')).forEach(function (el) {
            el.remove();
        });

        // Exact Stitch order: progress | play · sound · time | 1x · CC · settings · fullscreen.
        var progress = bar.querySelector('.vjs-progress-control');
        var play = firstMatch(bar, ['.vjs-play-control']);
        var volume = firstMatch(bar, ['.vjs-volume-panel', '.vjs-mute-control']);
        var current = firstMatch(bar, ['.vjs-current-time']);
        var divider = firstMatch(bar, ['.vjs-time-divider']);
        var duration = firstMatch(bar, ['.vjs-duration']);
        var rate = firstMatch(bar, ['.vjs-playback-rate']);
        var caps = firstMatch(bar, ['.vjs-subs-caps-button', '.vjs-captions-button', '.vjs-subtitles-button']);
        var quality = firstMatch(bar, ['.lp-vjs-quality']);
        var fullscreen = firstMatch(bar, ['.vjs-fullscreen-control']);

        var spacer = bar.querySelector('.lp-vjs-spacer');
        if (!spacer) {
            spacer = document.createElement('div');
            spacer.className = 'vjs-control lp-vjs-spacer';
            spacer.setAttribute('aria-hidden', 'true');
        }

        var ordered = [
            progress,
            play,
            volume,
            current,
            divider,
            duration,
            spacer,
            rate,
            caps,
            quality,
            fullscreen,
        ].filter(Boolean);

        // Rebuild bar children strictly — leftovers previously stayed at the front (PiP).
        ordered.forEach(function (el) {
            bar.appendChild(el);
        });
        Array.from(bar.children).forEach(function (child) {
            if (ordered.indexOf(child) === -1) {
                child.remove();
            }
        });
    }

    function isTouchUi() {
        try {
            return !!(window.matchMedia &&
                window.matchMedia('(hover: none), (pointer: coarse)').matches);
        } catch (err) {
            return 'ontouchstart' in window;
        }
    }

    /**
     * Mobile: tap the stage to show controls again after they auto-hide.
     * Desktop mouse-move activity does not fire on phones.
     */
    function bindTapToShowControls(playerEl, player) {
        if (!player || playerEl.getAttribute('data-lp-tap-controls') === '1') {
            return;
        }
        playerEl.setAttribute('data-lp-tap-controls', '1');
        playerEl.classList.add('lp-vjs-touch-controls');

        // Keep chrome up longer on touch so 1x / CC / gear are tappable.
        try {
            if (typeof player.options === 'function') {
                player.options({ inactivityTimeout: isTouchUi() ? 5000 : 3000 });
            }
        } catch (err) {
            // ignore
        }

        function reveal() {
            try {
                player.userActive(true);
            } catch (err) {
                playerEl.classList.add('vjs-user-active');
                playerEl.classList.remove('vjs-user-inactive');
            }
        }

        player.on('play', reveal);
        player.on('pause', reveal);

        // Any touch on the player surface re-shows the bar.
        playerEl.addEventListener('touchstart', function () {
            reveal();
        }, { passive: true });

        // Tap tech (video) while playing → show controls (do not steal control-bar taps).
        playerEl.addEventListener('click', function (e) {
            if (!playerEl.classList.contains('vjs-has-started')) {
                return;
            }
            if (e.target.closest && e.target.closest('.vjs-control-bar, .vjs-menu, .lp-vjs-boot')) {
                reveal();
                return;
            }
            // Stage tap: always reveal; second quick path lets VideoJS pause/play as usual.
            reveal();
        }, true);
    }

    function enhanceHost(host) {
        stripNativeChrome(host);

        var playerEl = findPlayerRoot(host);
        if (!playerEl) {
            return false;
        }

        // Native-only path (no VideoJS upgrade).
        if (!playerEl.classList.contains('video-js') && playerEl.tagName === 'VIDEO') {
            if (host.getAttribute('data-lp-vjs-enhanced') === '1') {
                return true;
            }
            host.setAttribute('data-lp-vjs-enhanced', '1');
            bindBoot(host, playerEl, null);
            return true;
        }

        if (!playerEl.querySelector('.vjs-control-bar')) {
            // Still booting VideoJS — keep overlay, strip native controls early.
            bindBoot(host, playerEl, null);
            return false;
        }

        if (host.getAttribute('data-lp-vjs-enhanced') === '1') {
            // VideoJS may re-inject PiP after first enhance — keep layout strict.
            layoutControlBar(playerEl);
            return true;
        }
        host.setAttribute('data-lp-vjs-enhanced', '1');
        playerEl.classList.add('lp-vjs-skin');

        layoutControlBar(playerEl);
        bindClickMenus(playerEl, null);
        bindBoot(host, playerEl, null);

        var qualities = parseQualities(host);
        withVideoJsPlayer(playerEl, function (player) {
            buildQualityButton(host, playerEl, player, qualities);
            layoutControlBar(playerEl);
            bindClickMenus(playerEl, player);
            bindBoot(host, playerEl, player);
            bindTapToShowControls(playerEl, player);
            if (player) {
                player.on('loadedmetadata', function () {
                    layoutControlBar(playerEl);
                });
                player.on('play', function () {
                    layoutControlBar(playerEl);
                });
                // Catch late-injected controls (PiP often appears after first paint).
                window.setTimeout(function () {
                    layoutControlBar(playerEl);
                }, 300);
                window.setTimeout(function () {
                    layoutControlBar(playerEl);
                }, 1200);
            }
        });
        return true;
    }

    function scan() {
        document.querySelectorAll('[data-lp-video-player].lp-activity-videojs').forEach(function (host) {
            enhanceHost(host);
        });
    }

    var tries = 0;
    var timer = window.setInterval(function () {
        tries += 1;
        scan();
        var pending = document.querySelectorAll(
            '[data-lp-video-player].lp-activity-videojs:not([data-lp-vjs-enhanced="1"])'
        );
        if (!pending.length || tries > 60) {
            window.clearInterval(timer);
        }
    }, 200);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('[data-lp-video-player].lp-activity-videojs').forEach(stripNativeChrome);
            scan();
        });
    } else {
        document.querySelectorAll('[data-lp-video-player].lp-activity-videojs').forEach(stripNativeChrome);
        scan();
    }
})();
