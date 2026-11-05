"""Read the original workbook; prepare source keys and relationships without editing it."""
import json, sys, runpy
from pathlib import Path
import openpyxl

source, target = sys.argv[1:3]
sys.argv = ['extract', source, target]
runpy.run_path(str(Path(__file__).parents[1] / 'database/seeders/extract_ahamson_setup.py'))
payload = json.loads(Path(target).read_text(encoding='utf-8'))
w = openpyxl.load_workbook(source, data_only=True)
tables = payload['tables']
issues = []

def clean(v):
    if v is None: return None
    if hasattr(v, 'isoformat'): return v.isoformat()[:10]
    return str(int(v) if isinstance(v, float) and v.is_integer() else v).strip() or None

def source_rows(sheet):
    return [(i, [clean(v) for v in row]) for i, row in enumerate(w[sheet].values, 1) if i > 1 and any(v is not None for v in row)]

def add(table, key, data, sheet, row, refs=None):
    tables.setdefault(table, []).append({'key':str(key), 'data':{k:v for k,v in data.items() if v is not None}, 'sheet':sheet, 'row':row, 'refs':refs or {}})

relations = {
 'bank_code':('banks','bank_id'), 'branch_code':('branches','branch_id'), 'company_code':('companies','company_id'),
 'parent_department_code':('departments','parent_department_id'), 'department_code':('departments','department_id'),
 'qualification_code':('qualifications','qualification_id'), 'certification_type_code':('certification_types','certification_type_id'),
 'grade_name':('grades','grade_id'), 'source_reporting_code':('designations','report_to_designation_id')}
grade_codes={r['data']['name']:r['data']['code'] for r in tables['grades']}
for table, rows in tables.items():
    for r in rows:
        data=r['data'];r['key']=str(data.get('code') or data['name']);r['refs']={}
        for field,(related,column) in relations.items():
            value=data.get(field)
            if value:
                r['refs'][column]=[related,grade_codes.get(value,value) if field=='grade_name' else value]
            if field!='source_reporting_code': data.pop(field,None)
        r['data']={k:v for k,v in data.items() if v is not None}

units={}
for i,c in source_rows('Business Units'):
    code,name,company,description,department,parent=c[:6]
    refs={'company_id':['companies',company], 'department_id':['departments',department]}
    if parent: refs['parent_business_unit_id']=['business_units',parent]
    add('business_units',code,{'code':code,'name':name,'description':description},'Business Units',i,refs)
    units[code]=(company,department)
designation_source={i:c for i,c in source_rows('Designation')}
for row in tables['designations']:
    c=designation_source[row['row']]
    unit=c[5]
    if unit:
        row['refs']['business_unit_id']=['business_units',unit]
        if 'company_id' not in row['refs']:
            row['refs']['company_id']=['companies',units[unit][0]]
            issues.append({'sheet':'Designation','row':row['row'],'note':'Company inferred from explicitly referenced Business Unit.'})
    for required in ['company_id','department_id','grade_id']:
        if required not in row['refs']: issues.append({'sheet':'Designation','row':row['row'],'note':required+' not supplied; left unset.'})
issues.append({'sheet':'Designation','note':'Job category is not supplied by the workbook; no category inferred.'})

types=set()
for i,c in source_rows('Compensations or Allowance'):
    code,name,kind,nature,description,contribution=c[:6]
    # Keep source spelling. Distinct names are required by the current schema.
    if kind not in types:
        add('allowance_types',kind,{'name':kind},'Compensations or Allowance',i);types.add(kind)
    refs={'allowance_type_id':['allowance_types',kind]}
    if contribution: refs['employee_contribution_allowance_id']=['allowances',contribution]
    add('allowances',code,{'code':code,'name':name,'description':description},'Compensations or Allowance',i,refs)
    if nature: issues.append({'sheet':'Compensations or Allowance','row':i,'note':'Source allowance nature: '+nature+'; this field is not present in the application.'})

plans=set();statuses=set();skipped=[]
for i,c in source_rows('Compensation Plan'):
    name,date,code,company,status,description,allowance,limit,amount,percentage,basis,base_allowance,maximum,taxable,gross,distributed=c[:16]
    if company not in {r['key'] for r in tables['companies']}:
        skipped.append({'sheet':'Compensation Plan','row':i,'reason':'Company code '+str(company)+' is absent from the Company sheet.'});continue
    if status not in statuses:
        add('employment_statuses',status,{'name':status},'Compensation Plan',i);statuses.add(status)
    if code not in plans:
        add('compensation_plans',code,{'name':name,'effective_from':date,'description':description},'Compensation Plan',i,{'employment_status_id':['employment_statuses',status]});plans.add(code)
    data={'allowance_limit':'percentage' if limit=='Percentage' else 'fixed','fixed_amount':amount,'percentage':percentage,'percentage_of':{'Gross':'gross_salary','Basic':'basic_salary'}.get(basis),'maximum_limit':maximum,'taxable':int(taxable.lower()=='yes'),'part_of_gross':int(gross.lower()=='yes'),'distributed_in_days':int(distributed.lower()=='yes')}
    add('compensation_plan_details',code+':'+allowance,data,'Compensation Plan',i,{'compensation_plan_id':['compensation_plans',code],'allowance_id':['allowances',allowance]})
    if len(c)>16 and c[16]: issues.append({'sheet':'Compensation Plan','row':i,'note':'Workbook note: '+c[16]})

supported={r['sheet'] for rows in tables.values() for r in rows}
payload['unsupported_sheets']=[{'sheet':s,'nonempty_data_rows':len(source_rows(s)),'reason':'No corresponding master-data module in the application.'} for s in w.sheetnames if s not in supported]
payload['issues']=issues;payload['skipped_rows']=skipped
Path(target).write_text(json.dumps(payload,indent=2,ensure_ascii=False),encoding='utf-8')
print(json.dumps({'prepared':{k:len(v) for k,v in tables.items()},'skipped':len(skipped),'unsupported':payload['unsupported_sheets']},indent=2))
