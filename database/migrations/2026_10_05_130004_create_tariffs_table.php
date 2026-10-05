<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tariffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_type_id')->constrained()->restrictOnDelete();
            $table->string('name')->nullable();
            $table->unsignedSmallInteger('billing_unit_minutes')->default(60);
            $table->decimal('price_per_unit', 10, 2);
            $table->decimal('first_unit_price', 10, 2)->nullable();
            $table->unsignedSmallInteger('grace_minutes')->default(0);
            $table->decimal('daily_max', 10, 2)->nullable();
            // Rounding rule. 'up' = partial unit is charged as a full unit.
            $table->string('rounding', 16)->default('up');
            $table->date('active_from')->nullable();
            $table->date('active_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['vehicle_type_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tariffs');
    }
};
