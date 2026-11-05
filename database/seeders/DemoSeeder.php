<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\FiscalYear;
use App\Models\Grade;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::create(['name' => 'Ayesha Khan', 'email' => 'admin@ahamson.test', 'password' => Str::password(24), 'role' => 'admin']);
        $hr = User::create(['name' => 'Hamza Ali', 'email' => 'hr@ahamson.test', 'password' => Str::password(24), 'role' => 'hr_manager']);
        $employeeUser = User::create(['name' => 'Omar Siddiqui', 'email' => 'employee@ahamson.test', 'password' => Str::password(24), 'role' => 'employee']);

        $company = Company::create(['code' => 'AHPK', 'name' => 'Ahamson Pakistan']);
        Company::create(['code' => 'AHME', 'name' => 'Ahamson Middle East']);
        $hq = Location::create(['company_id' => $company->id, 'code' => 'KHI-HQ', 'name' => 'Karachi Head Office', 'city' => 'Karachi']);
        $lahore = Location::create(['company_id' => $company->id, 'code' => 'LHE-01', 'name' => 'Lahore Office', 'city' => 'Lahore']);
        $people = Department::create(['company_id' => $company->id, 'code' => 'PEO', 'name' => 'People and Culture', 'is_hr' => true]);
        $technology = Department::create(['company_id' => $company->id, 'code' => 'TEC', 'name' => 'Technology', 'parent_department_id' => $people->id]);
        $finance = Department::create(['company_id' => $company->id, 'code' => 'FIN', 'name' => 'Finance', 'parent_department_id' => $people->id]);
        Department::create(['company_id' => $company->id, 'parent_department_id' => $people->id, 'code' => 'TAL', 'name' => 'Talent Operations']);
        $manager = Designation::create(['code' => 'HRM', 'name' => 'HR Manager']);
        $engineer = Designation::create(['code' => 'SWE', 'name' => 'Senior Software Engineer']);
        $analyst = Designation::create(['code' => 'FA', 'name' => 'Finance Analyst']);
        $professional = Grade::create(['code' => 'G1', 'name' => 'Professional', 'rank' => 1]);
        $senior = Grade::create(['code' => 'G2', 'name' => 'Senior Professional', 'rank' => 2]);
        $management = Grade::create(['code' => 'G3', 'name' => 'Management', 'rank' => 3]);
        FiscalYear::create(['name' => 'FY 2026-27', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);

        $this->seedNamedMaster('languages', ['English', 'Urdu', 'Sindhi']);
        $this->seedNamedMaster('religions', ['Islam', 'Christianity', 'Hinduism']);
        $this->seedNamedMaster('document_types', ['Identity Document', 'Employment Contract', 'Academic Certificate']);
        $this->seedNamedMaster('training_types', ['Technical', 'Leadership', 'Compliance']);
        $this->seedNamedMaster('leaving_reasons', ['Resignation', 'Retirement', 'End of contract', 'Termination']);
        $this->seedNamedMaster('certification_types', ['Professional', 'Technical']);
        $this->seedNamedMaster('certifications', ['Project Management Professional', 'Laravel Certified Developer']);
        $bankId = DB::table('banks')->insertGetId(['name' => 'Habib Bank Limited', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('branches')->insert(['bank_id' => $bankId, 'name' => 'Karachi Main Branch', 'code' => 'KHI-001', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('news')->insert(['title' => 'Employee records review', 'body' => 'Please review personal and employment information for completeness.', 'published_on' => now()->toDateString(), 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);

        $profiles = [
            ['PC-1001', 'Hamza', 'Ali', $hr, $people, $manager, $management, $hq, '2019-03-11'],
            ['PC-1002', 'Omar', 'Siddiqui', $employeeUser, $technology, $engineer, $senior, $hq, '2021-08-16'],
            ['PC-1003', 'Maha', 'Raza', $admin, $people, $analyst, $professional, $lahore, '2023-02-06'],
        ];
        $employees = [];
        foreach ($profiles as $index => $profile) {
            $employees[] = Employee::create([
                'employee_no' => $profile[0], 'first_name' => $profile[1], 'last_name' => $profile[2], 'user_id' => $profile[3]?->id,
                'date_of_joining' => $profile[8], 'company_id' => $company->id, 'location_id' => $profile[7]->id,
                'reporting_location_id' => $profile[7]->id, 'placement_location_id' => $profile[7]->id, 'department_id' => $profile[4]->id,
                'designation_id' => $profile[5]->id, 'grade_id' => $profile[6]->id, 'employment_status' => 'active', 'employment_type' => 'permanent',
                'email' => strtolower($profile[1].'.'.$profile[2].'@ahamson.test'), 'phone' => '+92 300 1234'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'primary_report_to_id' => $index === 1 ? $employees[0]->id : null,
                'profile_description' => 'Experienced professional contributing to organizational goals.', 'job_description' => 'Delivers role responsibilities and collaborates across teams.',
            ]);
        }
        foreach ($employees as $employee) {
            DB::table('employee_qualifications')->insert(['employee_id' => $employee->id, 'institution' => 'National University', 'degree' => 'Bachelor Degree', 'field_of_study' => 'Business or Technology', 'starts_on' => '2013-09-01', 'ends_on' => '2017-06-30', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('salary_histories')->insert(['employee_id' => $employee->id, 'effective_from' => '2026-07-01', 'basic_salary' => 120000 + ($employee->id * 15000), 'allowances' => 25000, 'other_components' => 5000, 'currency' => 'PKR', 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function seedNamedMaster(string $table, array $names): void
    {
        foreach ($names as $name) {
            DB::table($table)->insert(['name' => $name, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
