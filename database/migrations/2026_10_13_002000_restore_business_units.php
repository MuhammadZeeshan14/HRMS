<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (require __DIR__.'/2026_10_13_001000_remove_business_units.php')->down();
    }

    public function down(): void
    {
        (require __DIR__.'/2026_10_13_001000_remove_business_units.php')->up();
    }
};
