# BlockTicker Component Library

Modular CSS components for the BlockTicker WordPress plugin.

## Directory Structure

```
components/
├── README.md           # This file
├── critical.css        # Above-the-fold styles (inlined in <head>)
├── skeleton.css        # Loading skeleton states ✨ NEW v119.30.0
├── empty-states.css    # Empty state designs ✨ NEW v119.30.0
└── ...                 # Future components
```

## New Components in v119.30.0

### 1. Skeleton Screens (`skeleton.css`)

Replace loading spinners with skeleton loaders for better perceived performance.

**Usage:**
```html
<!-- Price Card Skeleton -->
<div class="bt-card bt-skeleton--card" aria-busy="true">
    <div class="bt-skeleton--title"></div>
    <div class="bt-skeleton--price"></div>
    <div class="bt-skeleton--change"></div>
</div>

<!-- Table Skeleton -->
<div class="bt-skeleton-container">
    <div class="bt-skeleton--table-row">
        <div class="bt-skeleton--table-cell"></div>
        <div class="bt-skeleton--table-cell"></div>
    </div>
</div>
```

**JavaScript Integration:**
```javascript
// Using BlockTicker.Skeleton module
BlockTicker.Skeleton.show(element, 'text');
BlockTicker.Skeleton.hide(element);
```

### 2. Empty States (`empty-states.css`)

Beautiful empty states with illustrations and CTAs for dashboards, alerts, portfolios, etc.

**Usage:**
```html
<!-- Empty Alerts State -->
<div class="bt-empty-state bt-empty-state--alerts">
    <div class="bt-empty-state__icon">🔔</div>
    <h3 class="bt-empty-state__title">No Price Alerts Yet</h3>
    <p class="bt-empty-state__description">
        Stay ahead of the market! Create your first price alert...
    </p>
    <div class="bt-empty-state__actions">
        <a href="/alerts/new" class="bt-empty-state__button">Create Alert</a>
    </div>
</div>
```

**Available Variants:**
- `.bt-empty-state--dashboard`
- `.bt-empty-state--alerts`
- `.bt-empty-state--portfolio`
- `.bt-empty-state--following`
- `.bt-empty-state--news`
- `.bt-empty-state--screener`

## CSS Variables

All components use BlockTicker design tokens:

```css
--bt-gray-800: #1e293b;
--bt-gray-700: #334155;
--bt-blue-600: #2563eb;
--bt-radius-md: 8px;
--bt-radius-lg: 12px;
```

## Accessibility

- ✅ ARIA attributes (`aria-busy`, `aria-label`, `role="status"`)
- ✅ Reduced motion support (`prefers-reduced-motion`)
- ✅ Keyboard navigation friendly
- ✅ Screen reader optimized
- ✅ Focus indicators

## Browser Support

- Chrome/Edge: Last 2 versions
- Firefox: Last 2 versions
- Safari: Last 2 versions
- Mobile Safari/Chrome: Last 2 versions

## Best Practices

1. **Always include ARIA attributes** on skeleton loaders
2. **Use meaningful empty state copy** with clear CTAs
3. **Test with screen readers** to ensure proper announcements
4. **Respect user preferences** for reduced motion
5. **Keep component classes modular** - avoid nesting too deeply

## Future Components (Planned)

- [ ] `cards.css` - Enhanced card layouts
- [ ] `forms.css` - Form elements and validation states
- [ ] `tables.css` - Responsive table designs
- [ ] `modals.css` - Dialog and modal components
- [ ] `tooltips.css` - Tooltip and popover components
- [ ] `notifications.css` - Toast and alert notifications

## Contributing

When adding new components:
1. Use BEM naming convention (`.bt-component__element--modifier`)
2. Include comprehensive documentation
3. Add example HTML in comments
4. Test accessibility with screen readers
5. Ensure responsive design works on mobile

---

**Version:** 119.30.0  
**Last Updated:** 2026-05-01
