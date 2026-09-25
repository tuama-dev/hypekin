<?php

namespace App\Socialite;

use GuzzleHttp\RequestOptions;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User;

class TikTokProvider extends AbstractProvider
{
    /**
     * Get the authentication URL for the provider.
     *
     * @param  string  $state
     */
    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase('https://www.tiktok.com/v2/auth/authorize/', $state);
    }

    /**
     * Get the token URL for the provider.
     */
    protected function getTokenUrl(): string
    {
        return 'https://open.tiktokapis.com/v2/oauth/token/';
    }

    /**
     * The default scopes requested when no explicit scopes are configured.
     *
     * @return list<string>
     */
    protected function getScopesDefault(): array
    {
        return ['user.info.basic'];
    }

    /**
     * Get the raw user for the given access token.
     *
     * @param  string  $token
     * @return array<string, mixed>
     */
    protected function getUserByToken($token): array
    {
        $response = Http::withToken($token)
            ->timeout(15)
            ->connectTimeout(5)
            ->get('https://open.tiktokapis.com/v2/user/info/', ['fields' => 'open_id,display_name,avatar_url'])
            ->throw()
            ->json();

        return Arr::get($response, 'data.user', []);
    }

    /**
     * Map the raw user array to a Socialite User instance.
     *
     * @param  array<string, mixed>  $user
     */
    protected function mapUserToObject(array $user): User
    {
        return (new User)->setRaw($user)->map([
            'id' => Arr::get($user, 'open_id'),
            'nickname' => Arr::get($user, 'display_name'),
            'name' => Arr::get($user, 'display_name'),
            'avatar' => Arr::get($user, 'avatar_url'),
        ]);
    }

    /**
     * Get the GET parameters for the code request.
     *
     * @param  string|null  $state
     * @return array<string, string>
     */
    protected function getCodeFields($state = null): array
    {
        $fields = parent::getCodeFields($state);

        $fields['client_key'] = $fields['client_id'];
        unset($fields['client_id']);

        return $fields;
    }

    /**
     * Get the POST fields for the token request.
     *
     * @param  string  $code
     * @return array<string, string>
     */
    protected function getTokenFields($code): array
    {
        $fields = [
            'grant_type' => 'authorization_code',
            'client_key' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'redirect_uri' => $this->redirectUrl,
        ];

        return array_merge($fields, $this->parameters);
    }

    /**
     * Get the access token response for the given code, unwrapping TikTok's envelope.
     *
     * @param  string  $code
     * @return array<string, mixed>
     */
    public function getAccessTokenResponse($code): array
    {
        $response = $this->getHttpClient()->post($this->getTokenUrl(), [
            RequestOptions::HEADERS => $this->getTokenHeaders($code),
            RequestOptions::FORM_PARAMS => $this->getTokenFields($code),
        ]);

        return Arr::get(json_decode($response->getBody(), true), 'data', []);
    }

    /**
     * Get the refresh token response for the given refresh token.
     *
     * @param  string  $refreshToken
     * @return array<string, mixed>
     */
    protected function getRefreshTokenResponse($refreshToken): array
    {
        return Arr::get(json_decode($this->getHttpClient()->post($this->getTokenUrl(), [
            RequestOptions::HEADERS => ['Accept' => 'application/json'],
            RequestOptions::FORM_PARAMS => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_key' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ],
        ])->getBody(), true), 'data', []);
    }
}
