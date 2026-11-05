<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DepartmentLevelsTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Support\EmployeeAccountFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->linkedHr());
    }

    private function createDepartment(string $code, ?int $parent = null): int
    {
        $this->post('/master-data/departments', ['code' => $code, 'name' => $code, 'status' => 'active', 'parent_department_id' => $parent, 'level' => 'ignore this input'])
            ->assertSessionHasNoErrors();
        return DB::table('departments')->where('code', $code)->value('id');
    }

    public function test_levels_are_derived_and_manual_values_are_ignored(): void
    {
        $root = $this->createDepartment('ROOT');
        $child = $this->createDepartment('CHILD', $root);
        $grandchild = $this->createDepartment('GRANDCHILD', $child);
        foreach ([$root => 1, $child => 2, $grandchild => 3] as $id => $level) {
            $this->assertDatabaseHas('departments', compact('id', 'level'));
        }
        $this->get('/master-data/departments')->assertOk()->assertSee('readonly', false)->assertSee('data-level="2"', false);
    }

    public function test_reparenting_updates_descendants_and_removing_parent_resets_level(): void
    {
        $root = $this->createDepartment('ROOT');
        $other = $this->createDepartment('OTHER');
        $child = $this->createDepartment('CHILD', $root);
        $grandchild = $this->createDepartment('GRANDCHILD', $child);
        $this->put('/master-data/departments/'.$root, ['code' => 'ROOT', 'name' => 'Root', 'status' => 'active', 'parent_department_id' => $other])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('departments', ['id' => $root, 'level' => 2]);
        $this->assertDatabaseHas('departments', ['id' => $child, 'level' => 3]);
        $this->assertDatabaseHas('departments', ['id' => $grandchild, 'level' => 4]);
        $this->put('/master-data/departments/'.$root, ['code' => 'ROOT', 'name' => 'Root', 'status' => 'active', 'parent_department_id' => null])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('departments', ['id' => $root, 'level' => 1]);
        $this->assertDatabaseHas('departments', ['id' => $grandchild, 'level' => 3]);
    }

    public function test_cycles_are_rejected_without_changing_levels(): void
    {
        $root = $this->createDepartment('ROOT');
        $child = $this->createDepartment('CHILD', $root);
        $this->put('/master-data/departments/'.$root, ['code' => 'ROOT', 'name' => 'Root', 'status' => 'active', 'parent_department_id' => $child])->assertSessionHasErrors('parent_department_id');
        $this->assertDatabaseHas('departments', ['id' => $root, 'parent_department_id' => null, 'level' => 1]);
        $this->assertDatabaseHas('departments', ['id' => $child, 'level' => 2]);
    }
}
