<?php

declare(strict_types=1);

namespace App\Socialite;

use GuzzleHttp\RequestOptions;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\ProviderInterface;
use Laravel\Socialite\Two\User;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class InstagramProvider extends AbstractProvider implements ProviderInterface
{
    protected $scopes = [
        'instagram_business_basic',
        'instagram_business_content_publish',
    ];

    protected function getAuthUrl($state): string
    {
        // enable_fb_login=0 forces the pure Instagram Login flow (without
        // delegating to Facebook OAuth). Tokens issued from the FB-delegated
        // path can't be exchanged via graph.instagram.com/access_token.
        return 'https://www.instagram.com/oauth/authorize?enable_fb_login=0&'.http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUrl,
            'response_type' => 'code',
            'scope' => implode(',', $this->getScopes()),
            'state' => $state,
            'force_reauth' => 'true',
        ]);
    }

    protected function getTokenUrl(): string
    {
        return 'https://api.instagram.com/oauth/access_token';
    }

    protected function getUserByToken($token): array
    {
        $response = $this->getHttpClient()->get(config('trypost.platforms.instagram.graph_api').'/me', [
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::QUERY => [
                'access_token' => $token,
                'fields' => 'id,username,account_type,name,profile_picture_url',
            ],
        ]);

        $user = $this->jsonBody($response);

        if ($response->getStatusCode() >= 400 || ! is_string(data_get($user, 'id'))) {
            throw new RuntimeException($this->instagramErrorMessage($user, 'Instagram profile lookup failed.'));
        }

        return $user;
    }

    protected function mapUserToObject(array $user): User
    {
        return (new User)->setRaw($user)->map([
            'id' => $user['id'],
            'nickname' => $user['username'] ?? null,
            'name' => $user['name'] ?? $user['username'] ?? null,
            'avatar' => $user['profile_picture_url'] ?? null,
        ]);
    }

    public function getAccessTokenResponse($code): array
    {
        // Meta's docs document this endpoint with curl -F flags (multipart/form-data).
        $multipart = [];
        foreach ($this->getTokenFields($code) as $name => $contents) {
            $multipart[] = ['name' => $name, 'contents' => (string) $contents];
        }

        $response = $this->getHttpClient()->post($this->getTokenUrl(), [
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::MULTIPART => $multipart,
        ]);

        $data = $this->flattenShortLivedToken($this->jsonBody($response));

        if ($response->getStatusCode() >= 400 || ! is_string(data_get($data, 'access_token')) || data_get($data, 'access_token') === '') {
            throw new RuntimeException($this->instagramErrorMessage($data, 'Instagram token exchange failed.'));
        }

        return $this->exchangeForLongLivedToken($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function exchangeForLongLivedToken(array $data): array
    {
        // Official params are grant_type + client_secret + access_token only.
        // Sending client_id here makes graph.instagram.com return OAuthException
        // code 200 ("API access blocked") after a successful short-lived exchange.
        $response = $this->getHttpClient()->get(config('trypost.platforms.instagram.auth_api').'/access_token', [
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::QUERY => [
                'grant_type' => 'ig_exchange_token',
                'client_secret' => $this->clientSecret,
                'access_token' => data_get($data, 'access_token'),
            ],
        ]);

        $longLivedData = $this->jsonBody($response);

        if ($response->getStatusCode() >= 400 || ! is_string(data_get($longLivedData, 'access_token')) || data_get($longLivedData, 'access_token') === '') {
            throw new RuntimeException($this->instagramErrorMessage($longLivedData, 'Instagram long-lived token exchange failed.'));
        }

        return array_merge($data, [
            'access_token' => $longLivedData['access_token'],
            'expires_in' => $longLivedData['expires_in'] ?? null,
        ]);
    }

    protected function getTokenFields($code): array
    {
        return [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUrl,
            'code' => $code,
        ];
    }

    /**
     * Business Login for Instagram returns `{ data: [{ access_token, user_id, permissions }] }`.
     * Older apps still get a flat `{ access_token, user_id }`. Socialite reads the top-level key.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function flattenShortLivedToken(array $data): array
    {
        $entry = data_get($data, 'data.0');

        if (is_array($entry) && isset($entry['access_token'])) {
            return array_merge($data, $entry);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function instagramErrorMessage(array $data, string $fallback): string
    {
        $message = data_get($data, 'error_message') ?? data_get($data, 'error.message');

        return is_string($message) && $message !== '' ? $message : $fallback;
    }
}
