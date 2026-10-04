<?php

declare(strict_types=1);

namespace Odden\Marketing\Contracts;

/**
 * Suggests email subject lines for a topic, plus a Variant B candidate to test against the first.
 *
 * The built-in implementation (SuggestSubjectLinesAction) picks from fixed phrase templates for the tone: the same input
 * always gives the same lines, and no outside service is called. An application or add-on can rebind this contract to
 * another implementation as long as it returns the same shape. The panel asks the container for this contract.
 *
 *     $this->app->bind(SuggestsSubjectLines::class, MySubjectLineWriter::class);
 */
interface SuggestsSubjectLines
{
    /**
     * @param  string  $topic  What the email is about (an empty topic is allowed and gets a generic one).
     * @param  string  $tone  One of the tones the panel offers, such as "engaging", "urgent" or "curious".
     * @param  string|null  $audience  Who it is for, when the sender said.
     * @return array{
     *     suggestions: list<string>,
     *     variant_b: string,
     *     preview_text: string,
     *     rationale: string
     * }
     */
    public function execute(string $topic, string $tone = 'engaging', ?string $audience = null): array;
}
