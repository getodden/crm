<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Double opt-in gets its own token, so the confirmation link and the preference center link
     * (marketing_verification_token) are no longer interchangeable.
     */
    public function up(): void
    {
        $contactsTable = config('odden-core.tables.contacts', 'odden_contacts');

        if (Schema::hasColumn($contactsTable, 'marketing_confirmation_token')) {
            return;
        }

        Schema::table($contactsTable, function (Blueprint $table): void {
            $table->string('marketing_confirmation_token', 64)->nullable()->index()->after('marketing_verification_token');
        });
    }

    public function down(): void
    {
        $contactsTable = config('odden-core.tables.contacts', 'odden_contacts');

        if (! Schema::hasColumn($contactsTable, 'marketing_confirmation_token')) {
            return;
        }

        Schema::table($contactsTable, function (Blueprint $table): void {
            $table->dropColumn('marketing_confirmation_token');
        });
    }
};
