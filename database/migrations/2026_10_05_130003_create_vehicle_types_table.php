<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            // How much of the shared capacity pool one vehicle occupies (motorbike = 0.5)
            $table->decimal('spots_used', 5, 2)->default(1);
            // Optional max number of vehicles of this type parked at once (null = no dedicated cap)
            $table->unsignedInteger('dedicated_capacity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_types');
    }
};
