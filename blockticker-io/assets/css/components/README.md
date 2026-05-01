/**
 * BlockTicker CSS Migration Guide
 * 
 * This directory contains modular CSS components migrated from monolithic files.
 * All new styles should use the --bt-* namespace (not --fxlm-*).
 * 
 * @package BlockTicker/Assets/CSS
 * @since 119.29.0
 */

/*
 * MIGRATION STATUS:
 * 
 * ✅ COMPLETED:
 * - critical.css - Above-the-fold styles with bt-* namespace
 * - Variables migrated from --fxlm-* to --bt-*
 * 
 * 🚧 IN PROGRESS:
 * - ticker.css - Extracted from frontend.css lines 72-150
 * - cards.css - News card, asset card, signal card styles
 * - forms.css - Input fields, buttons, form layouts
 * - navigation.css - Menu, breadcrumbs, pagination
 * 
 * 📋 TODO:
 * - Migrate all .fxlm-* class references to .bt-*
 * - Split revamp-v44.css into component modules
 * - Remove version numbers from filenames
 * - Implement CSS custom properties for theming
 * 
 * NAMING CONVENTIONS:
 * - Use BEM methodology: .block__element--modifier
 * - Prefix all classes with bt-
 * - Use lowercase with hyphens
 * - Be descriptive but concise
 * 
 * EXAMPLE:
 * .bt-card { }                    // Block
 * .bt-card__header { }            // Element
 * .bt-card--featured { }          // Modifier
 * .bt-card__header--large { }     // Element + Modifier
 */
