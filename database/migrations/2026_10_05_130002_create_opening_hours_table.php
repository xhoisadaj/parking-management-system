<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_hours', function (Blueprint $table) {
            $table->id();
            // 0 = Sunday ... 6 = Saturday (matches Carbon::dayOfWeek)
            $table->unsignedTinyInteger('weekday')->unique();
            $table->boolean('is_closed')->default(false);
            $table->time('opens_at')->nullable();
            // closes_at earlier than opens_at means the lot closes after midnight
            $table->time('closes_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_hours');
    }
};
