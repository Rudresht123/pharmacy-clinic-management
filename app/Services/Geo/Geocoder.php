<?php

namespace App\Services\Geo;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * An Indian address to a point on the map, through OpenStreetMap's Nominatim.
 *
 * Indian addresses are written for a postman, not a search engine — "123,
 * Green Park, Near City Hospital" finds nothing, though "Green Park, New
 * Delhi" finds the colony at once. So it asks from most to least precise and
 * stops at the first answer, saying which one it was:
 *
 *   address   the address as written
 *   address   the locality, with house numbers and "near …" landmarks dropped
 *   pincode   the PIN code's area
 *   city      the city
 *
 * Every answer is cached — found for three months, not found for a day — and
 * requests are spaced a second apart, as Nominatim's usage policy asks.
 */
class Geocoder
{
    private const FOUND_DAYS = 90;

    private const MISSING_DAYS = 1;

    /** When this process last asked, for the one-a-second pace. */
    private static float $lastAsked = 0.0;

    /**
     * @return array{latitude: float, longitude: float, source: string}|null
     *
     * @throws GeocodingUnavailable when OpenStreetMap cannot be asked
     */
    public function locate(?string $address, ?string $city, ?string $state, ?string $pincode): ?array
    {
        $city = $this->clean($city);
        $state = $this->clean($state);
        $pincode = $this->clean($pincode);
        $address = $this->clean($address);

        $attempts = [];

        if ($address) {
            $attempts[] = ['address', ['q' => $this->join([$address, $city, $state, $pincode])]];

            $locality = $this->locality($address);

            if ($locality && $locality !== $address) {
                $attempts[] = ['address', ['q' => $this->join([$locality, $city, $state])]];
            }
        }

        if ($pincode && preg_match(PincodeLookup::PATTERN, $pincode)) {
            $attempts[] = ['pincode', ['postalcode' => $pincode, 'country' => 'India']];
        }

        if ($city) {
            $attempts[] = ['city', ['q' => $this->join([$city, $state])]];
        }

        foreach ($attempts as [$source, $query]) {
            $point = $this->search($query);

            if ($point) {
                return [...$point, 'source' => $source];
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $query
     * @return array{latitude: float, longitude: float}|null
     */
    private function search(array $query): ?array
    {
        $query += ['format' => 'jsonv2', 'limit' => '1', 'countrycodes' => 'in'];

        $key = 'geo:nominatim:'.md5(json_encode($query));
        $remembered = Cache::get($key);

        if (is_array($remembered) && array_key_exists('point', $remembered)) {
            return $remembered['point'];
        }

        $this->pace();

        try {
            $response = Http::baseUrl((string) config('services.nominatim.url'))
                ->withUserAgent((string) config('services.nominatim.user_agent'))
                ->timeout((int) config('services.nominatim.timeout'))
                ->acceptJson()
                ->get('search', $query);
        } catch (ConnectionException $exception) {
            throw new GeocodingUnavailable('OpenStreetMap could not be reached.', 0, $exception);
        }

        if (! $response->successful()) {
            throw new GeocodingUnavailable("OpenStreetMap answered {$response->status()}.");
        }

        $first = $response->json()[0] ?? null;

        $point = is_array($first) && is_numeric($first['lat'] ?? null) && is_numeric($first['lon'] ?? null)
            ? ['latitude' => (float) $first['lat'], 'longitude' => (float) $first['lon']]
            : null;

        Cache::put($key, ['point' => $point], now()->addDays($point ? self::FOUND_DAYS : self::MISSING_DAYS));

        return $point;
    }

    /**
     * The colony or street, without what only a postman needs: house and
     * plot numbers, floors, and "near / opposite / behind" landmarks.
     */
    private function locality(string $address): ?string
    {
        $parts = collect(preg_split('/[,\n]+/', $address))
            ->map(fn (string $part) => Str::squish($part))
            ->reject(fn (string $part) => $part === ''
                || preg_match('/^\d/', $part)
                || preg_match('/^(near|nr\.?|opp\.?|opposite|behind|beside|next to|in front of)\b/i', $part)
                || preg_match('/^(plot|flat|house|h\.?\s?no\.?|shop|floor|block|sector)\b/i', $part)
                || preg_match('/\b(floor|tower|building)\b/i', $part))
            ->values();

        return $parts->isEmpty() ? null : $parts->slice(-2)->implode(', ');
    }

    /** @param  list<?string>  $parts */
    private function join(array $parts): string
    {
        return implode(', ', array_filter([...$parts, 'India']));
    }

    private function clean(?string $value): ?string
    {
        $value = Str::squish((string) $value);

        return $value === '' ? null : $value;
    }

    /** Never two requests from this process inside a second. */
    private function pace(): void
    {
        $wait = 1.05 - (microtime(true) - self::$lastAsked);

        if ($wait > 0 && self::$lastAsked > 0 && ! app()->runningUnitTests()) {
            usleep((int) ($wait * 1_000_000));
        }

        self::$lastAsked = microtime(true);
    }
}
