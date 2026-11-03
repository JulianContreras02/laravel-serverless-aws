<?php

namespace App\Http\Requests;

use App\Rules\NotReservedCode;
use App\Rules\NotShortLinkHost;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLinkRequest extends FormRequest
{
    /**
     * @return array<string, array<int, ValidationRule|string|\Stringable>>
     */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:2048', 'url:http,https', new NotShortLinkHost],
            'alias' => [
                'nullable', 'string', 'min:3', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/',
                new NotReservedCode,
                // Friendly early error; the unique index is what actually guarantees it.
                Rule::unique('links', 'code'),
            ],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    public function targetUrl(): string
    {
        return $this->string('url')->toString();
    }

    public function alias(): ?string
    {
        return $this->filled('alias') ? $this->string('alias')->toString() : null;
    }

    public function expiresAt(): ?CarbonImmutable
    {
        return $this->filled('expires_at')
            ? CarbonImmutable::parse($this->string('expires_at')->toString())->utc()
            : null;
    }
}
