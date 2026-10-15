<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tariff_duration_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tariff_id')->constrained()->cascadeOnDelete();
            $table->string('label')->nullable();
            // Null means "and beyond" — the open-ended last tier, e.g. the full-day rate once a
            // stay passes every other tier's cutoff. Minutes are measured from entry (same basis
            // grace_minutes already uses), within each rolling day-period independently.
            $table->unsignedInteger('up_to_minutes')->nullable();
            // Flat price for the whole day-period once its elapsed minutes fall in this tier —
            // not a per-unit rate, unlike price_per_unit on the tariff itself.
            $table->decimal('price', 10, 2);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tariff_duration_tiers');
    }
};
