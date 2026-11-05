# Master data reconstruction

Source: `D:\HRIS setup remove.docx`, 18 screenshots. The captions below the designation and grade screenshots identify removals; the other screenshots define the retained setup fields.

## Removed fields

- Designations: domain, salary_from, salary_to, increment_by, increment_value, percentage_of.
- Grades: salary_from, salary_to, award_amount, increment_by, increment_value, percentage_of.

The upgrade migration drops these columns. Back up the database before applying it. Rolling back restores empty columns, not their former values.

## Reconstructed setup

Retained fiscal years, designations, grades, certification types and certifications, religions, languages, banks and branches, document types and HR documents, training types, and leaving reasons. Branch address, phone and contact person are restored. Fiscal year status displays Open and Closed using the existing active/inactive storage values.

Added allowances, compensation plans and detail rows, possession types and items, and checklist items. Allowance types, employment statuses and possession categories are editable supporting lookup tables; their full option lists are not supplied by the screenshots. No payroll calculations are performed by these setup forms.

Compensation details are managed in a dedicated setup screen. Their internal name is derived from the selected allowance. Percentage fields in compensation details remain because that screenshot does not mark them for removal.

## Relationships

| Child | Parent |
| --- | --- |
| Bank branch | Bank |
| Company | Bank and bank branch |
| Designation | Company, hierarchy, business unit, grade, job category, reporting designation |
| Certification | Certification type |
| HR document | Document type |
| Allowance | Allowance type, optional employee contribution allowance |
| Compensation plan | Employment status |
| Compensation detail | Compensation plan and allowance |
| Possession type | Hierarchy, business unit and category |
| Possession item | Possession category and possession type |

Foreign keys restrict deletion of referenced records. Validation checks company membership, bank/branch matching, business unit/hierarchy matching, possession category/type matching, and reporting/contribution cycles. Each compensation plan can contain an allowance once. Fixed details require an amount; percentage details require a salary basis and a percentage from 0 to 100.

Checklist items include description, sequence, status and separate Employee Exit and Employee Hiring flags. Existing organization, qualification, specialization and news setup remains available.

## Verification and deployment

23 tests passed with 500 assertions using an isolated SQLite database. Tests cover all setup forms, removed schema fields, reference constraints and compensation/possession validation.

Applied to the local MySQL database ahamson_hris on 11 October 2026. Migration 2026_10_11_001000_reconstruct_document_master_data completed successfully. The compensation plan/allowance unique index uses an explicit short name compatible with MySQL. Configuration and compiled view caches were cleared. Verified all 30 master configurations against the live schema, preserved row counts in all 46 existing tables (with one new migration record), and checked 65 foreign keys with no orphaned references. The complete pre-migration SQL backup is E:\HRMS\.document-reference\master-data-before-reconstruction-20261011-160804.sql.

