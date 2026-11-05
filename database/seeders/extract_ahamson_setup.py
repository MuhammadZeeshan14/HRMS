"""Read the supplied workbook into a reviewed HRIS-only seeder payload."""
import hashlib
import json
import sys
from pathlib import Path
import openpyxl

source = Path(sys.argv[1])
output = Path(sys.argv[2])
workbook = openpyxl.load_workbook(source, data_only=True)

def clean(value):
    if isinstance(value, str):
        return value.strip() or None
    if isinstance(value, float) and value.is_integer():
        return str(int(value))
    return str(value) if value is not None else None

def rows(sheet, fields, header=True):
    result = []
    for number, cells in enumerate(workbook[sheet].iter_rows(values_only=True), 1):
        if header and number == 1:
            continue
        if not any(value is not None for value in cells):
            continue
        data = {field: clean(cells[i]) for i, field in enumerate(fields) if field}
        if not data.get('name'):
            continue
        result.append({'sheet': sheet, 'row': number, 'data': data})
    return result

tables = {
    'banks': rows('Bank', ['code', 'name', 'description'], False),
    'branches': rows('Branch', ['code', 'name', 'bank_code']),
    'companies': rows('Company', ['code', 'name', 'address', 'website', 'phone', 'fax', 'ntn', 'bank_code', 'branch_code', 'account_no']),
    'locations': rows('Location', ['code', 'name', 'company_code', 'phone', 'fax', 'address']),
    'departments': rows('Department Hierarchy', ['code', 'name', 'level', 'parent_department_code', 'company_code']),
    'grades': rows('Grade', ['code', 'name', 'description', 'company_code', 'rank', 'reference_titles']),
    'qualifications': rows('Qualification', ['code', 'name', 'description', 'level']),
    'specializations': rows('Specialization', ['code', 'name', 'description', 'qualification_code']),
    'certification_types': rows('Certification Type', ['code', 'name']),
    'certifications': rows('Certification', ['name', 'description', 'validity_months', 'certification_type_code']),
    'religions': rows('Religion', ['code', 'name', 'description']),
    'languages': rows('Language', ['code', 'name', 'description']),
    'training_types': rows('Training Type', ['name', 'description']),
}
designation_rows = rows('Designation', ['code', 'name', 'description'])
primary = [row for row in designation_rows if row['data']['code']]
supplemental = []
for row in designation_rows:
    data = row['data']
    if data['code'] or data['name'] == 'Designation':
        continue
    if data['name'] not in {item['data']['name'] for item in primary + supplemental}:
        supplemental.append({'sheet': 'Designation', 'row': row['row'], 'data': {'code': 'XLS-DES-'+str(row['row']), 'name': data['name']}})
tables['designations'] = primary + supplemental
payload = {'source': source.name, 'sha256': hashlib.sha256(source.read_bytes()).hexdigest(), 'tables': tables,
           'excluded_sheets': [sheet for sheet in workbook.sheetnames if sheet not in {row['sheet'] for items in tables.values() for row in items}],
           'generated_designation_codes': [{'row': row['row'], 'code': row['data']['code'], 'name': row['data']['name']} for row in supplemental]}
output.parent.mkdir(parents=True, exist_ok=True)
output.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding='utf-8')
print(json.dumps({'records': {table: len(items) for table, items in tables.items()}, 'excluded_sheets': payload['excluded_sheets'], 'generated_codes': payload['generated_designation_codes']}, indent=2))
