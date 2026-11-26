<?php
declare(strict_types=1);

namespace CommunityMapMaker\Auth;

use RuntimeException;

final class OsmOAuth
{
    private const ISSUER = 'https://www.openstreetmap.org';

    public function __construct(private array $config)
    {
    }

    public function enabled(): bool
    {
        $redirect = $this->config['redirect_uri'] ?? '';
        return ($this->config['client_id'] ?? '') !== ''
            && ($this->config['client_secret'] ?? '') !== ''
            && filter_var($redirect, FILTER_VALIDATE_URL) !== false
            && (parse_url($redirect, PHP_URL_SCHEME) === 'https'
                || preg_match('~^http://127\.0\.0\.1(?::[0-9]+)?/~', $redirect) === 1)
            && str_ends_with($redirect, '/api/osm-callback.php')
            && strpbrk($redirect, '?#') === false
            && parse_url($redirect, PHP_URL_USER) === null;
    }

    public function authorizationUrl(string $state, string $verifier): string
    {
        return self::ISSUER . '/oauth2/authorize?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->config['redirect_uri'],
            'scope' => 'openid',
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function subject(string $code, string $verifier): string
    {
        $token = self::request('/oauth2/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'redirect_uri' => $this->config['redirect_uri'],
            'code' => $code,
            'code_verifier' => $verifier,
        ])['access_token'] ?? null;
        if (!is_string($token) || !preg_match('/\A[A-Za-z0-9._~+\/-]+=*\z/', $token)) {
            throw new RuntimeException('OSM returned an invalid access token.');
        }
        // The profile comes directly from the issuer over HTTPS using the token just exchanged.
        $subject = self::request('/oauth2/userinfo', token: $token)['sub'] ?? null;
        if ((!is_string($subject) && !is_int($subject)) || !preg_match('/\A[1-9][0-9]{0,19}\z/', (string)$subject)) {
            throw new RuntimeException('OSM returned an invalid account identifier.');
        }
        return (string)$subject;
    }

    private static function request(string $path, ?array $fields = null, ?string $token = null): array
    {
        $curl = curl_init(self::ISSUER . $path);
        $headers = ['Accept: application/json'];
        if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
        curl_setopt_array($curl, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($fields !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($fields, '', '&'));
        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($status !== 200 || !is_string($response)) {
            throw new RuntimeException('OSM authentication service request failed.');
        }
        $payload = json_decode($response, true, flags: JSON_BIGINT_AS_STRING);
        if (!is_array($payload)) throw new RuntimeException('OSM returned an invalid response.');
        return $payload;
    }
}
