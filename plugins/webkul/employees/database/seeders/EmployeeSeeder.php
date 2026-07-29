<?php

namespace Webkul\Employee\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class EmployeeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('employees_employees')->delete();

        $user = User::first();
        $company = Company::query()->firstOrFail();

        $employees = [
            [
                'time_zone'      => 'UTC',
                'creator_id'     => $user?->id,
                'company_id'     => $company->id,
                'name'           => 'Paul Williams',
                'job_title'      => 'Experienced Developer',
                'work_email'     => 'paul@example.com',
                'employee_type'  => 'employee',
                'is_active'      => 1,
            ],
            [
                'time_zone'      => 'America/New_York',
                'creator_id'     => $user?->id,
                'company_id'     => $company->id,
                'name'           => 'John Doe',
                'job_title'      => 'Junior Developer',
                'work_email'     => 'john@example.com',
                'employee_type'  => 'employee',
                'is_active'      => 1,
            ],
            [
                'time_zone'      => 'Europe/London',
                'creator_id'     => $user?->id,
                'company_id'     => $company->id,
                'name'           => 'Jane Smith',
                'job_title'      => 'Project Manager',
                'work_email'     => 'jane@example.com',
                'employee_type'  => 'employee',
                'is_active'      => 1,
            ],
            [
                'time_zone'      => 'Asia/Kolkata',
                'creator_id'     => $user?->id,
                'company_id'     => $company->id,
                'name'           => 'Ravi Kumar',
                'job_title'      => 'Team Lead',
                'work_email'     => 'ravi@example.com',
                'employee_type'  => 'employee',
                'is_active'      => 1,
            ],
            [
                'time_zone'      => 'Australia/Sydney',
                'creator_id'     => $user?->id,
                'company_id'     => $company->id,
                'name'           => 'Emily Davis',
                'job_title'      => 'QA Engineer',
                'work_email'     => 'emily@example.com',
                'employee_type'  => 'employee',
                'is_active'      => 1,
            ],
            [
                'time_zone'      => 'America/Los_Angeles',
                'creator_id'     => $user?->id,
                'company_id'     => $company->id,
                'name'           => 'Michael Brown',
                'job_title'      => 'UX Designer',
                'work_email'     => 'michael@example.com',
                'employee_type'  => 'employee',
                'is_active'      => 1,
            ],
            [
                'time_zone'      => 'Asia/Tokyo',
                'creator_id'     => $user?->id,
                'company_id'     => $company->id,
                'name'           => 'Hiro Tanaka',
                'job_title'      => 'Backend Developer',
                'work_email'     => 'hiro@example.com',
                'employee_type'  => 'employee',
                'is_active'      => 1,
            ],
            [
                'time_zone'      => 'Africa/Johannesburg',
                'creator_id'     => $user?->id,
                'company_id'     => $company->id,
                'name'           => 'Linda Ndlovu',
                'job_title'      => 'HR Manager',
                'work_email'     => 'linda@example.com',
                'employee_type'  => 'employee',
                'is_active'      => 1,
            ],
            [
                'time_zone'      => 'Europe/Berlin',
                'creator_id'     => $user?->id,
                'company_id'     => $company->id,
                'name'           => 'Hans Müller',
                'job_title'      => 'Frontend Developer',
                'work_email'     => 'hans@example.com',
                'employee_type'  => 'employee',
                'is_active'      => 1,
            ],
            [
                'time_zone'      => 'America/Chicago',
                'creator_id'     => $user?->id,
                'company_id'     => $company->id,
                'name'           => 'Grace Wilson',
                'job_title'      => 'Data Scientist',
                'work_email'     => 'grace@example.com',
                'employee_type'  => 'employee',
                'is_active'      => 1,
            ],
        ];

        // Employee now enforces HasStrictCompanyId (#138 PR4 A4D). No
        // authenticated actor exists during erp:install/employees:install,
        // but PluginManager's generic InstallCommand::handle() already
        // wraps the entire install (including this seeder, invoked via
        // `db:seed`) in CompanyContext::runForBootstrap() — opening a
        // second, nested one here would throw ("nesting is not
        // supported"). An explicit company_id on each row is all that's
        // needed: HasStrictCompanyId's fail-closed null check is satisfied,
        // and CompanyScope::assertCanWriteCompany() no-ops under the
        // already-open BOOTSTRAP context.
        foreach ($employees as $employee) {
            Employee::create(array_merge($employee, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }
}
