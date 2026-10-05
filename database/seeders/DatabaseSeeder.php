<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Odden\Core\Enums\ListType;
use Odden\Core\Enums\PropertyType;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Models\CrmList;
use Odden\Core\Models\PropertyDefinition;
use Odden\Sales\Database\Seeders\SalesDatabaseSeeder;
use Odden\Sales\Enums\DealStatus;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\DealStageHistory;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\PipelineStage;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@odden.test'],
            [
                'name' => 'Odden Admin',
                'password' => bcrypt('password'),
            ]
        );

        // Seed sample custom property definitions
        PropertyDefinition::firstOrCreate(
            ['entity_type' => 'contact', 'name' => 'lead_score'],
            [
                'label' => 'Lead Score',
                'type' => PropertyType::Number,
                'group_name' => 'qualification',
                'is_searchable' => true,
            ]
        );

        PropertyDefinition::firstOrCreate(
            ['entity_type' => 'company', 'name' => 'tier'],
            [
                'label' => 'Account Tier',
                'type' => PropertyType::Select,
                'group_name' => 'classification',
                'options' => [
                    'starter' => 'Starter',
                    'growth' => 'Growth',
                    'enterprise' => 'Enterprise',
                ],
                'is_searchable' => true,
            ]
        );

        // Seed companies
        $companies = Company::factory()->count(5)->create([
            'owner_id' => $admin->id,
        ]);

        // Seed contacts and associate them with companies
        $contacts = Contact::factory()->count(10)->create([
            'owner_id' => $admin->id,
        ]);

        foreach ($contacts as $index => $contact) {
            $company = $companies->random();
            $contact->associateWith($company, $index % 2 === 0 ? 'primary' : 'billing');

            $contact->logNote('Initial intake call completed. Customer requested proposal.');
            $contact->logCall('Discovery Discussion', 'Covered budget, timeline, and security requirements.', [
                'duration_seconds' => 420,
            ]);
            $contact->logTask('Send follow-up contract and schedule kickoff', now()->addDays(2));
        }

        // Seed sample lists
        $leadsList = CrmList::firstOrCreate(
            ['name' => 'Active Leads'],
            [
                'description' => 'Dynamic list of all contacts in Lead stage',
                'entity_type' => (new Contact)->getMorphClass(),
                'type' => ListType::Active,
                'criteria' => [
                    [
                        'field' => 'lifecycle_stage',
                        'operator' => 'equals',
                        'value' => 'lead',
                    ],
                ],
                'created_by_id' => $admin->id,
            ]
        );
        $leadsList->syncActiveMembers();

        $vipCompaniesList = CrmList::firstOrCreate(
            ['name' => 'Target Accounts'],
            [
                'description' => 'Curated static list of high-priority accounts',
                'entity_type' => (new Company)->getMorphClass(),
                'type' => ListType::Static,
                'created_by_id' => $admin->id,
            ]
        );

        foreach ($companies->take(3) as $vipCompany) {
            $vipCompaniesList->addMember($vipCompany);
        }

        // Seed Sales Pipeline & Stages
        $pipeline = Pipeline::firstOrCreate(
            ['code' => 'direct_sales'],
            [
                'name' => 'Direct Sales Pipeline',
                'is_default' => true,
                'is_active' => true,
            ]
        );

        $stagesConfig = [
            ['name' => 'Discovery', 'code' => 'discovery', 'probability' => 20, 'sort_order' => 1, 'is_closed_won' => false, 'is_closed_lost' => false],
            ['name' => 'Qualification', 'code' => 'qualification', 'probability' => 40, 'sort_order' => 2, 'is_closed_won' => false, 'is_closed_lost' => false],
            ['name' => 'Proposal Sent', 'code' => 'proposal_sent', 'probability' => 60, 'sort_order' => 3, 'is_closed_won' => false, 'is_closed_lost' => false],
            ['name' => 'Negotiation', 'code' => 'negotiation', 'probability' => 80, 'sort_order' => 4, 'is_closed_won' => false, 'is_closed_lost' => false],
            ['name' => 'Closed Won', 'code' => 'closed_won', 'probability' => 100, 'sort_order' => 5, 'is_closed_won' => true, 'is_closed_lost' => false],
            ['name' => 'Closed Lost', 'code' => 'closed_lost', 'probability' => 0, 'sort_order' => 6, 'is_closed_won' => false, 'is_closed_lost' => true],
        ];

        $stages = [];
        foreach ($stagesConfig as $sc) {
            $stages[] = PipelineStage::firstOrCreate(
                ['pipeline_id' => $pipeline->id, 'code' => $sc['code']],
                $sc
            );
        }

        // Seed Sample Deals across stages
        $dealBlueprints = [
            ['name' => 'Cyberdyne Systems - Enterprise AI Cloud', 'amount' => 125000.00, 'stage_index' => 0],
            ['name' => 'Initech - Automated Reporting License', 'amount' => 38000.00, 'stage_index' => 1],
            ['name' => 'Wayne Enterprises - Global Security Stack', 'amount' => 250000.00, 'stage_index' => 2],
            ['name' => 'Stark Industries - ARC Cluster Expansion', 'amount' => 420000.00, 'stage_index' => 3],
            ['name' => 'Acme Corporation - 3-Year Enterprise SLA', 'amount' => 95000.00, 'stage_index' => 4],
            ['name' => 'Umbrella Health - Pilot Agreement', 'amount' => 45000.00, 'stage_index' => 5],
        ];

        foreach ($dealBlueprints as $idx => $blueprint) {
            $stage = $stages[$blueprint['stage_index']];
            $status = $stage->is_closed_won ? DealStatus::Won : ($stage->is_closed_lost ? DealStatus::Lost : DealStatus::Open);

            $deal = Deal::firstOrCreate(
                ['name' => $blueprint['name']],
                [
                    'pipeline_id' => $pipeline->id,
                    'stage_id' => $stage->id,
                    'amount' => $blueprint['amount'],
                    'currency' => 'USD',
                    'status' => $status,
                    'expected_close_date' => now()->addDays(15 * ($idx + 1)),
                    'closed_at' => $stage->isClosed() ? now() : null,
                    'lost_reason' => $stage->is_closed_lost ? 'Competitor discount matched' : null,
                    'owner_id' => $admin->id,
                ]
            );

            // Record initial stage history
            DealStageHistory::firstOrCreate(
                ['deal_id' => $deal->id, 'to_stage_id' => $stage->id],
                [
                    'from_stage_id' => null,
                    'user_id' => $admin->id,
                    'entered_at' => now()->subDays(5),
                ]
            );

            // Associate with a company and contact
            $deal->associateWith($companies[$idx % count($companies)], 'primary_company');
            $deal->associateWith($contacts[$idx % count($contacts)], 'primary_contact');

            $deal->logNote('Executive sponsor confirmed alignment on scope.');
        }

        $this->call(SalesDatabaseSeeder::class);
    }
}
