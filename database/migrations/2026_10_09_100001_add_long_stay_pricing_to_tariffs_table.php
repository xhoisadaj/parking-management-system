<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tariffs', function (Blueprint $table) {
            // Once a stay reaches this many calendar days, the whole stay is re-priced at
            // long_stay_daily_price per day instead of the hourly units / daily_max. Built for
            // long-term lots (e.g. airport parking) where a multi-day stay needs its own rate.
            $table->unsignedSmallInteger('long_stay_threshold_days')->nullable()->after('daily_max');
            $table->decimal('long_stay_daily_price', 10, 2)->nullable()->after('long_stay_threshold_days');
        });
    }

    public function down(): void
    {
        Schema::table('tariffs', function (Blueprint $table) {
            $table->dropColumn(['long_stay_threshold_days', 'long_stay_daily_price']);
        });
    }
};
