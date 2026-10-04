<?php

declare(strict_types=1);

namespace Odden\Service\Actions;

use Illuminate\Database\Eloquent\Model;
use Odden\Core\Support\UserModel;
use Odden\Service\Contracts\DraftsTicketReply;
use Odden\Service\Enums\MessageSenderType;
use Odden\Service\Models\CannedResponse;
use Odden\Service\Models\Ticket;

class DraftTicketReplyAction implements DraftsTicketReply
{
    /**
     * Words too common to say anything about what a ticket is about.
     *
     * @var list<string>
     */
    protected const STOP_WORDS = [
        'the', 'and', 'for', 'with', 'you', 'your', 'our', 'can', 'could', 'would', 'please', 'help', 'have', 'has', 'this',
        'that', 'from', 'what', 'when', 'how', 'are', 'was', 'not', 'but', 'about', 'there', 'their', 'hello', 'thanks', 'thank',
    ];

    public function __construct(protected DeflectTicketAction $articles) {}

    /**
     * @return array{body: string, sources: list<string>, rationale: string}
     */
    public function execute(Ticket $ticket, ?Model $agent = null): array
    {
        $question = $this->question($ticket);
        $sources = [];

        $canned = $this->bestCannedResponse($question, $agent);
        if ($canned !== null) {
            $sources[] = "Canned response: {$canned->title}";
        }

        $articles = $this->articles->execute($ticket->subject, 2);
        foreach ($articles as $article) {
            $sources[] = "Article: {$article['title']}";
        }

        $lines = [$this->greeting($ticket), ''];
        $lines[] = $canned !== null
            ? $canned->render($ticket, $agent)
            : "Thanks for getting in touch about \"{$ticket->subject}\". We are looking into it and will follow up shortly.";

        if ($articles->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'These articles may help in the meantime:';
            foreach ($articles as $article) {
                $lines[] = "- {$article['title']}: {$article['url']}";
            }
        }

        $lines[] = '';
        $lines[] = 'Best regards,';
        $lines[] = $agent !== null ? UserModel::displayName($agent, 'The support team') : 'The support team';

        return [
            'body' => implode("\n", $lines),
            'sources' => $sources,
            'rationale' => $sources === []
                ? 'No canned response or article matched this ticket, so this is a general acknowledgement.'
                : 'Built from the best matching canned response and help articles. Check it answers the question before sending.',
        ];
    }

    protected function greeting(Ticket $ticket): string
    {
        $first = trim((string) $ticket->contact?->first_name);

        return $first === '' ? 'Hello,' : "Hi {$first},";
    }

    /**
     * What the customer is asking: the subject plus their most recent message.
     */
    protected function question(Ticket $ticket): string
    {
        $latest = $ticket->messages()
            ->where('sender_type', MessageSenderType::Customer->value)
            ->where('is_internal_note', false)
            ->latest('created_at')
            ->first();

        return trim($ticket->subject.' '.($latest->body ?? $ticket->description ?? ''));
    }

    protected function bestCannedResponse(string $question, ?Model $agent): ?CannedResponse
    {
        $terms = $this->terms($question);
        if ($terms === []) {
            return null;
        }

        $best = null;
        $bestScore = 0;

        foreach (CannedResponse::query()->availableTo($agent?->getKey())->get() as $candidate) {
            $score = count(array_intersect($terms, $this->terms($candidate->title.' '.$candidate->category.' '.$candidate->content)));
            // Title and category hits say more than a word that happens to appear in the body.
            $score += count(array_intersect($terms, $this->terms($candidate->title.' '.$candidate->category)));

            if ($score > $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $bestScore >= 2 ? $best : null;
    }

    /**
     * @return list<string>
     */
    protected function terms(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(
            $words,
            fn (string $word): bool => mb_strlen($word) >= 4 && ! in_array($word, self::STOP_WORDS, true)
        )));
    }
}
