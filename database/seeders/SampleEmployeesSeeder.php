<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SampleEmployeesSeeder extends Seeder
{
    public function run(): void
    {
        $profiles = [
            ['SAMPLE-1001', 'Ahmed', 'Rashid', '14', '2018-03-12', 240000, 'Project Delivery and Risk Management', 'Agile Leadership Workshop'],
            ['SAMPLE-1002', 'Fatima', 'Hassan', '16', '2020-08-17', 200000, 'Secure Software Development', 'Advanced Application Architecture'],
            ['SAMPLE-1003', 'Bilal', 'Anwar', '21', '2022-02-07', 125000, 'Automated Testing Foundations', 'Performance Testing Workshop'],
            ['SAMPLE-1004', 'Areeba', 'Khalid', '13', '2017-11-06', 220000, 'Financial Controls and Reporting', 'Budget Planning Workshop'],
            ['SAMPLE-1005', 'Usman', 'Farooq', '18', '2019-06-10', 180000, 'Consultative Sales Techniques', 'Key Account Management'],
            ['SAMPLE-1006', 'Zainab', 'Malik', '22', '2021-04-05', 145000, 'Solution Discovery and Demonstrations', 'Technical Proposal Writing'],
            ['SAMPLE-1007', 'Haris', 'Iqbal', '23', '2022-09-19', 115000, 'Accessible Interface Design', 'Brand Communication Workshop'],
            ['SAMPLE-1008', 'Maryam', 'Saeed', '25', '2023-01-16', 105000, 'Network Operations Fundamentals', 'Network Security Workshop'],
            ['SAMPLE-1009', 'Danish', 'Akram', '19', '2019-10-14', 175000, 'Commercial Contract Drafting', 'Employment Law Case Workshop'],
            ['SAMPLE-1010', 'Sana', 'Yousaf', '29', '2024-07-08', 85000, 'Software Engineering Foundations', 'Code Review and Collaboration'],
        ];
        $company = DB::table('companies')->where('code', '03')->first();
        $location = $company ? DB::table('locations')->where('company_id', $company->id)->orderBy('id')->first() : null;
        if (! $company || ! $location) {
            throw new RuntimeException('Seed the A Hamson setup company and location first.');
        }
        $department = Department::find(config('employee_access.sample_department_id'));
        if (! $department || (int) $department->company_id !== (int) $company->id || $department->parent_department_id) {
            throw new RuntimeException('Set SAMPLE_DEPARTMENT_ID to a reviewed root department in the sample company before creating sample employees.');
        }
        $createdBy = DB::table('users')->where('role', 'admin')->value('id');
        $created = 0;
        DB::transaction(function () use ($profiles, $company, $location, $department, $createdBy, &$created) {
            foreach ($profiles as $index => [$number, $first, $last, $code, $joiningDate, $currentSalary, $courseOne, $courseTwo]) {
                $designation = DB::table('designations')->where('code', $code)->first();
                $gradeId = DB::table('grades')->orderBy('rank')->value('id');
                if (! $designation || ! $gradeId) {
                    throw new RuntimeException('Missing designation or grade for code '.$code);
                }
                $joined = CarbonImmutable::parse($joiningDate);
                $employee = DB::table('employees')->where('employee_no', $number)->first();
                if ($employee && ($employee->first_name !== $first || $employee->last_name !== $last)) {
                    throw new RuntimeException('Employee number '.$number.' is already assigned to another person.');
                }
                if ($employee) {
                    $id = $employee->id;
                } else {
                    $id = Employee::create([
                        'department_id' => $department->id, 'is_test_employee' => true,
                        'employee_no' => $number, 'first_name' => $first, 'last_name' => $last,
                        'email' => strtolower($first.'.'.$last.'@ahamson.test'),
                        'date_of_birth' => $joined->subYears(27)->toDateString(), 'date_of_joining' => $joiningDate,
                        'company_id' => $company->id, 'location_id' => $location->id,
                        'reporting_location_id' => $location->id, 'placement_location_id' => $location->id,
                        'designation_id' => $designation->id, 'grade_id' => $gradeId,
                        'employment_status' => 'active', 'employment_type' => $index === 7 ? 'contract' : 'permanent',
                        'profile_description' => 'Sample employee profile for HRIS demonstration. Role: '.$designation->name.'.',
                        'job_description' => 'Performs '.$designation->name.' responsibilities, coordinates with colleagues, and maintains delivery quality.',
                        'created_at' => now(), 'updated_at' => now(),
                    ])->id;
                    $created++;
                }
                foreach ([
                    ['Sample Horizon Services', 'Junior '.$designation->name, $joined->subYears(5), $joined->subYears(3)->subDay()],
                    ['Sample Meridian Consulting', $designation->name, $joined->subYears(3), $joined->subDay()],
                ] as [$employer, $title, $start, $end]) {
                    $this->insertOnce('employee_experiences', ['employee_id' => $id, 'employer' => $employer, 'starts_on' => $start->toDateString()], [
                        'job_title' => $title, 'ends_on' => $end->toDateString(),
                        'description' => 'Sample prior work experience in '.$designation->name.' responsibilities.',
                    ]);
                }
                foreach ([$courseOne, $courseTwo] as $courseIndex => $title) {
                    $typeName = $courseIndex === 0 ? 'Internal Trainings' : ($index % 2 === 0 ? 'Local Trainings' : 'External Trainings');
                    $typeId = DB::table('training_types')->where('name', $typeName)->value('id');
                    if (! $typeId) {
                        throw new RuntimeException('Missing training type '.$typeName);
                    }
                    $start = $courseIndex === 0 ? $joined->addMonths(2) : CarbonImmutable::parse('2026-02-02')->addDays($index * 3);
                    $this->insertOnce('employee_trainings', ['employee_id' => $id, 'title' => $title], [
                        'training_type_id' => $typeId, 'provider' => $courseIndex === 0 ? 'A Hamson Learning Team' : 'Sample Professional Learning Centre',
                        'starts_on' => $start->toDateString(), 'ends_on' => $start->addDays($courseIndex + 1)->toDateString(),
                        'notes' => 'Completed. Sample training record for demonstration.',
                    ]);
                }
                foreach ([[$joiningDate, '2025-06-30', 0.60], ['2025-07-01', '2026-06-30', 0.85], ['2026-07-01', null, 1.00]] as [$start, $end, $factor]) {
                    $basic = round($currentSalary * $factor, 2);
                    $this->insertOnce('salary_histories', ['employee_id' => $id, 'effective_from' => $start], [
                        'effective_to' => $end, 'basic_salary' => $basic, 'allowances' => round($basic * 0.20, 2),
                        'other_components' => $index % 3 === 0 ? 5000 : 0, 'currency' => 'PKR', 'created_by' => $createdBy,
                        'remarks' => 'Sample salary record: '.($end === null ? 'current compensation' : 'historical compensation').'.',
                    ]);
                }
            }
        });
        $this->command?->info($created.' employees added. The ten sample profiles have 20 experiences, 20 trainings, and 30 salary history records.');
    }

    private function insertOnce(string $table, array $identity, array $data): void
    {
        if (! DB::table($table)->where($identity)->exists()) {
            DB::table($table)->insert($identity + $data + ['created_at' => now(), 'updated_at' => now()]);
        }
    }
}
