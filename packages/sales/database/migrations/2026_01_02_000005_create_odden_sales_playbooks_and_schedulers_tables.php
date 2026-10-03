<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Odden\Core\Support\UserModel;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $playbooksTable = config('odden-sales.tables.playbooks', 'odden_sales_playbooks');
        $meetingLinksTable = config('odden-sales.tables.meeting_links', 'odden_sales_meeting_links');
        $routingRulesTable = config('odden-sales.tables.lead_routing_rules', 'odden_sales_lead_routing_rules');

        if (! Schema::hasTable($playbooksTable)) {
            Schema::create($playbooksTable, function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->string('category')->default('qualification'); // qualification, objection_handling, discovery
                $table->string('framework')->default('custom'); // bant, meddic, battlecard, custom
                $table->text('description')->nullable();
                $table->json('questions');
                $table->boolean('is_active')->default(true);
                $table->foreignIdFor(UserModel::className(), 'user_id')->nullable()->constrained()->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable($meetingLinksTable)) {
            Schema::create($meetingLinksTable, function (Blueprint $table): void {
                $table->id();
                $table->foreignIdFor(UserModel::className(), 'user_id')->constrained()->cascadeOnDelete();
                $table->string('slug')->unique();
                $table->string('title')->default('30 Min Meeting');
                $table->integer('duration_minutes')->default(30);
                $table->text('description')->nullable();
                $table->json('working_hours')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable($routingRulesTable)) {
            Schema::create($routingRulesTable, function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('strategy')->default('round_robin'); // round_robin, quota_weighted, territory
                $table->json('criteria')->nullable();
                $table->json('assigned_user_ids');
                $table->integer('last_assigned_index')->default(-1);
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(config('odden-sales.tables.lead_routing_rules', 'odden_sales_lead_routing_rules'));
        Schema::dropIfExists(config('odden-sales.tables.meeting_links', 'odden_sales_meeting_links'));
        Schema::dropIfExists(config('odden-sales.tables.playbooks', 'odden_sales_playbooks'));
    }
};
