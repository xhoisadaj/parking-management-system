<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            // What the operator themselves counted in the drawer at close, declared before the
            // shift can be closed at all. Separate from cash_counted, which is the manager's own
            // independent count at reconciliation — keeping both lets a mismatch between what the
            // operator reported and what the manager actually found be seen, not just overwritten.
            $table->decimal('closing_cash_declared', 12, 2)->nullable()->after('cash_collected');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn('closing_cash_declared');
        });
    }
};
