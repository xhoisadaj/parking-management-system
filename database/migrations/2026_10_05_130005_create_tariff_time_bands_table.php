<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tariff_time_bands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tariff_id')->constrained()->cascadeOnDelete();
            $table->string('label')->nullable();
            // starts_at later than ends_at means the band crosses midnight (e.g. 22:00 - 06:00)
            $table->time('starts_at');
            $table->time('ends_at');
            // Price per billing unit while a unit starts inside this band
            $table->decimal('price_per_unit', 10, 2);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tariff_time_bands');
    }
};
