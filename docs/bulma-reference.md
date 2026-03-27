# Bulma Reference for MikReMan

This document is a working reference for the MikReMan migration from mixed Bootstrap/native CSS to Bulma.

Official sources:
- Bulma documentation index: https://bulma.io/documentation/
- Bulma overview: https://bulma.io/documentation/start/overview/
- Bulma features: https://bulma.io/documentation/features/
- Bulma themes: https://bulma.io/documentation/features/themes/
- Bulma modularity: https://bulma.io/documentation/start/modular/
- Bulma components: https://bulma.io/documentation/components/
- Bulma elements: https://bulma.io/documentation/elements/
- Bulma modal: https://bulma.io/documentation/components/modal/

This is a repo-focused summary, not a verbatim copy of the official docs.

## What Bulma Is

Bulma is a mobile-first CSS framework built on Flexbox.

Key points relevant to this repository:
- a single CSS file is enough to start using Bulma
- Bulma v1 uses CSS variables and supports light/dark themes
- Bulma does not ship with built-in JavaScript behavior

Implications for MikReMan:
- layout and styling can move to Bulma cleanly
- modal, navbar burger, tabs, dropdowns, and dismiss behavior must be handled with local JavaScript
- Bulma works well with this server-rendered PHP application model

## Starter Integration

Minimal Bulma setup:

```html
<!DOCTYPE html>
<html>
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css">
  </head>
  <body>
    <section class="section">
      <div class="container">
        <h1 class="title">Hello Bulma</h1>
      </div>
    </section>
  </body>
</html>
```

## Core Bulma Concepts

### Layout Primitives

Bulma exposes several layout primitives:
- `container`
- `section`
- `columns` and `column`
- `grid`
- `level`
- `media`
- `hero`
- `footer`

Useful mappings for MikReMan:
- `container-fluid` -> `container is-fluid`
- `row` -> `columns`
- `col-md-6`, `col-lg-4`, and similar -> responsive `column` classes such as `is-half-tablet`, `is-one-third-desktop`

### Form Primitives

Bulma forms are built from:
- `field`
- `control`
- `label`
- `input`
- `textarea`
- `select`
- `checkbox`
- `radio`
- `file`

Bulma does not include Bootstrap-style floating labels. If floating labels are still needed, they must be implemented with custom CSS.

### Elements

Frequently used Bulma elements:
- `button`
- `box`
- `content`
- `icon`
- `notification`
- `progress`
- `table`
- `tag`
- `title`

For MikReMan, the most important ones are:
- buttons
- tables
- notifications
- tags
- titles
- icons

### Components

Most relevant Bulma components for this repository:
- `navbar`
- `card`
- `modal`
- `tabs`
- `message`
- `notification`
- `dropdown`
- `menu`

Common usage in MikReMan:
- `navbar` for the top navigation
- `card` for admin/dashboard blocks
- `modal` for add/edit/details flows
- `tabs` for the admin page

### Helpers

Bulma helpers cover:
- color
- spacing
- typography
- visibility
- flexbox
- alignment

Useful helpers in this repo:
- `is-flex`
- `is-align-items-center`
- `is-justify-content-space-between`
- `is-hidden-mobile`
- `is-hidden-touch`
- `has-text-centered`
- `has-text-weight-semibold`

Bulma helpers are not a one-to-one replacement for Bootstrap utility classes, so a small local utility layer is still acceptable when needed.

## Themes and CSS Variables

Bulma v1 is built around CSS variables.

Important points:
- the default light theme is available out of the box
- dark mode can be applied through `data-theme` or a custom theme layer
- theme overrides are easier and cleaner than legacy Bootstrap overrides

Guidance for MikReMan:
- map app colors to Bulma tokens wherever possible
- keep `assets/css/style.css` focused on brand and app-specific styling
- avoid reintroducing broad legacy framework overrides

## Bulma and JavaScript

Bulma does not provide Bootstrap-like JavaScript for:
- modals
- collapses
- navbar toggles
- dropdown behavior
- dismiss actions

This means MikReMan must keep local JS for those behaviors.

Direct implications:
- `bootstrap.Modal` usage must be replaced
- `data-bs-*` driven behavior must be replaced
- modal and alert behavior should be explicit in local JS

## Practical Bootstrap -> Bulma Mapping

### Layout
- `container-fluid` -> `container is-fluid`
- `row` -> `columns is-multiline`
- `col-md-6` -> `column is-half-tablet`
- `col-lg-6` -> `column is-half-desktop`
- `col-lg-4` -> `column is-one-third-desktop`

### Buttons
- `btn` -> `button`
- `btn-primary` -> `button is-primary` or `button is-link`
- `btn-success` -> `button is-success`
- `btn-danger` -> `button is-danger`
- `btn-warning` -> `button is-warning`
- `btn-info` -> `button is-info`
- `btn-outline-*` -> usually `is-light`, `is-outlined`, or a small custom variant
- `btn-sm` -> `button is-small`
- loading buttons -> `button is-loading`

### Forms
- `form-group` -> `field`
- `form-label` -> `label`
- `form-control` -> `input` or `textarea`
- `form-select` -> `select`
- `form-check` -> `checkbox` or `radio`
- input groups -> `field has-addons`

### Alerts and Notifications
- `alert alert-danger` -> `notification is-danger`
- `alert alert-success` -> `notification is-success`

### Tables
- `table-responsive` -> `table-container`
- `table table-striped` -> `table is-striped`
- `table-hover` -> `table is-hoverable`

### Modals
- Bootstrap modal structure -> Bulma `modal` and `modal-card`

## Repo-Specific Guidance

For MikReMan specifically:
- use Bulma primitives first
- keep custom CSS focused on spacing, app layout, and branded surfaces
- prefer class-based JS state changes over inline styles
- use `table-container` for wide tables on smaller screens
- prefer `modal-card` over ad hoc modal markup

## Recommended Working Pattern

When migrating or adjusting UI in this repo:
1. Start with Bulma structure in the page markup.
2. Move legacy inline styles into `assets/css/style.css` only when Bulma is not enough.
3. Replace Bootstrap-driven behavior with explicit local JS.
4. Validate both desktop and mobile layouts.

## Immediate Use In This Repo

Bulma is already the active direction in MikReMan for:
- navbar
- tabs
- forms
- modal-card layouts
- tables
- theme switching

Any future UI work should preserve that direction rather than reintroducing Bootstrap patterns.
