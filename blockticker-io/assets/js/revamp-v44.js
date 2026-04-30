/* ══════════════════════════════════════════════════════════════════
   BlockTicker v44 — REVAMP JS
   Scroll reveal · navbar scroll-state · active link detection
   v63.1: Shared TradingView loader (single tv.js download per page)
   ══════════════════════════════════════════════════════════════════ */

/* v63.1 — Shared TradingView loader (must be window-scoped so every
   widget on the page can await the same promise). Previously each chart
   shortcode injected its own <script src="tv.js">, causing 3× downloads
   of a ~400KB script on pages with 3 charts. Now exactly one download
   is shared. */
if (typeof window !== 'undefined' && !window.btLoadTV) {
    window.btLoadTV = function () {
        if (window.__btTvPromise) return window.__btTvPromise;
        window.__btTvPromise = new Promise(function (resolve, reject) {
            // Already loaded? (edge case: cached page, script leaked from prior visit)
            if (typeof window.TradingView !== 'undefined') { resolve(window.TradingView); return; }
            var s = document.createElement('script');
            s.src = 'https://s3.tradingview.com/tv.js';
            s.async = true;
            s.onload = function () { resolve(window.TradingView); };
            s.onerror = function () { reject(new Error('TradingView script failed to load')); };
            document.head.appendChild(s);
        });
        return window.__btTvPromise;
    };

    /* Initialize a single TV widget by wrapper element. Expects standard
       fxlm-tv-lazy wrapper with data-symbol/height/interval/hide-top/hide-side.
       Avoids duplicate init via a dataset guard. */
    /* ── CHART TYPE CONFIGS ── */
    var BT_CHART_TYPES = [
        { id: '1',  label: '▬',  title: 'Candles'    },
        { id: '2',  label: '▲',  title: 'Bars'        },
        { id: '3',  label: '╱',  title: 'Line'        },
        { id: '8',  label: '⬛', title: 'Hollow'      },
        { id: '9',  label: '╲╱', title: 'Heikin Ashi' },
    ];
    var BT_INTERVALS = [
        { key: '1',   label: '1m'  },
        { key: '5',   label: '5m'  },
        { key: '15',  label: '15m' },
        { key: '60',  label: '1h'  },
        { key: '240', label: '4h'  },
        { key: 'D',   label: '1D'  },
        { key: 'W',   label: '1W'  },
        { key: 'M',   label: '1M'  },
    ];

    /* Build the controls toolbar above a chart wrapper */
    function btBuildControls(wrap, state) {
        var existing = wrap.querySelector('.bt-chart-controls');
        if (existing) existing.remove();

        var sym = wrap.dataset.symbol || '';
        var ctrl = document.createElement('div');
        ctrl.className = 'bt-chart-controls';

        /* Left: symbol label + intervals */
        var left = document.createElement('div');
        left.className = 'bt-chart-controls-left';

        var symLabel = document.createElement('span');
        symLabel.className = 'bt-chart-symbol-label';
        symLabel.textContent = sym.replace(/^[A-Z]+:/, '');
        left.appendChild(symLabel);

        var sep1 = document.createElement('span');
        sep1.className = 'bt-chart-ctrl-sep';
        left.appendChild(sep1);

        BT_INTERVALS.forEach(function(iv) {
            var btn = document.createElement('button');
            btn.className = 'bt-chart-interval-btn' + (iv.key === state.interval ? ' active' : '');
            btn.textContent = iv.label;
            btn.title = iv.key;
            btn.addEventListener('click', function() {
                state.interval = iv.key;
                btRebuildWidget(wrap, state);
                btBuildControls(wrap, state);
            });
            left.appendChild(btn);
        });

        ctrl.appendChild(left);

        /* Right: chart types + actions */
        var right = document.createElement('div');
        right.className = 'bt-chart-controls-right';

        BT_CHART_TYPES.forEach(function(ct) {
            var btn = document.createElement('button');
            btn.className = 'bt-chart-type-btn' + (ct.id === state.style ? ' active' : '');
            btn.textContent = ct.label;
            btn.title = ct.title;
            btn.addEventListener('click', function() {
                state.style = ct.id;
                btRebuildWidget(wrap, state);
                btBuildControls(wrap, state);
            });
            right.appendChild(btn);
        });

        var sep2 = document.createElement('span');
        sep2.className = 'bt-chart-ctrl-sep';
        right.appendChild(sep2);

        /* Compare toggle */
        var cmpBtn = document.createElement('button');
        cmpBtn.className = 'bt-chart-action-btn';
        cmpBtn.textContent = '+ Compare';
        cmpBtn.title = 'Compare symbols';
        cmpBtn.addEventListener('click', function() {
            var sym2 = prompt('Enter symbol to compare (e.g. BINANCE:ETHUSD):');
            if (sym2 && sym2.trim()) {
                state.compare = sym2.trim().toUpperCase();
                btRebuildWidget(wrap, state);
                btBuildControls(wrap, state);
            }
        });
        right.appendChild(cmpBtn);

        /* Fullscreen */
        var fsBtn = document.createElement('button');
        fsBtn.className = 'bt-chart-action-btn';
        fsBtn.textContent = '⛶ Full';
        fsBtn.title = 'Open fullscreen chart';
        fsBtn.addEventListener('click', function() {
            if (typeof btOpenChart === 'function') {
                btOpenChart(sym, sym);
            } else {
                var host = wrap.querySelector('[data-tv-host]');
                if (host) { host.requestFullscreen && host.requestFullscreen(); }
            }
        });
        right.appendChild(fsBtn);

        ctrl.appendChild(right);
        /* Insert BEFORE the chart host element */
        var host = wrap.querySelector('[data-tv-host]') || wrap.firstElementChild;
        if (host) wrap.insertBefore(ctrl, host);
        else wrap.prepend(ctrl);
    }

    /* (Re)build TradingView widget with current state.
       v119.7: Stripped incompatible config keys. The free TradingView embed
       widget (tv.js) only accepts a subset of options. Advanced "overrides",
       "studies_overrides" and the "Compare@tv-basicstudies:..." string syntax
       are all part of the paid Charting Library, NOT the embed. Passing them
       caused silent init failure → endless "Chart loading…" placeholder. */
    function btRebuildWidget(wrap, state) {
        var host = wrap.querySelector('[data-tv-host]');
        if (!host || !window.TradingView) return;
        host.innerHTML = '';
        if (!host.id) host.id = 'tv_host_' + Date.now() + '_' + Math.random().toString(36).slice(2, 7);

        var config = {
            container_id:        host.id,
            symbol:              state.symbol,
            interval:            state.interval || 'D',
            timezone:            'Etc/UTC',
            theme:               'dark',
            style:               state.style || '1',
            locale:              'en',
            toolbar_bg:          '#0A0B0D',
            enable_publishing:   false,
            allow_symbol_change: wrap.dataset.hideTop !== '1',
            hide_top_toolbar:    wrap.dataset.hideTop  === '1',
            hide_side_toolbar:   wrap.dataset.hideSide === '1',
            save_image:          true,
            withdateranges:      true,
            hide_volume:         false,
            details:             false,
            calendar:            false,
            width:               '100%',
            height:              state.height || 400
        };

        /* Compare overlay — embed widget uses studies array with the legacy
           "Compare@tv-basicstudies" id (NOT a "compareSymbols" array). */
        if (state.compare) {
            config.studies = [{
                id: 'Compare@tv-basicstudies',
                inputs: { symbol: state.compare }
            }];
        }

        try {
            new window.TradingView.widget(config);
        } catch (e) {
            if (window.console) console.error('[BlockTicker] TV widget init error:', e, config);
            host.innerHTML = '<div style="padding:40px;text-align:center;color:#71717a;font-size:13px">⚠️ Chart unavailable. <a href="javascript:location.reload()" style="color:#00FF66">Reload</a></div>';
        }
    }

    window.btInitTvWidget = function (wrap) {
        if (!wrap || wrap.dataset.loaded) return;
        wrap.dataset.loaded = '1';

        /* Per-widget mutable state */
        var state = {
            symbol:   wrap.dataset.symbol   || 'FX:EURUSD',
            interval: wrap.dataset.interval || 'D',
            style:    '1',
            height:   parseInt(wrap.dataset.height, 10) || 400,
            compare:  null,
        };

        /* Show controls only when hide_top is NOT set (compact mode) */
        var showControls = wrap.dataset.hideTop !== '1';

        window.btLoadTV().then(function () {
            var host = wrap.querySelector('[data-tv-host]') || wrap.firstElementChild;
            if (!host || !window.TradingView) return;
            host.innerHTML = '';
            if (showControls) btBuildControls(wrap, state);
            btRebuildWidget(wrap, state);
        }).catch(function (e) {
            if (window.console) console.warn('[BlockTicker] TradingView load failed:', e);
            wrap.innerHTML = '<div style="padding:40px;text-align:center;color:#71717a;font-size:13px">' +
                             '⚠️ Chart unavailable. <button onclick="location.reload()" ' +
                             'style="color:#00FF66;background:none;border:none;cursor:pointer;' +
                             'text-decoration:underline">Retry</button></div>';
        });
    };

    /* Auto-attach IntersectionObserver to every chart on the page.
       First chart on the page (data-priority="high") uses a generous
       rootMargin so it loads quickly; below-fold charts use a tight
       rootMargin to preserve mobile bandwidth. */
    window.btObserveTvCharts = function () {
        var wraps = document.querySelectorAll('.fxlm-tv-lazy:not([data-observed])');
        if (!wraps.length) return;
        if (!('IntersectionObserver' in window)) {
            // No IO support — load all immediately
            wraps.forEach(function (w) { w.dataset.observed = '1'; window.btInitTvWidget(w); });
            return;
        }
        /* v119.5: Generous rootMargin everywhere + pre-warm TradingView lib
           The first TV widget triggers a ~250KB script load that blocks subsequent widgets.
           Pre-warming the lib on DOMContentLoaded makes all charts ready faster. */
        if (window.btLoadTV && wraps.length > 0) { window.btLoadTV(); }

        wraps.forEach(function (w, idx) {
            w.dataset.observed = '1';
            /* First 2 charts: load immediately (no observer) */
            if (idx < 2 || w.dataset.priority === 'high') {
                window.btInitTvWidget(w);
                return;
            }
            /* Others: large 800px margin so they load well before scroll-in */
            var obs = new IntersectionObserver(function (entries, self) {
                entries.forEach(function (e) {
                    if (e.isIntersecting) {
                        window.btInitTvWidget(e.target);
                        self.unobserve(e.target);
                    }
                });
            }, { rootMargin: '800px 0px' });
            obs.observe(w);
        });
    };

    if (document.readyState !== 'loading') {
        window.btObserveTvCharts();
    } else {
        document.addEventListener('DOMContentLoaded', window.btObserveTvCharts);
    }
}

(function () {
    'use strict';

    if (typeof window === 'undefined') return;

    // ── 1. Scroll-triggered reveal using IntersectionObserver ──────
    function initReveal() {
        if (!('IntersectionObserver' in window)) {
            // fallback: make everything visible
            document.querySelectorAll('.bt-reveal, .bt-reveal-stagger')
                .forEach(function (el) { el.classList.add('bt-reveal-in'); });
            return;
        }

        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('bt-reveal-in');
                    io.unobserve(entry.target);
                }
            });
        }, { threshold: 0.12, rootMargin: '0px 0px -60px 0px' });

        document.querySelectorAll('.bt-reveal, .bt-reveal-stagger').forEach(function (el) {
            io.observe(el);
        });
    }

    // ── 2. Navbar scroll state (adds .scrolled class past 20px) ────
    function initNavScroll() {
        var nav = document.getElementById('cp-navbar') || document.querySelector('.cp-navbar');
        if (!nav) return;

        var ticking = false;
        function update() {
            if (window.scrollY > 20) nav.classList.add('scrolled');
            else nav.classList.remove('scrolled');
            ticking = false;
        }
        function onScroll() {
            if (!ticking) {
                window.requestAnimationFrame(update);
                ticking = true;
            }
        }
        window.addEventListener('scroll', onScroll, { passive: true });
        update();
    }

    // ── 3. Active link highlighting based on current URL ──────────
    function initActiveLink() {
        var path = window.location.pathname.replace(/\/+$/, '') || '/';
        document.querySelectorAll('.cp-nav-link, .cp-mobile-link').forEach(function (a) {
            if (!a.href) return;
            try {
                var href = new URL(a.href).pathname.replace(/\/+$/, '') || '/';
                // Skip the home link when we're on a sub-page
                if (href === '/' && path !== '/') return;
                if (href === path) a.classList.add('active');
                else if (href !== '/' && path.indexOf(href) === 0) a.classList.add('active');
            } catch (e) { /* no-op */ }
        });
    }

    // ── 4. Auto-tag common containers for stagger reveal ──────────
    function autoTagReveal() {
        // Analysis/Blog post grids
        var grids = [
            '.fxlm-blog-grid', '.fxlm-blog-cards-grid',
            '.fxlm-signals-v2', '.fxlm-signals-feed',
            '.bt-editorial-grid'
        ];
        grids.forEach(function (sel) {
            document.querySelectorAll(sel).forEach(function (el) {
                if (!el.classList.contains('bt-reveal-stagger')) {
                    el.classList.add('bt-reveal-stagger');
                }
            });
        });

        // Single-element reveals
        var solos = [
            '.bt-editorial-commit',
            '.fxlm-signals-disclaimer',
            '.fxlm-blog-empty',
            '.fxlm-signals-empty'
        ];
        solos.forEach(function (sel) {
            document.querySelectorAll(sel).forEach(function (el) {
                if (!el.classList.contains('bt-reveal')) {
                    el.classList.add('bt-reveal');
                }
            });
        });
    }

    // ── 5. Dropdown ARIA wiring (a11y) ─────────────────────────────
    function initDropdownA11y() {
        document.querySelectorAll('.cp-has-dropdown').forEach(function (trigger) {
            trigger.setAttribute('aria-haspopup', 'true');
            trigger.setAttribute('aria-expanded', 'false');

            var menu = trigger.parentElement && trigger.parentElement.querySelector('.cp-dropdown-menu');
            if (!menu) return;

            // Observe display changes to mirror aria-expanded
            var sync = function () {
                var open = menu.style.display === 'block';
                trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
            };
            // Throttled observer
            var mo = new MutationObserver(sync);
            mo.observe(menu, { attributes: true, attributeFilter: ['style'] });
        });
    }

    // ── 6. Init on DOM ready ───────────────────────────────────────
    function boot() {
        autoTagReveal();
        initReveal();
        initNavScroll();
        initActiveLink();
        initDropdownA11y();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();


/* ---------------------------------------------------------------
   DexScan page interactivity (v48)
   --------------------------------------------------------------- */
(function() {
  // Network filter chips: navigate to same URL with ?network=X
  var netMap = {
    'All Networks': '',
    'Ethereum': 'eth',
    'Solana': 'solana',
    'Base': 'base',
    'BSC': 'bsc',
    'Monad': 'monad',
    'Arbitrum': 'arbitrum',
    'Avalanche': 'avax',
    'Sui Network': 'sui',
    'TRON': 'tron',
    'Polygon': 'polygon_pos',
    'Sonic': 'sonic',
    'HyperEVM': 'hyperevm',
    'PulseChain': 'pulsechain'
  };
  document.querySelectorAll('.bt-netchip').forEach(function(chip) {
    chip.addEventListener('click', function(e) {
      e.preventDefault();
      var label = chip.textContent.replace(/NEW/gi, '').trim();
      label = label.replace(/^[^a-zA-Z]*/, '').trim();
      var key = null;
      for (var k in netMap) { if (label.indexOf(k) === 0) { key = netMap[k]; break; } }
      if (key === null && label !== 'All Networks') return;
      var url = new URL(window.location.href);
      if (!key) url.searchParams.delete('network');
      else     url.searchParams.set('network', key);
      document.querySelectorAll('.bt-netchip').forEach(function(c){ c.classList.remove('active'); });
      chip.classList.add('active');
      window.location.href = url.toString();
    });
  });

  // Highlight currently-active network chip based on URL
  var currentNet = new URLSearchParams(window.location.search).get('network');
  if (currentNet) {
    document.querySelectorAll('.bt-netchip').forEach(function(chip) {
      var label = chip.textContent.replace(/NEW/gi, '').replace(/^[^a-zA-Z]*/, '').trim();
      var target = '';
      for (var k in netMap) { if (label.indexOf(k) === 0) { target = netMap[k]; break; } }
      if (target === currentNet) {
        document.querySelectorAll('.bt-netchip').forEach(function(c){ c.classList.remove('active'); });
        chip.classList.add('active');
      }
    });
  }

  // Generic active-toggle helper for visual-only chip groups
  function wireActive(groupSel, btnSel) {
    document.querySelectorAll(groupSel).forEach(function(group) {
      var btns = group.querySelectorAll(btnSel);
      btns.forEach(function(btn) {
        btn.addEventListener('click', function(e) {
          e.preventDefault();
          btns.forEach(function(b){ b.classList.remove('active'); });
          btn.classList.add('active');
        });
      });
    });
  }

  wireActive('.bt-subfilter-left', '.bt-timef-btn');
  wireActive('.bt-live-toggle',    '.bt-live-toggle-btn');
  wireActive('.bt-signal-cats',    '.bt-signal-cat');
  wireActive('.bt-meme-platforms', '.bt-meme-plat');
  wireActive('.bt-traders-time',   'button');

  // Signal timeframe chips inside each card
  document.querySelectorAll('.bt-signal-tf-row').forEach(function(row) {
    var tfs = row.querySelectorAll('.bt-signal-tf');
    tfs.forEach(function(tf) {
      tf.addEventListener('click', function() {
        tfs.forEach(function(t){ t.classList.remove('active'); });
        tf.classList.add('active');
      });
    });
  });
})();


/* ---------------------------------------------------------------
   DexScan table sort (v52) - click any column header to sort
   --------------------------------------------------------------- */
(function() {
  function parseCellValue(cell) {
    // Strip arrows, percent signs, commas, dollar signs
    var t = (cell.textContent || '').trim();
    // Take first line only (some cells have multi-line content)
    t = t.split(/\n|\r/)[0].trim();
    // Handle special notations
    if (/^-+$/.test(t)) return -Infinity;
    // Extract first number-like token, including $0.0₅1 style
    var m = t.match(/-?[\d.,>KkMmBb%]+/);
    if (!m) return t.toLowerCase();
    var s = m[0].replace(/,/g, '').replace(/%/g, '');
    // Handle K/M/B suffixes
    var mult = 1;
    if (/[Kk]$/.test(s)) { mult = 1e3; s = s.slice(0, -1); }
    else if (/[Mm]$/.test(s)) { mult = 1e6; s = s.slice(0, -1); }
    else if (/[Bb]$/.test(s)) { mult = 1e9; s = s.slice(0, -1); }
    // ">99.9K" style (very large)
    if (/^>/.test(s)) return 1e20;
    var n = parseFloat(s);
    if (isNaN(n)) return t.toLowerCase();
    return n * mult;
  }

  document.querySelectorAll('.bt-toktable, .bt-traders-table').forEach(function(table) {
    var headers = table.querySelectorAll('thead th');
    headers.forEach(function(th, colIndex) {
      // Skip star column
      if (th.classList.contains('c') && !th.textContent.trim()) return;
      th.addEventListener('click', function() {
        var currentDir = th.getAttribute('data-sort') || 'none';
        var nextDir = currentDir === 'desc' ? 'asc' : 'desc';
        // Clear all other headers
        headers.forEach(function(h) { h.removeAttribute('data-sort'); h.classList.remove('sort-active'); });
        th.setAttribute('data-sort', nextDir);
        th.classList.add('sort-active');
        // Sort rows
        var tbody = table.querySelector('tbody');
        if (!tbody) return;
        var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
        rows.sort(function(a, b) {
          var ca = a.cells[colIndex];
          var cb = b.cells[colIndex];
          if (!ca || !cb) return 0;
          var va = parseCellValue(ca);
          var vb = parseCellValue(cb);
          if (typeof va === 'number' && typeof vb === 'number') {
            return nextDir === 'asc' ? va - vb : vb - va;
          }
          va = String(va); vb = String(vb);
          return nextDir === 'asc' ? va.localeCompare(vb) : vb.localeCompare(va);
        });
        rows.forEach(function(r) { tbody.appendChild(r); });
      });
    });
  });

  // Simple client-side text filter for sector tables (search input pattern)
  document.querySelectorAll('.bt-cat-table').forEach(function(table) {
    var rows = table.querySelectorAll('tbody tr');
    // Find search input associated with this category
    var wrap = table.closest('.bt-cat-page');
    var input = wrap ? wrap.querySelector('input[type="text"]') : null;
    if (!input) return;
    input.addEventListener('input', function() {
      var q = (input.value || '').toLowerCase().trim();
      rows.forEach(function(r) {
        var name = (r.getAttribute('data-name') || '').toLowerCase();
        var sym  = (r.getAttribute('data-sym') || '').toLowerCase();
        r.style.display = (!q || name.indexOf(q) !== -1 || sym.indexOf(q) !== -1) ? '' : 'none';
      });
    });
  });
})();

/* ══════════════════════════════════════════════════════════════════
   v119 — Page-level filter JS (moved from inline PHP to avoid parse issues)
   ══════════════════════════════════════════════════════════════════ */
(function () {
    "use strict";

    // ── Crypto markets filter buttons (All / Top 100 / DeFi / Layer 1) ──
    var DEFI_IDS = {"uniswap":1,"aave":1,"compound-governance-token":1,"maker":1,"curve-dao-token":1,"yearn-finance":1,"sushi":1,"1inch":1,"balancer":1,"pancakeswap-token":1,"thorchain":1,"dydx":1,"gmx":1,"synthetix-network-token":1,"loopring":1,"bancor":1,"kyber-network-crystal":1,"reserve-rights-token":1,"perpetual-protocol":1,"frax-share":1,"convex-finance":1,"liquity":1};
    var L1_IDS = {"bitcoin":1,"ethereum":1,"solana":1,"ripple":1,"cardano":1,"avalanche-2":1,"polkadot":1,"near":1,"cosmos":1,"algorand":1,"fantom":1,"hedera-hashgraph":1,"tron":1,"stellar":1,"litecoin":1,"bitcoin-cash":1,"ethereum-classic":1,"binancecoin":1,"aptos":1,"sui":1,"kaspa":1,"sei-network":1,"injective-protocol":1,"monero":1,"tezos":1};

    function applyCryptoFilter(filter) {
        var rows = document.querySelectorAll("#fxlm-cft-body .fxlm-cft-row");
        var visible = 0;
        rows.forEach(function (r) {
            var id = r.dataset.id || "";
            var rank = parseInt(r.dataset.rank || r.dataset.orig || 999, 10);
            var show = (filter === "all")
                || (filter === "top100" && rank <= 100)
                || (filter === "defi"   && DEFI_IDS[id])
                || (filter === "layer1" && L1_IDS[id]);
            r.style.display = show ? "" : "none";
            if (show) visible++;
        });
        var lbl = document.getElementById("fxlm-cft-showing");
        if (lbl) lbl.textContent = "Showing " + visible + " assets";
    }

    function initCryptoFilter() {
        var btns = document.querySelectorAll(".bt-wf-btn[data-filter]");
        if (!btns.length) return;
        btns.forEach(function (btn) {
            btn.addEventListener("click", function () {
                document.querySelectorAll(".bt-wf-btn").forEach(function (b) { b.classList.remove("active"); });
                btn.classList.add("active");
                applyCryptoFilter(btn.dataset.filter);
            });
        });
    }

    // ── Signal feed filters (All / Forex / Crypto / Bullish / Bearish) ──
    function initSignalFilter() {
        var btns = document.querySelectorAll(".bt-sig-filter-btn[data-cls]");
        if (!btns.length) return;
        btns.forEach(function (btn) {
            btn.addEventListener("click", function () {
                document.querySelectorAll(".bt-sig-filter-btn").forEach(function (b) { b.classList.remove("active"); });
                btn.classList.add("active");
                var cls  = btn.dataset.cls  || "all";
                var sent = btn.dataset.sent || "all";
                document.querySelectorAll(".fxlm-signal-v2").forEach(function (item) {
                    var ic = item.dataset.cls  || "forex";
                    var is = item.dataset.sent || "neutral";
                    var showCls  = cls  === "all" || ic === cls;
                    var showSent = sent === "all" || is === sent;
                    item.style.display = (showCls && showSent) ? "" : "none";
                });
            });
        });
    }

    // ── DexScan time-period buttons (5m / 1h / 4h / 24h) ──
    function initTimeButtons() {
        var btns = document.querySelectorAll(".bt-timef-btn");
        if (!btns.length) return;
        btns.forEach(function (btn) {
            btn.addEventListener("click", function () {
                document.querySelectorAll(".bt-timef-btn").forEach(function (b) { b.classList.remove("active"); });
                btn.classList.add("active");
                var period = btn.dataset.period || "24h";
                var colMap = {"5m": "5m%", "1h": "1h%", "4h": "6h%", "24h": "24h%"};
                var colLabel = colMap[period] || "24h%";
                var table = document.querySelector(".bt-toktable");
                if (!table) return;
                var ths = Array.prototype.slice.call(table.querySelectorAll("thead th"));
                var colIdx = -1;
                for (var i = 0; i < ths.length; i++) {
                    if (ths[i].textContent.trim() === colLabel) { colIdx = i; break; }
                }
                if (colIdx < 0) return;
                var tbody = table.querySelector("tbody");
                if (!tbody) return;
                var rows = Array.prototype.slice.call(tbody.querySelectorAll("tr"));
                rows.sort(function (a, b) {
                    var cellA = a.cells[colIdx], cellB = b.cells[colIdx];
                    var av = parseFloat((cellA && cellA.textContent || "0").replace(/[▲▼%,]/g, "").trim()) || 0;
                    var bv = parseFloat((cellB && cellB.textContent || "0").replace(/[▲▼%,]/g, "").trim()) || 0;
                    var asign = (cellA && cellA.classList.contains("up")) ? av : -av;
                    var bsign = (cellB && cellB.classList.contains("up")) ? bv : -bv;
                    return bsign - asign;
                });
                rows.forEach(function (r) { tbody.appendChild(r); });
            });
        });
    }

    function initAll() {
        initCryptoFilter();
        initSignalFilter();
        initTimeButtons();
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initAll);
    } else {
        initAll();
    }
})();

/* ══════════════════════════════════════════════════════════════════
   v119.4 — Pagination for /crypto-markets/ and /crypto-category-X/
   Both tables show ALL rows server-side; JS paginates client-side.
   ══════════════════════════════════════════════════════════════════ */
(function () {
    "use strict";

    var PER_PAGE = 25;

    /* Generic paginator — attaches to any table body by config */
    function buildPaginator(config) {
        var body = document.querySelector(config.bodySel);
        if (!body) return null;

        var state = {
            page: 1,
            query: "",
            filter: "all",
            sortCol: null,
            sortDir: -1
        };

        function getRows() {
            return Array.prototype.slice.call(body.querySelectorAll(config.rowSel));
        }

        var allRows = getRows();
        if (!allRows.length) return null;

        function matchesFilter(row) {
            if (config.filterFn) return config.filterFn(row, state.filter);
            return true;
        }
        function matchesSearch(row) {
            if (!state.query) return true;
            var q = state.query.toLowerCase();
            var haystack = (row.dataset.name || "") + " " + (row.dataset.sym || "") + " " + (row.textContent || "");
            return haystack.toLowerCase().indexOf(q) !== -1;
        }

        function render() {
            /* Filter + sort + paginate */
            var filtered = allRows.filter(function (r) {
                return matchesFilter(r) && matchesSearch(r);
            });

            if (state.sortCol !== null && config.sortValue) {
                filtered.sort(function (a, b) {
                    var av = config.sortValue(a, state.sortCol);
                    var bv = config.sortValue(b, state.sortCol);
                    if (typeof av === "string") return av.localeCompare(bv) * state.sortDir;
                    return (av - bv) * state.sortDir;
                });
            }

            var total = filtered.length;
            var totalPages = Math.max(1, Math.ceil(total / PER_PAGE));
            if (state.page > totalPages) state.page = totalPages;
            if (state.page < 1) state.page = 1;

            var start = (state.page - 1) * PER_PAGE;
            var end = start + PER_PAGE;
            var visible = filtered.slice(start, end);
            var visibleSet = {};
            visible.forEach(function (r, i) { visibleSet[i] = r; });

            /* Hide everything, then show + reorder visible slice */
            allRows.forEach(function (r) { r.style.display = "none"; });
            visible.forEach(function (r) {
                r.style.display = "";
                body.appendChild(r);
            });

            /* Update info label */
            var info = document.querySelector(config.infoSel);
            if (info) {
                if (total === 0) {
                    info.textContent = "No results";
                } else {
                    info.textContent = "Showing " + (start + 1) + "–" + Math.min(end, total) + " of " + total + (config.unit ? " " + config.unit : "");
                }
            }

            /* Render pagination nav */
            var pager = document.querySelector(config.pagerSel);
            if (pager) {
                pager.innerHTML = "";
                if (totalPages > 1) {
                    var frag = document.createDocumentFragment();

                    var prev = document.createElement("button");
                    prev.className = "bt-pager-btn";
                    prev.textContent = "← Prev";
                    prev.disabled = state.page <= 1;
                    prev.addEventListener("click", function () { state.page--; render(); });
                    frag.appendChild(prev);

                    var startP = Math.max(1, state.page - 3);
                    var endP   = Math.min(totalPages, state.page + 3);
                    if (startP > 1) {
                        var b1 = makePageBtn(1);
                        frag.appendChild(b1);
                        if (startP > 2) {
                            var el = document.createElement("span");
                            el.className = "bt-pager-ellipsis";
                            el.textContent = "…";
                            frag.appendChild(el);
                        }
                    }
                    for (var p = startP; p <= endP; p++) {
                        frag.appendChild(makePageBtn(p));
                    }
                    if (endP < totalPages) {
                        if (endP < totalPages - 1) {
                            var el2 = document.createElement("span");
                            el2.className = "bt-pager-ellipsis";
                            el2.textContent = "…";
                            frag.appendChild(el2);
                        }
                        frag.appendChild(makePageBtn(totalPages));
                    }

                    var next = document.createElement("button");
                    next.className = "bt-pager-btn";
                    next.textContent = "Next →";
                    next.disabled = state.page >= totalPages;
                    next.addEventListener("click", function () { state.page++; render(); });
                    frag.appendChild(next);

                    pager.appendChild(frag);
                }
            }
        }

        function makePageBtn(num) {
            var btn = document.createElement("button");
            btn.className = "bt-pager-btn" + (num === state.page ? " active" : "");
            btn.textContent = num;
            btn.addEventListener("click", function () { state.page = num; render(); });
            return btn;
        }

        render();

        return {
            setFilter: function (f) { state.filter = f; state.page = 1; render(); },
            setQuery:  function (q) { state.query = q;  state.page = 1; render(); },
            setSort:   function (col, dir) { state.sortCol = col; state.sortDir = dir || -1; render(); },
            getState:  function () { return state; },
            refresh:   function () { allRows = getRows(); render(); }
        };
    }

    /* ── /crypto-markets/ — fxlm_crypto_full_table ── */
    function initCryptoMarketsPagination() {
        var body = document.getElementById("fxlm-cft-body");
        if (!body) return;

        /* Ensure info + pager containers exist — inject if missing */
        var table = body.closest("table");
        var wrap = body.closest(".fxlm-cft-wrap") || (table && table.parentElement);
        if (!wrap) return;

        if (!document.getElementById("fxlm-cft-showing")) {
            var footer = document.createElement("div");
            footer.className = "bt-cat-pager";
            footer.innerHTML = '<span class="bt-pager-info" id="fxlm-cft-showing"></span><div class="bt-pager-nav" id="fxlm-cft-pager"></div>';
            wrap.appendChild(footer);
        }

        /* Re-read DEFI / L1 sets from the first filter JS block above */
        var DEFI = {"uniswap":1,"aave":1,"compound-governance-token":1,"maker":1,"curve-dao-token":1,"yearn-finance":1,"sushi":1,"1inch":1,"balancer":1,"pancakeswap-token":1,"thorchain":1,"dydx":1,"gmx":1,"synthetix-network-token":1,"loopring":1,"bancor":1,"kyber-network-crystal":1};
        var L1 = {"bitcoin":1,"ethereum":1,"solana":1,"ripple":1,"cardano":1,"avalanche-2":1,"polkadot":1,"near":1,"cosmos":1,"algorand":1,"fantom":1,"hedera-hashgraph":1,"tron":1,"stellar":1,"litecoin":1,"bitcoin-cash":1,"ethereum-classic":1,"binancecoin":1,"aptos":1,"sui":1,"kaspa":1,"sei-network":1,"injective-protocol":1};

        var paginator = buildPaginator({
            bodySel:  "#fxlm-cft-body",
            rowSel:   ".fxlm-cft-row",
            infoSel:  "#fxlm-cft-showing",
            pagerSel: "#fxlm-cft-pager",
            unit:     "assets",
            filterFn: function (row, f) {
                var id = row.dataset.id || "";
                var rank = parseInt(row.dataset.rank || row.dataset.orig || 999, 10);
                if (f === "all")    return true;
                if (f === "top100") return rank <= 100;
                if (f === "defi")   return !!DEFI[id];
                if (f === "layer1") return !!L1[id];
                return true;
            }
        });

        if (!paginator) return;

        /* Wire the bt-wf-btn filter buttons to this paginator */
        document.querySelectorAll(".bt-wf-btn[data-filter]").forEach(function (btn) {
            btn.addEventListener("click", function () {
                document.querySelectorAll(".bt-wf-btn").forEach(function (b) { b.classList.remove("active"); });
                btn.classList.add("active");
                paginator.setFilter(btn.dataset.filter);
            });
        });

        /* Optional: search input #fxlm-cft-search */
        var search = document.getElementById("fxlm-cft-search");
        if (search) {
            search.addEventListener("input", function () { paginator.setQuery(this.value); });
        }
    }

    /* ── /crypto-category-X/ — bt-cat-body-* ── */
    /* The PHP shortcode already renders <div id="bt-cat-pager-{cat}">.
       It also renders inline <script> with window.btCatSearch_{cat}.
       We override with this cleaner version that ALWAYS runs. */
    function initCategoryPagination() {
        /* Find all bt-cat-body-* bodies on the page */
        var bodies = document.querySelectorAll('[id^="bt-cat-body-"]');
        bodies.forEach(function (body) {
            var cat = body.id.replace("bt-cat-body-", "");
            var pagerId = "bt-cat-pager-" + cat;

            /* Ensure pager container exists */
            if (!document.getElementById(pagerId)) {
                var pgr = document.createElement("div");
                pgr.id = pagerId;
                pgr.className = "bt-cat-pager";
                /* Insert right after the table */
                var table = body.closest("table");
                if (table && table.parentElement) {
                    table.parentElement.insertBefore(pgr, table.nextSibling);
                }
            }

            /* Split the pager container into info + nav */
            var pgrEl = document.getElementById(pagerId);
            if (pgrEl && !pgrEl.querySelector(".bt-pager-info")) {
                pgrEl.innerHTML = '<span class="bt-pager-info" id="bt-cat-info-' + cat + '"></span><div class="bt-pager-nav" id="bt-cat-nav-' + cat + '"></div>';
            }

            var paginator = buildPaginator({
                bodySel:  "#bt-cat-body-" + cat,
                rowSel:   ".bt-cat-row",
                infoSel:  "#bt-cat-info-" + cat,
                pagerSel: "#bt-cat-nav-" + cat,
                unit:     "tokens",
                sortValue: function (row, col) {
                    var cells = row.querySelectorAll("td");
                    if (col === "rank")  return parseFloat(cells[0] && cells[0].textContent) || 0;
                    if (col === "name")  return (row.dataset.name || "");
                    if (col === "price") return parseFloat((cells[2] && cells[2].textContent || "0").replace(/[$,]/g, "")) || 0;
                    if (col === "24h")   return parseFloat((cells[3] && cells[3].textContent || "0").replace(/[▲▼%,]/g, "").trim()) * ((cells[3] && cells[3].classList.contains("up")) ? 1 : -1) || 0;
                    if (col === "7d")    return parseFloat((cells[4] && cells[4].textContent || "0").replace(/[▲▼%,]/g, "").trim()) * ((cells[4] && cells[4].classList.contains("up")) ? 1 : -1) || 0;
                    if (col === "mcap")  {
                        var v = (cells[5] && cells[5].textContent || "0").replace(/[$,]/g, "").trim();
                        var mult = /B$/i.test(v) ? 1e9 : /M$/i.test(v) ? 1e6 : /K$/i.test(v) ? 1e3 : 1;
                        return parseFloat(v.replace(/[BMK]/gi, "")) * mult || 0;
                    }
                    return 0;
                }
            });

            if (!paginator) return;

            /* Override window.btCatSearch_{cat} */
            window["btCatSearch_" + cat] = function (q) { paginator.setQuery(q); };

            /* Wire clickable sort headers */
            var tbl = body.closest("table");
            if (tbl) {
                tbl.querySelectorAll("thead th[data-sort]").forEach(function (th) {
                    /* Ensure sort indicator exists */
                    if (!th.querySelector(".sort-ind")) {
                        var ind = document.createElement("span");
                        ind.className = "sort-ind";
                        ind.textContent = " ↕";
                        th.appendChild(ind);
                    }
                    th.style.cursor = "pointer";
                    th.addEventListener("click", function () {
                        var col = th.dataset.sort;
                        var cur = paginator.getState();
                        var dir = (cur.sortCol === col) ? -cur.sortDir : (col === "name" ? 1 : -1);
                        paginator.setSort(col, dir);
                        /* Update indicator */
                        tbl.querySelectorAll("thead th[data-sort] .sort-ind").forEach(function (i) { i.textContent = " ↕"; });
                        th.querySelector(".sort-ind").textContent = dir === 1 ? " ▲" : " ▼";
                        tbl.querySelectorAll("thead th[data-sort]").forEach(function (h) { h.classList.remove("sort-active"); });
                        th.classList.add("sort-active");
                    });
                });
            }
        });
    }

    function initPagination() {
        initCryptoMarketsPagination();
        initCategoryPagination();
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initPagination);
    } else {
        initPagination();
    }
})();
