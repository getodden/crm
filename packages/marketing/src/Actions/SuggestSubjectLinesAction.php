<?php

declare(strict_types=1);

namespace Odden\Marketing\Actions;

class SuggestSubjectLinesAction
{
    /**
     * Suggest subject lines and a Variant B candidate from fixed phrase templates for the tone.
     * No model is involved: the same topic, tone and audience always give the same lines.
     *
     * @return array{
     *     suggestions: list<string>,
     *     variant_b: string,
     *     preview_text: string,
     *     rationale: string
     * }
     */
    public function execute(string $topic, string $tone = 'engaging', ?string $audience = null): array
    {
        $cleanTopic = trim($topic);
        if ($cleanTopic === '') {
            $cleanTopic = 'Exclusive Update & Growth Insights';
        }

        $audienceText = $audience !== null && trim($audience) !== '' ? ' for '.trim($audience) : '';

        // Generate tailored suggestions based on tone and topic
        $suggestions = match ($tone) {
            'urgent' => [
                "⏳ Last chance: {$cleanTopic}{$audienceText}",
                "Time is running out on {$cleanTopic}",
                "Action required: Don't miss this {$cleanTopic} update",
            ],
            'curious' => [
                "The one thing you didn't know about {$cleanTopic}...",
                "Why everyone is talking about {$cleanTopic}{$audienceText}",
                "Quick question about {$cleanTopic}?",
            ],
            'friendly' => [
                "Exciting news: {$cleanTopic} is here! 🎉",
                "We thought you'd love this: {$cleanTopic}{$audienceText}",
                "A little something special regarding {$cleanTopic}",
            ],
            'bold' => [
                "Say goodbye to old ways: Welcome to {$cleanTopic} 🚀",
                "{$cleanTopic}: The future starts today",
                "Rethink everything you know about {$cleanTopic}{$audienceText}",
            ],
            default => [
                "Introducing {$cleanTopic}{$audienceText}",
                "Discover the latest: {$cleanTopic}",
                "Unlock new potential with {$cleanTopic}",
            ],
        };

        $variantB = match ($tone) {
            'urgent' => "⚡ Final hours: {$cleanTopic} closes tonight",
            'curious' => "👀 Sneak peek inside {$cleanTopic}",
            'friendly' => "👋 Quick update on {$cleanTopic} just for you",
            'bold' => "💥 Why {$cleanTopic} changes everything",
            default => "✨ A fresh perspective on {$cleanTopic}",
        };

        $previewText = "Read our comprehensive briefing on {$cleanTopic}{$audienceText} and see what is next.";
        $rationale = 'Template-based options contrasting emotional hooks, benefit-driven messaging, and urgent calls-to-action.';

        return [
            'suggestions' => $suggestions,
            'variant_b' => $variantB,
            'preview_text' => $previewText,
            'rationale' => $rationale,
        ];
    }
}
