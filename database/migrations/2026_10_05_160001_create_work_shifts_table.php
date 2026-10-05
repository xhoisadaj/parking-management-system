<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_shifts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Scheduled hours in the parking's timezone. ends_at earlier than starts_at means past midnight.
            $table->time('starts_at');
            $table->time('ends_at');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('work_shift_id')->nullable()->after('is_active')->constrained('work_shifts')->nullOnDelete();
        });

        Schema::table('shifts', function (Blueprint $table) {
            // The scheduled shift the operator was assigned when this shift opened.
            $table->foreignId('work_shift_id')->nullable()->after('user_id')->constrained('work_shifts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('work_shift_id');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('work_shift_id');
        });
        Schema::dropIfExists('work_shifts');
    }
};
