<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            // Max discount a role may apply at checkout, in percent.
            // 0 = no discounts, null = unlimited.
            $table->decimal('max_discount_percent', 5, 2)->nullable()->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('max_discount_percent');
        });
    }
};
