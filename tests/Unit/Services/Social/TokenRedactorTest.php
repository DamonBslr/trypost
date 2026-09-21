<?php

declare(strict_types=1);

use App\Services\Social\TokenRedactor;

test('redact strips access_token in URL form', function () {
    $input = 'POST https://api.example.com?access_token=abc123xyz&page=1';

    expect(TokenRedactor::redact($input))->toBe('POST https://api.example.com?access_token=[REDACTED]&page=1');
});

test('redact strips client_secret in URL form', function () {
    $input = 'GET https://graph.instagram.com/access_token?grant_type=ig_exchange_token&client_secret=shh&access_token=igaa';

    expect(TokenRedactor::redact($input))->toBe('GET https://graph.instagram.com/access_token?grant_type=ig_exchange_token&client_secret=[REDACTED]&access_token=[REDACTED]');
});

test('redact strips access_token in JSON form', function () {
    $input = '{"data":{"access_token":"abc123xyz","expires_in":3600}}';

    expect(TokenRedactor::redact($input))->toBe('{"data":{"access_token":"[REDACTED]","expires_in":3600}}');
});

test('redact strips Bearer authorization header', function () {
    $input = "Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.payload.sig\n";

    expect(TokenRedactor::redact($input))->toBe("Authorization: Bearer [REDACTED]\n");
});

test('redact strips "token" JSON field', function () {
    $input = '{"token":"shhh-secret","other":"keep"}';

    expect(TokenRedactor::redact($input))->toBe('{"token":"[REDACTED]","other":"keep"}');
});

test('redact handles multiple secrets in the same body', function () {
    $input = 'access_token=one&refresh_token=two with Bearer xyz';
    $output = TokenRedactor::redact($input);

    expect($output)->toContain('access_token=[REDACTED]')
        ->toContain('Bearer [REDACTED]');
});

test('redact returns null when input is null', function () {
    expect(TokenRedactor::redact(null))->toBeNull();
});

test('redact returns the input unchanged when nothing matches', function () {
    $input = 'plain log line with no secrets';

    expect(TokenRedactor::redact($input))->toBe($input);
});
