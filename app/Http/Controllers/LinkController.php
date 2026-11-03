<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLinkRequest;
use App\Http\Resources\LinkResource;
use App\Models\Link;
use App\Services\LinkCreator;
use App\Support\Metrics\Metrics;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class LinkController extends Controller
{
    public function store(StoreLinkRequest $request, LinkCreator $creator, Metrics $metrics): JsonResponse
    {
        $link = $creator->create($request->targetUrl(), $request->alias(), $request->expiresAt());
        $metrics->put('LinkCreated', 1);

        return LinkResource::make($link)->response()->setStatusCode(201);
    }

    public function show(Link $link): LinkResource
    {
        return LinkResource::make($link);
    }

    /**
     * Disables the link (soft delete). Idempotent: disabling twice keeps the first date.
     */
    public function destroy(Link $link): Response
    {
        if (! $link->isDisabled()) {
            $link->forceFill(['disabled_at' => CarbonImmutable::now()])->save();
        }

        return response()->noContent();
    }
}
