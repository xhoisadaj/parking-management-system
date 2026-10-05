<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            // Cash physically counted by the manager at reconciliation. Difference = counted - cash_collected.
            $table->decimal('cash_counted', 12, 2)->nullable()->after('cash_collected');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn('cash_counted');
        });
    }
};
