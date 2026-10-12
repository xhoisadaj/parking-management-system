<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            // Cash physically in the drawer when the operator clocked in, declared by them at
            // start — not defaulted or carried over from the previous shift. Null only for a
            // shift opened some other way than the explicit "Fillo turnin" action (e.g. an admin
            // completing a checkout from the panel without ever clocking in).
            $table->decimal('opening_cash', 12, 2)->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn('opening_cash');
        });
    }
};
