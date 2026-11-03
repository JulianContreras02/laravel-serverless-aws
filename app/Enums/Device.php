<?php

namespace App\Enums;

enum Device: string
{
    case Desktop = 'desktop';
    case Mobile = 'mobile';
    case Tablet = 'tablet';
    case Bot = 'bot';
    case Unknown = 'unknown';

    /**
     * Coarse classification from the User-Agent header: enough for analytics
     * buckets, without pulling a full UA-parsing library into the Lambda package.
     */
    public static function fromUserAgent(?string $userAgent): self
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return self::Unknown;
        }

        $userAgent = strtolower($userAgent);

        // Order matters: link previewers and tablets also contain "mobile"-like tokens.
        return match (true) {
            preg_match('/bot|crawl|spider|slurp|preview|facebookexternalhit|curl|wget|python-requests|okhttp/', $userAgent) === 1 => self::Bot,
            preg_match('/ipad|tablet|kindle|silk|android(?!.*mobile)/', $userAgent) === 1 => self::Tablet,
            preg_match('/mobi|iphone|ipod|android|blackberry|opera mini|windows phone/', $userAgent) === 1 => self::Mobile,
            default => self::Desktop,
        };
    }
}
