/**
 * Spliteezy Tracker — Vanilla JS, no dependencies.
 *
 * Responsibilities:
 *  - Fire a page_view event on load.
 *  - Detect goal-page visits.
 *  - Track scroll depth (25 / 50 / 75 / 100%).
 *  - Track time on page (10s, 30s, 60s milestones).
 *  - Track element visibility (via IntersectionObserver).
 *  - Track clicks on configured CSS selectors.
 *  - Track video play (YouTube, Vimeo, HTML5 <video>).
 *  - Queue and batch-send events to the WP AJAX endpoint.
 *
 * The API key NEVER touches the browser. Events go to WP AJAX (nonce-protected),
 * which proxies them to the Laravel API with HMAC signing.
 *
 * Convention: goal conversions are tracked as 'goal_page' events (the type
 * Variant::uniqueConversions() queries). Goal-specific event types like
 * 'click', 'element_view', 'video_play' are also pushed for analytics detail.
 */

(function () {
  'use strict';

  /** @type {{ ajax_url: string, nonce: string, context: Object, goals: Array }} */
  const cfg = window.SpliteezyConfig;
  if (!cfg || !cfg.context) return;

  const goals = cfg.goals || [];

  // ── Context resolution ─────────────────────────────────────────────────────
  //
  // Server mode: the context (visitor + assigned variant) is localized by PHP.
  // Cache-safe (client) mode: the page HTML is variant-neutral, so the inline
  // resolver script computes the assignment in the browser and exposes it as
  // window.SpliteezyAssignment / the 'spliteezy:assigned' event. Wait for it —
  // never track with a guessed variant.

  if (cfg.context.client_side) {
    var booted = false;

    var bootFromAssignment = function (assignment) {
      if (booted || !assignment || assignment.test_id !== cfg.context.test_id) return;
      booted = true;
      boot({
        test_id: assignment.test_id,
        variant_id: assignment.variant_id,
        visitor_id: assignment.visitor_id,
      });
    };

    if (window.SpliteezyAssignment) {
      bootFromAssignment(window.SpliteezyAssignment);
    }

    document.addEventListener('spliteezy:assigned', function (e) {
      bootFromAssignment(e.detail);
    });

    return;
  }

  boot(cfg.context);

  // ── Visitor identity fallback ──────────────────────────────────────────────
  //
  // Vary-mode HTML is cached and shared between visitors, so the context
  // carries no visitor_id — resolve it from the first-party cookie instead
  // (same cookie and format as Visitor::id() on the PHP side).

  function eezyGetCookie(name) {
    const m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : null;
  }

  function eezyVisitorId() {
    let id = eezyGetCookie('spliteezy_vid');
    if (id && /^eezy_[a-f0-9]{32}$/.test(id)) return id;

    const arr = new Uint8Array(16);
    (window.crypto || window.msCrypto).getRandomValues(arr);
    id = 'eezy_';
    for (let i = 0; i < arr.length; i++) id += (arr[i] + 0x100).toString(16).slice(1);

    document.cookie = 'spliteezy_vid=' + id + '; path=/; max-age=31536000; samesite=lax' +
      (location.protocol === 'https:' ? '; secure' : '');
    return id;
  }

  function boot(ctx) {

  if (!ctx.visitor_id) {
    ctx.visitor_id = eezyVisitorId();
  }

  // ── Event queue ────────────────────────────────────────────────────────────

  /** @type {Array<Object>} */
  let queue = [];
  let flushTimer = null;

  function push(type, meta = {}) {
    queue.push({
      type,
      test_id: ctx.test_id,
      variant_id: ctx.variant_id,
      visitor_id: ctx.visitor_id,
      url: window.location.href,
      meta,
      occurred_at: Math.floor(Date.now() / 1000),
    });

    scheduleFlush();
  }

  function scheduleFlush() {
    if (flushTimer) return;
    flushTimer = setTimeout(flush, 1500);
  }

  function flush() {
    flushTimer = null;

    if (!queue.length) return;

    const events = queue.slice();
    queue = [];

    const body = new FormData();
    body.append('action', 'spliteezy_event');
    body.append('nonce', cfg.nonce);
    body.append('events', JSON.stringify(events));

    // keepalive keeps this request alive past page unload (pagehide/visibilitychange flush).
    fetch(cfg.ajax_url, { method: 'POST', body, credentials: 'same-origin', keepalive: true }).catch(function () {
      // Re-queue on network failure (best-effort, no infinite retry).
      queue = events.concat(queue);
    });
  }

  // Flush before the user navigates away.
  window.addEventListener('pagehide', flush);
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') flush();
  });

  // ── Page view ──────────────────────────────────────────────────────────────

  push('page_view');

  // ── Goal page ─────────────────────────────────────────────────────────────

  goals.forEach(function (goal) {
    if (goal.type !== 'page_reached') return;
    if (!goal.url) return;

    const pattern = goal.url.replace(/[-[\]{}()*+?.,\\^$|#\s]/g, '\\$&').replace(/\\\*/g, '.*');
    const re = new RegExp('^' + pattern + '$');

    if (re.test(window.location.href)) {
      push('goal_page', { goal_id: goal.id });
    }
  });

  // ── Click tracking ─────────────────────────────────────────────────────────
  //
  // Uses the CAPTURE phase (true) so our handler runs before any
  // stopPropagation() call from modal libraries, accordions, etc. that
  // intercept clicks on their trigger elements.

  var clickGoalsFired = new Set();

  goals.forEach(function (goal) {
    if (goal.type !== 'click') return;
    if (!goal.selector) return;

    document.addEventListener('click', function (e) {
      if (clickGoalsFired.has(goal.id)) return;
      var matched;
      try {
        matched = e.target.closest(goal.selector);
      } catch (_) {
        return; // Invalid selector — fail silently.
      }
      if (matched) {
        clickGoalsFired.add(goal.id);
        push('click',     { goal_id: goal.id, selector: goal.selector });
        push('goal_page', { goal_id: goal.id, selector: goal.selector });
      }
    }, true); // capture phase
  });

  // ── Scroll depth ───────────────────────────────────────────────────────────

  const scrollMilestones = [25, 50, 75, 100];
  const scrollFired = new Set();
  const scrollGoalsFired = new Set();

  function onScroll() {
    const scrolled = window.scrollY + window.innerHeight;
    const total = document.documentElement.scrollHeight;
    const pct = Math.round((scrolled / total) * 100);

    scrollMilestones.forEach(function (milestone) {
      if (pct >= milestone && !scrollFired.has(milestone)) {
        scrollFired.add(milestone);
        push('scroll', { percent: milestone });

        goals.forEach(function (goal) {
          if (goal.type !== 'scroll_depth') return;
          if (scrollGoalsFired.has(goal.id)) return;
          // Fire when the user first reaches the goal's threshold.
          if (goal.percent <= milestone) {
            scrollGoalsFired.add(goal.id);
            push('goal_page', { goal_id: goal.id, percent: goal.percent });
          }
        });
      }
    });
  }

  window.addEventListener('scroll', onScroll, { passive: true });

  // ── Time on page ───────────────────────────────────────────────────────────

  const timeMilestones = [10, 30, 60];
  const timeGoalsFired = new Set();

  timeMilestones.forEach(function (seconds) {
    setTimeout(function () {
      if (document.visibilityState === 'hidden') return;
      push('time_on_page', { seconds });

      goals.forEach(function (goal) {
        if (goal.type !== 'time_on_page') return;
        if (timeGoalsFired.has(goal.id)) return;
        // Fire when the user first reaches the goal's threshold.
        if (goal.seconds <= seconds) {
          timeGoalsFired.add(goal.id);
          push('goal_page', { goal_id: goal.id, seconds: goal.seconds });
        }
      });
    }, seconds * 1000);
  });

  // ── Element view (IntersectionObserver) ───────────────────────────────────

  if (typeof IntersectionObserver !== 'undefined') {
    goals.forEach(function (goal) {
      if (goal.type !== 'element_view') return;
      if (!goal.selector) return;

      var el;
      try {
        el = document.querySelector(goal.selector);
      } catch (_) {
        return; // Invalid selector.
      }
      if (!el) return;

      const fired = { done: false };
      const obs = new IntersectionObserver(function (entries) {
        if (fired.done) return;
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            fired.done = true;
            obs.disconnect();
            push('element_view', { goal_id: goal.id, selector: goal.selector });
            push('goal_page',    { goal_id: goal.id, selector: goal.selector });
          }
        });
      }, { threshold: 0.5 });

      obs.observe(el);
    });
  }

  // ── Video play (YouTube, Vimeo, HTML5) ────────────────────────────────────

  const videoGoals = goals.filter(function (g) { return g.type === 'video_play'; });

  if (videoGoals.length) {

    // Helper: push a video_play event + a goal_page for every video goal.
    function onVideoPlay(platform, src) {
      push('video_play', { platform: platform, src: src });
      videoGoals.forEach(function (goal) {
        push('goal_page', { goal_id: goal.id, platform: platform });
      });
    }

    // ── HTML5 <video> ──────────────────────────────────────────────────────
    document.querySelectorAll('video').forEach(function (video) {
      var fired = false;
      video.addEventListener('play', function () {
        if (fired) return;
        fired = true;
        onVideoPlay('html5', video.src || video.currentSrc || '');
      });
    });

    // ── YouTube IFrame API ─────────────────────────────────────────────────
    var ytIframes = document.querySelectorAll('iframe[src*="youtube.com"], iframe[src*="youtube-nocookie.com"]');
    if (ytIframes.length) {
      // Ensure enablejsapi=1 is present before loading the API — Gutenberg
      // embeds often omit it, which causes YT.Player to silently fail.
      ytIframes.forEach(function (iframe) {
        if (iframe.src && iframe.src.indexOf('enablejsapi=1') === -1) {
          iframe.src += (iframe.src.indexOf('?') !== -1 ? '&' : '?') + 'enablejsapi=1';
        }
      });

      var existingCallback = window.onYouTubeIframeAPIReady;

      window.onYouTubeIframeAPIReady = function () {
        if (typeof existingCallback === 'function') existingCallback();

        document.querySelectorAll('iframe[src*="youtube.com"], iframe[src*="youtube-nocookie.com"]').forEach(function (iframe) {
          try {
            new YT.Player(iframe, { // eslint-disable-line no-undef
              events: {
                onStateChange: function (event) {
                  if (event.data === YT.PlayerState.PLAYING) { // eslint-disable-line no-undef
                    onVideoPlay('youtube', iframe.src);
                  }
                },
              },
            });
          } catch (_) {}
        });
      };

      // Only inject if the YT API hasn't loaded yet.
      if (!window.YT || !window.YT.Player) {
        var tag = document.createElement('script');
        tag.src = 'https://www.youtube.com/iframe_api';
        document.head.appendChild(tag);
      } else {
        window.onYouTubeIframeAPIReady();
      }
    }

    // ── Vimeo Player API ───────────────────────────────────────────────────
    if (document.querySelector('iframe[src*="vimeo.com"]')) {
      var vimeoScript = document.createElement('script');
      vimeoScript.src = 'https://player.vimeo.com/api/player.js';
      vimeoScript.onload = function () {
        document.querySelectorAll('iframe[src*="vimeo.com"]').forEach(function (iframe) {
          try {
            var player = new Vimeo.Player(iframe); // eslint-disable-line no-undef
            var fired = false;
            player.on('play', function () {
              if (fired) return;
              fired = true;
              onVideoPlay('vimeo', iframe.src);
            });
          } catch (_) {}
        });
      };
      document.head.appendChild(vimeoScript);
    }
  }

  // ── Form submission ───────────────────────────────────────────────────────
  //
  // Uses the CAPTURE phase so our handler fires before any plugin's own handler
  // calls preventDefault() (AJAX form plugins: CF7, WPForms, Gravity Forms, etc.).
  // A single document-level listener handles all forms — no per-plugin setup.

  var formGoalsFired = new Set();

  goals.forEach(function (goal) {
    if (goal.type !== 'form_submission') return;

    document.addEventListener('submit', function (e) {
      if (formGoalsFired.has(goal.id)) return;

      // If a selector was configured, only match forms that satisfy it.
      if (goal.selector) {
        var matched = false;
        try {
          matched = e.target.matches(goal.selector) || !!e.target.closest(goal.selector);
        } catch (_) {
          return; // Invalid selector — fail silently.
        }
        if (!matched) return;
      }

      formGoalsFired.add(goal.id);
      push('form_submission', { goal_id: goal.id, selector: goal.selector || null });
      push('goal_page',      { goal_id: goal.id, selector: goal.selector || null });
    }, true); // capture phase
  });

  // ── External event listeners (GA4 / GTM / Meta Pixel) ────────────────────

  goals.forEach(function (goal) {
    if (goal.type !== 'external_event') return;
    if (!goal.event_name) return;

    // Listen for custom DOM events dispatched by GA4/GTM/Meta Pixel helpers.
    document.addEventListener('eezy:external:' + goal.event_name, function (e) {
      push('goal_page', { goal_id: goal.id, event_name: goal.event_name, detail: e.detail });
    });
  });

  // Expose a helper for theme/plugin code to report external events.
  window.Spliteezy = window.Spliteezy || {};
  window.Spliteezy.trackEvent = function (eventName, detail) {
    var d = detail || {};
    document.dispatchEvent(new CustomEvent('eezy:external:' + eventName, { detail: d }));

    // Auto-forward to GA4 if gtag is present.
    if (typeof window.gtag === 'function') {
      window.gtag('event', eventName, d);
    }

    // Auto-forward to GTM dataLayer if present.
    if (Array.isArray(window.dataLayer)) {
      window.dataLayer.push(Object.assign({ event: eventName }, d));
    }
  };
  } // end boot()
})();
