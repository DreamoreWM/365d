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
     * @return array<string, string> Map of address → error reason for addresses that failed this request.
     */
    public function getFailedAttempts(): array
    {
        return $this->failedAttempts;
    }

    private function queryNominatim(string $address, array $extraQuery): ?array
    {
        $this->respectRateLimit();
        try {
            $resp = $this->httpClient->request('GET', self::NOMINATIM_URL, [
                'query' => array_merge([
                    'q' => $address,
                    'format' => 'json',
                    'limit' => 1,
                    'addressdetails' => 0,
                ], $extraQuery),
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
            if (!is_array($data) || empty($data[0]['lat']) || empty($data[0]['lon'])) {
                return null;
            }

            return [
                'lat' => (float) $data[0]['lat'],
                'lng' => (float) $data[0]['lon'],
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

        $coords = $this->geocode($address);
        if ($coords === null) {
            return $bon->hasCoordonnees();
        }

        $bon->setLatitude((string) $coords['lat']);
        $bon->setLongitude((string) $coords['lng']);
        $bon->setAdresseGeocodee($address);
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
