<?php

declare(strict_types=1);

namespace Odden\Marketing\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Core\Models\Contact;
use Odden\Marketing\Models\NpsResponse;
use Odden\Marketing\Models\NpsSurvey;

class NpsAccuracyTest extends TestCase
{
    use RefreshDatabase;

    private function survey(): NpsSurvey
    {
        return NpsSurvey::create(['name' => 'Onboarding', 'is_active' => true]);
    }

    private function contact(string $name, int $leadScore = 50): Contact
    {
        return Contact::create(['first_name' => $name, 'email' => strtolower($name).'@example.com', 'lead_score' => $leadScore]);
    }

    public function test_recipients_who_never_answered_are_not_part_of_the_score(): void
    {
        $survey = $this->survey();

        $promoter = NpsResponse::createForContact($survey, $this->contact('Pat'));
        foreach (['Ann', 'Bob', 'Cy', 'Di', 'Ed', 'Flo', 'Gus', 'Hal', 'Ivy'] as $name) {
            NpsResponse::createForContact($survey, $this->contact($name));
        }

        $this->get(route('odden.marketing.nps.rate', ['token' => $promoter->token, 'score' => 10]))->assertOk();

        // 10 surveys went out and one person answered, as a promoter: that is 100, not 10.
        $this->assertSame(100, $survey->calculateNpsScore());
    }

    public function test_an_amp_rating_is_categorised_and_scored_like_an_email_link_rating(): void
    {
        $survey = $this->survey();
        $contact = $this->contact('Dee', 50);
        $nps = NpsResponse::createForContact($survey, $contact);

        $this->withHeaders(['Origin' => 'https://mail.google.com', 'AMP-Email-Sender' => 'surveys@odden.test'])
            ->postJson(route('odden.marketing.amp.feedback'), ['token' => $nps->token, 'score' => 2, 'feedback' => 'Slow'])
            ->assertOk();

        $nps->refresh();
        $this->assertSame('detractor', $nps->category);
        $this->assertSame('Slow', $nps->feedback);
        $this->assertSame(-100, $survey->calculateNpsScore());
        $this->assertSame(40, $contact->fresh()?->lead_score);
        $this->assertSame('detractor', $contact->fresh()?->properties['nps_sentiment']);
    }

    public function test_a_later_visit_to_a_rating_link_does_not_overwrite_the_answer(): void
    {
        $survey = $this->survey();
        $nps = NpsResponse::createForContact($survey, $this->contact('Sam'));

        $this->get(route('odden.marketing.nps.rate', ['token' => $nps->token, 'score' => 9]))->assertOk();
        // A mail scanner (or prefetcher) opens every link in the message, including the other scores.
        $this->get(route('odden.marketing.nps.rate', ['token' => $nps->token, 'score' => 0]))->assertOk();

        $this->assertSame(9, $nps->fresh()?->score);
        $this->assertSame('promoter', $nps->fresh()?->category);
    }

    public function test_an_amp_retry_keeps_the_first_rating_but_accepts_comments(): void
    {
        $survey = $this->survey();
        $nps = NpsResponse::createForContact($survey, $this->contact('Rae'));
        $headers = ['Origin' => 'https://mail.google.com', 'AMP-Email-Sender' => 'surveys@odden.test'];

        $this->withHeaders($headers)->postJson(route('odden.marketing.amp.feedback'), ['token' => $nps->token, 'score' => 10])->assertOk();
        $this->withHeaders($headers)->postJson(route('odden.marketing.amp.feedback'), ['token' => $nps->token, 'score' => 1, 'feedback' => 'Great'])->assertOk();

        $this->assertSame(10, $nps->fresh()?->score);
        $this->assertSame('Great', $nps->fresh()?->feedback);
    }
}
