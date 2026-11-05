<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminOnlySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_seed_creates_only_one_admin_and_keeps_setup_empty(): void
    {
        $this->seed();
        $admin = User::sole();
        $passwordHash = $admin->password;
        $this->assertSame('admin', $admin->role);
        $this->assertFalse($admin->is_active);
        $this->seed();
        $this->assertSame($passwordHash, User::sole()->password);
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if (!in_array($table, ['migrations', 'users', 'sqlite_sequence'])) {
                $this->assertSame(0, DB::table($table)->count(), $table);
            }
        }
        $this->actingAs($admin)->get('/')->assertRedirect('/login');
        $this->get('/company-department')->assertRedirect('/login');
        $this->get('/master-data/companies')->assertRedirect('/login');
    }
}
