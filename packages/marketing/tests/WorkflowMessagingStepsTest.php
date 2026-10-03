<?php

declare(strict_types=1);

namespace Odden\Marketing\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Odden\Core\Models\Contact;
use Odden\Marketing\Actions\EnrollContactInWorkflowAction;
use Odden\Marketing\Actions\ExecuteWorkflowStepAction;
use Odden\Marketing\Actions\ProcessDueWorkflowsAction;
use Odden\Marketing\Enums\WorkflowEnrollmentStatus;
use Odden\Marketing\Enums\WorkflowStepType;
use Odden\Marketing\Enums\WorkflowTriggerType;
use Odden\Marketing\Mail\MarketingMessageMailable;
use Odden\Marketing\Models\EmailSuppression;
use Odden\Marketing\Models\MarketingSubscription;
use Odden\Marketing\Models\MarketingTemplate;
use Odden\Marketing\Models\MarketingWorkflow;
use Odden\Marketing\Models\WorkflowEnrollment;
use Odden\Marketing\Models\WorkflowLog;
use Odden\Marketing\Support\ContactPreferences;
use Odden\Marketing\Tests\Fixtures\User;

/**
 * #2: the send_email step queues a message through the campaign delivery path.
 * #3: the send_sms step must not fall through into assign_owner.
 */
class WorkflowMessagingStepsTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_email_step_queues_the_rendered_message_to_the_contact(): void
    {
        Mail::fake();
        config(['odden-marketing.mail.queue' => 'marketing-mail']);

        $contact = Contact::create(['first_name' => 'Linus', 'email' => 'linus@example.com']);
        $template = MarketingTemplate::create([
            'name' => 'Welcome',
            'subject' => 'Template subject',
            'body_html' => '<p>Hello {{contact.first_name}}, <a href="{{unsubscribe_url}}">manage preferences</a></p>',
        ]);

        $enrollment = $this->enroll($contact, WorkflowStepType::SendEmail, [
            'template_id' => $template->id,
            'subject' => 'Welcome, {{contact.first_name}}',
        ]);

        Mail::assertQueuedCount(1);
        Mail::assertQueued(MarketingMessageMailable::class, function (MarketingMessageMailable $mail) use ($contact): bool {
            $headers = $mail->headers()->text;

            $this->assertSame('marketing-mail', $mail->queue);
            $this->assertTrue($mail->hasSubject('Welcome, Linus'));
            $this->assertTrue($mail->hasFrom('newsletter@odden.test'));
            $this->assertStringContainsString('Hello Linus', $mail->htmlBody);
            $this->assertStringContainsString('Hello Linus', $mail->textBody);
            $this->assertSame('<'.ContactPreferences::preferenceCenterUrl($contact->fresh()).'>', $headers['List-Unsubscribe']);
            $this->assertArrayNotHasKey('List-Unsubscribe-Post', $headers);

            return $mail->hasTo('linus@example.com');
        });

        $log = $enrollment->logs()->firstOrFail();
        $this->assertSame('success', $log->status);
        $this->assertStringContainsString('Sent email: Welcome, Linus', $log->action_taken);
    }

    public function test_a_step_executed_twice_concurrently_sends_its_email_once(): void
    {
        // Two workers (the scheduler and the inline run after enrolment, or two overlapping
        // scheduler runs) load the same due enrollment before either has advanced it.
        Mail::fake();

        $contact = Contact::create(['first_name' => 'Ada', 'email' => 'ada@example.com']);
        $workflow = MarketingWorkflow::create(['name' => 'Race', 'trigger_type' => WorkflowTriggerType::Manual, 'is_active' => true]);
        $step = $workflow->steps()->create([
            'step_number' => 1,
            'name' => 'Welcome',
            'type' => WorkflowStepType::SendEmail,
            'config' => ['body' => '<p>Hi</p>', 'subject' => 'Welcome'],
        ]);
        $enrollment = WorkflowEnrollment::create([
            'workflow_id' => $workflow->id,
            'contact_id' => $contact->id,
            'current_step_id' => $step->id,
            'status' => WorkflowEnrollmentStatus::Active,
            'next_run_at' => now(),
            'enrolled_at' => now(),
        ]);

        $first = WorkflowEnrollment::query()->findOrFail($enrollment->id);
        $second = WorkflowEnrollment::query()->findOrFail($enrollment->id);

        app(ExecuteWorkflowStepAction::class)->execute($first);
        app(ExecuteWorkflowStepAction::class)->execute($second);

        Mail::assertQueuedCount(1);
        $this->assertSame(1, WorkflowLog::query()->where('enrollment_id', $enrollment->id)->count());
        $this->assertSame(1, (int) $workflow->fresh()?->completed_count);
        $this->assertSame(WorkflowEnrollmentStatus::Completed, $enrollment->fresh()?->status);
    }

    public function test_the_scheduler_does_not_resend_a_step_already_run_on_enrolment(): void
    {
        Mail::fake();

        $contact = Contact::create(['first_name' => 'Ada', 'email' => 'ada@example.com']);
        $workflow = MarketingWorkflow::create(['name' => 'Race', 'trigger_type' => WorkflowTriggerType::Manual, 'is_active' => true]);
        $workflow->steps()->create(['step_number' => 1, 'name' => 'Welcome', 'type' => WorkflowStepType::SendEmail, 'config' => ['body' => '<p>Hi</p>', 'subject' => 'Welcome']]);
        $workflow->steps()->create(['step_number' => 2, 'name' => 'Wait', 'type' => WorkflowStepType::Delay, 'config' => ['delay_minutes' => 60]]);
        $workflow->steps()->create(['step_number' => 3, 'name' => 'Follow-up', 'type' => WorkflowStepType::SendEmail, 'config' => ['body' => '<p>Again</p>', 'subject' => 'Follow-up']]);

        $enrollment = app(EnrollContactInWorkflowAction::class)->execute($workflow, $contact);
        $this->assertNotNull($enrollment);

        app(ProcessDueWorkflowsAction::class)->execute();
        $this->travel(61)->minutes();
        app(ProcessDueWorkflowsAction::class)->execute();
        app(ProcessDueWorkflowsAction::class)->execute();

        Mail::assertQueuedCount(2);
        $this->assertSame(WorkflowEnrollmentStatus::Completed, $enrollment->fresh()?->status);
    }

    public function test_send_email_step_skips_unsubscribed_and_suppressed_contacts(): void
    {
        Mail::fake();

        $unsubscribed = Contact::create(['first_name' => 'Opted', 'email' => 'opted@example.com']);
        MarketingSubscription::unsubscribe('opted@example.com', $unsubscribed->id);

        $bounced = Contact::create(['first_name' => 'Bounced', 'email' => 'bounced@example.com']);
        EmailSuppression::suppress('bounced@example.com', 'hard_bounce');

        foreach ([$unsubscribed, $bounced] as $contact) {
            $enrollment = $this->enroll($contact, WorkflowStepType::SendEmail, ['body' => '<p>Hi</p>', 'subject' => 'Hi']);

            $log = $enrollment->logs()->firstOrFail();
            $this->assertSame('skipped', $log->status);
            $this->assertStringContainsString('Skipped email', $log->action_taken);
            $this->assertDatabaseMissing('odden_activities', ['subject_id' => $contact->id, 'title' => 'Workflow Email: Hi']);
        }

        Mail::assertNothingQueued();
    }

    public function test_send_sms_step_does_not_fall_through_into_assign_owner(): void
    {
        $owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'secret']);

        $contact = Contact::create([
            'first_name' => 'Grace',
            'email' => 'grace@example.com',
            'phone' => '+12025550192',
            'sms_consent' => true,
            'sms_consent_at' => now(),
        ]);

        $enrollment = $this->enroll($contact, WorkflowStepType::SendSms, ['message' => 'Hello', 'requires_consent' => true]);

        $this->assertNull($contact->fresh()?->owner_id, "The SMS step must not assign owner #{$owner->getKey()}.");
        $this->assertCount(1, $enrollment->logs()->get());
        $this->assertStringContainsString('Dispatched SMS', $enrollment->logs()->firstOrFail()->action_taken);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function enroll(Contact $contact, WorkflowStepType $type, array $config): WorkflowEnrollment
    {
        $workflow = MarketingWorkflow::create([
            'name' => 'Flow '.$type->value.' '.$contact->id,
            'trigger_type' => WorkflowTriggerType::Manual,
            'is_active' => true,
        ]);

        $workflow->steps()->create([
            'step_number' => 1,
            'name' => 'Step',
            'type' => $type,
            'config' => $config,
        ]);

        $enrollment = app(EnrollContactInWorkflowAction::class)->execute($workflow, $contact);
        $this->assertNotNull($enrollment);

        return $enrollment;
    }
}
