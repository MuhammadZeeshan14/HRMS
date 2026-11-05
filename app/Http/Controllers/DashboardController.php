<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\EmployeeAccess;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $scope = app(EmployeeAccess::class)->employees(auth()->user());
        $metrics = [
            'employees' => (clone $scope)->count(),
            'active' => (clone $scope)->where('employment_status', 'active')->count(),
            'inactive' => (clone $scope)->where('employment_status', 'inactive')->count(),
            'exited' => (clone $scope)->where('employment_status', 'exited')->count(),
            'companies' => DB::table('companies')->where('id', auth()->user()->employee->company_id)->where('status', 'active')->count(),
            'expiring_documents' => DB::table('employee_documents')->whereIn('employee_id', (clone $scope)->select('id'))->whereBetween('expires_on', [now(), now()->addDays(30)])->count(),
        ];
        $departments = (clone $scope)->join('departments', 'employees.department_id', '=', 'departments.id')->select('departments.name', DB::raw('count(*) total'))->groupBy('departments.name')->orderByDesc('total')->limit(6)->get();
        $newsQuery = DB::table('news')->where('status', 'published');
        if (true) {
            $companyId = Employee::where('user_id', auth()->id())->value('company_id');
            $newsQuery->where(function ($query) use ($companyId) {
                // Legacy announcements without a company selection remain visible.
                $query->whereNotExists(fn ($scope) => $scope->selectRaw('1')->from('news_companies')->whereColumn('news_companies.news_id', 'news.id'))
                    ->orWhereExists(fn ($scope) => $scope->selectRaw('1')->from('news_companies')->whereColumn('news_companies.news_id', 'news.id')->where('company_id', $companyId));
            });
        }
        $news = $newsQuery->latest('published_on')->limit(4)->get();

        if (! app(EmployeeAccess::class)->admin(auth()->user())) {
            return view('account.dashboard', ['employee' => auth()->user()->employee, 'news' => $news]);
        }

        return view('dashboard', compact('metrics', 'departments', 'news'));
    }
}
