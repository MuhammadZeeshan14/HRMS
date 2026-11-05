# Compatibility report — updated 30 October 2026

## Result and release boundary

**Installed in the populated `ahamson_hris` database on 30 October 2026.** Only the reviewed additive module migration was applied, creating 12 empty module tables. No operational rows, accounts, permissions or workbook examples were inserted into that database. MySQL was unavailable on 29 October; the service became reachable during the follow-up on 30 October and the previously blocked checks below were completed.

The actual server is MariaDB 10.4.32 using Laravel's configured MySQL connection. Current schema inspection confirmed 3 employees, 1 user, 2 companies, 20 departments, 35 designations, 2 locations, no fiscal-year rows and `permanent` employment type. No existing classifications were repurposed.

Verification now covers forced in-memory SQLite automated tests, a disposable full MariaDB copy for migration/rollback and real concurrency, desktop/mobile browser checks on that copy, and authenticated read-only requests against the populated application. This does not claim that the full legacy suite passes or that every possible production behavior was tested.

## Database activation evidence

* A full `mysqldump` backup, including triggers/routines/events, was restored to new isolated schema `hrla_verify_20261030_e0b9d8`. Restored data matched all 52 pre-existing tables' row hashes. No triggers/events required special clone execution handling.
* Migration `2026_10_29_001000_create_leave_attendance_module` passed up/down/up on the copy, preserved all 52 original table hashes and created exactly 12 empty module tables. No reset or destructive seeder ran against the populated database.
* Two simultaneous approval processes for one correction produced exactly one version/decision. Two concurrent corrections based on the same old version produced one commit and one stale-version rejection. Two concurrent one-day leave approvals competing for a one-day allowance produced one approval and one insufficient-balance rejection.
* Pre-installation authenticated read-only smoke checks passed on 38 existing pages. After installation, all 52 checked existing/module pages returned HTTP 200. The live checks used in-memory sessions; no passwords or account roles were changed. Normal application session-table activity was detected separately.
* Immediately before activation a fresh full backup was saved as `E:\HRMS\.document-reference\hrla-deployment-20261030_e0b9d8\before-activation.sql`, SHA-256 `3555a5cddd7f64bffc25fea16bddf0dc97b2ba2b26ff83dd8774c247c4a9ce0f`.
* All 51 non-session table hashes were preserved across activation, ignoring only the expected appended module migration-history row. Session metadata can change through normal application use; it was included in the backup and excluded from equality checks. New module tables were verified empty.
* The full suite was rerun on 30 October: 77 tests, 927 assertions, with the same 8 errors and 3 failures listed below. The focused 42-test/425-assertion group passed after correcting module table separator encoding discovered during visual review.

Evidence is retained under `.document-reference/hrla-deployment-20261030_e0b9d8/` (`verification.json`, `concurrency.json`, `browser-results.json`, `activation.json`, and before/after production smoke results). All concurrency fixtures and verification accounts exist only in the isolated copy.

## Baseline comparison

| Check | Before | After |
|---|---:|---:|
| Full PHPUnit tests | 45 | 77 |
| Passing tests | 34 | 66 |
| Errors | 8 | 8, same test names and causes |
| Failures | 3 | 3, same test names and causes |
| Assertions | 616 | 927 |
| New module/compatibility tests | — | 32 passing, 299 assertions |

The full suite does **not** pass. No old tests/assertions were edited, deleted, skipped or weakened. The additional 12 assertions in existing tests come from their existing loops checking empty newly added tables. The focused run including all new tests and `HrmsWorkflowTest` passes: **42 tests, 425 assertions**.

Pre-existing errors:

* `AdditiveSetupImportTest::test_import_preserves_existing_records_maps_links_and_is_repeatable`: unsupported `business_units` table.
* `AhamsonSetupSeederTest::test_workbook_setup_is_imported_with_relationships_and_is_repeatable`: obsolete `report_to_designation_id` property.
* `BusinessUnitDepartmentsTest::test_units_support_multiple_departments_edit_clear_and_delete`: removed `business_units` table.
* Three `CompanyDepartmentTest` cases (`employees_are_placed_in_their_departments_and_reporting_positions`, `company_and_status_filters_and_unassigned_staff`, `circular_imported_links_do_not_hide_employees_or_recurse_forever`): obsolete `designations.company_id` fixture column.
* `DepartmentMigrationTest::test_existing_departments_and_employee_links_survive_consolidation`: obsolete `designations.hierarchy_id` fixture column.
* `HrisSchemaTest::test_explicit_workbook_seed_contains_only_the_reviewed_workbook_masters`: removed `functions` table assertion.

Pre-existing failures:

* `BusinessUnitDepartmentsTest::test_departments_from_other_companies_are_rejected`: expected obsolete business-unit validation.
* `HrisSchemaTest::test_company_branch_and_designation_company_department_are_validated`: expected obsolete designation relationship validation.
* `HrisSetupTest::test_designation_relationships_save_and_self_reporting_is_rejected`: expected removed designation `company_head` field.

These were observed before module changes. They need a separately scoped review of the earlier schema simplification; this module does not recreate those structures or change their meanings.

## Existing modules checked

* Real login success/failure, logout, guest redirects, existing admin/HR/employee dashboard navigation and inactive module access.
* Dashboard metrics and employee directory search/filter/empty results.
* Employee create/edit forms, related profile saving, full-name handling, profile display and separate exit workflow. Existing employee tests remain passing.
* Company-department page HTTP rendering and desktop/mobile browser smoke. Its three old fixture-dependent tests still fail as listed above, so chart semantics are not fully verified by that suite.
* Every currently configured master-data list renders. Company create/edit/delete and validation pass. Protected document download works for admin and denies employee. Existing compensation/possession and other passing master-data tests were included in the full run.
* Additive module migration `down/up` preserves snapshots of **all** representative pre-existing tables populated by the isolated existing DemoSeeder. Foreign-key checks pass and module setup reinstalls empty.

## New feature verification

The subsequent AM/PM presentation update passed the focused time/attendance/upload group (37 tests, 321 assertions). The full suite ran 86 tests with 1,015 assertions and retained the same 8 errors and 3 failures listed above. Browser checks on the isolated MariaDB copy verified noon/midnight shift entry, explicit overnight dates, desktop/mobile layouts, punch-button focus and both unchanged/edited saves. Direct storage auditing confirmed original seconds survive unchanged saves and changed minutes store correctly. The correction CSV assertion was updated only for the explicitly requested twelve-hour output; raw-punch storage assertions remain intact. No migration or populated-database data rewrite was required.

Rounding checks cover 09:12, 09:15, 09:16, 09:44, 09:45, 09:46, midnight rollover, exact grace boundaries, unrounded shift boundaries, original seconds/duration/Diff and combined Late/Early off. Tests cover missing single punches, null incomplete durations, completed absence without a row, future/in-progress shifts, explicit overnight schedules, missing configuration, and historical shift snapshots.

Calendar/leave tests cover Friday–Monday, a working-day holiday, a holiday on a weekend, all-day-off rejection, internal eligible 0.5 units with excluded dates zero, disabled half-day activation, approved leave without punches, Day off precedence, policy-state distinctions, insufficient balance, overlap, approval-time revalidation and exactly-once usage.

Workflow/security tests cover session-bound correction identity, HR/supervisor direct scope, enabled-secondary approval, self/unauthorized approvals, rejection reasons, stale expected versions, pending/rejected records having no attendance effect, version retention and deterministic timestamp/ID selection. Read/export/selector scopes and CSV formula escaping are checked. Import tests cover real generated XLSX files, physical row provenance, text codes/leading zeros, formula rejection, malformed files, unknown employees, ambiguous dates, duplicate rows/mappings, preview/commit, stale preview failures, retries and batch ownership.

Transactions, employee/request row locks and unique decision/version provenance constraints are implemented. Exact-once and stale-transition behavior is tested sequentially and, during the 30 October follow-up, with true simultaneous MariaDB processes as described above. Deliberately forced deadlock recovery is not separately tested. Half-day approvals, as-needed approvals, accrual/carry-forward, cancellation and machine adapters remain inactive as documented.

## Browser and build checks

Headless Edge against the disposable application fixture checked eight screens at 1440×1000 and 390×844: daily register, shift setup, leave form, direct attendance entry, employee directory, employee edit, company departments and company master data. All returned HTTP 200, had no page-level mobile horizontal overflow, and produced no JavaScript page errors. Wide tables scroll inside their own regions. The existing sidebar animation was allowed to settle before mobile screenshots. Representative module desktop/mobile screenshots were visually inspected. Server-backed Friday–Monday preview showed 2 working days; browser logout returned to login.

The 30 October MariaDB-copy browser run expanded this to 14 screens, adding correction inbox, leave inbox, balances, holidays, policies and upload. All 14 passed at both widths, with zero JavaScript errors or page overflow, and real login/logout and the server-backed leave preview succeeded. A UTF-8 display issue affecting separators in five new module views was corrected and the browser/focused checks repeated successfully. Existing shared styles and views were not rewritten.

PHP Pint passes for new PHP files. `php artisan view:cache` compiles all Blade templates successfully. `node --check public/leave-attendance.js` passes. The module uses static public CSS/JS; no npm dependencies or bundler changes were introduced. No npm build was run (existing project has no installed `node_modules`).

## Change inventory and reproducibility

No Git metadata was present. A SHA-256 snapshot of existing application/config/routes/resources/database/tests/public files was taken before implementation. Final comparison found **exactly two changed pre-existing files** and **zero removed pre-existing files**:

1. `routes/web.php`: includes the separate authenticated module route file. Existing route definitions remain intact.
2. `resources/views/layouts/app.blade.php`: adds one Leave & Attendance navigation entry. Existing components, styles, event handlers and HRIS navigation behavior remain intact.

All other implementation files are new module-specific models, services, policy, requests, controller, routes, migration, views, scoped CSS/JS and tests. No dependency, framework, global setting, existing model/policy/middleware or seeder was changed. Work outside the project is limited to reference extraction, hash/test evidence and disposable browser fixtures under `.document-reference`.

Run the full suite with `php vendor\phpunit\phpunit\phpunit`. Run the passing focused group with `php vendor\phpunit\phpunit\phpunit --filter "AttendanceUploadTest|AttendanceCalculatorTest|LeaveAttendanceTest|LeaveAttendanceCompatibilityTest|HrmsWorkflowTest"`. PHPUnit forces the isolated in-memory database. JUnit and browser evidence are saved under `.document-reference/hrla-*`; implementation/setup instructions and all seven worksheet mappings are in `docs/leave-attendance.md`.

The baseline failures were reviewed as the same pre-module issues; no module-introduced failing tests were found. Real schema/backup, disposable MariaDB migration/rollback, actual concurrent approval and populated read-only smoke checks are now complete. Existing-module create/edit/delete/file operations remain verified by the isolated compatibility tests, rather than by writing disposable examples into live company data. Live password authentication was not exercised with the existing user's credentials; real authentication was tested on the isolated copy. Retain the backup, enter actual module configuration through Setup, and separately address the recorded legacy failures. This report does not assert that untested deployment behavior cannot break.
