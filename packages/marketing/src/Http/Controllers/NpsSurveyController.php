<?php

declare(strict_types=1);

namespace Odden\Marketing\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Odden\Marketing\Actions\SubmitNpsResponseAction;
use Odden\Marketing\Models\NpsResponse;

class NpsSurveyController extends Controller
{
    /**
     * Record 1-click rating directly from an email link and show feedback page.
     */
    public function recordScore(
        string $token,
        int $score,
        SubmitNpsResponseAction $action
    ): View {
        /** @var NpsResponse $response */
        $response = NpsResponse::query()->where('token', $token)->firstOrFail();

        // The first click is the answer. Mail scanners and link prefetchers open every link in a message,
        // so a later GET must not overwrite a rating (the page lets the person add comments instead).
        if ($response->responded_at === null) {
            $action->execute($response, $score);
        }

        $survey = $response->survey;

        return view('odden-marketing::nps-feedback', [
            'response' => $response,
            'survey' => $survey,
        ]);
    }

    /**
     * Submit optional qualitative feedback text.
     */
    public function submitFeedback(Request $request, string $token): RedirectResponse
    {
        /** @var NpsResponse $response */
        $response = NpsResponse::query()->where('token', $token)->firstOrFail();

        $feedback = $request->input('feedback');
        if (! empty($feedback)) {
            $response->update([
                'feedback' => (string) $feedback,
            ]);
        }

        return redirect()->back()->with('success', 'Thank you! Your comments have been saved.');
    }
}
