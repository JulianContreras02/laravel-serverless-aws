<?php

namespace App\Http\Controllers;

use App\Models\Link;
use App\Services\ClickRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RedirectController extends Controller
{
    public function __invoke(Request $request, string $code, ClickRecorder $clicks): RedirectResponse|JsonResponse
    {
        $link = Link::query()
            ->where('code', $code)
            ->first(['id', 'code', 'target_url', 'expires_at', 'disabled_at']);

        if ($link === null) {
            abort(404, 'Link not found.');
        }

        if (! $link->isResolvable()) {
            return response()->json(['message' => 'This link has expired or was disabled.'], 410);
        }

        // HEAD requests come from link previewers and uptime checks, not visitors.
        if (! $request->isMethod('HEAD')) {
            $clicks->record($link, $request, CarbonImmutable::now());
        }

        // 302 instead of 301: browsers cache permanent redirects indefinitely,
        // which would hide later clicks and make disabling a link ineffective.
        return redirect()->away($link->target_url)->withHeaders([
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
