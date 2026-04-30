/**
 * BlockTicker Landing Page Revamp — interactive layer.
 * Handles: reveal-on-scroll, signal auto-cycle (§2), howit auto-cycle (§3),
 * formula bars, hero count-up, live time, frame tabs, CTA form,
 * sticky nav scroll class, avatar workspace dropdown, mobile drawer,
 * persistent live-counter random walk + latency cycling.
 *
 * @since 119.28
 */
(function(){
  'use strict';

  /* v119.28.3: Make sure <body> is marked, in every code path.
     The inline script in the template runs first; this is a belt-and-braces
     re-assert so caching / async loading edge cases don't leave the page
     half-styled. Idempotent — classList.add on an existing class is a no-op. */
  try {
    if (document.body && document.querySelector('.btlp')) {
      document.body.classList.add('bt-landing-page');
      document.documentElement.classList.add('bt-landing-page');
    }
  } catch (e) {}

  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const $  = (sel, root) => (root || document).querySelector(sel);
  const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));

  /* ── Reveal on scroll ── */
  if (!reduce) {
    const io = new IntersectionObserver((entries) => {
      entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } });
    }, { threshold: 0.05, rootMargin: '0px 0px -40px 0px' });
    $$('.btlp .reveal').forEach(el => io.observe(el));
  } else {
    $$('.btlp .reveal').forEach(el => el.classList.add('in'));
  }

  /* ── §2 Signal-in-action: auto-cycle + click ── */
  (function sigAction(){
    const dots = $$('#sig-steps .sig-action__dot');
    const states = $$('#sig-stage .sig-state');
    if (!dots.length) return;
    let active = 1, timer = null;
    const setActive = (n) => {
      active = n;
      dots.forEach(d => d.classList.toggle('active', +d.dataset.step === n));
      states.forEach(s => {
        const wasActive = s.classList.contains('active');
        s.classList.toggle('active', +s.dataset.step === n);
        if (!wasActive && s.classList.contains('active') && s.classList.contains('score-stage')) {
          s.querySelectorAll('.score-bar__fill').forEach(f => {
            const w = f.style.getPropertyValue('--w') || '0%';
            f.style.width = '0%';
            requestAnimationFrame(() => { f.style.transition='width 1.2s cubic-bezier(.16,1,.3,1)'; f.style.width = w; });
          });
        }
      });
    };
    const advance = () => setActive(active >= 4 ? 1 : active + 1);
    const start = () => { if (!timer && !reduce) timer = setInterval(advance, 6000); };
    const stop  = () => { if (timer) { clearInterval(timer); timer = null; } };
    dots.forEach(d => d.addEventListener('click', () => { stop(); setActive(+d.dataset.step); start(); }));
    const stage = $('#sig-stage');
    if (stage) new IntersectionObserver(([e]) => { if (e.isIntersecting) start(); else stop(); }, { threshold: 0.4 }).observe(stage);
  })();

  /* ── §3 Howit — auto-cycle (one block at a time, fade + slide; click progress to jump) ── */
  (function howit(){
    const root      = $('#how.howit--cycle') || $('.btlp .howit--cycle');
    if (!root) return;
    const blocks    = $$('#howit-nar .howit__block');
    const states    = $$('#howit-panel .howit__state');
    const segs      = $$('#howit-progress .howit__progress-seg');
    const stageLbl  = $('#howit-stage-label');
    const STAGE_TXT = {
      1: 'Streaming · 15 sources',
      2: 'Detectors · running',
      3: 'Computing weighted confidence',
      4: 'Output · the signal you receive'
    };
    const DUR = 5400; // ms per step (matches CSS --howit-dur default)
    if (!blocks.length || !states.length || !segs.length) return;

    if (reduce) {
      blocks.forEach(b => b.classList.add('is-active'));
      states.forEach((s, i) => s.classList.toggle('active', i === 0));
      return;
    }

    let active = 1;
    let timer = null;

    const swapStageLabel = (n) => {
      if (!stageLbl) return;
      stageLbl.classList.add('is-swap');
      setTimeout(() => {
        stageLbl.textContent = STAGE_TXT[n] || '';
        stageLbl.classList.remove('is-swap');
      }, 200);
    };

    const triggerStateAnimations = (state, n) => {
      // Re-fire dial rings on state 2 every cycle return
      if (n === 2) {
        state.querySelectorAll('.dial circle.fill').forEach(c => {
          const off = c.style.getPropertyValue('--off');
          c.style.transition = 'none';
          c.style.strokeDashoffset = '188.5';
          requestAnimationFrame(() => requestAnimationFrame(() => {
            c.style.transition = 'stroke-dashoffset 1s cubic-bezier(.16,1,.3,1)';
            c.style.strokeDashoffset = off;
          }));
        });
      }
      // Re-fire score bars on state 3 every cycle return
      if (n === 3) {
        state.querySelectorAll('.score-bar__fill').forEach(f => {
          const w = f.style.getPropertyValue('--w') || '0%';
          f.style.transition = 'none';
          f.style.width = '0%';
          requestAnimationFrame(() => requestAnimationFrame(() => {
            f.style.transition = 'width 1.1s cubic-bezier(.16,1,.3,1)';
            f.style.width = w;
          }));
        });
      }
    };

    const setActive = (n) => {
      // Mark previous as leaving (fade-out animation)
      if (n !== active) {
        const prev = blocks.find(b => +b.dataset.step === active);
        if (prev) {
          prev.classList.remove('is-active');
          prev.classList.add('is-leaving');
          setTimeout(() => prev.classList.remove('is-leaving'), 450);
        }
      }
      active = n;

      blocks.forEach(b => b.classList.toggle('is-active', +b.dataset.step === n));
      states.forEach(s => {
        const wasActive = s.classList.contains('active');
        const willBeActive = +s.dataset.state === n;
        s.classList.toggle('active', willBeActive);
        if (!wasActive && willBeActive) triggerStateAnimations(s, n);
      });

      // Progress bars: completed steps filled, current animates fill via CSS
      segs.forEach(seg => {
        const step = +seg.dataset.step;
        // Reset by clearing classes, then re-apply (forces CSS animation restart)
        seg.classList.remove('is-active');
        seg.classList.toggle('is-done', step < n);
        seg.setAttribute('aria-selected', step === n ? 'true' : 'false');
      });
      // Force reflow so the is-active animation restarts even when re-jumping
      // eslint-disable-next-line no-unused-expressions
      void segs[0].offsetWidth;
      const cur = segs.find(s => +s.dataset.step === n);
      if (cur) cur.classList.add('is-active');

      swapStageLabel(n);
    };

    const advance = () => setActive(active >= 4 ? 1 : active + 1);
    const start = () => { if (!timer) timer = setInterval(advance, DUR); root.classList.remove('howit--paused'); };
    const stop  = () => { if (timer) { clearInterval(timer); timer = null; } root.classList.add('howit--paused'); };

    // Click progress segment → jump to that step + reset timer
    segs.forEach(seg => seg.addEventListener('click', () => {
      stop();
      setActive(+seg.dataset.step);
      start();
    }));

    // Pause when out of view
    new IntersectionObserver(([e]) => { if (e.isIntersecting) start(); else stop(); }, { threshold: 0.25 }).observe(root);

    // Pause when tab hidden (saves cycles)
    document.addEventListener('visibilitychange', () => {
      if (document.hidden) stop();
      else if (isInView(root)) start();
    });

    function isInView(el) {
      const r = el.getBoundingClientRect();
      return r.bottom > 0 && r.top < (window.innerHeight || document.documentElement.clientHeight);
    }

    // Initial state (also kicks off the first progress fill animation)
    setActive(1);
  })();

  /* ── §3 Howit live counter — random walk + latency cycling ── */
  (function liveMetrics(){
    const numEl = document.getElementById('bt-live-num');
    const latEl = document.getElementById('bt-latency');
    if (!numEl && !latEl) return;
    if (reduce) return;

    // Counter random walk: 2,210 ↔ 2,780, ±18 per tick, every 1.3s
    if (numEl) {
      let val = parseInt((numEl.textContent || '2417').replace(/[^\d]/g, ''), 10) || 2417;
      const MIN = 2210, MAX = 2780, STEP = 18;
      const wrap = numEl.parentElement;
      setInterval(() => {
        const drift = Math.round((Math.random() - 0.5) * 2 * STEP);
        val = Math.max(MIN, Math.min(MAX, val + drift));
        numEl.textContent = val.toLocaleString('en-US');
        if (wrap) {
          wrap.classList.remove('is-tick');
          // force reflow to restart flash
          // eslint-disable-next-line no-unused-expressions
          void wrap.offsetWidth;
          wrap.classList.add('is-tick');
        }
      }, 1300);
    }

    // Latency cycling: 28 → 50ms, every 1.9s
    if (latEl) {
      const LATS = [28, 31, 34, 38, 33, 41, 29, 36, 44, 32, 39, 47, 30, 35, 50, 33];
      let idx = 0;
      setInterval(() => {
        idx = (idx + 1) % LATS.length;
        latEl.textContent = LATS[idx];
      }, 1900);
    }
  })();

  /* ── Sticky nav scroll class (BT nav + plugin nav fallback) ── */
  (function navScroll(){
    const navs = [];
    const bt = document.getElementById('bt-nav'); if (bt) navs.push(bt);
    const cp = document.getElementById('cp-navbar') || document.querySelector('.cp-navbar');
    if (cp) navs.push(cp);
    if (!navs.length) return;
    const apply = () => {
      const y = window.scrollY;
      navs.forEach(n => {
        n.classList.toggle('is-scrolled', y > 60);
        n.classList.toggle('scrolled', y > 60); // legacy class for cp-navbar
      });
    };
    apply();
    window.addEventListener('scroll', apply, { passive: true });
  })();

  /* ── Avatar workspace dropdown (logged-in) ── */
  (function avatarDropdown(){
    const btn  = document.getElementById('nav-avatar-btn');
    const menu = document.getElementById('nav-usermenu');
    if (!btn || !menu) return;
    const close = () => { menu.hidden = true;  btn.setAttribute('aria-expanded', 'false'); };
    const open  = () => { menu.hidden = false; btn.setAttribute('aria-expanded', 'true');  };
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      menu.hidden ? open() : close();
    });
    document.addEventListener('click', (e) => {
      if (menu.hidden) return;
      if (!menu.contains(e.target) && e.target !== btn) close();
    });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !menu.hidden) close(); });
  })();

  /* ── Mobile drawer (hamburger toggle, backdrop click, ESC, link-tap close) ── */
  (function drawer(){
    const ham = document.getElementById('nav-hamburger');
    const dr  = document.getElementById('nav-drawer');
    const bd  = document.getElementById('nav-drawer-backdrop');
    const cl  = document.getElementById('nav-drawer-close');
    if (!ham || !dr) return;
    const setOpen = (open) => {
      dr.hidden = !open;
      ham.setAttribute('aria-expanded', open ? 'true' : 'false');
      document.body.dataset.drawer = open ? 'open' : '';
    };
    ham.addEventListener('click', () => setOpen(dr.hidden));
    if (cl) cl.addEventListener('click', () => setOpen(false));
    if (bd) bd.addEventListener('click', () => setOpen(false));
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !dr.hidden) setOpen(false); });
    dr.addEventListener('click', (e) => { if (e.target.closest('a')) setOpen(false); });
  })();

  /* ── Mega menu: ARIA expanded toggling on hover/focus (CSS handles visuals) ── */
  (function megaA11y(){
    $$('.btlp .nav__group').forEach(group => {
      const btn = group.querySelector('.nav__link--has-mega');
      if (!btn) return;
      const set = (open) => btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      group.addEventListener('mouseenter', () => set(true));
      group.addEventListener('mouseleave', () => set(false));
      group.addEventListener('focusin', () => set(true));
      group.addEventListener('focusout', (e) => {
        if (!group.contains(e.relatedTarget)) set(false);
      });
    });
  })();

  /* ── Formula input bar animation on scroll into view ── */
  (function formulaBars(){
    const formula = $('.btlp .formula');
    if (!formula || reduce) return;
    new IntersectionObserver(([e], io) => {
      if (e.isIntersecting) {
        formula.querySelectorAll('.formula__input-fill').forEach(f => {
          const w = f.style.getPropertyValue('--w') || '0%';
          f.style.width = '0%';
          requestAnimationFrame(() => { f.style.transition = 'width 1.2s cubic-bezier(.16,1,.3,1)'; f.style.width = w; });
        });
        io.disconnect();
      }
    }, { threshold: 0.3 }).observe(formula);
  })();

  /* ── Hero confidence count-up ── */
  (function countUp(){
    const el = document.getElementById('hero-conf');
    if (!el || reduce) return;
    const target = parseInt(el.textContent, 10) || 78;
    let val = 0; const dur = 1200, t0 = performance.now();
    const tick = (t) => { const p = Math.min(1, (t - t0) / dur); val = Math.round(target * (1 - Math.pow(1 - p, 3))); el.textContent = val; if (p < 1) requestAnimationFrame(tick); };
    requestAnimationFrame(tick);
  })();

  /* ── Live signal time ── */
  (function liveTime(){
    const el = document.getElementById('signal-time');
    if (!el) return;
    let s = 4 * 60;
    const fmt = (s) => s < 60 ? s + 's ago' : Math.floor(s / 60) + ' min ago';
    el.textContent = fmt(s);
    setInterval(() => { s++; el.textContent = fmt(s); }, 1000);
  })();

  /* ── Skeleton reveal on markets table ── */
  (function skeletonReveal(){
    if (reduce) return;
    $$('[data-skel-on-reveal]').forEach(root => {
      new IntersectionObserver(([e], io) => {
        if (e.isIntersecting) {
          const cells = root.querySelectorAll('.frame__price,.frame__chg');
          const orig = []; cells.forEach(c => { orig.push({ el: c, h: c.innerHTML }); c.classList.add('skel'); c.innerHTML = '\u00a0\u00a0\u00a0\u00a0\u00a0'; });
          setTimeout(() => orig.forEach(({ el, h }) => { el.classList.remove('skel'); el.innerHTML = h; }), 700);
          io.disconnect();
        }
      }, { threshold: 0.3 }).observe(root);
    });
  })();

  /* ── Frame tab switching ── */
  $$('.btlp .frame__tab').forEach(t => {
    t.addEventListener('click', () => { t.closest('.frame__tabs').querySelectorAll('.frame__tab').forEach(x => x.classList.remove('active')); t.classList.add('active'); });
  });

  /* ── CTA form ── */
  (function ctaForm(){
    const form = document.getElementById('cta-form');
    const btn  = document.getElementById('cta-btn');
    const emailEl = document.getElementById('cta-email');
    const ok   = document.getElementById('cta-success');
    if (!form) return;
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const v = (emailEl.value || '').trim();
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) { emailEl.style.borderColor = 'var(--danger)'; emailEl.focus(); return; }
      if (typeof btAuthOpen === 'function') { btAuthOpen('register'); return; }
      btn.disabled = true; btn.textContent = '⏳ subscribing…';
      setTimeout(() => { form.classList.add('is-hidden'); if (ok) { ok.style.display = 'block'; } }, 800);
    });
    if (emailEl) emailEl.addEventListener('input', () => { emailEl.style.borderColor = ''; });
  })();

  /* ── v119.28.4: Risk-acknowledgment modal (first-visit gate)
     Surfaces the "Before you continue" disclaimer the first time a visitor
     lands on the page. Acknowledgment is remembered in localStorage so it
     never re-triggers for the same browser. ESC, backdrop click, and the
     "I understand & continue" button all dismiss; "Read methodology first"
     is a real link that navigates away. ── */
  (function riskModal(){
    const modal = document.getElementById('bt-risk-modal');
    if (!modal) return;
    const KEY = 'btlp.riskAck.v1';
    let acked = false;
    try { acked = localStorage.getItem(KEY) === '1'; } catch (e) {}
    if (acked) return; // already accepted on a prior visit

    // Defer slightly so the page paints first
    const open = () => {
      modal.hidden = false;
      document.body.classList.add('bt-risk-modal-open');
      // Move focus into the modal for keyboard users
      const primary = modal.querySelector('[data-risk-action="accept"]');
      if (primary) primary.focus();
    };
    const close = (persist) => {
      modal.hidden = true;
      document.body.classList.remove('bt-risk-modal-open');
      if (persist) {
        try { localStorage.setItem(KEY, '1'); } catch (e) {}
      }
    };

    // Open ~400ms after first paint so it doesn't race with hero animation
    setTimeout(open, 400);

    modal.addEventListener('click', (e) => {
      const t = e.target.closest('[data-risk-action]');
      if (!t) return;
      const act = t.getAttribute('data-risk-action');
      if (act === 'accept')  close(true);   // remember
      if (act === 'dismiss') close(false);  // backdrop click — don't persist
    });

    document.addEventListener('keydown', (e) => {
      if (modal.hidden) return;
      if (e.key === 'Escape') close(false);
    });
  })();

  /* ── v119.28.4: Admin "Preview as logged-in/out" toggle
     Only renders for admins (PHP-gated by current_user_can). Flips the
     data-auth attribute on .btlp + toggles visibility of the
     [data-show-when="logged-in/out"] elements so admins can see exactly
     what each user state experiences without logging out. ── */
  (function previewToggle(){
    const wrap = document.querySelector('.btlp .nav__preview');
    if (!wrap) return;
    const root = document.querySelector('.btlp');
    if (!root) return;

    wrap.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-preview-as]');
      if (!btn) return;
      const state = btn.getAttribute('data-preview-as'); // "logged-in" | "logged-out"

      // Update active class
      wrap.querySelectorAll('.nav__preview-btn').forEach(b => b.classList.remove('is-active'));
      btn.classList.add('is-active');

      // Flip the auth state — driven entirely by CSS via .btlp[data-auth=...]
      root.setAttribute('data-auth', state);

      // Also flip the elements that use the legacy [data-show-when] hook,
      // for any markup that was rendered server-side in the *opposite*
      // state (e.g. the avatar dropdown is only emitted server-side when
      // the user is actually logged in; the preview toggle can't fabricate
      // user data). We just hide-show what's present in the DOM.
      document.querySelectorAll('.btlp [data-show-when]').forEach(el => {
        const want = el.getAttribute('data-show-when');
        el.style.display = (want === state) ? '' : 'none';
      });
    });
  })();

  /* ── §NOW pill — alternating text messages (matches main site behaviour) ── */
  (function nowPill(){
    const pill = $('.btlp .hero__pill');
    const txtEl = pill && pill.querySelector('.pill__txt');
    const icoEl = pill && pill.querySelector('.pill__ico');
    if (!pill || !txtEl) return;

    const messages = [
      { ico: '⌕', html: "Today's brief is live · <code>/desk-brief</code> →" },
      { ico: '🤖', html: 'AI desk: reading macro, crypto &amp; FX news 24/7' },
      { ico: '⚡', html: 'New signal detected · confidence <strong>82</strong>' },
      { ico: '📡', html: 'Live data · auto-updated 24/7' },
      { ico: '📊', html: 'BTC dominance tracked across 6 exchanges' },
    ];
    let idx = 0;

    const swap = () => {
      if (reduce) return;
      idx = (idx + 1) % messages.length;
      const m = messages[idx];
      txtEl.style.transition = 'opacity .25s';
      txtEl.style.opacity = '0';
      if (icoEl) { icoEl.style.transition = 'opacity .25s'; icoEl.style.opacity = '0'; }
      setTimeout(() => {
        txtEl.innerHTML = m.html;
        if (icoEl) icoEl.textContent = m.ico;
        txtEl.style.opacity = '1';
        if (icoEl) icoEl.style.opacity = '1';
      }, 260);
    };

    setInterval(swap, 3500);
  })();

})();
