<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\User;
use App\Services\EmployeeAccess;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Console\Command;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\IOFactory;

class VerifyTestingCredentials extends Command
{
    protected $signature = 'hrms:verify-testing-credentials {file} {--base-url=http://127.0.0.1:8000}';

    protected $description = 'Verify workbook completeness, credential hashes, access metadata and representative real HTTP logins without displaying passwords';

    public function handle(EmployeeAccess $access): int
    {
        try {
            $sheet = IOFactory::load($this->argument('file'))->getActiveSheet();
            $hasCompanyEmail = $sheet->getCell('L5')->getValue() === 'Company email ID';
            $rows = $sheet->rangeToArray('A6:'.($hasCompanyEmail ? 'L' : 'K').$sheet->getHighestDataRow(), null, false, false);
            if (count($rows) !== Employee::count()) {
                throw new \RuntimeException('Workbook employee count mismatch.');
            }
            $seen = [];
            $representatives = [];
            foreach ($rows as $row) {
                $u = User::where('login_id', (string) $row[0])->first();
                $e = $u?->employee;
                if (! $e || isset($seen[$e->id]) || ! Hash::check((string) $row[8], $u->password)) {
                    throw new \RuntimeException('Missing, duplicate, or invalid workbook credential.');
                }
                $seen[$e->id] = true;
                $source = $access->source($e);
                $enabled = $access->enabled($u);
                $expected = [(string) $u->login_id, $e->employee_no !== $u->login_id ? $e->employee_no : '', $e->display_name, $e->company?->name, $e->department?->name, $access->level($e), $source ? 'Admin' : 'Employee', $source ?: '', (string) $row[8], $enabled ? 'Active · password change required' : 'INACTIVE · login blocked', $e->is_test_employee ? 'Test employee' : 'Existing employee'];
                if ($hasCompanyEmail) {
                    $expected[] = $e->email ?: '';
                    if ($e->email && $u->email !== $e->email && $u->pending_recovery_email !== $e->email) {
                        throw new \RuntimeException('Account company email assignment mismatch.');
                    }
                }
                foreach ($expected as $i => $value) {
                    if ((string) $value !== (string) $row[$i]) {
                        throw new \RuntimeException('Workbook access or identity metadata mismatch.');
                    }
                }
                $category = $enabled ? ($source ?: 'Employee') : 'Inactive';
                $representatives[$category] ??= $row;
            }
            foreach (['Level 1', 'HR', 'delegated', 'Employee', 'Inactive'] as $category) {
                if (! isset($representatives[$category])) {
                    throw new \RuntimeException('Missing representative category: '.$category);
                }
                $row = $representatives[$category];
                $cookies = new CookieJar;
                $base = rtrim($this->option('base-url'), '/');
                $client = Http::withOptions(['cookies' => $cookies, 'allow_redirects' => false])->timeout(15);
                $login = $client->get($base.'/login');
                if (! preg_match('/name="_token" value="([^"]+)"/', $login->body(), $match)) {
                    throw new \RuntimeException('Could not load CSRF-protected login form.');
                }
                $identifier = $hasCompanyEmail && in_array($category, ['HR', 'Employee', 'Inactive'], true) ? (string) $row[11] : (string) $row[0];
                $response = $client->asForm()->withHeaders(['Referer' => $base.'/login'])->post($base.'/login', ['_token' => html_entity_decode($match[1]), 'login_id' => $identifier, 'password' => (string) $row[8]]);
                if ($response->status() !== 302) {
                    throw new \RuntimeException('Login did not return the expected redirect (check the login rate limit).');
                }
                $account = $client->get($base.'/my-account');
                if ($category === 'Inactive') {
                    if ($account->status() !== 302 || ! str_ends_with($account->header('Location'), '/login')) {
                        throw new \RuntimeException('Inactive account was not denied.');
                    }
                } else {
                    if ($account->status() !== 200 || ! str_contains($account->body(), 'You can continue to other pages') || ! str_contains($account->body(), '<dd>'.$row[6].'</dd>')) {
                        throw new \RuntimeException('Account view or effective access mismatch.');
                    }
                    $dashboard = $client->get($base.'/');
                    if ($dashboard->status() !== 200 || ! str_contains($dashboard->body(), $category === 'Employee' ? 'Employee Dashboard' : 'Total employees')) {
                        throw new \RuntimeException('Dashboard navigation or effective access mismatch.');
                    }
                }
                $this->info($category.': HTTP ID/email login, account view and navigation checks passed.');
            }
            $this->info(count($rows).' workbook rows verified against account hashes, employee identities, status and current permissions.');

            return 0;
        } catch (\Throwable $e) {
            // Avoid rendering HTTP request/exception objects that could contain submitted credentials.
            $this->error($e instanceof \RuntimeException && ! ($e instanceof HttpClientException) ? $e->getMessage() : 'Credential verification failed. Check server availability and workbook configuration.');

            return 1;
        }
    }
}
