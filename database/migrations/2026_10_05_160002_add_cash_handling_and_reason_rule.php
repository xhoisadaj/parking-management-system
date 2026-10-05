<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parking_sessions', function (Blueprint $table) {
            // Cash the customer handed over, and the change given back. Null when no cash was counted.
            $table->decimal('amount_received', 10, 2)->nullable()->after('final_price');
            $table->decimal('change_given', 10, 2)->nullable()->after('amount_received');
        });

        Schema::table('settings', function (Blueprint $table) {
            // A reason is required when a price change is at least this many percent of the calculated price.
            // 0 = every change needs a reason.
            $table->decimal('reason_threshold_percent', 5, 2)->default(0)->after('ticket_paper_width_mm');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('reason_threshold_percent');
        });
        Schema::table('parking_sessions', function (Blueprint $table) {
            $table->dropColumn(['amount_received', 'change_given']);
        });
    }
};
