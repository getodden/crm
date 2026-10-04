<?php

declare(strict_types=1);

namespace Odden\Marketing\Support;

/**
 * Builds the document shown inside the email preview frames.
 *
 * Email HTML is written by the people who edit templates, so it must never run in the admin's page:
 * a script in it would run with the signed-in user's session. The previews show it in an iframe
 * with an empty `sandbox` (no scripts, no same-origin access, no forms, no popups), so whatever the
 * HTML contains is only drawn.
 */
final class EmailPreviewFrame
{
    private const string DARK_CSS = <<<'CSS'
        body { background-color: #0f172a !important; color: #f8fafc !important; }
        table { border-color: #334155 !important; }
        td[style*="background-color: #ffffff"], td[style*="background-color:#ffffff"], td[style*="background-color: #FFFFFF"] {
            background-color: #1e293b !important; color: #f8fafc !important; border-color: #334155 !important;
        }
        p, h1, h2, h3 { color: #f8fafc !important; }
        CSS;

    /**
     * A complete HTML document for the iframe's `srcdoc`, optionally with the dark-mode simulation.
     */
    public static function document(string $html, bool $dark = false): string
    {
        $css = $dark ? self::DARK_CSS : '';

        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<meta http-equiv="Content-Security-Policy" content="script-src \'none\'; object-src \'none\'; base-uri \'none\'">'
            .'<style>body{margin:0;padding:1rem;font-family:sans-serif;background:#fff;color:#0f172a}'.$css.'</style></head><body>'
            .$html.'</body></html>';
    }
}
