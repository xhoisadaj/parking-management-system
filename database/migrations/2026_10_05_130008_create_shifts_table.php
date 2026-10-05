<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->dateTime('opened_at');
            $table->dateTime('closed_at')->nullable();
            $table->unsignedInteger('tickets_issued')->default(0);
            $table->unsignedInteger('checkouts')->default(0);
            $table->decimal('cash_collected', 12, 2)->default(0);
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reconciled_at')->nullable();
            $table->text('reconciliation_note')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'closed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
