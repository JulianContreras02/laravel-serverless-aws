<?php

namespace App\Http\Resources;

use App\Models\Link;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Link $resource
 */
class LinkResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $link = $this->resource;

        return [
            'code' => $link->code,
            'short_url' => $link->shortUrl(),
            'target_url' => $link->target_url,
            'expires_at' => $link->expires_at?->toIso8601ZuluString(),
            'disabled_at' => $link->disabled_at?->toIso8601ZuluString(),
            'created_at' => $link->created_at->toIso8601ZuluString(),
        ];
    }
}
