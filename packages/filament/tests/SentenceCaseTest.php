<?php

declare(strict_types=1);

/*
 * Labels, titles and headings are written in sentence case ("Send test email", not "Send Test Email"), like the rest of the app.
 * Names and acronyms keep their capitals. A literal with two or more other capitalised words after the first is Title Case.
 */

const SENTENCE_CASE_NAMES = ['Europe', 'Berlin', 'New', 'York', 'Odden', 'AI', 'Google', 'Drive', 'Microsoft', 'Teams', 'Meet', 'Zoom', 'Figma', 'Notion', 'Markdown', 'Mailgun', 'Stripe', 'LinkedIn', 'Facebook', 'Meta', 'Slack', 'Zapier', 'HubSpot', 'Salesforce', 'Gmail', 'Outlook', 'Postmark', 'SendGrid', 'Amazon', 'OpenGraph', 'WordPress', 'YouTube'];

it('writes labels, titles and headings in sentence case', function (): void {
    $offenders = [];
    $root = dirname(__DIR__, 2);
    $files = new AppendIterator;
    foreach (['core', 'sales', 'filament'] as $package) {
        $files->append(new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$package}/src", FilesystemIterator::SKIP_DOTS)));
    }

    foreach ($files as $file) {
        $path = (string) $file;

        if (! str_ends_with($path, '.php')) {
            continue;
        }

        preg_match_all("/->(?:label|title|modalHeading|modalSubmitActionLabel|heading)\\('((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents($path), $matches);

        foreach ($matches[1] as $text) {
            $capitalised = array_filter(array_slice(explode(' ', $text), 1), function (string $word): bool {
                $core = trim($word, '()[]{}.,:;!?"/&');

                return $core !== '' && ctype_upper($core[0]) && ! ctype_upper($core) && ! in_array($core, SENTENCE_CASE_NAMES, true)
                    && preg_match('/[0-9{$]|^.+[A-Z]/', $core) !== 1;
            });

            if (count($capitalised) >= 2) {
                $offenders[] = str_replace($root.'/', '', $path).": {$text}";
            }
        }
    }

    expect($offenders)->toBe([]);
});
