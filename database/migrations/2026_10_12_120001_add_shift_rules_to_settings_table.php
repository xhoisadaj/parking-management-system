<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            // Both default to true, matching the behavior they were introduced with — an admin
            // who never touches this setting sees no change. Turning either off removes the rule
            // entirely (not "optional"): no schedule check, or no cash field at all.
            $table->boolean('enforce_work_shift_hours')->default(true)->after('reason_threshold_percent');
            $table->boolean('require_cash_declaration')->default(true)->after('enforce_work_shift_hours');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['enforce_work_shift_hours', 'require_cash_declaration']);
        });
    }
};
