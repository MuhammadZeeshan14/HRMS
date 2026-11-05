# A Hamson HRIS setup import

Imported 220 source records: 215 inserted, 5 matched/enriched.

All supplied values and relationships were verified against the live database. Existing record IDs and all unrelated data, including employees, were preserved. No foreign key violations.

| Master | Source records | Inserted | Matched |
| --- | ---: | ---: | ---: |
| banks | 1 | 0 | 1 |
| branches | 1 | 1 | 0 |
| companies | 1 | 1 | 0 |
| locations | 1 | 1 | 0 |
| departments | 2 | 2 | 0 |
| business_units | 24 | 24 | 0 |
| grades | 12 | 12 | 0 |
| qualifications | 36 | 36 | 0 |
| specializations | 53 | 53 | 0 |
| certification_types | 9 | 9 | 0 |
| certifications | 14 | 14 | 0 |
| religions | 8 | 7 | 1 |
| languages | 22 | 19 | 3 |
| training_types | 4 | 4 | 0 |
| designations | 32 | 32 | 0 |

## Missing source relationships

- Designation row 10: reporting designation code 40 is absent; source code retained, link unset.
- Designation row 11: reporting designation code 86 is absent; source code retained, link unset.
- Designation row 12: reporting designation code 100 is absent; source code retained, link unset.
- Designation row 13: reporting designation code 83 is absent; source code retained, link unset.
- Designation row 15: reporting designation code 91 is absent; source code retained, link unset.
- Designation row 17: reporting designation code 103 is absent; source code retained, link unset.
- Designation row 18: reporting designation code 111 is absent; source code retained, link unset.
- Designation row 20: reporting designation code 120 is absent; source code retained, link unset.
- Designation row 21: reporting designation code 115 is absent; source code retained, link unset.
- Designation row 22: reporting designation code 103 is absent; source code retained, link unset.
- Designation row 23: reporting designation code 93 is absent; source code retained, link unset.
- Designation row 24: reporting designation code 108 is absent; source code retained, link unset.
- Designation row 25: reporting designation code 87 is absent; source code retained, link unset.
- Designation row 26: reporting designation code 84 is absent; source code retained, link unset.
- Designation row 27: reporting designation code 103 is absent; source code retained, link unset.
- Designation row 28: reporting designation code 97 is absent; source code retained, link unset.
- Designation row 29: reporting designation code 98 is absent; source code retained, link unset.
- Designation row 30: reporting designation code 99 is absent; source code retained, link unset.

## Generated codes

- Office Boy: XLS-DES-69 (Designation row 69; workbook has no code).
- Office Assistant: XLS-DES-71 (Designation row 71; workbook has no code).
- Trainee HR: XLS-DES-74 (Designation row 74; workbook has no code).

## Excluded sheets

Leave Type, Compensations or Allowance, Expense Head, Expense Type, Unit, Compensation Plan, Benefit Plan, Leave Plan, Loan Plan, Expense Claim Plan.

Source SHA-256: d5347550df06ee054b9e35437ed150cb52016a98bddfa980c2189991dab7531e

Backup: database/backups/hris-before-workbook-seed-20261009.sqlite
