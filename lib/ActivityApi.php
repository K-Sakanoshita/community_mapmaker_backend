<?php
declare(strict_types=1);

namespace CommunityMapMaker\Activity;

require_once __DIR__ . '/ConsoleSession.php';

use CommunityMapMaker\Auth\Http;
use CommunityMapMaker\Auth\ProjectAccessDeniedException;
use Throwable;

final class ActivityApi
{
    public static function requireAuthentication(array $container): array
    {
        $credentials = Http::basicCredentials();
        if ($credentials === null) {
            $user = \CommunityMapMaker\Auth\ConsoleSession::user($container);
            if ($user !== null) return $user;
            self::unauthorized();
        }
        try {
            $user = $container['auth']->authenticate($credentials[0], $credentials[1]);
        } catch (Throwable) {
            $user = null;
        }
        if ($user === null) self::unauthorized();
        return $user;
    }

    public static function requireWriteAccess(array $container, string $appKey): ?array
    {
        $appConfig = $container['activity_schema']->appConfig($appKey);
        if (($appConfig['write_auth_required'] ?? true) !== true) return null;
        return self::requireProjectWriteAccess($container, $appKey);
    }

    public static function requireProjectWriteAccess(array $container, string $appKey): array
    {
        $container['activity_schema']->appConfig($appKey);
        $user = self::requireAuthentication($container);
        $container['project_access']->assertCanWrite($user, $appKey);
        return $user;
    }

    public static function run(callable $callback): never
    {
        try {
            [$status, $payload] = $callback();
            Http::respond((int)$status, (array)$payload);
        } catch (ActivityValidationException $error) {
            Http::respond(422, ['status' => 'error', 'code' => 'validation_failed', 'errors' => $error->errors]);
        } catch (UnknownAppException) {
            Http::respond(404, ['status' => 'error', 'code' => 'app_not_found']);
        } catch (ActivityNotFoundException) {
            Http::respond(404, ['status' => 'error', 'code' => 'activity_not_found']);
        } catch (DuplicateActivityException) {
            Http::respond(409, ['status' => 'error', 'code' => 'activity_already_exists']);
        } catch (ProjectNotFoundException) {
            Http::respond(404, ['status' => 'error', 'code' => 'project_not_found']);
        } catch (ProjectAccessDeniedException) {
            Http::respond(403, ['status' => 'error', 'code' => 'project_access_denied']);
        } catch (DuplicateProjectException) {
            Http::respond(409, ['status' => 'error', 'code' => 'project_already_exists']);
        } catch (Throwable $error) {
            $requestId = bin2hex(random_bytes(8));
            error_log(sprintf('Activity API error [%s]: %s', $requestId, $error->getMessage()));
            Http::respond(500, ['status' => 'error', 'code' => 'server_error', 'request_id' => $requestId]);
        }
    }

    public static function activityKey(array $input): string
    {
        $key = self::optionalActivityKey($input) ?? '';
        if ($key === '') throw new ActivityValidationException(['id' => 'Activity ID is required.']);
        return $key;
    }

    public static function optionalActivityKey(array $input = []): ?string
    {
        $path = trim((string)($_SERVER['PATH_INFO'] ?? ''), '/');
        $key = $path !== '' ? rawurldecode($path) : trim((string)($_GET['id'] ?? $input['id'] ?? ''));
        return $key === '' ? null : $key;
    }

    public static function appKey(array $input = []): string
    {
        return trim((string)($_GET['app'] ?? $input['app'] ?? $input['app_key'] ?? ''));
    }

    private static function unauthorized(): never
    {
        if (($_SERVER['HTTP_X_CONSOLE_SESSION'] ?? '') !== '1') {
            header('WWW-Authenticate: Basic realm="Community Map Maker"');
        }
        Http::respond(401, ['status' => 'error', 'code' => 'authentication_required']);
    }
}
