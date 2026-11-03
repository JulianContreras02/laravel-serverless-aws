<?php

namespace Database\Factories;

use App\Models\Link;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Link>
 */
class LinkFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => Str::random(7),
            'target_url' => fake()->url(),
            'expires_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => CarbonImmutable::now()->subMinute()]);
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['disabled_at' => CarbonImmutable::now()->subMinute()]);
    }
}
