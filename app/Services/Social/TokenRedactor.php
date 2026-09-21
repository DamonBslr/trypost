<?php

declare(strict_types=1);

namespace App\Services\Social;

/**
 * Strips OAuth tokens from raw HTTP bodies before they hit logs or
 * exception messages. Centralizes the regex patterns so they evolve in
 * one place — adding a new token format (e.g. provider-specific) means
 * extending this list, not hunting through the codebase.
 */
class TokenRedactor
{
    public static function redact(?string $body): ?string
    {
        if ($body === null) {
            return null;
        }

        return preg_replace(
            [
                '/access_token=([^&"\s]+)/',
                '/client_secret=([^&"\s]+)/',
                '/"access_token"\s*:\s*"([^"]+)"/',
                '/Bearer\s+\S+/',
                '/"token"\s*:\s*"([^"]+)"/',
            ],
            [
                'access_token=[REDACTED]',
                'client_secret=[REDACTED]',
                '"access_token":"[REDACTED]"',
                'Bearer [REDACTED]',
                '"token":"[REDACTED]"',
            ],
            $body
        );
    }
}
