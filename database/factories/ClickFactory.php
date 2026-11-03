<?php

namespace Database\Factories;

use App\Enums\Device;
use App\Models\Click;
use App\Models\Link;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Click>
 */
class ClickFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'link_id' => Link::factory(),
            'clicked_at' => CarbonImmutable::now(),
            'referrer_host' => null,
            'device' => Device::Desktop,
        ];
    }
}
