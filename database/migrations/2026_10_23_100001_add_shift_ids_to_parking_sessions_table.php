<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parking_sessions', function (Blueprint $table) {
            // The operator shift a ticket was issued in, and the one it was checked out (or marked
            // lost) in. Shift totals are counted by these, not by time windows: timestamps only
            // have whole-second precision, so a checkout in the same second a shift closed could
            // fall inside that shift's window without being in its stored totals.
            $table->foreignId('entry_shift_id')->nullable()->after('exit_user_id')->constrained('shifts')->nullOnDelete();
            $table->foreignId('exit_shift_id')->nullable()->after('entry_shift_id')->constrained('shifts')->nullOnDelete();
        });

        // Existing tickets: assign them by the time-window rule shift totals used until now, so
        // past shifts keep exactly the numbers they were closed with. If two windows of the same
        // operator touch at a boundary second, the earlier shift wins.
        $tickets = DB::table('parking_sessions')->get(['id', 'entry_user_id', 'exit_user_id', 'entered_at', 'exited_at', 'status']);

        foreach ($tickets as $ticket) {
            $updates = [];

            if ($ticket->entry_user_id !== null) {
                $updates['entry_shift_id'] = $this->shiftCovering($ticket->entry_user_id, $ticket->entered_at);
            }

            if ($ticket->exit_user_id !== null && $ticket->exited_at !== null && in_array($ticket->status, ['paid', 'lost'], true)) {
                $updates['exit_shift_id'] = $this->shiftCovering($ticket->exit_user_id, $ticket->exited_at);
            }

            if (array_filter($updates) !== []) {
                DB::table('parking_sessions')->where('id', $ticket->id)->update($updates);
            }
        }
    }

    public function down(): void
    {
        Schema::table('parking_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('exit_shift_id');
            $table->dropConstrainedForeignId('entry_shift_id');
        });
    }

    private function shiftCovering(int $userId, string $at): ?int
    {
        return DB::table('shifts')
            ->where('user_id', $userId)
            ->where('opened_at', '<=', $at)
            ->where(fn ($query) => $query->whereNull('closed_at')->orWhere('closed_at', '>=', $at))
            ->orderBy('id')
            ->value('id');
    }
};
