<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
public function up(): void {
Schema::table('designations', function (Blueprint $table) { $table->dropColumn(['domain', 'salary_from', 'salary_to', 'increment_by', 'increment_value', 'percentage_of']); });
Schema::table('grades', function (Blueprint $table) { $table->dropColumn(['salary_from', 'salary_to', 'award_amount', 'increment_by', 'increment_value', 'percentage_of']); });
Schema::table('branches', function (Blueprint $table) { $table->text('address')->nullable(); $table->string('phone', 64)->nullable(); $table->string('contact_person')->nullable(); });
foreach (array (
  'allowance_types' => 
  array (
    'name' => 
    array (
      'label' => 'Name',
      'unique' => true,
      'max' => 255,
    ),
    'status' => 
    array (
      'label' => 'Status',
      'type' => 'select',
      'options' => 
      array (
        'active' => 'Active',
        'inactive' => 'Inactive',
      ),
      'default' => 'active',
      'max' => 255,
    ),
  ),
  'employment_statuses' => 
  array (
    'name' => 
    array (
      'label' => 'Name',
      'unique' => true,
      'max' => 255,
    ),
    'status' => 
    array (
      'label' => 'Status',
      'type' => 'select',
      'options' => 
      array (
        'active' => 'Active',
        'inactive' => 'Inactive',
      ),
      'default' => 'active',
      'max' => 255,
    ),
  ),
  'possession_categories' => 
  array (
    'name' => 
    array (
      'label' => 'Name',
      'unique' => true,
      'max' => 255,
    ),
    'status' => 
    array (
      'label' => 'Status',
      'type' => 'select',
      'options' => 
      array (
        'active' => 'Active',
        'inactive' => 'Inactive',
      ),
      'default' => 'active',
      'max' => 255,
    ),
  ),
  'allowances' => 
  array (
    'code' => 
    array (
      'label' => 'Code',
      'unique' => true,
      'max' => 64,
    ),
    'name' => 
    array (
      'label' => 'Name',
      'unique' => true,
      'max' => 255,
    ),
    'description' => 
    array (
      'label' => 'Description',
      'type' => 'textarea',
      'optional' => true,
      'max' => 255,
    ),
    'allowance_type_id' => 
    array (
      'label' => 'Allowance Type',
      'type' => 'relation',
      'table' => 'allowance_types',
      'optional' => false,
      'max' => 255,
    ),
    'employee_contribution_allowance_id' => 
    array (
      'label' => 'Employee Contribution Allowance',
      'type' => 'relation',
      'table' => 'allowances',
      'optional' => true,
      'max' => 255,
    ),
    'status' => 
    array (
      'label' => 'Status',
      'type' => 'select',
      'options' => 
      array (
        'active' => 'Active',
        'inactive' => 'Inactive',
      ),
      'default' => 'active',
      'max' => 255,
    ),
  ),
  'compensation_plans' => 
  array (
    'name' => 
    array (
      'label' => 'Name',
      'unique' => true,
      'max' => 255,
    ),
    'employment_status_id' => 
    array (
      'label' => 'Employment Status',
      'type' => 'relation',
      'table' => 'employment_statuses',
      'optional' => false,
      'max' => 255,
    ),
    'effective_from' => 
    array (
      'label' => 'Effective From',
      'type' => 'date',
      'max' => 255,
    ),
    'description' => 
    array (
      'label' => 'Description',
      'type' => 'textarea',
      'optional' => true,
      'max' => 255,
    ),
    'status' => 
    array (
      'label' => 'Status',
      'type' => 'select',
      'options' => 
      array (
        'active' => 'Active',
        'inactive' => 'Inactive',
      ),
      'default' => 'active',
      'max' => 255,
    ),
  ),
  'compensation_plan_details' => 
  array (
    'name' => 
    array (
      'label' => 'Detail Name',
      'max' => 255,
    ),
    'compensation_plan_id' => 
    array (
      'label' => 'Compensation Plan',
      'type' => 'relation',
      'table' => 'compensation_plans',
      'optional' => false,
      'max' => 255,
    ),
    'allowance_id' => 
    array (
      'label' => 'Allowance',
      'type' => 'relation',
      'table' => 'allowances',
      'optional' => false,
      'max' => 255,
    ),
    'distributed_in_days' => 
    array (
      'label' => 'Distributed in Days',
      'type' => 'boolean',
      'max' => 255,
    ),
    'allowance_limit' => 
    array (
      'label' => 'Allowance Limit',
      'type' => 'select',
      'options' => 
      array (
        'fixed' => 'Fixed',
        'percentage' => 'Percentage',
      ),
      'max' => 255,
    ),
    'percentage_of' => 
    array (
      'label' => 'Percentage Of',
      'type' => 'select',
      'options' => 
      array (
        'gross_salary' => 'Gross Salary',
        'basic_salary' => 'Basic Salary',
      ),
      'optional' => true,
      'max' => 255,
    ),
    'percentage' => 
    array (
      'label' => 'Percentage (%)',
      'type' => 'decimal',
      'optional' => true,
      'max' => 255,
    ),
    'fixed_amount' => 
    array (
      'label' => 'Fixed Amount',
      'type' => 'decimal',
      'optional' => true,
      'max' => 255,
    ),
    'maximum_limit' => 
    array (
      'label' => 'Maximum Limit',
      'type' => 'decimal',
      'optional' => true,
      'max' => 255,
    ),
    'part_of_gross' => 
    array (
      'label' => 'Part of Gross',
      'type' => 'boolean',
      'max' => 255,
    ),
    'taxable' => 
    array (
      'label' => 'Taxable',
      'type' => 'boolean',
      'max' => 255,
    ),
  ),
  'possession_types' => 
  array (
    'name' => 
    array (
      'label' => 'Name',
      'unique' => true,
      'max' => 255,
    ),
    'hierarchy_id' => 
    array (
      'label' => 'Hierarchy',
      'type' => 'relation',
      'table' => 'hierarchies',
      'optional' => false,
      'max' => 255,
    ),
    'business_unit_id' => 
    array (
      'label' => 'Business Unit',
      'type' => 'relation',
      'table' => 'business_units',
      'optional' => false,
      'max' => 255,
    ),
    'possession_category_id' => 
    array (
      'label' => 'Category',
      'type' => 'relation',
      'table' => 'possession_categories',
      'optional' => false,
      'max' => 255,
    ),
    'description' => 
    array (
      'label' => 'Description',
      'type' => 'textarea',
      'optional' => true,
      'max' => 255,
    ),
    'status' => 
    array (
      'label' => 'Status',
      'type' => 'select',
      'options' => 
      array (
        'active' => 'Active',
        'inactive' => 'Inactive',
      ),
      'default' => 'active',
      'max' => 255,
    ),
  ),
  'possession_items' => 
  array (
    'name' => 
    array (
      'label' => 'Name',
      'unique' => true,
      'max' => 255,
    ),
    'possession_category_id' => 
    array (
      'label' => 'Possession Type Category',
      'type' => 'relation',
      'table' => 'possession_categories',
      'optional' => false,
      'max' => 255,
    ),
    'possession_type_id' => 
    array (
      'label' => 'Possession Type',
      'type' => 'relation',
      'table' => 'possession_types',
      'optional' => false,
      'max' => 255,
    ),
    'description' => 
    array (
      'label' => 'Description',
      'type' => 'textarea',
      'optional' => true,
      'max' => 255,
    ),
    'status' => 
    array (
      'label' => 'Status',
      'type' => 'select',
      'options' => 
      array (
        'active' => 'Active',
        'inactive' => 'Inactive',
      ),
      'default' => 'active',
      'max' => 255,
    ),
  ),
  'checklist_items' => 
  array (
    'name' => 
    array (
      'label' => 'Name',
      'unique' => true,
      'max' => 255,
    ),
    'description' => 
    array (
      'label' => 'Description',
      'type' => 'textarea',
      'max' => 255,
    ),
    'sequence' => 
    array (
      'label' => 'Sequence',
      'type' => 'integer',
      'max' => 255,
    ),
    'status' => 
    array (
      'label' => 'Status',
      'type' => 'select',
      'options' => 
      array (
        'active' => 'Active',
        'inactive' => 'Inactive',
      ),
      'default' => 'active',
      'max' => 255,
    ),
    'employee_exit' => 
    array (
      'label' => 'Employee Exit',
      'type' => 'boolean',
      'max' => 255,
    ),
    'employee_hiring' => 
    array (
      'label' => 'Employee Hiring',
      'type' => 'boolean',
      'max' => 255,
    ),
  ),
) as $name => $fields) {
Schema::create($name, function (Blueprint $table) use ($fields, $name) {
$table->id();
foreach ($fields as $field => $meta) {
$type = $meta['type'] ?? 'text';
$column = match ($type) {
'relation' => $table->foreignId($field),
'boolean' => $table->boolean($field)->default(false),
'date' => $table->date($field),
'integer' => $table->unsignedInteger($field),
'decimal' => $table->decimal($field, 14, 2),
'select' => $table->enum($field, array_keys($meta['options'])),
 'textarea' => $table->text($field),
default => $table->string($field),
};
if ($meta['optional'] ?? false) $column->nullable();
if ($meta['unique'] ?? false) $column->unique();
if ($field === 'status') $column->default('active');
if ($type === 'relation') $column->constrained($meta['table'])->restrictOnDelete();
}
if ($name === 'compensation_plan_details') $table->unique(['compensation_plan_id', 'allowance_id'], 'comp_plan_allowance_unique');
$table->timestamps();
});
}
}
public function down(): void {
foreach (['checklist_items', 'possession_items', 'possession_types', 'compensation_plan_details', 'compensation_plans', 'allowances', 'possession_categories', 'employment_statuses', 'allowance_types'] as $name) Schema::dropIfExists($name);
Schema::table('branches', function (Blueprint $table) { $table->dropColumn(['address','phone','contact_person']); });
foreach (['designations','grades'] as $name) Schema::table($name, function (Blueprint $table) use ($name) {
foreach (['salary_from','salary_to','increment_value'] as $field) $table->decimal($field,14,2)->nullable();
foreach (['increment_by','percentage_of'] as $field) $table->string($field)->nullable();
if ($name === 'grades') $table->decimal('award_amount',14,2)->nullable();
else $table->string('domain')->nullable();
});
}
};

