# Employee access and account implementation

Implemented in `E:\HRMS\hrms` on 3 November 2026. The local MySQL database was backed up before the two additive migrations were applied. Employee, attendance version, correction, leave and approval history was preserved.

## Permission matrix

Every permission requires an active linked user and an employee whose employment status is `active`. Legacy `users.role` never grants authority. The middleware refreshes the account on each request, checks session revocation. Temporary-password reminders are advisory; users can navigate before changing their password.

| Action | Employee | Delegated Admin (Level 2/3) | Level 1 Admin | HR Admin |
|---|---|---|---|---|
| My Account, permitted profile, leave, attendance and request history | Own | Own | Own | Own |
| Employee directory and employee administration | No | Same company | Same company | Same company |
| Attendance register, history, exports and upload | Own permitted records; upload denied | Same company | Same company | Same company |
| Manual attendance creation/edit | Live current-day punches commit immediately; previous-day corrections are pending requests for self | Immediate committed version within scope, including self | Immediate committed version within scope, including self | Immediate committed version within company, including self |
| Attendance decision | No | Assigned eligible reporting Admin, never self | Assigned eligible reporting Admin, never self | Same company, never self |
| Bulk/single shift assignment | No | Same company | Same company | Same company |
| Shared HRIS setup and company HRLA configuration | No | Yes, subject to company ownership | Yes, subject to company ownership | Yes, subject to company ownership |
| Department descriptive edits | No | Same company | Same company | Same company |
| Department creation / hierarchy, HR flag or activation changes affecting eligibility | No | No | Same company; reporting protection applies | Only if also Level 1 |
| Grant/revoke delegated Admin | No | No | Other Level 2/3 employees in same company; audited | Only if also Level 1 |
| Assisted password reset | No | Same company | Same company | Same company |
| Create another company or edit another company's record | No | No | No; operator provisioning required | No; operator provisioning required |

HR membership is an explicit `departments.is_hr` flag, independent of the department name. The local database's reviewed department IDs **18 and 19** were designated as HR. Each relevant department must be explicitly designated; descendants do not inherit HR membership automatically. Level 1 takes precedence in the workbook's single access-source column; every effective Admin receives company-wide employee scope, regardless of access source. Department level is derived from `parent_department_id` through `DepartmentLevelService`, not from the persisted level label.

Delegation persists on the employee but is effective only while the employee and account are active and the current department is Level 2 or 3. At the user's request on 4 November 2026, all effective Admins have the same company-wide data access, including other department branches and configuration. Reporting eligibility still follows strict department ancestry; Admin delegation does not make someone an eligible reporting person for another branch. Administrative employee workflows cannot change the actor's own organizational placement, employment status or user link.

HRLA shifts, holidays, leave types, leave policies and HR documents now have company ownership. Existing definitions were assigned to company 5 because it was the only company represented by employees. On a database with employees in several companies, ambiguous legacy definitions remain shared and read-only until reviewed by an operator. Existing global HRIS vocabulary tables (for example languages, designations and employment-status definitions) remain shared reference data; all effective Admins can manage that vocabulary. Company-owned master data, private documents, news, employees and HRLA records are scoped independently. Existing global unique code constraints remain in place, so configuration codes must remain unique across companies.

## Reporting and changes to access

Primary and enabled secondary reporting require an active effective Admin in the same company and a **strict ancestor department**. Root employees can have no reporting person. Other new/changed reporting records require a valid primary reporting Admin; missing eligible candidates produce an actionable error. The UI loads candidates after company/department selection. Server validation and employee model hooks enforce the same rules for application writes. Secondary reporting requires dual reporting and a distinct eligible person. Self reporting and cycles are rejected.

Existing invalid assignments are preserved and listed in `employee-reporting-audit.md`. A transfer, deactivation, hierarchy edit or privilege revocation cannot introduce an invalid edge that was valid before the change. Reassign affected employees first; ordinary transfers are blocked until this is done. The audited privilege endpoint also accepts a `replacements` map, keyed by employee ID, to replace primary/secondary reporting and dual-reporting values in the same database transaction as revocation. The UI offers grant/revoke with a clear blocking error if replacements are needed. Employee and department writes are transactional, with employee locks shared with approval workflows.

The existing setup seeder applies the same protection before committing hierarchy imports. Authorized employment-status changes synchronize account activation and revoke prior sessions. Effective levels are derived from each employee's validated ancestor path; a corrupt imported branch cannot confer reporting ancestry or disrupt access calculations for unrelated branches. Missing, cyclic or cross-company parent paths produce actionable reporting-validation errors.

## Attendance decision rule

The existing rule was **one approval**, not two sequential approvals. That rule is preserved: either an eligible assigned primary reporting Admin, an eligible secondary reporting Admin when the existing `secondary_approval` setting is enabled, or an eligible HR employee in the same company may decide. Primary/eligible secondary and HR recipients are all notified using Laravel's existing `Notifiable` mechanism with database notifications. Notifications are visible in My Account; the existing corrections inbox and approval UI remain in use. Reporting validity and effective access are rechecked when a decision is made.

An Admin action is selected by server permissions, regardless of `direct`. `direct=1` from an Employee is denied. An Admin's own attendance also commits immediately. Employee submissions are bound to the authenticated employee and create only a pending correction request. They cannot change official attendance or totals. Approval appends a version; rejection leaves committed attendance unchanged and records its reason. The existing employee lock, version comparison, request-status check and unique correction reference prevent stale overwrites and duplicate commits. Self-approval remains forbidden if a requester later becomes an Admin. Missing approvers produce an actionable validation error.

Attendance calculations remain derived from committed versions through the existing Register/calculator services; the next read recalculates the affected results. No pending data is included. Every committed version stores its actor, reason, source, committed timestamp and complete punch values; earlier versions remain available in history.

## Provisioning and migration

1. Back up the database. Do not restore this task's older backup over subsequent legitimate changes.
2. Run `php artisan migrate --force`. The additive migrations are `2026_11_03_001000_add_employee_access_and_accounts` and `2026_11_03_002000_scope_hrla_configuration`.
3. Review HR department IDs and existing account ownership. Do not identify HR by a department name or an old role string.
4. If an existing unlinked user belongs to an employee, run `php artisan hrms:link-account EMPLOYEE_ID USER_ID` after verifying ownership. It refuses either side already being linked, resets legacy role authority, revokes sessions and audits the link. Matching email addresses alone never prove ownership; provisioning stops for a conflict instead of duplicating or automatically claiming an account.
5. Ensure an active employee exists in a legitimate root department. Provisioning stops and rolls back if it would leave no active linked Level 1 employee. In this database, existing root employees were provisioned before the old unlinked account was quarantined. The old account (user 1) remains stored, inactive, with no employee-derived permissions.
6. Run `php artisan hrms:provision-accounts --hr-department=18 --hr-department=19 --audit=docs/employee-reporting-audit.md` for this database. Substitute reviewed IDs on other databases. This preserves linked passwords and suitable login IDs when no credential-export option is supplied. New employees created through the application automatically receive linked accounts and unique login IDs. Employee numbers are reused when suitable; collisions/unsuitable values receive a generated ID.
7. A testing export deliberately assigns new, individual temporary passwords to **every exported employee** and revokes their existing sessions. It must not be rerun casually after handing off credentials. Use the command below only for an explicitly intended new handoff.

```powershell
php artisan hrms:provision-accounts --hr-department=18 --hr-department=19 --test-fixtures --credentials=E:\HRMS\hrms\outputs\employee-access\employee-testing-credentials.xlsx --node=C:\Users\Admin\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe --audit=E:\HRMS\hrms\docs\employee-reporting-audit.md
```

The workbook exporter uses the bundled `@oai/artifact-tool` runtime via a local ignored `node_modules` junction. The Node executable and that runtime must be available for subsequent exports. Passwords move from the provisioning process into the workbook builder through standard input in memory. They are never placed in a JSON/CSV intermediate, database plaintext field, source file or console output. The workbook and its directory are ignored by version control. A masked layout preview contains no passwords. The verification command reads the workbook without displaying credentials:

```powershell
php artisan hrms:verify-testing-credentials E:\HRMS\hrms\outputs\employee-access\employee-testing-credentials.xlsx --base-url=http://127.0.0.1:8000
```

The `--test-fixtures` option created four explicitly marked employees: `ACCESS-TEST-L1`, `ACCESS-TEST-DELEGATED`, `ACCESS-TEST-EMPLOYEE`, and `ACCESS-TEST-INACTIVE`. It is idempotent and does not relabel existing employees as test accounts. No existing reporting person was replaced. Existing sample/demo seeders now use the account lifecycle; the optional SampleEmployeesSeeder requires an explicitly reviewed root `SAMPLE_DEPARTMENT_ID` before creating its sample records. The default legacy AdminSeeder reserves an inactive unlinked account and never prints a password or creates unrestricted access.

## Account management

My Account appears in the shared account menu and both module sidebars. Organizational identifiers, login ID, reporting relationships, employment status and permissions are read-only. Only phone, address and a validated profile image are accepted by the personal profile endpoint; forged identity/privilege fields are ignored. Password changes, recovery-email changes, session revocation, delegated privilege changes and assisted resets require current-password verification as appropriate. All web writes use CSRF protection; login, password and recovery endpoints are rate limited.

At the user's request on 4 November 2026, new and reset passwords show an optional change reminder without blocking other application pages. Password changes and assisted resets revoke other sessions through database-session deletion, a monotonically increasing account session version and remember-token rotation. An assisted temporary password is shown only in its generation response with `Cache-Control: no-store, private`; it is not flashed to a session or retained in an audit record. Reset audits record only the target, actor, action and timestamp.

Recovery email changes leave the existing verified email active until a signed, expiring verification link bound to that account and proposed address is used. Forgot Password sends only to a verified email for an active linked account, using Laravel's password broker and single-use reset token. Responses do not reveal whether an address exists. Without a verified address, the page directs the employee to an Admin with scope to issue a temporary password.

**Environment limitation:** the existing local environment uses `MAIL_MAILER=log`. Recovery flows and notifications were tested with Laravel's notification fake; actual external email delivery requires configuring a working mail transport and a reachable `APP_URL`. No employee email was silently marked verified. Database attendance notifications work without email delivery.

## Verification and remaining issues

See `employee-access-verification.md` for the complete passing suite (197 tests, 2,024 assertions), live credential checks and deployment requirements, rechecked on 4 November 2026. The data audit lists 11 preserved reporting problems requiring a human choice of reporting Admin. The separate credential workbook contains all 13 original employees and four test employees, including the explicitly inactive test account. The unlinked legacy user is excluded because it is not an employee account.


## Requested testing credential and company email refresh

On 4 November 2026, all 17 employees received unique company email IDs under `ahamson.com`, taken from the recorded company setup website. The user requested simpler testing passwords, so the current handoff assigns an individual 9-character word-plus-four-digits temporary password to every employee. Temporary-password changes are recommended and no longer block navigation; inactive accounts remain blocked. The previous handoff passwords are replaced.

The credential workbook now includes a **Company email ID** column. Addresses are assigned to employee and unverified account email records; assignment does not mark them verified or create external mailboxes. If an account already has a verified recovery address, that address is retained and the new corporate address is held as pending until verification. Unique-address collision handling and idempotent reassignment are tested.

The opt-in command for an intentionally requested replacement testing handoff is:

```powershell
php artisan hrms:provision-accounts --email-domain=ahamson.com --simple-testing-passwords --credentials=E:\HRMS\hrms\outputs\employee-access\employee-testing-credentials.xlsx --node=C:\Users\Admin\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe
```

Simple passwords require the explicit credentials-export option. The ordinary administrator-assisted password reset still generates strong temporary passwords. Corporate email assignment and password resets are audited without recording plaintext passwords. A pre-refresh database backup is stored at `database/backups/ahamson_hris-before-testing-email-password-refresh-20261104.sql` and excluded from version control.

Login accepts an employee login ID or assigned company email (case insensitive, surrounding spaces ignored). A separate account recovery email also works; an unverified pending replacement alone does not. Ambiguous duplicate imported emails are denied. All login methods retain active-account, company/scope and session-revocation checks. Existing testing workbook passwords remain unchanged; its earlier “password change required” status text is now advisory.

Company-wide Admin access update (4 November 2026): centralized employee/department queries, write/place checks, configuration routes and both sidebars now admit all effective Admins across their company. Privilege grants/revocations remain Level 1-only; reporting validation, approval recipients/decision eligibility, company isolation, inactive-account denial and self-promotion protection remain unchanged. All Admins can edit descriptive department data; creating departments or changing parent, HR designation or activation still requires Level 1 because those fields affect automatic Admin eligibility. Existing credentials, privilege assignments and reporting records were not modified.

Employee HRLA live punches and previous-day correction behavior are documented in `employee-hrla-live-attendance.md`. The Employee navigation now separates Attendance, Edit / Mark Attendance and Attendance History; Admin entry and approval eligibility are unchanged.
