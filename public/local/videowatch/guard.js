// Playback guard for local_videowatch.
// Skipping ahead is pulled back to the watched position. The server still
// decides completion; this only stops the ordinary player controls.
// Progress responses also update optional LaunchPad UI hooks and fire
// `local-videowatch:progress` for live Done / progress-bar feedback (Slice 2).
(function () {
    var node = document.getElementById('local-videowatch');
    if (!node) {
        return;
    }
    var cfg = {
        cmid: node.getAttribute('data-cmid'),
        sesskey: node.getAttribute('data-sesskey'),
        url: node.getAttribute('data-url'),
        frontier: parseFloat(node.getAttribute('data-frontier') || '0'),
        duration: parseFloat(node.getAttribute('data-duration') || '0'),
        required: parseFloat(node.getAttribute('data-required') || '0'),
        completed: node.getAttribute('data-completed') === '1'
    };
    var snapping = false;
    var lastSent = 0;
    var lastCompletedFired = cfg.completed;

    /** Raw 0–100 toward required watch; UI shows stepped milestones only. */
    function pctOfRequired(frontier, required) {
        if (!(required > 0)) {
            return 0;
        }
        return Math.min(100, Math.max(0, Math.floor((frontier / required) * 100)));
    }

    /** Snap to 0 / 25 / 50 / 75 / 100 — not a continuously ticking bar. */
    function stepPct(raw, completed) {
        if (completed || raw >= 100) {
            return 100;
        }
        if (raw >= 75) {
            return 75;
        }
        if (raw >= 50) {
            return 50;
        }
        if (raw >= 25) {
            return 25;
        }
        return 0;
    }

    function publish(data) {
        if (!data || data.ok === false) {
            return;
        }
        if (typeof data.frontier === 'number') {
            cfg.frontier = data.frontier;
            node.setAttribute('data-frontier', String(data.frontier));
            node.setAttribute('data-lp-videowatch-progress', String(data.frontier));
        }
        if (typeof data.duration === 'number') {
            cfg.duration = data.duration;
            node.setAttribute('data-duration', String(data.duration));
        }
        if (typeof data.required === 'number') {
            cfg.required = data.required;
            node.setAttribute('data-required', String(data.required));
        }
        if (typeof data.completed === 'boolean') {
            cfg.completed = data.completed;
            node.setAttribute('data-completed', data.completed ? '1' : '0');
        }

        var raw = pctOfRequired(cfg.frontier, cfg.required > 0 ? cfg.required : cfg.duration * 0.9);
        var pct = stepPct(raw, !!cfg.completed);
        node.setAttribute('data-pct', String(pct));
        node.setAttribute('data-pct-raw', String(raw));

        var fill = document.querySelector('[data-lp-videowatch-bar-fill]');
        if (fill) {
            fill.style.width = pct + '%';
            fill.setAttribute('aria-valuenow', String(pct));
        }
        var label = document.querySelector('[data-lp-videowatch-bar-label]');
        if (label) {
            label.textContent = pct + '%';
        }
        var bar = document.querySelector('[data-lp-videowatch-bar]');
        if (bar) {
            bar.setAttribute('data-pct', String(pct));
            if (cfg.completed || pct >= 100) {
                bar.classList.add('is-complete');
            }
        }

        var detail = {
            cmid: cfg.cmid,
            frontier: cfg.frontier,
            duration: cfg.duration,
            required: cfg.required,
            completed: !!cfg.completed,
            pct: pct,
            pctRaw: raw,
            justCompleted: !!(cfg.completed && !lastCompletedFired)
        };
        if (cfg.completed) {
            lastCompletedFired = true;
        }
        try {
            document.dispatchEvent(new CustomEvent('local-videowatch:progress', {detail: detail}));
        } catch (e) {
            // IE / old: ignore.
        }
    }

    function limit(video) {
        if (snapping) {
            return;
        }
        if (video.playbackRate !== 1) {
            video.playbackRate = 1;
        }
        if (video.currentTime > cfg.frontier + 3) {
            snapping = true;
            video.currentTime = cfg.frontier;
            snapping = false;
        }
    }

    function send(video) {
        var now = Date.now();
        if (now - lastSent < 900) {
            return;
        }
        lastSent = now;
        var body = 'sesskey=' + encodeURIComponent(cfg.sesskey)
            + '&cmid=' + encodeURIComponent(cfg.cmid)
            + '&position=' + encodeURIComponent(String(video.currentTime));
        fetch(cfg.url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: body
        }).then(function (response) {
            return response.json();
        }).then(function (data) {
            publish(data);
        }).catch(function () {
            return null;
        });
    }

    function arm(video) {
        if (video.dataset.videowatchArmed === '1') {
            return;
        }
        video.dataset.videowatchArmed = '1';
        video.controlsList = 'nodownload noplaybackrate';
        video.disablePictureInPicture = true;
        video.addEventListener('contextmenu', function (event) {
            event.preventDefault();
        });
        video.addEventListener('ratechange', function () {
            limit(video);
        });
        video.addEventListener('seeking', function () {
            limit(video);
        });
        video.addEventListener('seeked', function () {
            limit(video);
        });
        video.addEventListener('timeupdate', function () {
            limit(video);
            if (!video.paused && !video.ended) {
                send(video);
            }
        });
        video.addEventListener('ended', function () {
            send(video);
        });
    }

    function scan() {
        document.querySelectorAll('.mediafallbacklink').forEach(function (link) {
            link.hidden = true;
        });
        document.querySelectorAll('video').forEach(function (video) {
            arm(video);
            if (!window.videojs || !video.id || video.dataset.videowatchPlayer === '1') {
                return;
            }
            try {
                var player = videojs.getPlayer(video.id);
                if (!player) {
                    return;
                }
                video.dataset.videowatchPlayer = '1';
                player.playbackRate(1);
                player.on('ratechange', function () {
                    if (player.playbackRate() !== 1) {
                        player.playbackRate(1);
                    }
                });
                player.on('seeking', function () {
                    limit(video);
                });
            } catch (error) {
                return;
            }
        });
    }

    // Initial paint for LaunchPad progress bar (SSR pct).
    publish({
        ok: true,
        frontier: cfg.frontier,
        duration: cfg.duration,
        required: cfg.required,
        completed: cfg.completed
    });

    scan();
    document.addEventListener('DOMContentLoaded', scan);
    var ticks = 0;
    var timer = setInterval(function () {
        scan();
        ticks += 1;
        if (ticks > 20) {
            clearInterval(timer);
        }
    }, 500);
    if (window.MutationObserver && document.body) {
        new MutationObserver(scan).observe(document.body, {childList: true, subtree: true});
    }
})();
