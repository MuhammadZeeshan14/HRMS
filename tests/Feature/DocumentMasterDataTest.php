<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
class DocumentMasterDataTest extends TestCase {
use RefreshDatabase;
use \Tests\Support\EmployeeAccountFixtures;
public function test_compensation_relationships_and_calculation_fields(): void {
$this->actingAs($this->linkedHr());
$type=DB::table('allowance_types')->insertGetId(['name'=>'Salary']);
$employment=DB::table('employment_statuses')->insertGetId(['name'=>'Probation']);
$allowance=DB::table('allowances')->insertGetId(['code'=>'BASIC','name'=>'Basic Salary','allowance_type_id'=>$type]);
$plan=DB::table('compensation_plans')->insertGetId(['name'=>'Probation Plan','employment_status_id'=>$employment,'effective_from'=>'2026-01-01']);
$payload=['name'=>'Basic salary detail','compensation_plan_id'=>$plan,'allowance_id'=>$allowance,'distributed_in_days'=>1,'allowance_limit'=>'percentage','percentage_of'=>'gross_salary','percentage'=>67,'part_of_gross'=>1,'taxable'=>1];
$this->post('/master-data/compensation_plan_details',$payload)->assertSessionHasNoErrors();
$this->assertDatabaseHas('compensation_plan_details',['compensation_plan_id'=>$plan,'allowance_id'=>$allowance,'percentage'=>67]);
$this->post('/master-data/compensation_plan_details',$payload)->assertSessionHasErrors('allowance_id');
$this->delete('/master-data/allowances/'.$allowance)->assertSessionHasErrors('master');
$id=DB::table('compensation_plan_details')->value('id');
$payload['percentage']=101;
$this->put('/master-data/compensation_plan_details/'.$id,$payload)->assertSessionHasErrors('percentage');
$payload['allowance_limit']='fixed';unset($payload['percentage'],$payload['percentage_of']);
$this->put('/master-data/compensation_plan_details/'.$id,$payload)->assertSessionHasErrors('fixed_amount');
}
public function test_possession_category_must_match_type(): void {
$this->seed(\Database\Seeders\DemoSeeder::class);
        \App\Models\User::query()->update(['must_change_password'=>false]); $this->seed(\Database\Seeders\AhamsonSetupSeeder::class);
$this->actingAs(User::where('role','admin')->first());
$category=DB::table('possession_categories')->insertGetId(['name'=>'IT']);
$other=DB::table('possession_categories')->insertGetId(['name'=>'Vehicles']);
$type=DB::table('possession_types')->insertGetId(['name'=>'Laptop','department_id'=>DB::table('departments')->value('id'),'possession_category_id'=>$category]);
$payload=['name'=>'Laptop 1','possession_category_id'=>$other,'possession_type_id'=>$type,'status'=>'active'];
$this->post('/master-data/possession_items',$payload)->assertSessionHasErrors('possession_type_id');
$payload['possession_category_id']=$category;
$this->post('/master-data/possession_items',$payload)->assertSessionHasNoErrors();
$this->delete('/master-data/possession_types/'.$type)->assertSessionHasErrors('master');
$this->assertSame([], DB::select('PRAGMA foreign_key_check'));
}
}

