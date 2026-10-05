<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('parking_name')->default('Parking');
            $table->text('address')->nullable();
            $table->decimal('total_capacity', 8, 2)->default(0);
            $table->string('currency', 3)->default('ALL');
            $table->decimal('lost_ticket_fee', 10, 2)->default(0);
            $table->text('ticket_header')->nullable();
            $table->text('ticket_footer')->nullable();
            $table->unsignedSmallInteger('ticket_paper_width_mm')->default(80);
            $table->string('timezone')->default('Europe/Tirane');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
