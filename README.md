# Ahamson HRIS backend

The application uses XAMPP MySQL (MariaDB) at `127.0.0.1:3306`, database `ahamson_hris`, through Laravel's `mysql` connection. Both `.env` and `.env.example` use this connection. SQLite is used only for isolated tests and preserved rebuild backups.

The database was cleared on 12 October 2026. Only the existing administrator `admin@ahamson.test` remains, with its password preserved. All master data, companies, hierarchy levels, business units, departments, designations, grades, employees, related records, and other users were removed. Tables, forms, validation, and the Company Hierarchy flowchart remain available for building a new organization. The pre-reset SQL backup is `E:/HRMS/.document-reference/before-admin-only-reset-20261012-114514.sql`.

The setup schema is defined in one baseline migration, `database/migrations/2026_10_09_001000_create_hris_setup_schema.php`. The PDF setup forms define the operational fields. The Excel workbook supplies HRIS master records and the company, hierarchy, business-unit, qualification, and specialization relationships.

Reports, HRLA, attendance, leave plans, compensation plans, benefits, loans, and expense plans are excluded.

Business Units were removed on 13 October 2026, including their forms, tables, relationship columns, and Unit Head flag. The company flowchart now uses hierarchy levels and designation reporting relationships directly. Historical migrations and backups retain the prior schema for upgrade and recovery purposes.

## Setup tables

| Group | Tables and relationships |
| --- | --- |
| Organization | `companies` -> bank/branch; `locations` -> company; `hierarchies` -> company/parent |
| Jobs | `designations` -> company/hierarchy/grade/job category/reporting designation; `grades` -> company; `job_categories` |
| Fiscal year | `fiscal_years`: name, start date, end date, status |
| Certifications | `certifications` -> `certification_types`; description and validity in months |
| Personal lookups | `religions`, `languages`: code, name, description, status |
| Banking | `branches` -> `banks`; branch code and name are unique within the bank |
| Documents | `hr_documents` -> `document_types`; private file path and Publish on Portal flag; document types specify allowed extensions |
| Training and exits | `training_types`, `leaving_reasons`: descriptions, reason type, status |
| Education | `specializations` -> `qualifications`; codes, descriptions, qualification levels |
| News | `news` -> companies through `news_companies`; date, subject, details |

Codes are strings, preserving leading zeros. Specialization names may repeat; their codes identify them. Foreign keys protect referenced setup records from deletion. Status values are constrained in the database. Request validation enforces date and salary ranges, decimal precision, company membership, bank/branch membership, hierarchy membership, required fields, unique codes, and cycle prevention.

The default seeder creates only one administrator on an empty database and preserves an existing administrator on subsequent runs. It does not seed any master data, hierarchy, or employees. Workbook, demo, sample-employee, and restore seeders remain explicit development/import utilities; do not invoke them when an empty installation is required.

## Existing employee data

Employee tables are defined separately in `2026_10_09_002000_create_employee_records.php` and are now empty. `departments` and `functions` remain dependencies of the employee screens, but are not exposed as setup forms. Historical SQL/SQLite backups and uploaded files have not been deleted by the database reset.

`is_current` has been removed from fiscal years because the reference form uses Status. Pre-rebuild backups retain the old column and its original values. Fresh installs contain only an administrator.

## Fresh installation

Start MySQL in XAMPP and create `ahamson_hris` with `utf8mb4_unicode_ci` before running:

```powershell
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Before first seeding, optionally set `ADMIN_NAME`, `ADMIN_EMAIL`, and `ADMIN_PASSWORD` in `.env`. The default email is `admin@ahamson.test`. If no password is supplied, the seeder generates a random password and prints it once in the console. Re-running the default seeder preserves the existing administrator's credentials. It does not delete records; the requested live cleanup was a separate, backed-up operation.

## Reconciliation and testing

```powershell
php artisan db:seed --force
php artisan test
```

The workbook seeder is repeatable and does not duplicate records. Tests force an isolated in-memory SQLite database before Laravel boots and refuse to use the application database.

Legacy patch migrations are retained under `database/legacy-migrations` for reference and are not active. The replacement database is built separately before copying records with `database/copy_rebuilt_sqlite.py`. That helper verifies all shared column values, record IDs, sequences, database integrity, and foreign keys before committing; it excludes legacy migration history and retired fiscal-year metadata. Do not run the new baseline migration directly over an old schema, or use `migrate:fresh` on existing data.

The MySQL transfer was verified across all 45 application tables. All 24 application pages load against MySQL, and foreign-key rejection was checked against the actual server. The one-time transfer helper is `database/import_sqlite_to_mysql.php`; it requires an empty migrated `ahamson_hris` database and verifies copied values and relationships before committing.

The pre-rebuild snapshot is `database/backups/hris-before-schema-rebuild-20261009.sqlite`.

