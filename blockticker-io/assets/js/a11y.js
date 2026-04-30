/**
 * BlockTicker v96.7 — Accessibility JS
 * =====================================
 * Loaded deferred in wp_footer on all frontend pages.
 *
 * Responsibilities:
 *  1. Market-table keyboard navigation (arrow keys, Home/End)
 *  2. aria-sort on sortable table headers (visual sort indicator)
 *  3. aria-live polite announcements for live price updates
 *  4. Escape key closes any open dropdown / modal
 *  5. Role and label hygiene: scope="col" on TH, caption on tables
 *  6. Dropdown focus trapping (navbar lang picker, mobile menu)
 *  7. Reduced-motion JS guard (stops JS-driven animation loops)
 */
(function () {
    'use strict';

    /* ------------------------------------------------------------------
     * 1. Constants & helpers
     * ------------------------------------------------------------------ */

    var ANNOUNCE_DEBOUNCE = 3000; // ms — throttle live price announcements
    var lastAnnounce = 0;

    function qs(sel, ctx)  { return (ctx || document).querySelector(sel); }
    function qsa(sel, ctx) { return Array.from((ctx || document).querySelectorAll(sel)); }

    /** Post a message to the aria-live region. */
    function announce(msg) {
        var now = Date.now();
        if (now - lastAnnounce < ANNOUNCE_DEBOUNCE) return;
        lastAnnounce = now;
        var region = qs('#bt-a11y-live');
        if (!region) return;
        // Clear first so re-setting the same text triggers a new announcement.
        region.textContent = '';
        // rAF ensures the DOM flush completes between clear and set.
        requestAnimationFrame(function () { region.textContent = msg; });
    }

    /* ------------------------------------------------------------------
     * 2. Table accessibility: scope, captions, keyboard grid
     * ------------------------------------------------------------------ */

    function setupTables() {
        var tables = qsa(
            '.fxlm-table, .bt-cat-table, .fxlm-forex-table, .bt-sentiment-table'
        );
        tables.forEach(function (table) {
            // Mark table as a navigable grid.
            table.setAttribute('role', 'grid');
            table.setAttribute('data-a11y-grid', '1');

            // Add scope="col" to all TH cells that lack it.
            qsa('thead th', table).forEach(function (th) {
                if (!th.getAttribute('scope')) th.setAttribute('scope', 'col');

                // Append sort icon span if not present.
                if (!th.querySelector('.bt-sort-icon')) {
                    var icon = document.createElement('span');
                    icon.className = 'bt-sort-icon';
                    icon.setAttribute('aria-hidden', 'true');
                    th.appendChild(icon);
                }

                // Make TH keyboard-focusable.
                if (!th.getAttribute('tabindex')) th.setAttribute('tabindex', '0');
            });

            // Make every data cell keyboard-focusable.
            qsa('tbody td', table).forEach(function (td) {
                if (!td.getAttribute('tabindex')) td.setAttribute('tabindex', '-1');
            });

            // Attach arrow-key grid navigation.
            table.addEventListener('keydown', handleGridKeydown);
        });
    }

    /**
     * Arrow-key navigation inside a role="grid" table.
     * Arrow Up/Down moves between rows; Left/Right moves between columns.
     * Home/End jump to first/last cell in the current row.
     * Ctrl+Home/End jump to first/last cell in the table.
     */
    function handleGridKeydown(e) {
        var cell = e.target;
        if (cell.tagName !== 'TD' && cell.tagName !== 'TH') return;

        var table = e.currentTarget;
        var rows  = qsa('tbody tr', table);
        var row   = cell.closest('tr');
        var ri    = rows.indexOf(row);
        var cells = qsa('td, th', row);
        var ci    = cells.indexOf(cell);

        var target = null;

        switch (e.key) {
            case 'ArrowDown':
                if (ri + 1 < rows.length) {
                    var nextCells = qsa('td', rows[ri + 1]);
                    target = nextCells[Math.min(ci, nextCells.length - 1)] || null;
                }
                break;
            case 'ArrowUp':
                if (ri > 0) {
                    var prevCells = qsa('td', rows[ri - 1]);
                    target = prevCells[Math.min(ci, prevCells.length - 1)] || null;
                } else {
                    // Move to header row
                    target = qsa('thead th', table)[ci] || null;
                }
                break;
            case 'ArrowRight':
                target = cells[ci + 1] || null;
                break;
            case 'ArrowLeft':
                target = cells[ci - 1] || null;
                break;
            case 'Home':
                target = e.ctrlKey
                    ? (qsa('td', rows[0])[0] || null)
                    : cells[0];
                break;
            case 'End':
                target = e.ctrlKey
                    ? (qsa('td', rows[rows.length - 1]).slice(-1)[0] || null)
                    : cells[cells.length - 1];
                break;
            default:
                return;
        }

        if (target) {
            e.preventDefault();
            target.setAttribute('tabindex', '0');
            target.focus();
            // Reset previous cell tabindex so tab-order stays sane.
            cell.setAttribute('tabindex', '-1');
        }
    }

    /* ------------------------------------------------------------------
     * 3. Live price announcements
     * ------------------------------------------------------------------ */

    /**
     * Watch price cells for DOM mutations and announce significant changes.
     * Threshold: announce when a price changes (any mutation on a price cell).
     * Debounced at 3 seconds to avoid reading out every tick.
     */
    function setupPriceAnnouncements() {
        // Target the containers WP renders for live price data.
        var targets = qsa(
            '#fxlm-btc-price, #fxlm-eth-price, .bcp-price, [data-live-price]'
        );
        if (!targets.length) return;

        var observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (m) {
                var el  = m.target;
                var val = el.textContent.trim();
                if (!val) return;
                // Build a human-readable announcement.
                var label = el.closest('[data-symbol]')
                    ? el.closest('[data-symbol]').dataset.symbol
                    : 'Price';
                announce(label + ' updated to ' + val);
            });
        });

        targets.forEach(function (el) {
            observer.observe(el, { characterData: true, childList: true, subtree: true });
        });
    }

    /* ------------------------------------------------------------------
     * 4. Escape key — close open dropdowns / modals
     * ------------------------------------------------------------------ */

    function setupEscapeKey() {
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;

            // Close language dropdown
            var langDropdown = qs('#bt-lang-dropdown');
            var langBtn      = qs('#bt-lang-btn');
            if (langDropdown && langDropdown.classList.contains('open')) {
                langDropdown.classList.remove('open');
                if (langBtn) {
                    langBtn.setAttribute('aria-expanded', 'false');
                    langBtn.focus();
                }
            }

            // Close mobile menu
            var mobileMenu = qs('#cp-mobile-menu');
            var hamburger  = qs('#cp-hamburger');
            if (mobileMenu && mobileMenu.classList.contains('open')) {
                mobileMenu.classList.remove('open');
                if (hamburger) {
                    hamburger.classList.remove('open');
                    hamburger.focus();
                }
            }

            // Close any generic [data-bt-modal] modals
            qsa('[data-bt-modal].open').forEach(function (modal) {
                modal.classList.remove('open');
                var trigger = qs('[data-bt-modal-trigger="' + modal.id + '"]');
                if (trigger) trigger.focus();
            });
        });
    }

    /* ------------------------------------------------------------------
     * 5. Dropdown focus trap (language picker, mobile menu)
     * ------------------------------------------------------------------ */

    function setupDropdownFocusTrap() {
        function trapFocus(container, trigger) {
            container.addEventListener('keydown', function (e) {
                if (e.key !== 'Tab') return;
                var focusable = qsa(
                    'a,button,input,select,textarea,[tabindex]:not([tabindex="-1"])',
                    container
                ).filter(function (el) { return !el.disabled; });
                if (!focusable.length) return;
                var first = focusable[0];
                var last  = focusable[focusable.length - 1];
                if (e.shiftKey && document.activeElement === first) {
                    e.preventDefault();
                    last.focus();
                } else if (!e.shiftKey && document.activeElement === last) {
                    e.preventDefault();
                    first.focus();
                }
            });
        }

        var langDropdown = qs('#bt-lang-dropdown');
        var langBtn      = qs('#bt-lang-btn');
        if (langDropdown && langBtn) trapFocus(langDropdown, langBtn);

        var mobileMenu = qs('#cp-mobile-menu');
        var hamburger  = qs('#cp-hamburger');
        if (mobileMenu && hamburger) trapFocus(mobileMenu, hamburger);
    }

    /* ------------------------------------------------------------------
     * 6. aria-expanded on collapsible sections
     * ------------------------------------------------------------------ */

    function setupAriaExpanded() {
        // Any button with data-toggle attribute that doesn't already manage
        // aria-expanded itself (the BT JS may already do some of these).
        qsa('[data-toggle]:not([data-expanded-managed])').forEach(function (btn) {
            if (!btn.hasAttribute('aria-expanded')) {
                btn.setAttribute('aria-expanded', 'false');
            }
            btn.setAttribute('data-expanded-managed', '1');
            btn.addEventListener('click', function () {
                var expanded = btn.getAttribute('aria-expanded') === 'true';
                btn.setAttribute('aria-expanded', String(!expanded));
            });
        });
    }

    /* ------------------------------------------------------------------
     * 7. Table captions (for context in screen readers)
     * ------------------------------------------------------------------ */

    function setupTableCaptions() {
        var captionMap = {
            '#fxlm-cft-table':    'Live Cryptocurrency Prices',
            '.fxlm-forex-table':  'Live Forex Exchange Rates',
            '.bt-sentiment-table':'Market Sentiment Scores',
        };
        Object.keys(captionMap).forEach(function (sel) {
            qsa(sel).forEach(function (table) {
                if (table.querySelector('caption')) return; // already has one
                var cap = document.createElement('caption');
                cap.className = 'bt-sr-only';
                cap.textContent = captionMap[sel];
                table.insertBefore(cap, table.firstChild);
            });
        });
    }

    /* ------------------------------------------------------------------
     * 8. Reduced-motion guard (JS animations)
     * ------------------------------------------------------------------ */

    function applyReducedMotion() {
        if (!window.matchMedia) return;
        var mq = window.matchMedia('(prefers-reduced-motion: reduce)');
        if (!mq.matches) return;

        // Stop the hero ticker rotation
        var ticker = qs('#bt-hero-ticker');
        if (ticker) {
            // Show only first message, hide rest
            qsa('.bt-hero-ticker-msg', ticker).forEach(function (msg, i) {
                msg.style.opacity    = i === 0 ? '1' : '0';
                msg.style.transition = 'none';
            });
        }

        // Stop animated number counters (if present)
        qsa('[data-count-up]').forEach(function (el) {
            el.textContent = el.dataset.countUp;
        });
    }

    /* ------------------------------------------------------------------
     * 9. Image alt text audit — warn in console on missing alts (dev aid)
     * ------------------------------------------------------------------ */

    function auditImageAlts() {
        if (typeof console === 'undefined' || !console.warn) return;
        qsa('img:not([alt])').forEach(function (img) {
            if (img.src && !img.src.includes('placeholder')) {
                console.warn('[BT A11y] Missing alt attribute:', img.src);
            }
        });
    }

    /* ------------------------------------------------------------------
     * Init — run after DOM is ready
     * ------------------------------------------------------------------ */

    function init() {
        setupTables();
        setupPriceAnnouncements();
        setupEscapeKey();
        setupDropdownFocusTrap();
        setupAriaExpanded();
        setupTableCaptions();
        applyReducedMotion();

        // Only audit in dev environments (no console.warn in production builds).
        if (window.location.hostname === 'localhost' ||
            window.location.hostname.includes('.local') ||
            window.location.search.indexOf('bt_debug') !== -1) {
            auditImageAlts();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
