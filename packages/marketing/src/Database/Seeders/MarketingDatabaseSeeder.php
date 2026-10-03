<?php

declare(strict_types=1);

namespace Odden\Marketing\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Models\Contact;
use Odden\Marketing\Enums\CampaignStatus;
use Odden\Marketing\Enums\CampaignType;
use Odden\Marketing\Enums\LeadScoringEventType;
use Odden\Marketing\Enums\RecipientStatus;
use Odden\Marketing\Enums\WorkflowStepType;
use Odden\Marketing\Enums\WorkflowTriggerType;
use Odden\Marketing\Models\Campaign;
use Odden\Marketing\Models\CampaignRecipient;
use Odden\Marketing\Models\EspEvent;
use Odden\Marketing\Models\FormSubmission;
use Odden\Marketing\Models\LandingPage;
use Odden\Marketing\Models\LeadScoringRule;
use Odden\Marketing\Models\MarketingForm;
use Odden\Marketing\Models\MarketingSubscriptionTopic;
use Odden\Marketing\Models\MarketingTemplate;
use Odden\Marketing\Models\MarketingWorkflow;

class MarketingDatabaseSeeder extends Seeder
{
    /**
     * Seed email templates, lead capture forms, scoring rules, drip workflows, and demo campaigns.
     */
    public function run(): void
    {
        // 0. Preference center topics
        MarketingSubscriptionTopic::seedDefaults();

        // 1. Email Templates
        $newsletterTemplate = MarketingTemplate::firstOrCreate(
            ['name' => 'Monthly Product Digest & Spotlight'],
            [
                'subject' => '{{contact.first_name}}, see what is new in Odden this month',
                'preview_text' => 'Major updates to Sales pipelines, Service SLAs, and Marketing automations.',
                'category' => 'newsletter',
                'body_html' => '<html><body style="font-family: Arial, sans-serif; color: #333; line-height: 1.6; max-width: 600px; margin: 0 auto; padding: 20px;">'
                    .'<h2 style="color: #0284c7;">Odden Monthly Product Digest</h2>'
                    .'<p>Hi {{contact.first_name}},</p>'
                    .'<p>We are thrilled to share the latest enhancements shipped for {{company.name}} to accelerate deal velocity and streamline customer care.</p>'
                    .'<div style="background: #f8fafc; border-left: 4px solid #0284c7; padding: 15px; margin: 20px 0;">'
                    .'<h3 style="margin-top: 0;">Featured Capabilities</h3>'
                    .'<ul><li>Enterprise Lead & Deal Prospecting Cockpit</li><li>Service Hub with Inbound Email Threading</li><li>Multi-channel Campaign Broadcasts</li></ul>'
                    .'</div>'
                    .'<p><a href="https://odden.test/demo" style="background: #0284c7; color: #ffffff; padding: 10px 20px; text-decoration: none; border-radius: 5px; font-weight: bold; display: inline-block;">Explore the New Features</a></p>'
                    .'<hr style="border: none; border-top: 1px solid #e2e8f0; margin: 30px 0;" />'
                    .'<p style="font-size: 12px; color: #64748b;">You received this email because you are a valued customer of Odden. <a href="{{unsubscribe_url}}" style="color: #64748b;">Unsubscribe</a></p>'
                    .'</body></html>',
                'body_text' => "Odden Monthly Product Digest\n\nHi {{contact.first_name}},\n\nCheck out our latest product updates at https://odden.test/demo\n\nUnsubscribe: {{unsubscribe_url}}",
            ]
        );

        $webinarTemplate = MarketingTemplate::firstOrCreate(
            ['name' => 'VIP Enterprise Webinar Invitation'],
            [
                'subject' => 'Exclusive Invitation: Scaling Revenue Operations with Modern CRM',
                'preview_text' => 'Join our live executive session with industry leaders.',
                'category' => 'event',
                'body_html' => '<html><body style="font-family: Arial, sans-serif; color: #333; padding: 20px;">'
                    .'<h2>Exclusive Executive Webinar</h2>'
                    .'<p>Hello {{contact.first_name}},</p>'
                    .'<p>Join us on Thursday at 10 AM PST for an intimate briefing on optimizing your sales pipeline and minimizing customer churn.</p>'
                    .'<p><a href="https://odden.test/webinar/register">Reserve Your Seat Today</a></p>'
                    .'<p style="font-size: 11px; color: #999;"><a href="{{unsubscribe_url}}">Unsubscribe</a></p>'
                    .'</body></html>',
                'body_text' => "Join our webinar at https://odden.test/webinar/register\n\nUnsubscribe: {{unsubscribe_url}}",
            ]
        );

        // 2. Lead Capture Forms
        $demoForm = MarketingForm::firstOrCreate(
            ['slug' => 'request-demo'],
            [
                'title' => 'Request an Enterprise Consultation & Demo',
                'description' => 'Fill out this brief form to see Odden in action with a custom walkthrough tailored to your business.',
                'fields_schema' => [
                    ['name' => 'first_name', 'label' => 'First Name', 'type' => 'text', 'required' => true],
                    ['name' => 'last_name', 'label' => 'Last Name', 'type' => 'text', 'required' => true],
                    ['name' => 'email', 'label' => 'Work Email', 'type' => 'email', 'required' => true],
                    ['name' => 'company', 'label' => 'Company Name', 'type' => 'text', 'required' => true],
                    ['name' => 'phone', 'label' => 'Direct Phone', 'type' => 'text', 'required' => false],
                    ['name' => 'notes', 'label' => 'Project Requirements', 'type' => 'textarea', 'required' => false],
                ],
                'submit_button_text' => 'Book My Demo',
                'success_message' => 'Thank you! An enterprise account executive will contact you shortly.',
                'is_active' => true,
                'submissions_count' => 14,
            ]
        );

        $newsletterForm = MarketingForm::firstOrCreate(
            ['slug' => 'newsletter-signup'],
            [
                'title' => 'Weekly Growth & RevOps Newsletter',
                'description' => 'Join 12,000+ revenue leaders receiving our weekly playbook on CRM best practices.',
                'fields_schema' => [
                    ['name' => 'first_name', 'label' => 'First Name', 'type' => 'text', 'required' => false],
                    ['name' => 'email', 'label' => 'Email Address', 'type' => 'email', 'required' => true],
                ],
                'submit_button_text' => 'Subscribe for Free',
                'success_message' => 'You are subscribed! Check your inbox for our welcome kit.',
                'is_active' => true,
                'submissions_count' => 38,
            ]
        );

        // 3. Demo Broadcast Campaigns
        $campaign1 = Campaign::firstOrCreate(
            ['name' => 'Odden 2.0 Major Ecosystem Announcement'],
            [
                'subject' => 'Introducing Odden 2.0: Unified Sales, Service, and Marketing',
                'preview_text' => 'Discover the next generation CRM platform built for speed.',
                'sender_name' => 'Odden Leadership',
                'sender_email' => 'announcements@odden.test',
                'reply_to_email' => 'support@odden.test',
                'template_id' => $newsletterTemplate->id,
                'status' => CampaignStatus::Sent,
                'type' => CampaignType::Regular,
                'topic' => 'product_updates',
                'budget' => 5000.00,
                'actual_cost' => 3200.00,
                'scheduled_at' => now()->subDays(5),
                'sent_at' => now()->subDays(5),
                'total_recipients' => 120,
                'delivered_count' => 118,
                'opens_count' => 64,
                'unique_opens_count' => 52,
                'clicks_count' => 28,
                'unique_clicks_count' => 22,
                'bounces_count' => 2,
                'unsubscribes_count' => 1,
            ]
        );

        $campaign2 = Campaign::firstOrCreate(
            ['name' => 'Q4 RevOps Executive Webinar'],
            [
                'subject' => 'VIP Invitation: Optimizing Your Pipeline with Odden',
                'preview_text' => 'Join industry leaders this Thursday at 10 AM PST.',
                'sender_name' => 'Odden Events',
                'sender_email' => 'events@odden.test',
                'template_id' => $webinarTemplate->id,
                'status' => CampaignStatus::Sent,
                'type' => CampaignType::Regular,
                'topic' => 'webinars',
                'budget' => 2500.00,
                'actual_cost' => 1850.00,
                'scheduled_at' => now()->subDays(2),
                'sent_at' => now()->subDays(2),
                'total_recipients' => 85,
                'delivered_count' => 84,
                'opens_count' => 45,
                'unique_opens_count' => 39,
                'clicks_count' => 19,
                'unique_clicks_count' => 16,
                'bounces_count' => 1,
                'unsubscribes_count' => 0,
            ]
        );

        $campaign3 = Campaign::firstOrCreate(
            ['name' => 'Upcoming Holiday Security Advisory'],
            [
                'subject' => 'Best practices for securing your customer data',
                'preview_text' => 'Essential checklists before the holiday freeze.',
                'sender_name' => 'Odden Security Team',
                'sender_email' => 'security@odden.test',
                'template_id' => $newsletterTemplate->id,
                'status' => CampaignStatus::Draft,
                'type' => CampaignType::Regular,
                'topic' => 'security',
                'budget' => 1000.00,
                'actual_cost' => 450.00,
                'total_recipients' => 0,
                'delivered_count' => 0,
            ]
        );

        // 4. Seed sample form submissions
        $contacts = Contact::query()->take(3)->get();
        foreach ($contacts as $contact) {
            FormSubmission::firstOrCreate(
                [
                    'form_id' => $demoForm->id,
                    'contact_id' => $contact->id,
                ],
                [
                    'form_data' => [
                        'first_name' => $contact->first_name,
                        'last_name' => $contact->last_name,
                        'email' => $contact->email,
                        'company' => 'Acme Corporation',
                        'notes' => 'Interested in upgrading from our current solution.',
                    ],
                    'ip_address' => '127.0.0.1',
                    'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
                ]
            );

            // Sample recipient tracking
            CampaignRecipient::firstOrCreate(
                [
                    'campaign_id' => $campaign1->id,
                    'email' => $contact->email,
                ],
                [
                    'contact_id' => $contact->id,
                    'status' => RecipientStatus::Opened,
                    'tracking_token' => Str::random(40),
                    'unsubscribe_token' => Str::random(40),
                    'sent_at' => now()->subDays(5),
                    'opened_at' => now()->subDays(5)->addMinutes(12),
                ]
            );
        }

        // 5. Automated Lead Scoring Rules
        $rules = [
            [
                'name' => 'Form Submission',
                'description' => 'Points awarded when a contact submits any lead capture or contact form.',
                'event_type' => LeadScoringEventType::FormSubmission,
                'score_change' => 15,
                'is_active' => true,
            ],
            [
                'name' => 'Marketing Email Opened',
                'description' => 'Points awarded when an email recipient opens a broadcast or drip email.',
                'event_type' => LeadScoringEventType::EmailOpened,
                'score_change' => 3,
                'is_active' => true,
            ],
            [
                'name' => 'Email Call-to-Action Clicked',
                'description' => 'High engagement indicator when a recipient clicks a tracked link.',
                'event_type' => LeadScoringEventType::EmailClicked,
                'score_change' => 10,
                'is_active' => true,
            ],
            [
                'name' => 'High Intent ICP Fit',
                'description' => 'Bonus points for matching target account enterprise criteria.',
                'event_type' => LeadScoringEventType::PropertyMatch,
                'score_change' => 25,
                'is_active' => true,
            ],
            [
                'name' => 'Campaign Unsubscribed',
                'description' => 'Severe penalty deduction when a contact opts out.',
                'event_type' => LeadScoringEventType::Unsubscribed,
                'score_change' => -50,
                'is_active' => true,
            ],
        ];

        foreach ($rules as $ruleData) {
            LeadScoringRule::firstOrCreate(
                ['name' => $ruleData['name']],
                $ruleData
            );
        }

        // 6. Automated Drip Workflows & Nurture Journeys
        $inboundWorkflow = MarketingWorkflow::firstOrCreate(
            ['name' => 'Enterprise Demo Inbound Nurture Journey'],
            [
                'description' => 'Automated follow-up drip triggered upon submitting the Enterprise Demo form.',
                'trigger_type' => WorkflowTriggerType::FormSubmitted,
                'trigger_config' => ['form_id' => $demoForm->id],
                'is_active' => true,
                'enrollments_count' => 14,
                'completed_count' => 8,
            ]
        );

        if ($inboundWorkflow->steps()->count() === 0) {
            $inboundWorkflow->steps()->createMany([
                [
                    'step_number' => 1,
                    'type' => WorkflowStepType::SendEmail,
                    'config' => [
                        'template_id' => $newsletterTemplate->id,
                        'subject' => 'Thank you for requesting a demo with Odden',
                        'body' => '<p>Hello {{contact.first_name}}, our enterprise team is reviewing your requirements and will reach out shortly.</p>',
                    ],
                ],
                [
                    'step_number' => 2,
                    'type' => WorkflowStepType::Delay,
                    'config' => [
                        'delay_minutes' => 2880, // 2 days
                    ],
                ],
                [
                    'step_number' => 3,
                    'type' => WorkflowStepType::Condition,
                    'config' => [
                        'property' => 'lead_score',
                        'operator' => '>=',
                        'value' => 50,
                    ],
                    'next_step_on_true' => 4,
                    'next_step_on_false' => null,
                ],
                [
                    'step_number' => 4,
                    'type' => WorkflowStepType::UpdateContact,
                    'config' => [
                        'lifecycle_stage' => LifecycleStage::MarketingQualifiedLead->value,
                        'next_step' => null,
                    ],
                ],
            ]);
        }

        // 7. A/B Split Test Campaign
        Campaign::firstOrCreate(
            ['name' => 'Q1 Subject Line Optimization: ROI vs Speed'],
            [
                'subject' => 'Variant A: Unlock 10x ROI with Odden 2.0',
                'preview_text' => 'Discover how modern teams accelerate deal closure.',
                'sender_name' => 'Odden Growth Team',
                'sender_email' => 'growth@odden.test',
                'template_id' => $newsletterTemplate->id,
                'is_ab_test' => true,
                'variant_b_subject' => 'Variant B: See how fast-growing teams close more deals',
                'variant_b_template_id' => $webinarTemplate->id,
                'ab_test_sample_percentage' => 30,
                'ab_test_duration_hours' => 6,
                'ab_winning_metric' => 'click_rate',
                'status' => CampaignStatus::Draft,
                'type' => CampaignType::Regular,
                'total_recipients' => 0,
                'delivered_count' => 0,
            ]
        );

        // 8. Hosted Public Landing Page
        LandingPage::firstOrCreate(
            ['slug' => 'enterprise-revops-platform'],
            [
                'title' => 'Enterprise RevOps Platform 2026',
                'headline' => 'Scale Revenue Faster With Unified CRM & Care',
                'subheadline' => 'Connect Sales, Service SLAs, and Lifecycle Automations seamlessly.',
                'body_content' => '<p>Odden unifies your customer records across all touchpoints with sub-millisecond response times.</p>',
                'form_id' => $demoForm->id,
                'meta_title' => 'Enterprise RevOps Platform | Odden',
                'meta_description' => 'Experience unified CRM architecture built for speed and velocity.',
                'is_published' => true,
                'views_count' => 24,
                'submissions_count' => 5,
                'published_at' => now()->subDays(7),
            ]
        );

        // 9. ESP Deliverability Event Log
        EspEvent::firstOrCreate(
            [
                'provider' => 'mailgun',
                'event_type' => 'delivered',
                'email' => 'admin@odden.test',
            ],
            [
                'campaign_id' => $campaign1->id,
                'error_code' => '250',
                'error_message' => '2.0.0 OK: Message accepted for delivery',
                'created_at' => now()->subDays(5),
            ]
        );
    }
}
