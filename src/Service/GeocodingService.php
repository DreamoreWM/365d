<?php

namespace App\Service;

use App\Entity\BonDeCommande;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Geocodes French addresses using the official BAN (Base Adresse Nationale)
 * API at api-adresse.data.gouv.fr — free, no key, no strict rate limit, and
 * specialized for French addresses (better fuzzy matching for typos like
 * "rue" → "allée" than OpenStreetMap Nominatim).
 *
 * The canonical label returned by BAN is stored on the bon as an override
 * so that both the map link and the optimizer use the verified address.
 */
class GeocodingService
{
    private const BAN_URL   = 'https://api-adresse.data.gouv.fr/search/';
    private const TIMEOUT_S = 6;

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
     * Kept for callers that only need coordinates and don't care about the
     * normalized label (e.g. ad-hoc distance calculations).
     */
    public function geocode(string $address): ?array
    {
        $r = $this->geocodeWithFallbacks($address);
        return $r ? $r['coords'] : null;
    }

    /**
     * Geocodes via BAN and returns both the coordinates and the canonical
     * address label. BAN returns the best match by relevance, so we trust
     * the first result — it already handles voie-type typos ("rue" vs
     * "allée") and municipality ambiguity natively.
     *
     * If BAN matches only at the street level (no housenumber) but the
     * user's query started with a number, we prepend that number to the
     * label so Google Maps / the optimizer still target the right building.
     *
     * @return array{coords: array{lat:float,lng:float}, matched: string}|null
     */
    public function geocodeWithFallbacks(string $address): ?array
    {
        $address = trim($address);
        if ($address === '') return null;

        if (isset($this->failedAttempts[$address])) return null;

        // Pull the leading house number from the original query ("89 bis" etc.)
        // so we can restore it if BAN returns a street-level match.
        $userHousenumber = null;
        if (preg_match('/^\s*(\d+\s*(?:bis|ter|quater|[A-Za-z])?)\s+/u', $address, $m)) {
            $userHousenumber = preg_replace('/\s+/', '', $m[1]);
        }

        $feature = $this->queryBan($address);
        if ($feature === null) {
            $this->failedAttempts[$address] = 'no_result';
            return null;
        }

        $props  = $feature['properties'] ?? [];
        $coords = $feature['geometry']['coordinates'] ?? null;
        if (!is_array($coords) || count($coords) < 2) {
            $this->failedAttempts[$address] = 'no_coords';
            return null;
        }
        // BAN uses [lng, lat]
        $lng = (float) $coords[0];
        $lat = (float) $coords[1];

        // Build the canonical label, re-inserting the housenumber if BAN dropped it
        $label = $this->buildLabel($props, $userHousenumber);

        return [
            'coords'  => ['lat' => $lat, 'lng' => $lng],
            'matched' => $label,
        ];
    }

    /**
     * @return array<string, string> Map of address → error reason for addresses that failed this request.
     */
    public function getFailedAttempts(): array
    {
        return $this->failedAttempts;
    }

    /**
     * Calls BAN's /search endpoint and returns the top feature, or null on failure.
     *
     * @return array<string, mixed>|null
     */
    private function queryBan(string $address): ?array
    {
        try {
            $resp = $this->httpClient->request('GET', self::BAN_URL, [
                'query'   => [
                    'q'            => $address,
                    'limit'        => 1,
                    'autocomplete' => 0,
                ],
                'timeout' => self::TIMEOUT_S,
            ]);

            $status = $resp->getStatusCode();
            if ($status !== 200) {
                $this->logger->warning('BAN non-200', ['status' => $status, 'addr' => $address]);
                if ($status === 429 || $status >= 500) {
                    $this->failedAttempts[$address] = "http_{$status}";
                }
                return null;
            }

            $data = json_decode($resp->getContent(false), true);
            if (!is_array($data) || empty($data['features'])) {
                return null;
            }
            return $data['features'][0];
        } catch (\Throwable $e) {
            $this->logger->warning('BAN error: ' . $e->getMessage(), ['addr' => $address]);
            $this->failedAttempts[$address] = 'exception';
            return null;
        }
    }

    /**
     * Rebuilds a usable address label from BAN's properties. If the match
     * type is "street" (no housenumber in the match), prepend the user's
     * original number — otherwise links like Google Maps land at the start
     * of the street instead of the correct building.
     *
     * @param array<string, mixed> $props
     */
    private function buildLabel(array $props, ?string $userHousenumber): string
    {
        $label = trim((string) ($props['label'] ?? ''));
        if ($label === '') return '';

        $type = $props['type'] ?? '';
        $labelHasNumber = (bool) preg_match('/^\s*\d/', $label);

        if ($userHousenumber !== null && $type !== 'housenumber' && !$labelHasNumber) {
            $label = $userHousenumber . ' ' . $label;
        }

        return $label;
    }

    /**
     * Geocodes a BonDeCommande if its current address differs from what was last geocoded.
     * Persists the new coordinates without flushing — caller is in charge of flush.
     * Returns true if coordinates are now available (either freshly geocoded or already cached).
     */
    public function ensureGeocoded(BonDeCommande $bon): bool
    {
        // Priority: admin-curated override → raw clientAdresse.
        // We deliberately bypass the getAdresseGps() cleaner when no override is
        // set — BAN handles noise better than our regex.
        $address = $bon->getAdresseGpsOverride() ?: $bon->getClientAdresse();
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
        $bon->setAdresseGeocodee($address);

        // Always store BAN's canonical label as the override when it differs from
        // what we queried — used by the GPS link AND as a cache key for next time.
        $matched = $result['matched'];
        if ($matched !== '' && $matched !== $address) {
            $bon->setAdresseGpsOverride($matched);
            $bon->setAdresseGeocodee($matched);
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
