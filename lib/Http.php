<?php
declare(strict_types=1);

namespace CommunityMapMaker\Auth;

use JsonException;
use Throwable;

final class Http
{
    public static function prepare(array $authConfig): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store');

        $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
        $allowed = array_values(array_filter(array_map('strval', $authConfig['allowed_origins'] ?? [])));

        $originAllowed = $origin === ''
            || in_array($origin, $allowed, true)
            || (
                ($authConfig['allow_private_network_origins'] ?? false) === true
                && self::isPrivateNetworkOrigin(
                    $origin,
                    (array)($authConfig['allowed_origin_hosts'] ?? [])
                )
            );

        if ($origin !== '' && $originAllowed) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
            header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization');
            header('Access-Control-Max-Age: 600');
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            if (!$originAllowed) {
                self::respond(403, ['status' => 'error', 'code' => 'origin_not_allowed']);
            }
            http_response_code(204);
            exit;
        }

        if (!$originAllowed) {
            self::respond(403, ['status' => 'error', 'code' => 'origin_not_allowed']);
        }
    }

    private static function isPrivateNetworkOrigin(string $origin, array $allowedHosts): bool
    {
        if ($origin === '') {
            return true;
        }

        $parts = parse_url($origin);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        // An Origin must not contain user-info, path, query, or fragment.
        if (
            isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['path'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            return false;
        }

        foreach ($allowedHosts as $allowedHost) {
            $allowedHost = strtolower(rtrim(trim((string)$allowedHost), '.'));
            if ($allowedHost !== '' && rtrim($host, '.') === $allowedHost) {
                return true;
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $octets = array_map('intval', explode('.', $host));
            [$a, $b] = $octets;

            // Loopback: 127.0.0.0/8
            if ($a === 127) {
                return true;
            }

            // RFC1918: 10.0.0.0/8
            if ($a === 10) {
                return true;
            }

            // RFC1918: 172.16.0.0/12
            if ($a === 172 && $b >= 16 && $b <= 31) {
                return true;
            }

            // RFC1918: 192.168.0.0/16
            if ($a === 192 && $b === 168) {
                return true;
            }

            // Shared address space / Tailscale IPv4: 100.64.0.0/10
            if ($a === 100 && $b >= 64 && $b <= 127) {
                return true;
            }

            return false;
        }

        // IPv6 loopback.
        if ($host === '::1') {
            return true;
        }

        /*
         * Tailscale also supports IPv6, but this local stack currently discovers
         * and advertises its Tailscale IPv4 address. Exact Tailscale DNS names
         * remain permitted through allowed_origin_hosts.
         */
        return false;
    }

    public static function requireMethod(string $method): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== strtoupper($method)) {
            header('Allow: ' . strtoupper($method));
            self::respond(405, ['status' => 'error', 'code' => 'method_not_allowed']);
        }
    }


    public static function requireMethods(array|string $methods, string ...$moreMethods): string
    {
        if (is_array($methods)) {
            $allowed = $methods;
        } else {
            $allowed = array_merge([$methods], $moreMethods);
        }

        $allowed = array_values(array_unique(array_map(
            static fn ($method): string => strtoupper((string)$method),
                                                       $allowed
        )));

        $requestMethod = strtoupper(
            (string)($_SERVER['REQUEST_METHOD'] ?? 'GET')
        );

        if (!in_array($requestMethod, $allowed, true)) {
            header('Allow: ' . implode(', ', $allowed));
            self::respond(405, [
                'status' => 'error',
                'code' => 'method_not_allowed',
            ]);
        }

        return $requestMethod;
    }

    public static function basicCredentials(): ?array
    {
        $user = $_SERVER['PHP_AUTH_USER'] ?? null;
        $password = $_SERVER['PHP_AUTH_PW'] ?? null;
        if (is_string($user) && is_string($password)) {
            return [$user, $password];
        }

        $header = (string)(
            $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? ''
        );
        if (!preg_match('/\ABasic\s+([A-Za-z0-9+\/=]+)\z/i', trim($header), $matches)) {
            return null;
        }

        $decoded = base64_decode($matches[1], true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            return null;
        }

        return explode(':', $decoded, 2);
    }


    public static function jsonInput(int $maxBytes = 65536): array
    {
        $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > $maxBytes) {
            self::respond(413, ['status' => 'error', 'code' => 'payload_too_large']);
        }
        $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
        if ($raw === false || strlen($raw) > $maxBytes) {
            self::respond(413, ['status' => 'error', 'code' => 'payload_too_large']);
        }
        try {
            $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            self::respond(400, ['status' => 'error', 'code' => 'invalid_json']);
        }
        if (!is_array($input)) self::respond(400, ['status' => 'error', 'code' => 'invalid_json']);
        return $input;
    }

    public static function clientIdentifier(array $authConfig): string
    {
        $address = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (($authConfig['trust_proxy_headers'] ?? false) === true) {
            $forwarded = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0]);
            if (filter_var($forwarded, FILTER_VALIDATE_IP)) $address = $forwarded;
        }
        return $address;
    }

    public static function run(callable $callback): never
    {
        try {
            [$status, $payload] = $callback();
            self::respond((int)$status, (array)$payload);
        } catch (ValidationException $error) {
            self::respond(422, ['status' => 'error', 'code' => 'validation_failed', 'errors' => $error->errors]);
        } catch (DuplicateIdentityException) {
            self::respond(409, ['status' => 'error', 'code' => 'identity_already_registered']);
        } catch (AuthDisabledException) {
            self::respond(403, ['status' => 'error', 'code' => 'registration_disabled']);
        } catch (RateLimitExceededException) {
            header('Retry-After: 60');
            self::respond(429, ['status' => 'error', 'code' => 'rate_limit_exceeded']);
        } catch (InvalidTokenException) {
            self::respond(400, ['status' => 'error', 'code' => 'invalid_or_expired_token']);
        } catch (Throwable $error) {
            $requestId = bin2hex(random_bytes(8));
            error_log(sprintf('Auth API error [%s]: %s', $requestId, $error->getMessage()));
            self::respond(500, ['status' => 'error', 'code' => 'server_error', 'request_id' => $requestId]);
        }
    }

    public static function respond(int $status, array $payload): never
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }
}
