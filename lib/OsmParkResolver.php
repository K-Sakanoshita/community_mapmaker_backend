<?php
declare(strict_types=1);

namespace CommunityMapMaker\Activity;

use Closure;
use RuntimeException;
use Throwable;

final class ParkNotFoundException extends RuntimeException {}
final class ParkSourceUnavailableException extends RuntimeException {}

/** Cached, bounded read-only lookup. The endpoint is server configuration, never request input. */
final class OsmParkResolver
{
    private Closure $transport;
    private string $endpoint;
    private string $cacheDir;

    public function __construct(private array $config = [], ?callable $transport = null)
    {
        $this->endpoint = (string)($config['endpoint'] ?? 'https://overpass-api.de/api/interpreter');
        if (!PageRendering::isWebUrl($this->endpoint)) throw new RuntimeException('Invalid park lookup endpoint.');
        $this->cacheDir = (string)($config['cache_dir'] ?? sys_get_temp_dir() . '/cmm-parks-' . substr(hash('sha256', $this->endpoint), 0, 12));
        $this->transport = Closure::fromCallable($transport ?? [$this, 'fetch']);
    }

    public function find(string $osmid): array
    {
        if (!preg_match('~\A(node|way|relation)/([1-9][0-9]{0,18})\z~', $osmid, $parts)) {
            throw new ActivityValidationException(['osmid' => 'Use node/ID, way/ID or relation/ID.']);
        }
        $file = $this->cacheDir === '' ? null : $this->cacheDir . '/' . hash('sha256', $osmid) . '.json';
        $lock = null;
        if ($file !== null) {
            if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0700, true) && !is_dir($this->cacheDir)) {
                throw new ParkSourceUnavailableException('Park cache is not writable.');
            }
            // Serialize each ID lookup and fail quickly if another request is already refreshing it.
            $lock = @fopen($file . '.lock', 'c');
            if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
                if (is_resource($lock)) fclose($lock);
                throw new ParkSourceUnavailableException('Park refresh in progress.');
            }
        }
        try {
            $cached = $file !== null && is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
            if (is_array($cached) && ($cached['expires_at'] ?? 0) > time()) return $this->readResult($cached);
            try {
                $response = ($this->transport)('[out:json][timeout:5];' . $parts[1] . '(' . $parts[2] . ');out meta center;');
                if (!is_array($response) || isset($response['remark']) || !isset($response['elements']) || !is_array($response['elements'])) {
                    throw new ParkSourceUnavailableException('Incomplete OSM response.');
                }
                $park = null;
                foreach ($response['elements'] as $element) {
                    if (is_array($element) && ($element['type'] ?? '') . '/' . ($element['id'] ?? '') === $osmid) {
                        $park = $this->normalize($element, $osmid);
                        break;
                    }
                }
                $result = ['state' => $park === null ? 'missing' : 'ok', 'park' => $park,
                    'expires_at' => time() + ($park === null ? 300 : max(60, (int)($this->config['cache_ttl'] ?? 86400)))];
            } catch (Throwable $error) {
                $result = ['state' => 'unavailable', 'expires_at' => time() + 30];
            }
            if ($file !== null && @file_put_contents($file, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX) === false) {
                throw new ParkSourceUnavailableException('Park cache write failed.');
            }
            return $this->readResult($result);
        } finally {
            if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
        }
    }

    private function readResult(array $result): array
    {
        if ($result['state'] === 'missing') throw new ParkNotFoundException('Park not found.');
        if ($result['state'] !== 'ok' || !is_array($result['park'] ?? null)) throw new ParkSourceUnavailableException('OSM source unavailable.');
        return $result['park'];
    }

    private function normalize(array $element, string $osmid): ?array
    {
        $tags = is_array($element['tags'] ?? null) ? $element['tags'] : [];
        if (($element['visible'] ?? true) === false || !in_array($tags['leisure'] ?? '', ['park', 'playground', 'recreation_ground'], true)) return null;
        $point = $element['type'] === 'node' ? $element : ($element['center'] ?? []);
        foreach (['lat' => 90, 'lon' => 180] as $key => $limit) {
            if (!isset($point[$key]) || !is_numeric($point[$key]) || !is_finite((float)$point[$key]) || abs((float)$point[$key]) > $limit) {
                throw new ParkSourceUnavailableException('OSM location missing.');
            }
        }
        $text = static fn(mixed $value): string => is_string($value) ? mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]/', ' ', $value)), 0, 500) : '';
        $name = $text($tags['name:ja'] ?? $tags['name'] ?? '');
        return [
            'osmid' => $osmid,
            'name' => $name !== '' ? $name : (($tags['leisure'] === 'playground' ? '名前のない遊び場' : '名前のない公園')),
            'latitude' => round((float)$point['lat'], 7), 'longitude' => round((float)$point['lon'], 7),
            'address' => $text($tags['addr:full'] ?? implode('', array_map($text, [$tags['addr:province'] ?? '', $tags['addr:city'] ?? '', $tags['addr:suburb'] ?? '', $tags['addr:street'] ?? '', $tags['addr:housenumber'] ?? '']))),
            'osm_updated_at' => $text($element['timestamp'] ?? ''),
            'fetched_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    private function fetch(string $query): array
    {
        if (!extension_loaded('curl')) throw new ParkSourceUnavailableException('cURL is required.');
        $curl = curl_init($this->endpoint);
        $body = '';
        curl_setopt_array($curl, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['data' => $query]),
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: CommunityMapMaker/1.0 (public park pages)'],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 1048576) return 0;
                $body .= $chunk; return strlen($chunk);
            },
        ]);
        try {
            if (curl_exec($curl) === false || (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 200) throw new ParkSourceUnavailableException('OSM lookup failed.');
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (!is_array($data)) throw new ParkSourceUnavailableException('Invalid OSM response.');
            return $data;
        } finally { curl_close($curl); }
    }
}
