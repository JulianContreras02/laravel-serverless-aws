<?php

namespace App\Support;

final class ReferrerHost
{
    private const MAX_LENGTH = 255;

    /**
     * Normalized host of a Referer header, or null for direct traffic.
     *
     * Only the host is kept (paths and query strings may carry personal data),
     * and anything that is not a plain ASCII hostname is discarded: the header
     * is user-controlled and must never make the click INSERT fail.
     */
    public static function fromHeader(?string $referer): ?string
    {
        if ($referer === null || $referer === '') {
            return null;
        }

        $host = parse_url($referer, PHP_URL_HOST);

        if (! is_string($host)) {
            return null;
        }

        $host = strtolower($host);

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        if (strlen($host) > self::MAX_LENGTH || preg_match('/^[a-z0-9.-]+$/', $host) !== 1) {
            return null;
        }

        return $host;
    }
}
