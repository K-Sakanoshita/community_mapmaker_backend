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
        if ($origin !== '' && in_array($origin, $allowed, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Access-Control-Max-Age: 600');
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            if ($origin !== '' && !in_array($origin, $allowed, true)) {
                self::respond(403, ['status' => 'error', 'code' => 'origin_not_allowed']);
            }
            http_response_code(204);
            exit;
        }

        if ($origin !== '' && !in_array($origin, $allowed, true)) {
            self::respond(403, ['status' => 'error', 'code' => 'origin_not_allowed']);
        }
    }

    public static function requireMethod(string $method): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== strtoupper($method)) {
            header('Allow: ' . strtoupper($method));
            self::respond(405, ['status' => 'error', 'code' => 'method_not_allowed']);
        }
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

