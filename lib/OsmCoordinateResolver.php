<?php
declare(strict_types=1);
namespace CommunityMapMaker\Activity;

use Closure;
use RuntimeException;
use Throwable;

/** Batched read-only Overpass lookups with a shared cache for preview and apply. */
final class OsmCoordinateResolver implements OsmCoordinateResolverInterface
{
    private Closure $transport;
    private ?Closure $fallbackTransport;
    private string $endpoint;
    private string $cacheDir;
    private int $ttl;
    private float $budget;

    public function __construct(array $config = [], ?callable $transport = null, ?callable $fallbackTransport = null)
    {
        $this->endpoint = (string)($config['endpoint'] ?? (getenv('CMM_OVERPASS_URL') ?: 'https://overpass-api.de/api/interpreter'));
        $parts = parse_url($this->endpoint);
        if (!$parts || !in_array($parts['scheme'] ?? '', ['http','https'], true) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) throw new RuntimeException('Invalid Overpass endpoint.');
        $this->cacheDir = (string)($config['cache_dir'] ?? sys_get_temp_dir() . '/cmm-osm-coordinates-' . substr(hash('sha256', $this->endpoint), 0, 12));
        $this->ttl = max(60, (int)($config['cache_ttl'] ?? 86400));
        $this->budget = max(1, min(25, (float)($config['budget_seconds'] ?? 20)));
        $this->transport = Closure::fromCallable($transport ?? [$this, 'fetch']);
        // Injected transports stay offline unless a fallback transport is explicitly supplied.
        $this->fallbackTransport = ($config['osm_api_fallback'] ?? true) && ($transport === null || $fallbackTransport !== null)
            ? Closure::fromCallable($fallbackTransport ?? [$this, 'fetchOsm']) : null;
    }

    public function resolve(array $osmids): array
    {
        $result = []; $pending = [];
        foreach (array_unique($osmids) as $osmid) {
            if (!is_string($osmid) || !preg_match('/\A(node|way|relation)\/([1-9][0-9]{0,18})\z/', $osmid)) continue;
            $file = $this->cacheFile($osmid);
            $cached = $file !== null && is_file($file) && filemtime($file) >= time() - $this->ttl
                ? json_decode((string)@file_get_contents($file), true) : null;
            $coordinates = self::coordinates($cached);
            if ($coordinates !== null) $result[$osmid] = $coordinates;
            else $pending[] = $osmid;
        }
        $deadline = microtime(true) + $this->budget;
        foreach (array_chunk($pending, 500) as $batch) {
            $remaining = $deadline - microtime(true);
            if ($remaining < 1) break;
            $groups = [];
            foreach ($batch as $osmid) { [$type, $id] = explode('/', $osmid); $groups[$type][] = $id; }
            $query = '[out:json][timeout:' . max(1, min(10, (int)$remaining - 1)) . '];(';
            foreach ($groups as $type => $ids) $query .= $type . '(id:' . implode(',', $ids) . ');';
            $query .= ');out skel center;';
            try {
                $response = ($this->transport)($query, min(12, $remaining));
                if (!is_array($response) || isset($response['remark']) || !isset($response['elements']) || !is_array($response['elements'])) break;
                $requested = array_fill_keys($batch, true);
                foreach ($response['elements'] as $element) {
                    if (!is_array($element)) continue;
                    $key = ($element['type'] ?? '') . '/' . ($element['id'] ?? '');
                    if (!isset($requested[$key])) continue;
                    $point = ($element['type'] ?? '') === 'node' ? $element : ($element['center'] ?? null);
                    $coordinates = self::coordinates(is_array($point) ? ['latitude'=>$point['lat'] ?? null, 'longitude'=>$point['lon'] ?? null] : null);
                    if ($coordinates === null) continue;
                    $result[$key] = $coordinates;
                    $file = $this->cacheFile($key);
                    if ($file !== null && (is_dir($this->cacheDir) || @mkdir($this->cacheDir, 0700, true))) {
                        @file_put_contents($file, json_encode($coordinates, JSON_THROW_ON_ERROR), LOCK_EX);
                    }
                }
            } catch (Throwable) {
                // Unavailable lookups are returned as unresolved, never as invented coordinates.
                break;
            }
        }
        $missing = array_values(array_diff($pending, array_keys($result)));
        if ($missing && $this->fallbackTransport !== null) {
            foreach ($this->resolveFromOsm($missing, $deadline) as $key => $coordinates) {
                $result[$key] = $coordinates;
                $file = $this->cacheFile($key);
                if ($file !== null && (is_dir($this->cacheDir) || @mkdir($this->cacheDir, 0700, true))) {
                    @file_put_contents($file, json_encode($coordinates, JSON_THROW_ON_ERROR), LOCK_EX);
                }
            }
        }
        return $result;
    }

    /** Official read-only API fallback, with the same overall request budget. */
    private function resolveFromOsm(array $osmids, float $deadline): array
    {
        $result = []; $nodes = []; $shapes = [];
        foreach ($osmids as $osmid) {
            [$type,$id] = explode('/', $osmid);
            if ($type === 'node') $nodes[] = $id; else $shapes[] = $osmid;
        }
        $get = function(string $path) use ($deadline): ?array {
            $remaining = $deadline - microtime(true);
            if ($remaining < 0.2) return null;
            try {
                $response = ($this->fallbackTransport)($path, min(6, $remaining));
                return is_array($response) && isset($response['elements']) && is_array($response['elements']) ? $response['elements'] : null;
            } catch (Throwable) { return null; }
        };
        foreach (array_chunk($nodes, 100) as $ids) {
            $elements = $get('nodes.json?nodes=' . implode(',', $ids));
            // One nonexistent ID makes multi-fetch fail. Split only failed batches,
            // with the deadline bounding all retries and preventing unbounded work.
            $batches = [[$ids, $elements]];
            while ($batches) {
                [$batch,$elements] = array_pop($batches);
                if ($deadline - microtime(true) < 0.2) break;
                if ($elements === null && count($batch) > 1) {
                    foreach (array_chunk($batch, (int)ceil(count($batch)/2)) as $half) $batches[] = [$half, $get('nodes.json?nodes=' . implode(',', $half))];
                    continue;
                }
                $requested = array_fill_keys($batch, true);
                foreach ($elements ?? [] as $element) {
                    if (!is_array($element) || ($element['type'] ?? '') !== 'node' || ($element['visible'] ?? true) === false || !isset($requested[(string)($element['id'] ?? '')])) continue;
                    $point = self::coordinates(['latitude'=>$element['lat'] ?? null,'longitude'=>$element['lon'] ?? null]);
                    if ($point !== null) $result['node/'.$element['id']] = $point;
                }
            }
        }
        foreach ($shapes as $osmid) {
            if ($deadline - microtime(true) < 0.2) break;
            [$type,$id] = explode('/', $osmid);
            $elements = $get($type.'/'.$id.'/full.json');
            if ($elements === null) continue;
            $indexed = [];
            foreach ($elements as $element) {
                if (is_array($element) && isset($element['type'],$element['id'])) $indexed[$element['type'].'/'.$element['id']] = $element;
            }
            $points = []; $visiting = [];
            // Refuse incomplete geometry (including nested relations absent from /full),
            // rather than deriving a misleading center from a subset of the nodes.
            $collect = function(string $key) use (&$collect,&$points,&$visiting,$indexed): bool {
                if (isset($visiting[$key]) || !isset($indexed[$key])) return false;
                $visiting[$key] = true; $element = $indexed[$key];
                if (($element['visible'] ?? true) === false) return false;
                if ($element['type'] === 'node') {
                    $point = self::coordinates(['latitude'=>$element['lat'] ?? null,'longitude'=>$element['lon'] ?? null]);
                    if ($point === null) return false;
                    $points[$key] = $point;
                } elseif ($element['type'] === 'way') {
                    if (empty($element['nodes'])) return false;
                    foreach ($element['nodes'] as $node) if (!$collect('node/'.$node)) return false;
                } elseif ($element['type'] === 'relation') {
                    if (empty($element['members'])) return false;
                    foreach ($element['members'] as $member) {
                        if (!is_array($member) || !isset($member['type'],$member['ref']) || !$collect($member['type'].'/'.$member['ref'])) return false;
                    }
                } else return false;
                unset($visiting[$key]); return true;
            };
            if (!$collect($osmid) || !$points) continue;
            $latitudes = array_column($points,'latitude'); $longitudes = array_column($points,'longitude');
            $result[$osmid] = self::coordinates(['latitude'=>(min($latitudes)+max($latitudes))/2,'longitude'=>(min($longitudes)+max($longitudes))/2]);
        }
        return $result;
    }

    private function cacheFile(string $osmid): ?string
    {
        return $this->cacheDir === '' ? null : $this->cacheDir . '/' . hash('sha256', $osmid) . '.json';
    }

    private static function coordinates(mixed $value): ?array
    {
        if (!is_array($value)) return null;
        foreach (['latitude'=>90, 'longitude'=>180] as $field=>$limit) {
            if (!isset($value[$field]) || !is_numeric($value[$field]) || !is_finite((float)$value[$field]) || abs((float)$value[$field]) > $limit) return null;
        }
        return ['latitude'=>round((float)$value['latitude'],7), 'longitude'=>round((float)$value['longitude'],7)];
    }

    private function fetch(string $query, float $timeout): array
    {
        return $this->fetchJson($this->endpoint, $timeout, $query);
    }

    private function fetchOsm(string $path, float $timeout): array
    {
        return $this->fetchJson('https://www.openstreetmap.org/api/0.6/' . $path, $timeout);
    }

    private function fetchJson(string $url, float $timeout, ?string $query = null): array
    {
        if (!extension_loaded('curl')) throw new RuntimeException('cURL is required for OSM coordinate lookup.');
        $curl = curl_init($url); $body = '';
        curl_setopt_array($curl, [
            CURLOPT_POST=>$query !== null,
            CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT_MS=>min(4000,(int)($timeout*1000)),
            CURLOPT_TIMEOUT_MS=>(int)($timeout*1000), CURLOPT_ENCODING=>'',
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HTTPHEADER=>['Accept: application/json','User-Agent: CommunityMapMaker/1.0 (OSM coordinate import)'],
            CURLOPT_WRITEFUNCTION=>static function ($handle,string $chunk) use (&$body):int {
                if (strlen($body)+strlen($chunk)>4194304) return 0;
                $body.=$chunk; return strlen($chunk);
            },
        ]);
        if ($query !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query(['data'=>$query]));
        try {
            $ok = curl_exec($curl); $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
            if ($ok===false || $status!==200) throw new RuntimeException('OSM coordinate lookup failed.');
            $data=json_decode($body,true,512,JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (!is_array($data)) throw new RuntimeException('Invalid OSM response.');
            return $data;
        } finally { curl_close($curl); }
    }
}
