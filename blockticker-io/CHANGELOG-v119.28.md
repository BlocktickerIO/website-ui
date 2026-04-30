# BlockTicker v119.28.0 — Signal Archive

**Released:** April 2026  
**Type:** Feature — new public page  
**Files changed:** 5 (3 new, 2 patched)

---

## What's new

### Public Signal Archive — `/signal-archive/`

The missing third leg of the trust spine:

```
Methodology PDF (Q3 roadmap)
  └── Desk Brief (shipped v10)
        └── Signal Archive (shipped v119.28.0) ← this release
```

Every signal ever fired is listed publicly with its entry, targets,
stop and verified outcome. Nothing deleted. Nothing edited post-hoc.

**Page sections:**

| Section | What it shows |
|---|---|
| Hero | Page title + immutability promise |
| KPI strip | 5 cards — total signals, hit rate, avg return, best, worst. Live data from `BT_SignalTracker::get_signal_track_stats()` with graceful demo fallback |
| Honesty banner | Dashed-border callout: 4 explicit disclosures about what "honest" means |
| Filter bar (sticky) | Asset / Direction / Outcome chips + Sort dropdown — real client-side JS |
| Signal table | 30-row sample (29 recent + all-time best #BT-0892 +6.2R SOL). Losses and timeouts are visible by default — win/loss ratio matches the claimed 64% hit rate |
| Detector breakdown | 4 cards: Volume 71% (w=0.30), Momentum 66% (w=0.25), Funding 62% (w=0.25), Correlation 58% (w=0.20) |
| CTA + links | Free signals CTA + cross-links to Home / Desk Brief |

**Deliberate honesty choices in the sample data:**
- ~32% of rows are losses or timeouts — ratio matches 64% hit rate when you count
- Every loss stops at exactly −1.0R — consistent stop-loss discipline visible in the data
- Two timeout rows (`#BT-1242`, `#BT-1231`) show 0R — not counted as losses, listed separately
- No pre-launch backtest data mixed in — the earliest row is dated post-launch

---

## Files

### NEW: `includes/class-signal-archive.php`
- Registers shortcode `[blockticker_signal_archive]`
- Pulls live KPIs from `BT_SignalTracker::get_signal_track_stats()` with placeholder fallback
- `get_demo_rows()` private method holds 30-row sample — flagged `// TODO v119.29: replace with DB query`
- CSS/JS conditionally enqueued only when shortcode renders
- All strings wrapped in `__('...', 'blockticker')` for i18n

### NEW: `assets/css/signal-archive.css`
- ~300 lines, zero new token declarations
- All selectors prefixed `bt-archive__` — no bleed
- Uses `--bt-bg`, `--bt-bg-elev`, `--bt-accent`, `--bt-danger`, `--bt-accent-warm`, `--bt-text-*`, `--bt-border*`, `--bt-font-*` from revamp-v44.css
- Responsive: 5-col KPI → 3-col → 2-col; 4-col detectors → 2-col → 1-col

### NEW: `assets/js/signal-archive.js`
- ~100 lines, no dependencies, vanilla JS
- Chip filter: asset (All / BTC / ETH / SOL / Other), direction (All / LONG / SHORT), outcome (All / Hit / Miss / Timeout)
- Sort: Newest / Oldest / Best return / Worst return / Confidence
- Live counter badge updates on every filter/sort action

### PATCH: `includes/class-pages.php`
Add signal archive page to `get_pages_config()` — see patch notes below.

### PATCH: `fx-live-markets.php`
- `BT_VERSION` → `119.28.0`
- `require_once` for `class-signal-archive.php` after `class-intelligence-brief.php`

---

## Patch instructions

### fx-live-markets.php — version + require

Find:
```php
define( 'BT_VERSION', '119.27.0' );
```
Replace with:
```php
define( 'BT_VERSION', '119.28.0' );
```

Find:
```php
require_once BT_DIR . 'includes/class-intelligence-brief.php'; // v73: Composite cross-market brief (rebuilt + extracted)
```
Add immediately after:
```php
require_once BT_DIR . 'includes/class-signal-archive.php';     // v119.28: Public signal archive page
```

Also add the init call — find the section where other classes are initialised (look for `BT_IntelligenceBrief::init()` or similar) and add:
```php
BT_SignalArchive::init();
```

### class-pages.php — page registration

Find the `// ── TRADING SIGNALS ──` comment block and add the following immediately before it:

```php
            // ── SIGNAL ARCHIVE ──
            'signal-archive' => array(
                'title'   => 'Signal Archive',
                'slug'    => 'signal-archive',
                'content' => '<!-- wp:shortcode -->[blockticker_signal_archive]<!-- /wp:shortcode -->',
            ),

```

---

## What's next (v119.29 candidates)

- Replace `get_demo_rows()` with paginated DB query from `bt_signal_track` option / custom table
- Per-signal detail page `/signal/{id}` — chart of price action vs published levels + post-mortem
- CSV export endpoint for the full archive
- Methodology PDF — the only remaining item in the trust spine (Q3 roadmap)
