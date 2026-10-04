<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $ticketsTable = config('odden-service.tables.tickets', 'odden_service_tickets');

        // When the ticket started waiting on the customer: the resolution clock is paused from then, and the due
        // date moves out by the time spent waiting when the customer answers.
        Schema::table($ticketsTable, function (Blueprint $table): void {
            $table->timestamp('sla_paused_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $ticketsTable = config('odden-service.tables.tickets', 'odden_service_tickets');

        Schema::table($ticketsTable, function (Blueprint $table): void {
            $table->dropColumn('sla_paused_at');
        });
    }
};
