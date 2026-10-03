<?php

declare(strict_types=1);

namespace Odden\Marketing\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Odden\Marketing\Actions\ProcessFormSubmissionAction;
use Odden\Marketing\Actions\RecordWebVisitAction;
use Odden\Marketing\Models\LandingPage;

class LandingPageController extends Controller
{
    /**
     * Render the hosted public landing page.
     */
    public function show(Request $request, string $slug, RecordWebVisitAction $visitAction): View
    {
        /** @var LandingPage $page */
        $page = LandingPage::query()
            ->where('slug', $slug)
            ->where('is_published', true)
            ->firstOrFail();

        $page->increment('views_count');

        // Record inbound web visit
        $rawVid = $request->cookie('odden_vid');
        $visitorToken = is_string($rawVid) ? $rawVid : null;
        $referer = is_string($r = $request->header('referer')) ? $r : null;
        $utmSource = is_string($s = $request->query('utm_source')) ? $s : null;
        $utmMedium = is_string($m = $request->query('utm_medium')) ? $m : null;
        $utmCampaign = is_string($c = $request->query('utm_campaign')) ? $c : null;

        $visitAction->execute([
            'visitor_token' => $visitorToken,
            'url' => $request->fullUrl(),
            'path' => route('odden.marketing.landing-pages.show', $slug, false),
            'title' => $page->title,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'referer' => $referer,
            'utm_source' => $utmSource,
            'utm_medium' => $utmMedium,
            'utm_campaign' => $utmCampaign,
        ]);

        return view('odden-marketing::landing-page', compact('page'));
    }

    /**
     * Handle form submission on a hosted landing page.
     */
    public function submit(Request $request, string $slug, ProcessFormSubmissionAction $action): RedirectResponse
    {
        /** @var LandingPage $page */
        $page = LandingPage::query()
            ->where('slug', $slug)
            ->where('is_published', true)
            ->firstOrFail();

        $form = $page->form;
        if ($form === null) {
            abort(404, 'No form associated with this landing page.');
        }

        $request->validate($form->validationRulesFor($form->resolveFieldsForContact(null)));

        $inputData = $request->except(['_token']);
        $rawVid = $request->cookie('odden_vid');
        if (is_string($rawVid)) {
            $inputData['visitor_token'] = $rawVid;
        }

        $action->execute(
            form: $form,
            data: $inputData,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent()
        );

        $page->increment('submissions_count');

        $successMsg = $form->success_message ?: 'Thank you! Your information has been received.';

        return redirect()->back()->with('success', $successMsg);
    }
}
