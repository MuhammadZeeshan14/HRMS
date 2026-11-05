# Employee HRLA attendance update — 4 November 2026

## Implemented behavior

Employee HRLA navigation and dashboard now offer Attendance, Edit / Mark Attendance, and Attendance History, alongside existing leave and My Account links. The new pages reuse the Blade application shell and use the supplied reference for the two prominent punch buttons, employee information card, location details, and recent records. Their CSS is scoped to Employee attendance pages. Admin screens and manual-entry behavior are unchanged.

- **Attendance** (`/leave-attendance/attendance/today`): green Check In and red Check Out capture browser location and save immediately. A server-derived live clock displays the attendance timezone. Employee, day and timestamps are determined by the authenticated account and server, never client fields. Multiple punches are allowed.
- **Edit / Mark Attendance** (`/leave-attendance/attendance/entry` for Employees): defaults to yesterday and permits only previous attendance dates. Shows existing committed attendance beside proposed times and a required reason. Today/future dates are rejected by HTTP validation and the attendance service. Admin manual entry retains its existing date/reason rules.
- **Attendance History** (`/leave-attendance/attendance/my-history`): displays daily committed records, proposed requests and decisions, version audit, and original punch events separately. Date ranges are limited to 93 days. Ownership is enforced on the server; existing scoped register/history/export endpoints remain protected.

## Storage and consistency

Additive migration: `2026_11_04_001000_add_employee_live_punches.php`.

Adds nullable `latitude` and `longitude` to existing Locations configuration and creates `hrla_punch_events`. Every accepted event stores employee, action, server timestamp, attendance date, captured coordinates/accuracy, office ID and coordinate snapshot, Haversine distance in metres, unique submission UUID, created timestamp, and the resulting committed attendance version ID. No employee edit/delete endpoint exists for events.

The event and committed version are saved in one transaction. Employee locking serializes punches with organizational edits and existing attendance writes. Locking occurs before consistent reads to prevent stale MySQL REPEATABLE READ snapshots. Retry UUIDs return the original event instead of creating another version; changing the action on an existing UUID is rejected. The browser disables both buttons during capture/save, preserves an uncertain submission ID across reloads, and retries that ID after a lost response.

Daily committed values retain the earliest check-in and latest check-out across captured events and any existing committed values. The existing effective-version query still returns one official record per employee/day; preceding versions remain available. A lone check-in/out leaves the other field empty. If an out precedes a later in, original timestamps are preserved and calculated work duration is clamped to zero until a later checkout; incomplete durations stay empty. Pending proposals do not enter calculations. Results are recalculated through the existing Register/calculator services on read.

Attendance dates use `config('app.timezone')`, currently **Asia/Karachi**, as existing HRLA calculations do. Live events belong to the local calendar day; an overnight punch after midnight belongs to the new day. Historical correction forms retain explicit timestamp dates for overnight shifts.

## Location requirements and setup

Office resolution uses `employees.reporting_location_id`, falling back to `employees.location_id` only when no reporting office is assigned. A selected office must be active, belong to the employee's company or existing shared configuration, and have valid latitude/longitude. Coordinates, accuracy and office assignment are validated on the server. Missing capture, denied permission, unavailable location, timeout, missing office/coordinates and missing shift all produce actionable errors; failed saves roll back the event.

Distance is informational, with no radius restriction or biometric requirement. Browser-reported coordinates are not a tamper-proof identity check. Geolocation requires a browser secure context (HTTPS, or localhost for development) and device/browser permission.

**Existing-data readiness:** none of the 17 existing employees currently resolves to an office with configured coordinates; four have no effective shift for 4 November 2026. No office coordinates or assignments were guessed. An Admin must enter actual coordinates in **HRIS Setup → Locations**, assign the correct reporting/location office through the authorized employee workflow, and assign an effective shift where missing. The UI remains blocked with setup guidance until those requirements are met.

## Approvals and preserved behavior

Previous-day proposals use the existing notification and approval flow: eligible assigned primary/secondary reporting Admins and same-company HR are notified; **one eligible approval** commits a new version. Reporting eligibility, self-approval denial, permission rechecks, stale-version checks, duplicate decision prevention and rejection reasons remain intact. Identical pending date/time proposals are rejected as duplicates. Approval never changes original punch events. Admins continue to commit authorized manual entries immediately, including their own attendance.

No roles, passwords, employee placements, grants or reporting assignments were changed. The testing credential export was not regenerated.

## Migration and verification

The live database was backed up to `database/backups/ahamson_hris-before-employee-live-punches-20261104.sql` before applying the additive migration. On another installation, back up first and run `php artisan migrate --force`.

Application tests cover multiple punches, event retention, retry IDs, forged identity/date/time, incomplete and reverse-order records, location ranges/accuracy/distance, missing office/shift rollback, timezone rollover, past-only corrections, reasons, pending/approved/rejected behavior, notifications, stale/duplicate decisions, record ownership and unchanged Admin behavior.

The repeatable MySQL race check is `php tools/verify-employee-punch-concurrency.php`. It creates a uniquely named isolated `hrla_punch_verify_20261104_*` schema and retains it for inspection; it requires permission to create a test database. Two simultaneous identical submissions produced one event/version, and two distinct simultaneous submissions preserved both events and correct earliest/latest daily values. Production attendance was not used or changed by these tests.

All three Employee pages were rendered using isolated SQLite fixtures and checked with Playwright at **1440, 1024 and 390 pixels**: no page overflow or JavaScript errors. Browser tests also exercised denied location, successful capture, a lost response, retry with the same UUID and save confirmation. Screenshots were visually inspected. Physical-device GPS and external HTTPS hosting were not tested.

Final verification: **210 tests, 2,215 assertions passed**; Blade templates compiled successfully. The affected account/attendance tests and the real MySQL race verifier also passed after the concurrency fix. Desktop/mobile screenshots are in `E:/HRMS/.document-reference/employee-punch-{today,edit,history}-{1440,1024,390}.png`.
