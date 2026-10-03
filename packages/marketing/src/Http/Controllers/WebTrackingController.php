<?php

declare(strict_types=1);

namespace Odden\Marketing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Odden\Core\Enums\ActivityType;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Models\Contact;
use Odden\Marketing\Actions\ApplyLeadScoringEventAction;
use Odden\Marketing\Actions\RecordWebVisitAction;
use Odden\Marketing\Actions\StitchVisitorToContactAction;
use Odden\Marketing\Enums\LeadScoringEventType;
use Odden\Marketing\Support\VisitorToken;

class WebTrackingController extends Controller
{
    /**
     * Ingestion endpoint for first-party website pageview events.
     */
    public function pageview(Request $request, RecordWebVisitAction $action): JsonResponse
    {
        $this->mergeRawJsonBody($request);

        $visitorToken = VisitorToken::fromRequest($request);

        $defaultUrl = (string) config('app.url', 'http://localhost');
        $referer = is_string($ref = $request->header('referer')) ? $ref : $defaultUrl;
        $url = (string) $request->input('url', $referer);

        $result = $action->execute([
            'visitor_token' => $visitorToken,
            'contact_id' => $request->user()?->id !== null ? null : null, // Handled via session/auth if logged in
            'url' => $url,
            'path' => $request->input('path'),
            'title' => $request->input('title'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'referer' => $request->input('referer'),
            'utm_source' => $request->input('utm_source'),
            'utm_medium' => $request->input('utm_medium'),
            'utm_campaign' => $request->input('utm_campaign'),
            'duration_seconds' => $request->input('duration_seconds') ? (int) $request->input('duration_seconds') : null,
        ]);

        $token = $result['session']->visitor_token;

        return response()->json([
            'status' => 'success',
            'session_id' => $result['session']->id,
            'visitor_token' => $token,
        ])->cookie(VisitorToken::COOKIE, $token, 525600); // 1 year cookie, for hosted (same-site) pages
    }

    /**
     * Ingestion endpoint for external website form auto-capture.
     * Automatically ingests HTML forms submitted on host websites into Odden Leads.
     */
    public function autoCapture(Request $request, StitchVisitorToContactAction $stitch): JsonResponse
    {
        $this->mergeRawJsonBody($request);

        $email = strtolower(trim((string) $request->input('email')));
        if (empty($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['status' => 'error', 'message' => 'Valid email address is required.'], 422);
        }

        $firstName = (string) $request->input('first_name');
        $lastName = (string) $request->input('last_name');

        if (empty($firstName) && ! empty($request->input('name'))) {
            $parts = explode(' ', trim((string) $request->input('name')), 2);
            $firstName = $parts[0];
            $lastName = $parts[1] ?? '';
        }

        $phone = $request->input('phone') ? (string) $request->input('phone') : null;
        $pageUrl = (string) $request->input('page_url', 'Website Form');

        /** @var Contact $contact */
        $contact = Contact::query()->firstOrNew(['email' => $email]);
        $isNew = ! $contact->exists;

        if (empty($contact->first_name) && ! empty($firstName)) {
            $contact->first_name = $firstName;
        }
        if (empty($contact->last_name) && ! empty($lastName)) {
            $contact->last_name = $lastName;
        }
        if (empty($contact->phone) && ! empty($phone)) {
            $contact->phone = $phone;
        }

        if ($isNew) {
            $contact->lifecycle_stage = LifecycleStage::MarketingQualifiedLead;
        }

        $contact->save();

        // The score change goes through the scoring action, so it is logged and can promote the contact.
        $contact = app(ApplyLeadScoringEventAction::class)->execute(
            contact: $contact,
            eventType: LeadScoringEventType::FormSubmission,
            description: "Website form auto-captured on {$pageUrl}",
            context: ['page_url' => $pageUrl],
            points: $isNew ? 15 : 10,
        );

        // Link the visitor's anonymous sessions (and their page views) to the contact
        $visitorToken = VisitorToken::fromRequest($request);
        if ($visitorToken !== null) {
            $stitch->execute($visitorToken, $contact);
        }

        // Log timeline activity
        $contact->logActivity(
            type: ActivityType::Task,
            title: 'Website Form Auto-Captured',
            body: "Visitor submitted an external form on {$pageUrl}."
        );

        return response()->json([
            'status' => 'success',
            'contact_id' => $contact->id,
            'is_new' => $isNew,
        ]);
    }

    /**
     * Serve lightweight embeddable client tracking JavaScript script with Form Auto-Capture.
     */
    public function clientScript(): Response
    {
        $script = <<<'JS'
(function() {
    var ODDEN_PAGEVIEW_URL = __ODDEN_PAGEVIEW_URL__;
    var ODDEN_AUTO_CAPTURE_URL = __ODDEN_AUTO_CAPTURE_URL__;

__ODDEN_VISITOR_ID_JS__
    // JSON sent as text/plain is a CORS-safelisted request: no preflight, so it works from
    // any domain. fetch() only sends cookies same-origin (hosted landing pages); the visitor
    // id travels in the body. The endpoints parse the raw body as JSON.
    function post(url, payload, beacon) {
        var body = JSON.stringify(payload);
        try {
            if (beacon && navigator.sendBeacon && navigator.sendBeacon(url, new Blob([body], { type: 'text/plain;charset=UTF-8' }))) {
                return;
            }
            fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'text/plain;charset=UTF-8' },
                body: body,
                keepalive: true
            }).catch(function() {});
        } catch (e) {}
    }

    function sendPageView() {
        var vid = oddenVisitorId();
        var params = new URLSearchParams(window.location.search);
        var payload = {
            visitor_token: vid,
            url: window.location.href,
            path: window.location.pathname,
            title: document.title,
            referer: document.referrer,
            utm_source: params.get('utm_source'),
            utm_medium: params.get('utm_medium'),
            utm_campaign: params.get('utm_campaign')
        };
        post(ODDEN_PAGEVIEW_URL, payload, false);
    }

    function interceptForms() {
        var forms = document.querySelectorAll('form');
        forms.forEach(function(form) {
            if (form.getAttribute('data-odden-tracked')) return;
            form.setAttribute('data-odden-tracked', 'true');
            form.addEventListener('submit', function() {
                var emailInput = form.querySelector('input[type="email"], input[name*="email"]');
                if (!emailInput || !emailInput.value) return;
                var nameInput = form.querySelector('input[name="name"], input[name*="full_name"]');
                var firstInput = form.querySelector('input[name*="first"]');
                var lastInput = form.querySelector('input[name*="last"]');
                var phoneInput = form.querySelector('input[type="tel"], input[name*="phone"]');
                var params = new URLSearchParams(window.location.search);

                var payload = {
                    visitor_token: oddenVisitorId(),
                    email: emailInput.value,
                    name: nameInput ? nameInput.value : null,
                    first_name: firstInput ? firstInput.value : null,
                    last_name: lastInput ? lastInput.value : null,
                    phone: phoneInput ? phoneInput.value : null,
                    page_url: window.location.href,
                    utm_source: params.get('utm_source'),
                    utm_medium: params.get('utm_medium'),
                    utm_campaign: params.get('utm_campaign')
                };

                post(ODDEN_AUTO_CAPTURE_URL, payload, true);
            });
        });
    }

    if (document.readyState === 'complete') {
        sendPageView();
        interceptForms();
    } else {
        window.addEventListener('load', function() {
            sendPageView();
            interceptForms();
        });
    }
})();
JS;

        // Absolute URLs so the script works when embedded on other domains and honors route prefixes.
        $script = strtr($script, [
            '__ODDEN_PAGEVIEW_URL__' => json_encode(route('odden.marketing.track.pageview'), JSON_UNESCAPED_SLASHES),
            '__ODDEN_AUTO_CAPTURE_URL__' => json_encode(route('odden.marketing.forms.auto-capture'), JSON_UNESCAPED_SLASHES),
            "__ODDEN_VISITOR_ID_JS__\n" => VisitorToken::javascript(),
        ]);

        return response($script, 200, [
            'Content-Type' => 'application/javascript',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * navigator.sendBeacon() and cross-domain fetch() send the JSON payload as text/plain
     * (a CORS-safelisted type, so browsers skip the preflight). Laravel only parses JSON
     * bodies with a JSON content type, so decode the raw body here.
     */
    private function mergeRawJsonBody(Request $request): void
    {
        if ($request->isJson()) {
            return;
        }

        $content = $request->getContent();
        if (! str_starts_with(ltrim($content), '{')) {
            return;
        }

        $decoded = json_decode($content, true);
        if (is_array($decoded) && ! array_is_list($decoded)) {
            $request->merge($decoded);
        }
    }
}
