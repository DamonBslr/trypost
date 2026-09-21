<?php

declare(strict_types=1);

use App\Socialite\InstagramProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;
use RuntimeException;

test('instagram provider has correct scopes', function () {
    $request = Request::create('/');
    $provider = new InstagramProvider($request, 'client-id', 'client-secret', 'https://example.com/callback');

    $reflection = new ReflectionClass($provider);
    $property = $reflection->getProperty('scopes');
    $property->setAccessible(true);

    expect($property->getValue($provider))->toContain('instagram_business_basic');
    expect($property->getValue($provider))->toContain('instagram_business_content_publish');
});

test('instagram provider has correct token url', function () {
    $request = Request::create('/');
    $provider = new InstagramProvider($request, 'client-id', 'client-secret', 'https://example.com/callback');

    $reflection = new ReflectionClass($provider);
    $method = $reflection->getMethod('getTokenUrl');
    $method->setAccessible(true);

    expect($method->invoke($provider))->toBe('https://api.instagram.com/oauth/access_token');
});

test('instagram provider generates correct token fields', function () {
    $request = Request::create('/');
    $provider = new InstagramProvider($request, 'client-id', 'client-secret', 'https://example.com/callback');

    $reflection = new ReflectionClass($provider);
    $method = $reflection->getMethod('getTokenFields');
    $method->setAccessible(true);

    $fields = $method->invoke($provider, 'test-code');

    expect($fields['client_id'])->toBe('client-id');
    expect($fields['client_secret'])->toBe('client-secret');
    expect($fields['grant_type'])->toBe('authorization_code');
    expect($fields['redirect_uri'])->toBe('https://example.com/callback');
    expect($fields['code'])->toBe('test-code');
});

test('instagram provider maps user to object correctly', function () {
    $request = Request::create('/');
    $provider = new InstagramProvider($request, 'client-id', 'client-secret', 'https://example.com/callback');

    $reflection = new ReflectionClass($provider);
    $method = $reflection->getMethod('mapUserToObject');
    $method->setAccessible(true);

    $user = $method->invoke($provider, [
        'id' => '12345',
        'username' => 'testuser',
        'name' => 'Test User',
        'profile_picture_url' => 'https://example.com/avatar.jpg',
    ]);

    expect($user->getId())->toBe('12345');
    expect($user->getNickname())->toBe('testuser');
    expect($user->getName())->toBe('Test User');
    expect($user->getAvatar())->toBe('https://example.com/avatar.jpg');
});

test('instagram provider maps user without name uses username', function () {
    $request = Request::create('/');
    $provider = new InstagramProvider($request, 'client-id', 'client-secret', 'https://example.com/callback');

    $reflection = new ReflectionClass($provider);
    $method = $reflection->getMethod('mapUserToObject');
    $method->setAccessible(true);

    $user = $method->invoke($provider, [
        'id' => '12345',
        'username' => 'testuser',
    ]);

    expect($user->getName())->toBe('testuser');
});

test('instagram token exchange unwraps the business-login data envelope', function () {
    $provider = instagramProviderWithResponses([
        instagramJsonResponse([
            'data' => [[
                'access_token' => 'short-lived-token',
                'user_id' => '17841400000000000',
                'permissions' => 'instagram_business_basic,instagram_business_content_publish',
            ]],
        ]),
        instagramJsonResponse([
            'access_token' => 'long-lived-token',
            'token_type' => 'bearer',
            'expires_in' => 5184000,
        ]),
    ]);

    $token = $provider->getAccessTokenResponse('auth-code');

    expect($token['access_token'])->toBe('long-lived-token')
        ->and($token['user_id'])->toBe('17841400000000000')
        ->and($token['expires_in'])->toBe(5184000);
});

test('instagram token exchange still accepts a flat access_token payload', function () {
    $provider = instagramProviderWithResponses([
        instagramJsonResponse([
            'access_token' => 'short-lived-token',
            'user_id' => '17841400000000000',
        ]),
        instagramJsonResponse([
            'access_token' => 'long-lived-token',
            'expires_in' => 5184000,
        ]),
    ]);

    $token = $provider->getAccessTokenResponse('auth-code');

    expect($token['access_token'])->toBe('long-lived-token')
        ->and($token['user_id'])->toBe('17841400000000000');
});

test('instagram token exchange surfaces Meta error_message', function () {
    $provider = instagramProviderWithResponses([
        instagramJsonResponse([
            'error_type' => 'OAuthException',
            'code' => 400,
            'error_message' => 'Error validating verification code. Please make sure your redirect_uri is identical to the one you used in the OAuth dialog request',
        ], 400),
    ]);

    $provider->getAccessTokenResponse('auth-code');
})->throws(
    RuntimeException::class,
    'Error validating verification code. Please make sure your redirect_uri is identical to the one you used in the OAuth dialog request',
);

test('instagram token exchange fails loudly when the data envelope has no token', function () {
    $provider = instagramProviderWithResponses([
        instagramJsonResponse(['data' => []]),
    ]);

    $provider->getAccessTokenResponse('auth-code');
})->throws(RuntimeException::class, 'Instagram token exchange failed.');

test('instagram long-lived exchange omits client_id', function () {
    $history = [];
    $provider = instagramProviderWithResponses([
        instagramJsonResponse([
            'access_token' => 'short-lived-token',
            'user_id' => '17841400000000000',
        ]),
        instagramJsonResponse([
            'access_token' => 'long-lived-token',
            'expires_in' => 5184000,
        ]),
    ], $history);

    $provider->getAccessTokenResponse('auth-code');

    expect($history)->toHaveCount(2);

    parse_str($history[1]['request']->getUri()->getQuery(), $query);

    expect($query)->toMatchArray([
        'grant_type' => 'ig_exchange_token',
        'client_secret' => 'client-secret',
        'access_token' => 'short-lived-token',
    ])->and($query)->not->toHaveKey('client_id');
});

test('instagram long-lived exchange surfaces Meta Graph errors', function () {
    $provider = instagramProviderWithResponses([
        instagramJsonResponse([
            'access_token' => 'short-lived-token',
            'user_id' => '17841400000000000',
        ]),
        instagramJsonResponse([
            'error' => [
                'message' => 'API access blocked.',
                'type' => 'OAuthException',
                'code' => 200,
            ],
        ], 400),
    ]);

    $provider->getAccessTokenResponse('auth-code');
})->throws(RuntimeException::class, 'API access blocked.');

/**
 * @param  array<int, Response>  $responses
 * @param  array<int, array<string, mixed>>  $history
 */
function instagramProviderWithResponses(array $responses, array &$history = []): InstagramProvider
{
    $provider = new InstagramProvider(Request::create('/'), 'client-id', 'client-secret', 'https://example.com/callback');

    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    $provider->setHttpClient(new Client([
        'handler' => $stack,
    ]));

    return $provider;
}

/**
 * @param  array<string, mixed>  $body
 */
function instagramJsonResponse(array $body, int $status = 200): Response
{
    return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
}
