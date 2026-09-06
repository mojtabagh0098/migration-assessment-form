=== Migration Assessment Form ===
Contributors: yourcompany
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Custom WordPress plugin: a multilingual (WPML-native) Immigration Assessment
Form builder with a Vanilla-JS front end, a dynamic-filter Entries admin
dashboard, per-field audit logging, and CSV/PDF export.

== Architecture ==

**Form definitions** live on a custom post type, `maf_form`. This is what
plugs natively into WPML: every language edition of a form is its own post,
with its own ID and its own permalink (WPML → Settings → Custom Posts
Translation → enable "Assessment Forms"). No client-side language switcher
is used anywhere — this matches the requirement precisely.

Each `maf_form` post stores its field schema as JSON in post meta
(`_maf_form_schema`), editable in the "Form Fields (Schema)" meta box on the
post edit screen. New forms are seeded from `MAF_Fields::default_schema()`,
which encodes every section/field requested (Contact Info, Personal Info
incl. spouse conditional fields, Family/Friend in Canada, Language Skills,
Education & Training repeater, Work Experience, Canadian Job Offer, Express
Entry Profile, financial/notes fields).

**Entries** (submissions) are stored in a dedicated custom table
(`{prefix}maf_entries`), NOT as post meta. Reasoning: submissions are
high-volume, need fast dynamic filtering (status, country, age range,
marital status, language, free-text search), and have no reason to be
WordPress posts. A handful of "hot" fields (name, email, phone, age,
country, marital status, net worth) get real indexed columns for fast
filtering; the complete submission is additionally stored as JSON in a
`data` column so new form fields never require a schema migration.

**Audit log** is a second custom table (`{prefix}maf_audit_log`) with one
row per changed field: admin id/name, field name, old value, new value,
optional note, timestamp. Written automatically whenever an admin changes
an entry's status or a hot field via the REST API.

== Admin Dashboard ==

WordPress Admin → "Assessment Forms":
- **Entries** — dynamic-filter table (form, language, status, country,
  search) with pagination, a detail modal showing full submitted data +
  the complete audit trail, and inline status changes (Submitted /
  Conditional / Approved / Rejected) with an optional note.
- **All Forms** — the native `maf_form` list table; extra columns show the
  shortcode and per-language WPML translation status at a glance.
- **Add New Form** — create a new form (or use WPML's "+" translate button
  on an existing form to create a language variant).
- **Export CSV / Export PDF** buttons on the Entries screen respect the
  currently active filters. CSV is UTF-8 with a BOM so Excel renders
  Persian/Arabic and other non-Latin scripts correctly.

== Front-end ==

Drop `[assessment_form id="123"]` into any page/post (get the exact
shortcode — with the correct per-language form ID — from the "Shortcode"
box on that language's form edit screen, or the "Shortcode" column in All
Forms).

100% Vanilla JS/HTML5/CSS3: no React/Vue/build step. Conditional logic
(e.g. spouse fields appearing only when Marital Status = Married), the
Education & Training repeater (add/remove rows), the international phone
input (via the lightweight `intl-tel-input` library, loaded from CDN), and
submission are all implemented in `assets/js/maf-frontend.js` using the
Fetch API against a small custom REST namespace (`/wp-json/maf/v1/`).

== Security ==

- Public submission endpoint requires a valid WordPress REST nonce
  (`X-WP-Nonce`, obtained via `wp_create_nonce( 'wp_rest' )` and localized
  into the page) — mitigates CSRF while still allowing logged-out visitors
  to submit.
- All admin REST routes require both `manage_options` capability AND a
  valid nonce.
- All input is sanitized server-side per declared field type
  (`MAF_REST::sanitize_field()`), regardless of what client-side validation
  already did — never trust the client.
- All output in admin screens/front-end templates is escaped
  (`esc_html`, `esc_attr`, `esc_url`, `esc_textarea`, `wp_json_encode`).
- Direct file access is blocked (`ABSPATH` check) in every PHP file, and
  every directory has an `index.php` silence file.
- Schema edits go through nonce verification + `current_user_can( 'edit_post' )`
  + strict JSON validation before being saved.

== File structure ==

migration-assessment-form/
├── migration-assessment-form.php   Bootstrap, autoloader, activation
├── uninstall.php                   Optional data cleanup (opt-in only)
├── wpml-config.xml                 Declares `maf_form` as translatable
├── includes/
│   ├── class-maf-activator.php     Custom-table creation/upgrade (dbDelta)
│   ├── class-maf-cpt.php           `maf_form` CPT + schema meta box
│   ├── class-maf-fields.php        Canonical field schema definitions
│   ├── class-maf-fields-countries.php
│   ├── class-maf-shortcode.php     `[assessment_form]` + HTML renderer
│   ├── class-maf-rest.php          REST API (submit/list/update/audit)
│   ├── class-maf-audit.php         Audit-log read/write helper
│   ├── class-maf-wpml.php          WPML language helpers
│   ├── class-maf-export.php        CSV + PDF export (admin-post handlers)
│   ├── class-maf-simple-pdf.php    Dependency-free minimal PDF writer
│   └── class-maf-admin.php         Admin menu + Entries dashboard shell
├── assets/
│   ├── js/maf-frontend.js          Front-end form behavior (Vanilla JS)
│   ├── js/maf-admin.js             Admin dashboard behavior (Vanilla JS)
│   ├── css/maf-frontend.css        Front-end styles (RTL-aware)
│   └── css/maf-admin.css           Admin dashboard styles
└── templates/                      Reserved for future custom templates

== Notes & next steps for production hardening ==

1. `class-maf-simple-pdf.php` uses PDF's built-in Helvetica font, which only
   supports Latin-1 text — sufficient for a plain entries table, but not
   for embedding Persian text inside the PDF itself. If a fully localized
   PDF is required, swap this class for Dompdf or mPDF via Composer (the
   `MAF_Export::export_pdf()` call site is a single, isolated integration
   point) — the CSV export already fully supports Persian/UTF-8.
2. Add rate limiting / a honeypot field to `/maf/v1/submit` if the form is
   likely to attract bot spam.
3. The JSON-schema editor is intentionally a plain textarea (per the
   Vanilla-JS-only requirement, this avoids shipping a heavy visual
   builder); `MAF_Fields::default_schema()` is the single source of truth
   to extend with new field types.
