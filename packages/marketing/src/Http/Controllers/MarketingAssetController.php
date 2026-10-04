<?php

declare(strict_types=1);

namespace Odden\Marketing\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Odden\Marketing\Actions\TrackAssetDownloadAction;
use Odden\Marketing\Models\MarketingAsset;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MarketingAssetController extends Controller
{
    /**
     * Download or access a digital marketing asset, tracking download metrics and contact activity.
     */
    public function download(
        Request $request,
        string $slug,
        TrackAssetDownloadAction $tracker
    ): RedirectResponse|BinaryFileResponse {
        /** @var MarketingAsset $asset */
        $asset = MarketingAsset::query()->where('slug', $slug)->firstOrFail();

        // Unsigned or tampered links still download, but aren't attributed to a contact.
        $contact = $asset->resolveSignedContact($request->query('contact_id'), $request->query('signature'));

        $tracker->execute(
            asset: $asset,
            contact: $contact,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent()
        );

        if (! empty($asset->external_url)) {
            return redirect()->away($asset->external_url);
        }

        $file = $asset->downloadPath();

        if ($file !== null) {
            return response()->download($file);
        }

        return redirect()->back()->with('success', "Download started for {$asset->name}");
    }
}
