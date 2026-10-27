<?php

declare(strict_types=1);

namespace Odden\Filament\Support;

use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\MentionProvider;
use Illuminate\Database\Eloquent\Model;
use Odden\Core\Models\Mention;
use Odden\Core\Support\Mentions;

/**
 * The text box of a note or comment, where typing "@" offers the teammates who can be mentioned (Mentions::teammates).
 *
 * The text is still kept as plain text, a mention written as "@Name", and the people picked are kept by id beside it
 * (RecordMentionsAction). The editor works on a document instead: document() turns the stored text back into one, with the
 * mentions of the record in place, and parse() turns what was typed into plain text and the ids of the people picked.
 */
final class MentionEditor
{
    private const array INLINE = ['text', 'mention', 'hardBreak'];

    public static function make(string $name): RichEditor
    {
        return RichEditor::make($name)
            ->json()
            ->toolbarButtons([])
            ->mentions([
                MentionProvider::make('@')
                    ->getSearchResultsUsing(fn (?string $search): array => Mentions::teammates((string) $search))
                    ->getLabelsUsing(self::labels(...))
                    ->searchPrompt('Type a name to mention a teammate')
                    ->noSearchResultsMessage('No teammate has that name'),
            ])
            ->helperText('Type @ to mention a teammate.')
            // Built from the record's text, not the state: the editor would read the stored text as HTML. Read as the form reads it,
            // so a text outside the person's data areas stays empty (ProtectedByArea).
            ->afterStateHydrated(function (RichEditor $component, ?Model $record) use ($name): void {
                $text = $record?->attributesToArray()[$name] ?? null;

                if (is_string($text)) {
                    $component->state(self::document($text, $record));
                }
            });
    }

    /**
     * The current names of the people with these ids who can be mentioned, for the mentions shown in the editor.
     *
     * @param  array<string>  $ids
     * @return array<string, string>
     */
    public static function labels(array $ids): array
    {
        $names = Mentions::names($ids);
        $ids = array_values(array_filter($ids, fn (string $id): bool => isset($names[(int) $id])));

        return array_combine($ids, array_map(fn (string $id): string => $names[(int) $id], $ids));
    }

    /**
     * The stored text as a document for the editor: a paragraph per line, and each "@Name" of the people mentioned in the
     * record as a mention of them. The name is matched as it was written (the mention's label), so a person renamed since keeps
     * their mention; two people written with the same name take its places in the order they were mentioned.
     *
     * @return array{type: string, content: list<array<string, mixed>>}
     */
    public static function document(string $text, ?Model $mentionable = null): array
    {
        $people = $mentionable?->exists ? self::mentionedPeople($mentionable) : [];
        $pattern = $people === [] ? null : self::namePattern(array_map('strval', array_keys($people)));
        $seen = [];
        $paragraphs = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $content = [];
            $pieces = $pattern === null ? array_filter([$line], fn (string $piece): bool => $piece !== '') : (preg_split($pattern, $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: []);

            foreach ($pieces as $piece) {
                $name = str_starts_with((string) $piece, '@') ? mb_substr((string) $piece, 1) : null;

                if ($name === null || ! isset($people[$name])) {
                    $content[] = ['type' => 'text', 'text' => $piece];

                    continue;
                }

                $ids = $people[$name];
                $id = $ids[min($seen[$name] ?? 0, count($ids) - 1)];
                $seen[$name] = ($seen[$name] ?? 0) + 1;
                $content[] = ['type' => 'mention', 'attrs' => ['id' => (string) $id, 'label' => $name, 'char' => '@']];
            }

            $paragraphs[] = $content === [] ? ['type' => 'paragraph'] : ['type' => 'paragraph', 'content' => $content];
        }

        return ['type' => 'doc', 'content' => $paragraphs];
    }

    /**
     * What was typed, as plain text, the people picked in it in the order they appear, and the name each is written with (for
     * RecordMentionsAction). A mention of someone who is not a teammate stays as its text and mentions no one; a teammate's mention
     * is written with their current name, whatever label came with it.
     *
     * @return array{text: string, user_ids: list<int>, labels: array<int, string>}
     */
    public static function parse(mixed $state): array
    {
        if (! is_array($state)) {
            return ['text' => trim((string) (is_scalar($state) ? $state : '')), 'user_ids' => [], 'labels' => []];
        }

        $ids = [];
        self::collectMentionIds($state, $ids);
        $names = Mentions::names($ids);
        $userIds = array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id): bool => isset($names[$id]))));

        return [
            'text' => trim(self::text($state, $names)),
            'user_ids' => $userIds,
            'labels' => array_combine($userIds, array_map(fn (int $id): string => $names[$id], $userIds)),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  list<string>  $ids
     */
    private static function collectMentionIds(array $node, array &$ids): void
    {
        if (($node['type'] ?? null) === 'mention' && (($node['attrs']['char'] ?? '@') === '@') && is_scalar($node['attrs']['id'] ?? null)) {
            $ids[] = (string) $node['attrs']['id'];
        }

        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            if (is_array($child)) {
                self::collectMentionIds($child, $ids);
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  array<int, string>  $names
     */
    private static function text(array $node, array $names): string
    {
        $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];

        return match ($node['type'] ?? null) {
            'text' => is_string($node['text'] ?? null) ? $node['text'] : '',
            'hardBreak' => "\n",
            'mention' => ($attrs['char'] ?? '@').($names[(int) (is_numeric($attrs['id'] ?? null) ? $attrs['id'] : 0)] ?? (is_scalar($attrs['label'] ?? null) ? (string) $attrs['label'] : '')),
            default => self::children($node, $names),
        };
    }

    /**
     * Inline content runs together; blocks (paragraphs, list items) go on lines of their own.
     *
     * @param  array<array-key, mixed>  $node
     * @param  array<int, string>  $names
     */
    private static function children(array $node, array $names): string
    {
        $children = array_values(array_filter(is_array($node['content'] ?? null) ? $node['content'] : [], 'is_array'));
        $inline = true;

        foreach ($children as $child) {
            $inline = $inline && in_array($child['type'] ?? null, self::INLINE, true);
        }

        return implode($inline ? '' : "\n", array_map(fn (array $child): string => self::text($child, $names), $children));
    }

    /**
     * The people mentioned in the record, by the name they were written with, in the order they were mentioned.
     *
     * @return array<string, non-empty-list<int>> User ids by label.
     */
    private static function mentionedPeople(Model $mentionable): array
    {
        $people = [];

        foreach (Mention::query()
            ->where('mentionable_type', $mentionable->getMorphClass())
            ->where('mentionable_id', $mentionable->getKey())
            ->orderBy('id')
            ->get(['user_id', 'label']) as $mention) {
            if ($mention->label !== '') {
                $people[$mention->label][] = $mention->user_id;
            }
        }

        return $people;
    }

    /**
     * @param  list<string>  $names
     */
    private static function namePattern(array $names): string
    {
        usort($names, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return '/('.implode('|', array_map(fn (string $name): string => preg_quote('@'.$name, '/'), $names)).')/u';
    }
}
