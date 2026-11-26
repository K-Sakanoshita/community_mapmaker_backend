<?php
declare(strict_types=1);

namespace CommunityMapMaker\Auth;

use PDO;
use PDOException;
use RuntimeException;

final class ExternalApps
{
    public const SCOPES = ['activities:create', 'activities:update', 'activities:delete', 'batch:create', 'batch:update', 'batch:delete'];
    private const CODE_TTL = 90;
    private const TOKEN_TTL = 900;

    public function __construct(private PDO $pdo) {}

    private function row(string $sql, array $params): ?array
    {
        $query = $this->pdo->prepare($sql);
        $query->execute($params);
        $row = $query->fetch();
        return $row === false ? null : $row;
    }

    private function execute(string $sql, array $params): void
    {
        $this->pdo->prepare($sql)->execute($params);
    }

    private function app(string $clientId, bool $lock = false): array
    {
        $app = $this->row('SELECT * FROM external_apps WHERE client_id = ?' . ($lock ? ' FOR UPDATE' : ''), [$clientId]);
        if ($app === null) throw new ExternalAppException('invalid_client', 404);
        return self::present($app);
    }

    private static function present(array $app): array
    {
        $app['enabled'] = (bool)$app['enabled'];
        $app['scopes'] = json_decode($app['scopes'], true, flags: JSON_THROW_ON_ERROR);
        return $app;
    }

    public function list(): array
    {
        return array_map(self::present(...), $this->pdo->query('SELECT * FROM external_apps ORDER BY client_id')->fetchAll());
    }

    public function allowsOrigin(string $origin): bool
    {
        return $this->row('SELECT client_id FROM external_apps WHERE allowed_origin=? AND enabled=1 LIMIT 1', [$origin]) !== null;
    }

    /** Changes to a registered client revoke its grants, including outstanding codes. */
    public function save(array $input, bool $create): array
    {
        $id = self::identifier($input['client_id'] ?? null, 'client_id');
        $appKey = self::identifier($input['app_key'] ?? null, 'app_key');
        $name = is_string($input['app_name'] ?? null) ? trim($input['app_name']) : '';
        if ($name === '' || mb_strlen($name, 'UTF-8') > 255 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
            throw new ValidationException(['app_name' => 'Enter an app name of up to 255 characters.']);
        }
        $origin = $input['allowed_origin'] ?? null;
        $redirect = $input['redirect_uri'] ?? null;
        if (!is_string($origin) || strlen($origin) > 255 || self::httpsOrigin($origin) !== $origin) {
            throw new ValidationException(['allowed_origin' => 'Enter an HTTPS origin without a path or trailing slash.']);
        }
        if (!is_string($redirect) || strlen($redirect) > 2048 || self::httpsOrigin($redirect) !== $origin) {
            throw new ValidationException(['redirect_uri' => 'Enter an HTTPS URL on the registered origin.']);
        }
        parse_str((string)parse_url($redirect, PHP_URL_QUERY), $redirectQuery);
        if (array_intersect(['code', 'state', 'error'], array_keys($redirectQuery))) {
            throw new ValidationException(['redirect_uri' => 'The redirect URL reserves the code, state and error query parameters.']);
        }
        $scopes = $input['scopes'] ?? null;
        if (!is_array($scopes) || !array_is_list($scopes) || $scopes === [] ||
            count(array_filter($scopes, static fn($scope) => in_array($scope, self::SCOPES, true))) !== count($scopes)) {
            throw new ValidationException(['scopes' => 'Select at least one supported write operation.']);
        }
        $scopes = array_values(array_intersect(self::SCOPES, $scopes));
        $enabled = $input['enabled'] ?? true;
        if (!is_bool($enabled)) throw new ValidationException(['enabled' => 'Use a boolean.']);
        $definition = ['client_id' => $id, 'app_name' => $name, 'allowed_origin' => $origin,
            'redirect_uri' => $redirect, 'app_key' => $appKey, 'scopes' => $scopes, 'enabled' => $enabled];
        if (!$create && $this->app($id, true) == $definition) return $definition;
        if ($this->row('SELECT id FROM projects WHERE app_key=? AND is_deleted=0', [$appKey]) === null) {
            throw new ExternalAppException('project_access_denied', 403);
        }
        $values = [$name, $origin, $redirect, $appKey, json_encode($scopes, JSON_THROW_ON_ERROR), (int)$enabled, $id];
        try {
            $this->execute($create
                ? 'INSERT INTO external_apps (app_name, allowed_origin, redirect_uri, app_key, scopes, enabled, client_id) VALUES (?,?,?,?,?,?,?)'
                : 'UPDATE external_apps SET app_name=?, allowed_origin=?, redirect_uri=?, app_key=?, scopes=?, enabled=? WHERE client_id=?',
                $values);
        } catch (PDOException $error) {
            if ($create && ($error->errorInfo[1] ?? null) === 1062) throw new ExternalAppException('client_already_exists', 409);
            throw $error;
        }
        if (!$create) $this->execute('DELETE FROM external_app_grants WHERE client_id = ?', [$id]);
        return $definition;
    }

    public function stop(string $clientId): void
    {
        $this->app($clientId, true);
        $this->execute('UPDATE external_apps SET enabled = 0 WHERE client_id = ?', [$clientId]);
        $this->execute('DELETE FROM external_app_grants WHERE client_id = ?', [$clientId]);
    }

    private static function identifier(mixed $value, string $field): string
    {
        if (!is_string($value) || !preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/', $value)) {
            throw new ValidationException([$field => 'Enter a lowercase identifier of up to 64 characters.']);
        }
        return $value;
    }

    private static function httpsOrigin(string $url): ?string
    {
        if (preg_match('/[^\x21-\x7e]|\\\\/', $url) || !filter_var($url, FILTER_VALIDATE_URL)) return null;
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) return null;
        return 'https://' . strtolower($parts['host']) . (isset($parts['port']) && $parts['port'] !== 443 ? ':' . $parts['port'] : '');
    }

    private function requireProject(string $appKey, bool $lock = false): array
    {
        $project = $this->row('SELECT id, project_name, enabled, is_deleted FROM projects WHERE app_key = ?' . ($lock ? ' FOR UPDATE' : ''), [$appKey]);
        if ($project === null || !$project['enabled'] || $project['is_deleted']) {
            throw new ExternalAppException('project_access_denied', 403);
        }
        return $project;
    }

    private function member(int $userId, string $appKey, bool $lock): array
    {
        $suffix = $lock ? ' FOR UPDATE' : '';
        $user = $this->row('SELECT u.id, u.userid, u.status, u.role FROM users u JOIN osm_identities o ON o.user_id=u.id WHERE u.id=?' . $suffix, [$userId]);
        $project = $this->requireProject($appKey, $lock);
        $membership = $this->row('SELECT role FROM user_projects WHERE project_id=? AND user_id=?' . $suffix, [$project['id'], $userId]);
        $role = $membership['role'] ?? null;
        if ($user === null || $user['status'] !== 'active' || !in_array($user['role'], ['user', 'admin'], true)
            || !in_array($role, ['contributor', 'editor', 'project_admin'], true)) {
            throw new ExternalAppException('project_access_denied', 403);
        }
        return ['userid' => $user['userid'], 'role' => $role];
    }

    public function requestAuthorization(array $input): array
    {
        $app = $this->app(self::identifier($input['client_id'] ?? null, 'client_id'));
        if (!$app['enabled']) throw new ExternalAppException('invalid_client', 403);
        if (($input['redirect_uri'] ?? null) !== $app['redirect_uri']) throw new ExternalAppException('invalid_redirect_uri', 400);
        $challenge = $input['code_challenge'] ?? null;
        $state = $input['state'] ?? null;
        if (($input['response_type'] ?? null) !== 'code' || ($input['code_challenge_method'] ?? null) !== 'S256'
            || !is_string($challenge) || !preg_match('/\A[A-Za-z0-9_-]{43}\z/', $challenge)
            || !is_string($state) || !preg_match('/\A[\x21-\x7e]{1,256}\z/', $state)) {
            throw new ExternalAppException('invalid_request', 400);
        }
        return ['app' => $app, 'challenge' => $challenge, 'state' => $state, 'nonce' => bin2hex(random_bytes(32)),
            'expires_at' => time() + 600];
    }

    private function consentApp(array $request, bool $lock = false): array
    {
        $app = $this->app($request['app']['client_id'], $lock);
        if ($request['expires_at'] <= time() || !$app['enabled'] || $request['app'] !== $app) {
            throw new ExternalAppException('authorization_expired', 409);
        }
        return $app;
    }

    public function consentDetails(array $request, int $userId): array
    {
        $app = $this->consentApp($request);
        $member = $this->member($userId, $app['app_key'], false);
        $project = $this->requireProject($app['app_key']);
        return $app + ['nonce' => $request['nonce'], 'userid' => $member['userid'], 'role' => $member['role'],
            'project_name' => $project['project_name']];
    }

    public function consent(array $request, int $userId, bool $approved): string
    {
        $app = $this->consentApp($request, true);
        $response = ['state' => $request['state']];
        if ($approved) {
            $this->member($userId, $app['app_key'], true);
            $this->execute('INSERT INTO external_app_grants (client_id,user_id) VALUES (?,?) ON DUPLICATE KEY UPDATE id=id', [$app['client_id'], $userId]);
            $grant = $this->row('SELECT id FROM external_app_grants WHERE client_id=? AND user_id=? FOR UPDATE', [$app['client_id'], $userId]);
            $this->execute('DELETE FROM external_app_keys WHERE grant_id=? AND expires_at<=UTC_TIMESTAMP()', [$grant['id']]);
            $response['code'] = $this->issueKey((int)$grant['id'], 'code', self::CODE_TTL, $request['challenge']);
        } else {
            $response['error'] = 'access_denied';
        }
        return $app['redirect_uri'] . (str_contains($app['redirect_uri'], '?') ? '&' : '?')
            . http_build_query($response, '', '&', PHP_QUERY_RFC3986);
    }

    private function issueKey(int $grantId, string $kind, int $ttl, ?string $challenge = null): string
    {
        $value = bin2hex(random_bytes(32));
        $this->execute('INSERT INTO external_app_keys (key_hash, grant_id, kind, challenge, expires_at) VALUES (?,?,?,?,?)',
            [hash('sha256', $value), $grantId, $kind, $challenge, gmdate('Y-m-d H:i:s', time() + $ttl)]);
        return $value;
    }

    private function findKey(mixed $value, string $kind, bool $lock): ?array
    {
        if (!is_string($value) || !preg_match('/\A[a-f0-9]{64}\z/', $value)) return null;
        return $this->row('SELECT k.*, g.client_id, g.user_id FROM external_app_keys k JOIN external_app_grants g ON g.id=k.grant_id
            WHERE k.key_hash=? AND k.kind=? AND k.expires_at>UTC_TIMESTAMP()' . ($lock ? ' FOR UPDATE' : ''), [hash('sha256', $value), $kind]);
    }

    public function exchange(array $input, string $origin): array
    {
        $app = $this->app(self::identifier($input['client_id'] ?? null, 'client_id'), true);
        if (!$app['enabled'] || $origin !== $app['allowed_origin']) throw new ExternalAppException('invalid_client', 403);
        if (($input['grant_type'] ?? null) !== 'authorization_code') throw new ExternalAppException('unsupported_grant_type', 400);
        $code = $this->findKey($input['code'] ?? null, 'code', true);
        $verifier = $input['code_verifier'] ?? null;
        if (($input['redirect_uri'] ?? null) !== $app['redirect_uri'] || $code === null || $code['client_id'] !== $app['client_id']
            || !is_string($verifier) || !preg_match('/\A[A-Za-z0-9._~-]{43,128}\z/', $verifier)
            || !hash_equals($code['challenge'], rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='))) {
            throw new ExternalAppException('invalid_grant', 400);
        }
        $this->member((int)$code['user_id'], $app['app_key'], true);
        $this->execute('DELETE FROM external_app_keys WHERE key_hash=?', [$code['key_hash']]);
        return ['access_token' => $this->issueKey((int)$code['grant_id'], 'token', self::TOKEN_TTL),
            'token_type' => 'Bearer', 'expires_in' => self::TOKEN_TTL, 'scope' => implode(' ', $app['scopes'])];
    }

    /** Must run in the same transaction as the protected operation. */
    public function access(?string $token, string $origin): array
    {
        $key = $this->findKey($token, 'token', false);
        if ($key === null) throw new ExternalAppException('invalid_token', 401);
        $app = $this->app($key['client_id'], true);
        $key = $this->findKey($token, 'token', true);
        if ($key === null || !$app['enabled'] || $origin !== $app['allowed_origin']) {
            throw new ExternalAppException('invalid_token', 401);
        }
        return $this->member((int)$key['user_id'], $app['app_key'], true) + $app + ['user_id' => (int)$key['user_id']];
    }

    public function writeAccess(string $appKey, array $operations): ExternalWriteAccess
    {
        $access = $this->access(Http::bearerToken(), (string)($_SERVER['HTTP_ORIGIN'] ?? ''));
        if ($access['app_key'] !== $appKey || array_diff($operations, $access['scopes'])
            || ($operations === [] && !array_intersect(['batch:create', 'batch:update', 'batch:delete'], $access['scopes']))) {
            throw new ExternalAppException('insufficient_scope', 403);
        }
        return new ExternalWriteAccess($this->pdo, $appKey, $access['user_id'], $access['role'] === 'contributor');
    }

    public function grants(int $userId): array
    {
        $query = $this->pdo->prepare('SELECT a.client_id,a.app_name,a.allowed_origin,a.app_key FROM external_apps a
            JOIN external_app_grants g ON g.client_id=a.client_id WHERE g.user_id=? ORDER BY a.app_name');
        $query->execute([$userId]);
        return $query->fetchAll();
    }

    public function revoke(string $clientId, int $userId): void
    {
        $this->app($clientId, true);
        $this->execute('DELETE FROM external_app_grants WHERE client_id=? AND user_id=?', [$clientId, $userId]);
    }
}

final class ExternalWriteAccess
{
    public function __construct(private PDO $pdo, private string $appKey, public readonly int $userId, private bool $ownersOnly) {}

    public function assertCanChange(string $key): void
    {
        if (!$this->ownersOnly) return;
        $query = $this->pdo->prepare('SELECT created_by_user_id FROM activities WHERE app_key=? AND activity_key=? AND is_deleted=0 FOR UPDATE');
        $query->execute([$this->appKey, $key]);
        if ((int)$query->fetchColumn() !== $this->userId) {
            throw new ExternalAppException('activity_owner_required', 403);
        }
    }
}

final class ExternalAppException extends RuntimeException
{
    public function __construct(string $code, public readonly int $status) { parent::__construct($code); }
}
