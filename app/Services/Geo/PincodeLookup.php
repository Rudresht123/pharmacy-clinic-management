<?php

namespace App\Services\Geo;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * What an Indian PIN code says about a place.
 *
 * Asked of India Post's public API, from the server rather than the browser,
 * for three reasons that are really one: every client — the web app, the
 * phone, whatever comes next — gets the same answer from one place; the
 * answer can be remembered, because PIN codes do not move; and a third-party
 * outage becomes one clear error here instead of a CORS failure or a hung
 * request inside somebody's registration form.
 *
 * Organization-agnostic on purpose. A PIN code is a fact about India, not
 * about a clinic, so the cache is shared across tenants.
 */
class PincodeLookup
{
    /** Six digits, never starting with zero — India Post has no 0xxxxx codes. */
    public const PATTERN = '/^[1-9][0-9]{5}$/';

    /** How long a found PIN code is remembered. They are re-drawn rarely. */
    private const FOUND_DAYS = 30;

    /**
     * How long "no such PIN code" is remembered. Shorter, because India Post
     * does add codes, and a new colony should not be refused for a month.
     */
    private const MISSING_DAYS = 1;

    /**
     * The place a PIN code belongs to, or null when India Post has no such code.
     *
     * @return array{
     *     pincode: string,
     *     country: string,
     *     state: string,
     *     district: string,
     *     areas: list<array{name: string, block: ?string, branch_type: ?string, delivery: bool}>
     * }|null
     *
     * @throws PincodeLookupUnavailable when India Post cannot be asked
     */
    public function find(string $pincode): ?array
    {
        $pincode = trim($pincode);

        if (! preg_match(self::PATTERN, $pincode)) {
            return null;
        }

        $key = "geo:pincode:{$pincode}";

        $remembered = Cache::get($key);

        if (is_array($remembered) && array_key_exists('place', $remembered)) {
            return $remembered['place'];
        }

        // Throws on an outage, which is the point: an outage is never cached.
        $place = $this->ask($pincode);

        Cache::put(
            $key,
            ['place' => $place],
            now()->addDays($place === null ? self::MISSING_DAYS : self::FOUND_DAYS),
        );

        return $place;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function ask(string $pincode): ?array
    {
        try {
            $response = Http::baseUrl((string) config('services.india_post.url'))
                /*
                 * Named, not left as Guzzle's default. India Post's firewall
                 * resets any request that introduces itself as "GuzzleHttp"
                 * within a second — found by sending curl with that same
                 * User-Agent and watching it fail identically.
                 */
                ->withUserAgent((string) config('services.india_post.user_agent'))
                // Long, because the service genuinely is slow — 20 seconds and
                // more for an answer is normal. Only the first lookup of each
                // code pays it; after that the answer is cached.
                ->timeout((int) config('services.india_post.timeout'))
                /*
                 * One more try, and only for a dropped connection, which this
                 * service does intermittently. A 500 is an answer and is not
                 * retried: it is reported at once as the service being down.
                 */
                ->retry(2, 400, fn (\Throwable $e) => $e instanceof ConnectionException, throw: false)
                ->acceptJson()
                ->get("pincode/{$pincode}");
        } catch (ConnectionException $exception) {
            throw new PincodeLookupUnavailable('India Post could not be reached.', 0, $exception);
        }

        if (! $response->successful()) {
            throw new PincodeLookupUnavailable("India Post answered {$response->status()}.");
        }

        /*
         * The answer is a one-element list: [{ Status, Message, PostOffice }].
         * Anything else is a change on their side, and is reported as the
         * service being unavailable rather than guessed at.
         */
        $body = $response->json();
        $result = is_array($body) ? ($body[0] ?? null) : null;

        if (! is_array($result) || ! array_key_exists('Status', $result)) {
            throw new PincodeLookupUnavailable('India Post answered in an unexpected shape.');
        }

        $offices = collect(is_array($result['PostOffice'] ?? null) ? $result['PostOffice'] : [])
            ->filter(fn ($office) => is_array($office));

        if ($result['Status'] !== 'Success' || $offices->isEmpty()) {
            return null;
        }

        return [
            'pincode' => $pincode,
            'country' => $this->mostCommon($offices, 'Country') ?: 'India',
            'state' => $this->mostCommon($offices, 'State'),
            'district' => $this->mostCommon($offices, 'District'),

            /*
             * Every post office the code covers, for somebody to pick their
             * locality from. A code in a city routinely covers a dozen —
             * 201301 is most of Noida — so the state and district are
             * certain but the area is a choice.
             */
            'areas' => $offices
                ->map(fn (array $office) => [
                    'name' => trim((string) ($office['Name'] ?? '')),
                    'block' => $this->textOrNull($office['Block'] ?? null),
                    'branch_type' => $this->textOrNull($office['BranchType'] ?? null),
                    'delivery' => ($office['DeliveryStatus'] ?? null) === 'Delivery',
                ])
                ->filter(fn (array $area) => $area['name'] !== '')
                ->unique('name')
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all(),
        ];
    }

    /**
     * The value most of the offices agree on.
     *
     * Almost always they all say the same thing. The rare code that straddles
     * a district boundary is answered with the district most of its offices
     * sit in, rather than whichever happened to be listed first.
     *
     * @param  Collection<int, array<string, mixed>>  $offices
     */
    private function mostCommon(Collection $offices, string $field): string
    {
        return (string) $offices
            ->map(fn (array $office) => trim((string) ($office[$field] ?? '')))
            ->filter()
            ->countBy()
            ->sortDesc()
            ->keys()
            ->first();
    }

    private function textOrNull(mixed $value): ?string
    {
        $text = trim((string) $value);

        // India Post writes "NA" where it has nothing to say.
        return $text === '' || strtoupper($text) === 'NA' ? null : $text;
    }
}
