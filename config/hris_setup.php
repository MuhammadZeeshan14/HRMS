<?php

$status = ['status' => ['label' => 'Status', 'type' => 'select', 'options' => ['active' => 'Active', 'inactive' => 'Inactive'], 'default' => 'active']];
$name = ['name' => ['label' => 'Name', 'unique' => true]];
$code = ['code' => ['label' => 'Code', 'unique' => true]];
$description = ['description' => ['label' => 'Description', 'type' => 'textarea', 'optional' => true]];
$relation = fn ($label, $table, $optional = false) => ['label' => $label, 'type' => 'relation', 'table' => $table, 'optional' => $optional];
$lookup = fn ($label) => ['label' => $label, 'fields' => $name + $status];

$masters = [
    'fiscal_years' => ['label' => 'Fiscal Years', 'fields' => $name + ['starts_on' => ['label' => 'Start Date', 'type' => 'date'], 'ends_on' => ['label' => 'End Date', 'type' => 'date']] + $status],
    'designations' => ['label' => 'Designations', 'fields' => $code + $name + $description + $status],
    'grades' => ['label' => 'Grades', 'fields' => $code + $name + $description + ['rank' => ['label' => 'Grade Rank', 'type' => 'integer']] + $status],
    'certification_types' => $lookup('Certification Types'),
    'certifications' => ['label' => 'Certifications', 'fields' => ['certification_type_id' => $relation('Certification Type', 'certification_types')] + $name + $description + ['validity_months' => ['label' => 'Validity Period (Months)', 'type' => 'integer', 'optional' => true]] + $status],
    'religions' => ['label' => 'Religions', 'fields' => $code + $name + $description + $status],
    'languages' => ['label' => 'Languages', 'fields' => $code + $name + $description + $status],
    'banks' => ['label' => 'Banks', 'fields' => $code + $name + $description],
    'branches' => ['label' => 'Bank Branches', 'fields' => ['code' => ['label' => 'Branch Code']] + ['name' => ['label' => 'Branch Name']] + ['bank_id' => $relation('Bank', 'banks')] + $status],
    'document_types' => ['label' => 'Document Types', 'fields' => $name + $description + ['file_extensions' => ['label' => 'File Extensions', 'optional' => true]] + $status],
    'hr_documents' => ['label' => 'HR Documents', 'fields' => ['code' => ['label' => 'Document Code', 'optional' => true], 'name' => ['label' => 'Document Name'], 'document_type_id' => $relation('Document Type', 'document_types'), 'document_file' => ['label' => 'Document', 'type' => 'file'], 'publish_on_portal' => ['label' => 'Publish on Portal', 'type' => 'boolean']]],
    'training_types' => ['label' => 'Training Types', 'fields' => $name + ['description' => ['label' => 'Description', 'type' => 'textarea']] + $status],
    'news' => ['label' => 'News', 'fields' => ['published_on' => ['label' => 'News Date', 'type' => 'date'], 'title' => ['label' => 'News Subject'], 'body' => ['label' => 'News Details', 'type' => 'textarea'], 'company_ids' => ['label' => 'Visible for Company', 'type' => 'multiple', 'table' => 'companies']]],
    'leaving_reasons' => ['label' => 'Leaving Reasons', 'fields' => $name + ['type' => ['label' => 'Type'], 'description' => ['label' => 'Description', 'type' => 'textarea']] + $status],
    'companies' => ['label' => 'Companies', 'fields' => $code + ['name' => ['label' => 'Name']] + $status],
    'locations' => ['label' => 'Locations', 'fields' => ['company_id' => $relation('Company', 'companies', true)] + $code + ['name' => ['label' => 'Name']] + ['city' => ['label' => 'City', 'optional' => true]] + $status],
    'departments' => $lookup('Departments'), 'job_categories' => $lookup('Job Categories'),
];

foreach (['companies' => ['address' => 'Address', 'website' => 'Website', 'phone' => 'Phone', 'fax' => 'Fax', 'ntn' => 'NTN', 'account_no' => 'Account Number'], 'locations' => ['address' => 'Address', 'phone' => 'Phone', 'fax' => 'Fax']] as $table => $fields) {
    foreach ($fields as $field => $label) {
        $masters[$table]['fields'][$field] = ['label' => $label, 'optional' => true];
    }
}
$masters['companies']['fields']['bank_id'] = $relation('Bank', 'banks', true);
$masters['locations']['fields']['latitude'] = ['label' => 'Office latitude (-90 to 90)', 'optional' => true, 'max' => 30];
$masters['locations']['fields']['longitude'] = ['label' => 'Office longitude (-180 to 180)', 'optional' => true, 'max' => 30];
$masters['companies']['fields']['branch_id'] = $relation('Bank Branch', 'branches', true);
$masters['grades']['fields']['company_id'] = $relation('Company', 'companies', true);
$masters['grades']['fields']['reference_titles'] = ['label' => 'Reference Designation Titles', 'type' => 'textarea', 'optional' => true];
$masters['certification_types']['fields'] = ['code' => ['label' => 'Code', 'optional' => true, 'unique' => true]] + $masters['certification_types']['fields'];
foreach (['departments' => 'parent_department_id'] as $table => $parent) {
    $masters[$table]['fields'] = $code + ['name' => ['label' => 'Name'], 'company_id' => $relation('Company', 'companies', true), $parent => $relation('Parent', $table, true)] + $status;
}
$masters['departments']['fields']['level'] = ['label' => 'Level', 'type' => 'integer', 'optional' => true];
$masters['qualifications'] = ['label' => 'Qualifications', 'fields' => $code + ['name' => ['label' => 'Name']] + $description + ['level' => ['label' => 'Level', 'type' => 'integer', 'optional' => true]] + $status];
$masters['specializations'] = ['label' => 'Specializations', 'fields' => $code + ['name' => ['label' => 'Name']] + $description + ['qualification_id' => $relation('Qualification', 'qualifications')] + $status];


$masters['fiscal_years']['fields']['status']['options'] = ['active' => 'Open', 'inactive' => 'Closed'];
$masters['branches']['fields'] += ['address' => ['label' => 'Address', 'type' => 'textarea', 'optional' => true], 'phone' => ['label' => 'Phone No', 'optional' => true], 'contact_person' => ['label' => 'Contact Person', 'optional' => true]];
$masters['allowance_types'] = $lookup('Allowance Types');
$masters['employment_statuses'] = $lookup('Employment Statuses');
$masters['possession_categories'] = $lookup('Possession Categories');
$masters['allowances'] = ['label' => 'Allowances', 'fields' => $code + $name + $description + ['allowance_type_id' => $relation('Allowance Type', 'allowance_types'), 'employee_contribution_allowance_id' => $relation('Employee Contribution Allowance', 'allowances', true)] + $status];
$masters['compensation_plans'] = ['label' => 'Compensation Plans', 'fields' => $name + ['employment_status_id' => $relation('Employment Status', 'employment_statuses'), 'effective_from' => ['label' => 'Effective From', 'type' => 'date']] + $description + $status];
$masters['compensation_plan_details'] = ['label' => 'Compensation Plan Details', 'fields' => ['compensation_plan_id' => $relation('Compensation Plan', 'compensation_plans'), 'allowance_id' => $relation('Allowance', 'allowances'), 'distributed_in_days' => ['label' => 'Distributed in Days', 'type' => 'boolean'], 'allowance_limit' => ['label' => 'Allowance Limit', 'type' => 'select', 'options' => ['fixed' => 'Fixed', 'percentage' => 'Percentage']], 'percentage_of' => ['label' => 'Percentage Of', 'type' => 'select', 'options' => ['gross_salary' => 'Gross Salary', 'basic_salary' => 'Basic Salary'], 'optional' => true], 'percentage' => ['label' => 'Percentage (%)', 'type' => 'decimal', 'optional' => true], 'fixed_amount' => ['label' => 'Fixed Amount', 'type' => 'decimal', 'optional' => true], 'maximum_limit' => ['label' => 'Maximum Limit', 'type' => 'decimal', 'optional' => true], 'part_of_gross' => ['label' => 'Part of Gross', 'type' => 'boolean'], 'taxable' => ['label' => 'Taxable', 'type' => 'boolean']]];
$masters['possession_types'] = ['label' => 'Possession Types', 'fields' => $name + ['department_id' => $relation('Department', 'departments'), 'possession_category_id' => $relation('Category', 'possession_categories')] + $description + $status];
$masters['possession_items'] = ['label' => 'Possession Items', 'fields' => $name + ['possession_category_id' => $relation('Possession Type Category', 'possession_categories'), 'possession_type_id' => $relation('Possession Type', 'possession_types')] + $description + $status];
$masters['checklist_items'] = ['label' => 'Checklist Items', 'fields' => $name + ['description' => ['label' => 'Description', 'type' => 'textarea'], 'sequence' => ['label' => 'Sequence', 'type' => 'integer']] + $status + ['employee_exit' => ['label' => 'Employee Exit', 'type' => 'boolean'], 'employee_hiring' => ['label' => 'Employee Hiring', 'type' => 'boolean']]];

foreach ($masters as &$master) {
    foreach ($master['fields'] as $field => &$meta) {
        $meta['max'] = match ($field) {
            'code', 'phone', 'fax', 'ntn' => 64,
            'address' => 500,
            'account_no' => 100,
            default => 255,
        };
        $meta['size'] = match (true) {
            in_array($meta['type'] ?? 'text', ['textarea', 'file', 'multiple', 'boolean', 'relation', 'select'], true) => null,
            in_array($field, ['currency'], true) => 4,
            in_array($field, ['rank', 'level', 'sequence', 'validity_months', 'percentage'], true) => 8,
            in_array($meta['type'] ?? 'text', ['date'], true) => 16,
            in_array($field, ['code', 'phone', 'fax', 'ntn', 'city', 'type', 'contact_person', 'file_extensions'], true) => 16,
            in_array($field, ['address'], true) => 64,
            in_array($field, ['account_no'], true) => 32,
            default => 32,
        };
    }
    unset($meta);
}
unset($master);
foreach (['grades', 'designations'] as $key) {
    unset($masters[$key]['fields']['name']['unique']);
}

// Sheet order from A Hamson Setup Data.xlsx. Forms not in the workbook follow
// the workbook forms in their existing order. Unimplemented sheets are skipped.
$workbookOrder = [
    'companies', 'locations', 'departments', 'banks',
    'branches', 'grades', 'designations', 'qualifications', 'specializations',
    'certification_types', 'certifications', 'religions', 'languages',
    'training_types', 'leave_types', 'allowances', 'expense_heads',
    'expense_types', 'units', 'compensation_plans', 'benefit_plans',
    'leave_plans', 'loan_plans', 'expense_claim_plans',
];
$orderedMasters = [];
foreach ($workbookOrder as $key) {
    if (isset($masters[$key])) {
        $orderedMasters[$key] = $masters[$key];
    }
}

return $orderedMasters + $masters;
