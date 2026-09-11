<?php
declare(strict_types=1);

namespace CommunityMapMaker\Auth;

require_once __DIR__ . '/ConsoleSession.php';

use Throwable;

final class AdminApi
{
    public static function requireAdmin(array $container): array
    {
        $credentials = Http::basicCredentials();
        try {
            $user = $credentials === null ? ConsoleSession::user($container)
                : $container['auth']->authenticate($credentials[0], $credentials[1]);
        } catch (Throwable) {
            $user = null;
        }
        if ($user === null) self::unauthorized();
        if (($user['role'] ?? 'user') !== 'admin') {
            Http::respond(403, ['status' => 'error', 'code' => 'admin_required']);
        }
        return $user;
    }

    public static function userId(array $input = []): int
    {
        $id = (int)($_GET['id'] ?? $input['id'] ?? 0);
        if ($id < 1) throw new ValidationException(['id' => 'User ID is required.']);
        return $id;
    }

    public static function run(callable $callback): never
    {
        try {
            [$status, $payload] = $callback();
            Http::respond((int)$status, (array)$payload);
        } catch (ValidationException $error) {
            Http::respond(422, ['status' => 'error', 'code' => 'validation_failed', 'errors' => $error->errors]);
        } catch (DuplicateIdentityException) {
            Http::respond(409, ['status' => 'error', 'code' => 'identity_already_registered']);
        } catch (AdminUserNotFoundException) {
            Http::respond(404, ['status' => 'error', 'code' => 'user_not_found']);
        } catch (LastAdminException) {
            Http::respond(409, ['status' => 'error', 'code' => 'last_active_admin']);
        } catch (RateLimitExceededException) {
            header('Retry-After: 60');
            Http::respond(429, ['status' => 'error', 'code' => 'rate_limit_exceeded']);
        } catch (Throwable $error) {
            $requestId = bin2hex(random_bytes(8));
            error_log(sprintf('Admin API error [%s]: %s', $requestId, $error->getMessage()));
            Http::respond(500, ['status' => 'error', 'code' => 'server_error', 'request_id' => $requestId]);
        }
    }

    private static function unauthorized(): never
    {
        if (($_SERVER['HTTP_X_CONSOLE_SESSION'] ?? '') !== '1') {
            header('WWW-Authenticate: Basic realm="Community Map Maker Admin"');
        }
        Http::respond(401, ['status' => 'error', 'code' => 'authentication_required']);
    }
}
