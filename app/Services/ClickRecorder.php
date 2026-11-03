<?php

namespace App\Services;

use App\Enums\Device;
use App\Models\Click;
use App\Models\Link;
use App\Support\ReferrerHost;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class ClickRecorder
{
    /**
     * Writes the click synchronously: a single INSERT into an append-only table.
     *
     * A queue (SQS) would take this off the request path, but a Lambda inside a
     * VPC needs a NAT gateway or a VPC endpoint to reach SQS, which would cost
     * more than the whole stack. If the INSERT fails, the visitor is still
     * redirected: losing a click is better than losing the visit.
     *
     * @return bool whether the click was stored
     */
    public function record(Link $link, Request $request, CarbonImmutable $clickedAt): bool
    {
        try {
            Click::query()->toBase()->insert([
                'link_id' => $link->id,
                'clicked_at' => $clickedAt->utc()->toDateTimeString(),
                'referrer_host' => ReferrerHost::fromHeader($request->headers->get('referer')),
                'device' => Device::fromUserAgent($request->userAgent())->value,
            ]);

            return true;
        } catch (QueryException $e) {
            report($e);

            return false;
        }
    }
}
