<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parking_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_code', 32)->unique();
            $table->foreignId('vehicle_type_id')->constrained()->restrictOnDelete();
            $table->string('plate', 20)->nullable()->index();
            $table->dateTime('entered_at');
            $table->dateTime('exited_at')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->decimal('calculated_price', 10, 2)->nullable();
            $table->decimal('final_price', 10, 2)->nullable();
            $table->text('adjustment_reason')->nullable();
            $table->foreignId('adjusted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('entry_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('exit_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('active');
            // Frozen copy of the tariff at entry. Prices are always computed from this, never from the live tariff.
            $table->json('tariff_snapshot');
            $table->timestamps();

            $table->index(['status', 'vehicle_type_id']);
            $table->index('entered_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parking_sessions');
    }
};
