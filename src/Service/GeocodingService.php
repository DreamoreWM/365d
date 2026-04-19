<?php

namespace App\Service;

use App\Entity\BonDeCommande;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Geocodes addresses using OpenStreetMap Nominatim and caches the result on BonDeCommande.
 * Nominatim's usage policy requires a meaningful User-Agent and ≤ 1 req/s; both are honored.
 */
class GeocodingService
{
    private const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';
    private const TIMEOUT_S     = 6;
    private const MIN_INTERVAL_US = 1_100_000; // 1.1 s between calls — Nominatim policy
    private const USER_AGENT    = '365d-planning/1.0 (admin@365d.local)';

    /** Timestamp (microseconds) of the last Nominatim call in this PHP process. */
    private float $lastCallAt = 0.0;

    /**
     * @var array<string, string> Addresses already attempted in this request,
     * mapped to a short error reason. Prevents retrying dead lookups in a loop.
     */
    private array $failedAttempts = [];

    public function __construct(
        private HttpClientInterface $httpClient,
        private EntityManagerInterface $em,
        private ?LoggerInterface $logger = null,
    ) {
        $this->logger ??= new NullLogger();
    }

    /**
     * Returns ['lat' => float, 'lng' => float] or null on failure.
     * Tries a restricted FR lookup first, then falls back to an open lookup.
     */
    public function geocode(string $address): ?array
    {
        $address = trim($address);
        if ($address === '') return null;

        if (isset($this->failedAttempts[$address])) return null;

        $result = $this->queryNominatim($address, ['countrycodes' => 'fr']);
        if ($result !== null) return $result;

        // Fallback: sometimes Nominatim's FR filter misses addresses — retry unconstrained
        $result = $this->queryNominatim($address, []);
        if ($result !== null) return $result;

        $this->failedAttempts[$address] = 'no_result';
        return null;
    }

    /**
     * Like geocode() but resilient to address mistakes: "45 rue Jean Jaurès" might not
     * exist but "45 avenue Jean Jaurès" does. We try the original first, then strip the
     * voie type (often enough on its own), then substitute it with common alternatives.
     *
     * Uses Nominatim's *structured* search when a postcode is present — that anchors the
     * lookup to the right municipality, so a street that exists in another city won't
     * win over a typo in the right one.
     *
     * @return array{coords: array{lat:float,lng:float}, matched: string}|null
     *         `matched` is the variant that Nominatim accepted (to cache as an override).
     */
    public function geocodeWithFallbacks(string $address): ?array
    {
        $address = trim($address);
        if ($address === '') return null;

        $parts = $this->parseAddress($address);
        $streetVariants = $this->buildStreetVariants($parts['street']);

        // Build a "must-contain" hint regex from postcode and/or city. This rejects
        // matches in the wrong municipality (e.g. "rue Léon Blum" exists in Wattrelos
        // and Hellemmes — without a hint we'd silently accept Wattrelos).
        $hintRegex = $this->buildLocationHintRegex($parts['postcode'], $parts['city']);

        // Try structured search first (anchors postcode + city)
        if ($parts['postcode'] !== null || $parts['city'] !== null) {
            foreach ($streetVariants as $street) {
                $coords = $this->queryNominatim('', [
                    'street'     => $street,
                    'postalcode' => $parts['postcode'] ?? '',
                    'city'       => $parts['city'] ?? '',
                    'country'    => 'France',
                ], $hintRegex);
                if ($coords !== null) {
                    $matched = $this->rebuildAddress($street, $parts['postcode'], $parts['city']);
                    return ['coords' => $coords, 'matched' => $matched];
                }
            }
        }

        // Fallback: free-form variants of the whole address — covers addresses without
        // a postcode and edge cases where structured search returns nothing.
        // We still pass the hint so the wrong-city matches get rejected.
        $variants = $this->buildAddressVariants($address);
        foreach ($variants as $v) {
            if (isset($this->failedAttempts[$v])) continue;
            $coords = $this->queryNominatim($v, ['countrycodes' => 'fr'], $hintRegex)
                   ?? $this->queryNominatim($v, [], $hintRegex);
            if ($coords !== null) {
                return ['coords' => $coords, 'matched' => $v];
            }
            $this->failedAttempts[$v] = 'no_result';
        }
        return null;
    }

    /**
     * Returns a case-insensitive regex matching either the postcode or the city in a
     * Nominatim `display_name`, or null if neither is known. The city regex is loose
     * (allows accents, "-" or " " separators) so "Hellemmes" matches "Hellemmes-Lille".
     */
    private function buildLocationHintRegex(?string $postcode, ?string $city): ?string
    {
        $alts = [];
        if ($postcode) $alts[] = preg_quote($postcode, '/');
        if ($city) {
            // Strip accents, lowercase, then build a permissive pattern
            $norm = $this->stripAccents(mb_strtolower($city));
            $alts[] = preg_quote($norm, '/');
        }
        if (empty($alts)) return null;
        return '/(' . implode('|', $alts) . ')/iu';
    }

    private function stripAccents(string $s): string
    {
        return strtr($s,
            ['à'=>'a','á'=>'a','â'=>'a','ä'=>'a','ã'=>'a','å'=>'a',
             'ç'=>'c',
             'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
             'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i',
             'ò'=>'o','ó'=>'o','ô'=>'o','ö'=>'o','õ'=>'o',
             'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u',
             'ý'=>'y','ÿ'=>'y','ñ'=>'n']);
    }

    /**
     * Splits a full address into street / postcode / city.
     * Tolerant: works whether the postcode is present or not, with or without comma.
     *
     * @return array{street:string, postcode:?string, city:?string}
     */
    private function parseAddress(string $address): array
    {
        $address = preg_replace('/\s+/', ' ', trim($address));
        $address = trim($address, " ,;");

        // "<street>, <postcode> <city>" or "<street> <postcode> <city>"
        if (preg_match('/^(.+?)[\s,]+(\d{5})\s+(.+)$/u', $address, $m)) {
            return ['street' => trim($m[1], " ,"), 'postcode' => $m[2], 'city' => trim($m[3])];
        }
        // "<street> <postcode>"
        if (preg_match('/^(.+?)[\s,]+(\d{5})$/u', $address, $m)) {
            return ['street' => trim($m[1], " ,"), 'postcode' => $m[2], 'city' => null];
        }
        // No postcode: heuristically split off the last 1–2 capitalised words as the city
        // ("89 rue Léon Blum Hellemmes" → street="89 rue Léon Blum", city="Hellemmes").
        // We only do this if the address contains a voie type — otherwise we don't know
        // where the street ends.
        if (preg_match($this->voieTypePattern(), $address)
            && preg_match('/^(.+?\b\p{L}+\b)[\s,]+(\p{Lu}[\p{L}\-\']+(?:\s+\p{Lu}[\p{L}\-\']+)?)$/u', $address, $m)) {
            // Make sure the "city" candidate isn't itself part of a proper noun street name
            // by checking the street part still ends with a recognisable word (>2 chars).
            $street = trim($m[1], " ,");
            $city   = trim($m[2]);
            if (mb_strlen($city) >= 3) {
                return ['street' => $street, 'postcode' => null, 'city' => $city];
            }
        }
        return ['street' => $address, 'postcode' => null, 'city' => null];
    }

    private function rebuildAddress(string $street, ?string $postcode, ?string $city): string
    {
        return trim($street . ' ' . ($postcode ?? '') . ' ' . ($city ?? ''));
    }

    /**
     * Generates street-only variants (voie type substitutions) keeping postcode/city anchored.
     * @return string[]
     */
    private function buildStreetVariants(string $street): array
    {
        $variants = [$street];
        $pattern = $this->voieTypePattern();
        if (!preg_match($pattern, $street)) {
            return $variants;
        }
        $stripped = trim(preg_replace($pattern, '', $street, 1));
        if ($stripped !== '' && $stripped !== $street) $variants[] = $stripped;

        $common = ['avenue', 'rue', 'boulevard', 'place', 'allée', 'chemin', 'route', 'impasse', 'cours', 'square'];
        foreach ($common as $sub) {
            $candidate = trim(preg_replace($pattern, $sub . ' ', $street, 1));
            if ($candidate !== '' && !in_array($candidate, $variants, true)) {
                $variants[] = $candidate;
            }
        }
        return $variants;
    }

    /**
     * Free-form variants of a whole address — used as a last-resort fallback when
     * structured search fails (e.g. addresses with no postcode).
     * @return string[]
     */
    private function buildAddressVariants(string $address): array
    {
        $variants = [$address];
        $pattern = $this->voieTypePattern();
        if (!preg_match($pattern, $address)) return $variants;

        $stripped = trim(preg_replace($pattern, '', $address, 1));
        if ($stripped !== '' && $stripped !== $address) $variants[] = $stripped;

        $common = ['avenue', 'rue', 'boulevard', 'place', 'allée', 'chemin', 'route', 'impasse', 'cours'];
        foreach ($common as $sub) {
            $candidate = trim(preg_replace($pattern, $sub . ' ', $address, 1));
            if ($candidate !== '' && !in_array($candidate, $variants, true)) {
                $variants[] = $candidate;
            }
        }
        return $variants;
    }

    /**
     * Regex that matches a French "voie type" word (rue, avenue, bd, …) anchored at a
     * word boundary and followed by whitespace. Also matches abbreviations like "av."
     */
    private function voieTypePattern(): string
    {
        $voieTypes = [
            'rue', 'avenue', 'av', 'boulevard', 'bd', 'bld', 'blvd',
            'place', 'allée', 'allee', 'chemin', 'impasse', 'imp',
            'route', 'rte', 'chaussée', 'chaussee', 'square', 'cours',
            'quai', 'passage', 'voie', 'esplanade', 'mail', 'parvis',
        ];
        return '/\b(' . implode('|', array_map(fn($t) => preg_quote($t, '/'), $voieTypes)) . ')\.?\s+/iu';
    }

    /**
     * @return array<string, string> Map of address → error reason for addresses that failed this request.
     */
    public function getFailedAttempts(): array
    {
        return $this->failedAttempts;
    }

    private function queryNominatim(string $address, array $extraQuery, ?string $hintRegex = null): ?array
    {
        $this->respectRateLimit();
        try {
            // When a hint regex is provided we ask for several results and pick the
            // first one whose display_name matches the hint (right city/postcode).
            $base = [
                'format' => 'json',
                'limit' => $hintRegex ? 5 : 1,
                'addressdetails' => 0,
            ];
            if ($address !== '') $base['q'] = $address;
            $resp = $this->httpClient->request('GET', self::NOMINATIM_URL, [
                'query' => array_merge($base, array_filter($extraQuery, fn($v) => $v !== '')),
                'headers' => [
                    'User-Agent' => self::USER_AGENT,
                    'Accept-Language' => 'fr',
                ],
                'timeout' => self::TIMEOUT_S,
            ]);

            $status = $resp->getStatusCode();
            if ($status !== 200) {
                $this->logger->warning('Nominatim non-200', ['status' => $status, 'addr' => $address]);
                if ($status === 429 || $status >= 500) {
                    $this->failedAttempts[$address] = "http_{$status}";
                }
                return null;
            }

            $data = json_decode($resp->getContent(false), true);
            if (!is_array($data) || empty($data)) {
                return null;
            }

            // Pick the first result that satisfies the hint (or the very first if no hint)
            $pick = null;
            if ($hintRegex) {
                foreach ($data as $row) {
                    $dn = $this->stripAccents(mb_strtolower($row['display_name'] ?? ''));
                    if (preg_match($hintRegex, $dn)) { $pick = $row; break; }
                }
            } else {
                $pick = $data[0];
            }
            if (!$pick || empty($pick['lat']) || empty($pick['lon'])) return null;

            return [
                'lat' => (float) $pick['lat'],
                'lng' => (float) $pick['lon'],
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('Nominatim error: ' . $e->getMessage(), ['addr' => $address]);
            $this->failedAttempts[$address] = 'exception';
            return null;
        }
    }

    private function respectRateLimit(): void
    {
        if ($this->lastCallAt === 0.0) {
            $this->lastCallAt = microtime(true);
            return;
        }
        $elapsedUs = (microtime(true) - $this->lastCallAt) * 1_000_000;
        if ($elapsedUs < self::MIN_INTERVAL_US) {
            usleep((int) (self::MIN_INTERVAL_US - $elapsedUs));
        }
        $this->lastCallAt = microtime(true);
    }

    /**
     * Geocodes a BonDeCommande if its current address differs from what was last geocoded.
     * Persists the new coordinates without flushing — caller is in charge of flush.
     * Returns true if coordinates are now available (either freshly geocoded or already cached).
     */
    public function ensureGeocoded(BonDeCommande $bon): bool
    {
        // Priority: admin-curated override → cleaned-up adresseGps helper → raw clientAdresse
        $address = $bon->getAdresseGpsOverride()
            ?: ($bon->getAdresseGps() ?: $bon->getClientAdresse());
        if (!$address) return false;

        if ($bon->hasCoordonnees() && $bon->getAdresseGeocodee() === $address) {
            return true;
        }

        $result = $this->geocodeWithFallbacks($address);
        if ($result === null) {
            return $bon->hasCoordonnees();
        }

        $bon->setLatitude((string) $result['coords']['lat']);
        $bon->setLongitude((string) $result['coords']['lng']);
        $bon->setAdresseGeocodee($result['matched']);

        // Auto-save the successful variant as an override so subsequent lookups
        // are instant and the correction is visible in the UI. Only if it differs
        // from the original address (don't overwrite a hand-typed override with
        // the same thing).
        if ($result['matched'] !== $address && $bon->getAdresseGpsOverride() === null) {
            $bon->setAdresseGpsOverride($result['matched']);
        }

        $this->em->persist($bon);
        return true;
    }

    /**
     * Haversine distance in kilometers between two lat/lng pairs.
     */
    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 2 * $earthRadius * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Estimated road travel time in minutes given an average speed and a detour factor.
     */
    public static function travelMinutes(float $km, float $speedKmh, float $detourFactor): int
    {
        if ($speedKmh <= 0) return 0;
        $effectiveKm = $km * max($detourFactor, 1.0);
        return (int) round(($effectiveKm / $speedKmh) * 60);
    }
}
