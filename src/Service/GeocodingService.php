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
    private const USER_AGENT    = '365d-planning/1.0 (admin@365d.local)';

    public function __construct(
        private HttpClientInterface $httpClient,
        private EntityManagerInterface $em,
        private ?LoggerInterface $logger = null,
    ) {
        $this->logger ??= new NullLogger();
    }

    /**
     * Returns ['lat' => float, 'lng' => float] or null on failure.
     */
    public function geocode(string $address): ?array
    {
        $address = trim($address);
        if ($address === '') return null;

        try {
            $resp = $this->httpClient->request('GET', self::NOMINATIM_URL, [
                'query' => [
                    'q' => $address,
                    'format' => 'json',
                    'limit' => 1,
                    'countrycodes' => 'fr',
                    'addressdetails' => 0,
                ],
                'headers' => [
                    'User-Agent' => self::USER_AGENT,
                    'Accept-Language' => 'fr',
                ],
                'timeout' => self::TIMEOUT_S,
            ]);

            if ($resp->getStatusCode() !== 200) {
                $this->logger->warning('Nominatim non-200', ['status' => $resp->getStatusCode()]);
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
            $this->logger->warning('Nominatim error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Geocodes a BonDeCommande if its current address differs from what was last geocoded.
     * Persists the new coordinates without flushing — caller is in charge of flush.
     * Returns true if coordinates are now available (either freshly geocoded or already cached).
     */
    public function ensureGeocoded(BonDeCommande $bon): bool
    {
        $address = $bon->getAdresseGps() ?: $bon->getClientAdresse();
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
