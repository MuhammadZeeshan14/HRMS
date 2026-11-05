# Shared HRIS and HRLA form design — 2 November 2026

The Employee Information tab is the visual reference for frontend forms in both modules. Its existing create/edit design remains the reference; a shared stylesheet extends its card surfaces, section typography, orange markers, spacing, pale input backgrounds, borders, focus states and button treatment to the remaining forms.

## Coverage

* HRIS setup add forms and edit dialogs, including nested banking fields, multiple selections, checkboxes and document uploads.
* HRLA setup add forms and edit dialogs for shifts, assignments, holidays, leave types and policies.
* Leave application, attendance entry/correction, approval controls, upload/mapping, register/inbox/balance filters and history filters.
* Employee exit records, directory search/filter controls, company-department filters, and login.
* The existing employee entry tabs, repeated records and dialogs retain their Employee Information styling and behavior.

Desktop forms use a four-column field grid where space permits, with full-width text areas and appropriately wider long fields. Narrow attendance editors keep two columns. Grids collapse to three/two/one columns at smaller viewport widths. Setup edit dialogs use the same controls and card rhythm. Save rows remain in document flow so they cannot cover fields. Segmented hour/minute controls and AM/PM selectors retain their input behavior and exact stored timestamp handling.

## Implementation boundaries

`public/shared-form-design.css` holds the shared design and loads after existing module styles. `layouts/app.blade.php` and `auth/login.blade.php` opt into the theme. The leave application receives a matching section heading. No routes, controllers, requests, models, permissions, validation rules, field names, database structures or existing tests were changed. The HRLA sidebar still exposes only Dashboard and Setup as previously requested.

The update is presentation-only. Existing company/employee/configuration records were not rewritten, and test fixtures were confined to the existing isolated preview and verification databases.

## Verification

* Baseline and final full suite: **88 tests, 1,035 assertions; the same 8 errors and 3 failures** from the earlier schema changes. The suite is not fully green; there are no newly failing test identities. No assertions were removed or weakened.
* Rendered **47 form pages** from isolated fixture data. Desktop (1440 px) and mobile (390 px) browser checks covered those pages and populated setup edit dialogs: **132 checks**, no page/dialog overflow and no JavaScript page errors. Employee tabs and dialog open/close behavior were exercised.
* Focused follow-up checks covered full-width fields, company banking fields, search controls, dynamically added exit records and unobstructed save rows. Representative screenshots were visually inspected.
* Existing segmented-clock browser tests passed against the disposable MariaDB copy: shift save, attendance save, hour bounds, midnight conversion, retained original seconds and mobile layout.
* All Blade templates compile with `php artisan view:cache`. Assets are static public CSS; no dependency or bundler change was needed.

Evidence is under `.document-reference/forms-ui-*`, including baseline/final JUnit results, screenshots, browser results and file-hash comparison. The comparison confirms that only the three intended Blade views changed and the shared stylesheet was added within application source.
