<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Forward geocoding through Nominatim (OpenStreetMap).
 *
 * Never throws: any upstream problem (timeout, non-2xx, malformed body) degrades to null coordinates.
 */
class GeocodingService
{
    private const CACHE_PREFIX = 'geocode:';

    /**
     * @param array{address?: ?string, city?: ?string, state?: ?string, zip_code?: ?string} $parts
     * @return array{latitude: ?float, longitude: ?float}
     */
    public function geocode(array $parts): array
    {
        $query = $this->buildQuery($parts);

        if ($query === '') {
            return $this->empty();
        }

        $key = self::CACHE_PREFIX . sha1($query);

        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $result = $this->fetch($query);

        // null means upstream failure: do not cache it so the next attempt can retry.
        if ($result === null) {
            return $this->empty();
        }

        Cache::put($key, $result, now()->addSeconds((int) config('services.nominatim.cache_ttl', 86400)));

        return $result;
    }

    /**
     * Normalized query: lowercase, single spaces, empty parts dropped.
     */
    private function buildQuery(array $parts): string
    {
        $values = [];
        foreach (['address', 'zip_code', 'city', 'state'] as $field) {
            $value = Str::of((string) ($parts[$field] ?? ''))->squish()->lower()->toString();
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return implode(', ', $values);
    }

    /**
     * @return array{latitude: ?float, longitude: ?float}|null null on upstream failure
     */
    private function fetch(string $query): ?array
    {
        $params = [
            'q'               => $query,
            'format'          => 'jsonv2',
            'limit'           => 1,
            'accept-language' => 'es',
        ];

        $countryCodes = config('services.nominatim.country_codes');
        if (!empty($countryCodes)) {
            $params['countrycodes'] = $countryCodes;
        }

        try {
            $response = Http::withHeaders(['User-Agent' => (string) config('services.nominatim.user_agent')])
                ->acceptJson()
                ->timeout((int) config('services.nominatim.timeout', 5))
                ->get(rtrim((string) config('services.nominatim.base_url'), '/') . '/search', $params);

            if (!$response->successful()) {
                Log::warning('Nominatim geocoding failed', ['status' => $response->status()]);

                return null;
            }

            $first = $response->json('0');

            if (!is_array($first) || !is_numeric($first['lat'] ?? null) || !is_numeric($first['lon'] ?? null)) {
                return $this->empty();
            }

            return [
                'latitude'  => round((float) $first['lat'], 8),
                'longitude' => round((float) $first['lon'], 8),
            ];
        } catch (\Throwable $e) {
            Log::warning('Nominatim geocoding error', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @return array{latitude: null, longitude: null}
     */
    private function empty(): array
    {
        return ['latitude' => null, 'longitude' => null];
    }
}
