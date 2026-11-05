<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_unit_hierarchies', function (Blueprint $table) {
            $table->foreignId('business_unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hierarchy_id')->constrained('hierarchies')->restrictOnDelete();
            $table->primary(['business_unit_id', 'hierarchy_id'], 'business_unit_hierarchy_primary');
        });
        DB::table('business_units')->whereNotNull('hierarchy_id')->orderBy('id')->chunkById(200, function ($units) {
            DB::table('business_unit_hierarchies')->insert($units->map(fn ($unit) => [
                'business_unit_id' => $unit->id, 'hierarchy_id' => $unit->hierarchy_id,
            ])->all());
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_unit_hierarchies');
    }
};
