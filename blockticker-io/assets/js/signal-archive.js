/**
 * Signal Archive — interactive filter + sort.
 *
 * No dependencies. Runs after DOM ready.
 * Filters and sorts the #bt-archive-tbody rows client-side.
 *
 * @since 119.28.0
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var table   = document.getElementById('bt-archive-table');
        var tbody   = document.getElementById('bt-archive-tbody');
        var sort    = document.getElementById('bt-archive-sort');
        var counter = document.getElementById('bt-archive-count');
        var showing = document.getElementById('bt-archive-showing');

        if (!table || !tbody) return;

        // ── Active filter state ──────────────────────────────────
        var filters = { asset: 'all', direction: 'all', outcome: 'all' };

        // ── Attach chip listeners ────────────────────────────────
        var chipGroups = document.querySelectorAll('.bt-archive__chips[data-filter]');
        chipGroups.forEach(function (group) {
            var filterKey = group.getAttribute('data-filter');
            group.querySelectorAll('.bt-archive__chip').forEach(function (chip) {
                chip.addEventListener('click', function () {
                    // toggle active class within group
                    group.querySelectorAll('.bt-archive__chip').forEach(function (c) {
                        c.classList.remove('active');
                        c.setAttribute('aria-pressed', 'false');
                    });
                    chip.classList.add('active');
                    chip.setAttribute('aria-pressed', 'true');
                    filters[filterKey] = chip.getAttribute('data-value');
                    applyFilters();
                });
            });
        });

        // ── Attach sort listener ─────────────────────────────────
        if (sort) {
            sort.addEventListener('change', function () {
                applyFilters();
            });
        }

        // ── Core filter + sort ───────────────────────────────────
        function applyFilters() {
            var rows = Array.from(tbody.querySelectorAll('tr.bt-archive__row'));

            // 1. Filter
            rows.forEach(function (row) {
                var assetOk     = filters.asset     === 'all' || matchAsset(row, filters.asset);
                var dirOk       = filters.direction === 'all' || row.dataset.direction === filters.direction;
                var outcomeOk   = filters.outcome   === 'all' || row.dataset.outcome   === filters.outcome;
                var visible     = assetOk && dirOk && outcomeOk;
                row.classList.toggle('bt-archive__row--hidden', !visible);
            });

            // 2. Sort
            var visible = rows.filter(function (r) {
                return !r.classList.contains('bt-archive__row--hidden');
            });

            var sortVal = sort ? sort.value : 'newest';
            visible.sort(function (a, b) {
                switch (sortVal) {
                    case 'newest':     return +b.dataset.date - +a.dataset.date;
                    case 'oldest':     return +a.dataset.date - +b.dataset.date;
                    case 'best':       return +b.dataset.r - +a.dataset.r;
                    case 'worst':      return +a.dataset.r - +b.dataset.r;
                    case 'confidence': return +b.dataset.conf - +a.dataset.conf;
                    default:           return 0;
                }
            });

            // Re-append in sorted order (hidden rows stay in place but that's fine)
            visible.forEach(function (row) {
                tbody.appendChild(row);
            });

            // 3. Update counter
            var count = visible.length;
            if (counter) {
                counter.textContent = count + ' signal' + (count === 1 ? '' : 's');
            }
            if (showing) {
                showing.textContent = count;
            }
        }

        function matchAsset(row, value) {
            if (value === 'other') {
                return ['BTC','ETH','SOL'].indexOf(row.dataset.asset) === -1;
            }
            return row.dataset.asset === value;
        }

        // ── Init counter ─────────────────────────────────────────
        applyFilters();
    });
}());
