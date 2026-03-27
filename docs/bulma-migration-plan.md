# Bulma Migration Plan for MikReMan

This document records the migration strategy from mixed Bootstrap/native CSS to Bulma.

## Goal

The migration goals were:
- replace Bootstrap CSS and Bootstrap JS dependencies
- simplify styling with Bulma as the base framework
- preserve feature parity
- improve consistency across layout, forms, alerts, and modals

## Original State

Before the migration, the codebase relied on:
- Bootstrap CDN on the main pages
- Bootstrap Icons
- Bootstrap JS for modal and collapse behavior on several pages
- a custom stylesheet that overrode many Bootstrap selectors directly

Primary files involved:
- `index.php`
- `pages/admin.php`
- `pages/dashboard.php`
- `pages/monitoring.php`
- `pages/ppp.php`
- `assets/css/style.css`
- `assets/js/admin.js`

## Main Risks

The highest-risk areas during migration were:
- modal behavior
- notification dismiss behavior
- sidebar and navbar collapse behavior
- table responsiveness
- async button states
- JavaScript that dynamically changed Bootstrap class names

## Required Refactor Areas

### 1. Framework Includes

Pages needed to move away from:
- Bootstrap CSS CDN
- Bootstrap JS bundle

Toward:
- Bulma CSS
- a chosen icon library
- local JS helpers for interactive behavior

### 2. CSS Layer

`assets/css/style.css` needed to move from:
- Bootstrap-override-oriented styling

Toward:
- theme tokens
- light Bulma-aware overrides
- app-specific component styles

### 3. JavaScript Layer

Interactions that depended on Bootstrap had to move to local helpers:
- modal open/close
- dismiss notifications
- navbar/menu toggle
- collapse/toggle states

### 4. HTML Structure

Markup needed to follow Bulma structure for:
- grids
- forms
- cards
- buttons
- notifications
- tables
- modal-card layouts

## Execution Order

### Batch 1: Foundation
- add Bulma reference docs
- define the theme direction
- add local UI helpers for modal and notification behavior
- define practical Bootstrap -> Bulma mappings

### Batch 2: Login Page
Files:
- `index.php`
- `assets/css/style.css`
- `assets/js/login.js`

Reason:
- isolated
- few Bootstrap JS dependencies
- good place to establish the visual language

### Batch 3: Admin Page
Files:
- `pages/admin.php`
- `assets/js/admin.js`
- `assets/css/style.css`

Targets:
- form markup
- cards
- service controls
- configuration alerts

### Batch 4: Dashboard and Monitoring
Files:
- `pages/dashboard.php`
- `pages/monitoring.php`
- `assets/css/style.css`

Targets:
- stat cards
- tables
- panel layout
- responsive polish

### Batch 5: PPP Users
Files:
- `pages/ppp.php`
- `assets/css/style.css`

Targets:
- the largest conversion in the repo
- modal system
- table layout and bulk actions
- user details UI

## Definition of Done Per Page

A page was considered migrated when:
- it no longer loaded Bootstrap CSS
- it no longer loaded Bootstrap JS
- its main Bootstrap classes were removed from the page
- user interactions still worked
- the layout remained usable on both desktop and mobile

## Suggested JS Utilities

Recommended local utilities:
- `window.ui.openModal(id)`
- `window.ui.closeModal(id)`
- `window.ui.showNotification(type, message)`
- `window.ui.toggleMenu(id)`

These replace the old dependency on `bootstrap.Modal` and `data-bs-*`.

## Design Direction

To preserve the app's identity:
- keep the dark-capable admin-oriented visual language
- use Bulma cards, tables, tags, notifications, and modal-card layouts
- prefer cleaner spacing and more consistent Bulma form structure
- avoid recreating Bootstrap one-to-one

## Outcome

The repository is now on a Bulma-first path.

Future UI work should:
- stay consistent with Bulma primitives
- avoid reintroducing Bootstrap dependencies
- keep custom CSS scoped to app-specific needs instead of framework-level overrides
