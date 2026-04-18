<?php

namespace App\Service;

use App\Entity\Prestation;
use App\Entity\User;
use App\Enum\CreneauPrestation;
use App\Repository\PrestationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reorders flexible (matin/aprem) prestations of a (date, employee) pair to
 * minimize total travel time, while keeping fixed-hour (creneau=fixe) prestations
 * anchored at their scheduled time. Matin tours start from the depot; aprem
 * tours start from the last morning location (or depot if no morning).
 */
class TourneeOptimizer
{
    public function __construct(
        private EntityManagerInterface $em,
        private PrestationRepository $prestationRepo,
        private GeocodingService $geocoder,
        private ParametreService $parametres,
    ) {}

    /**
     * Optimize all flexible prestations for the given employee on the given day.
     * Persists changes (datePrestation + dureeTrajetMinutes) and flushes.
     *
     * @return array{depotMissing: bool, notGeocoded: array<int, string>}
     *         Diagnostic info for the caller (to surface warnings to the admin).
     */
    public function optimizeDay(\DateTimeImmutable $date, User $employe): array
    {
        $start = $date->setTime(0, 0, 0);
        $end   = $date->setTime(23, 59, 59);

        $prestations = $this->prestationRepo->createQueryBuilder('p')
            ->leftJoin('p.bonDeCommande', 'b')
            ->addSelect('b')
            ->where('p.datePrestation >= :start')
            ->andWhere('p.datePrestation <= :end')
            ->andWhere('p.employe = :emp')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->setParameter('emp', $employe)
            ->orderBy('p.datePrestation', 'ASC')
            ->getQuery()->getResult();

        if (empty($prestations)) {
            return ['depotMissing' => false, 'notGeocoded' => []];
        }

        // Make sure every bon used today is geocoded — best effort.
        $notGeocoded = [];
        foreach ($prestations as $p) {
            $bon = $p->getBonDeCommande();
            if (!$bon) continue;
            $this->geocoder->ensureGeocoded($bon);
            if (!$bon->hasCoordonnees()) {
                $notGeocoded[$bon->getId()] = $bon->getClientNom() ?: ('Bon #' . $bon->getId());
            }
        }

        $depotLat = $this->parametres->get(ParametreService::COMPANY_LATITUDE);
        $depotLng = $this->parametres->get(ParametreService::COMPANY_LONGITUDE);
        $depotCoords = ($depotLat !== '' && $depotLng !== null && $depotLng !== '')
            ? ['lat' => (float) $depotLat, 'lng' => (float) $depotLng]
            : null;
        $depotMissing = $depotCoords === null;

        $heurePremiere = $this->parametres->get(ParametreService::HEURE_PREMIERE);
        $heurePauseDeb = $this->parametres->get(ParametreService::HEURE_PAUSE_DEBUT);
        $heurePauseFin = $this->parametres->get(ParametreService::HEURE_PAUSE_FIN);
        $heureFin      = $this->parametres->get(ParametreService::HEURE_FIN_JOURNEE);

        // Split prestations by half-day
        $matinFlex = $matinFixed = $apremFlex = $apremFixed = [];
        $pauseDebMin = $this->hmToMin($heurePauseDeb);

        foreach ($prestations as $p) {
            $creneau = $p->getCreneau();
            $startMin = (int)$p->getDatePrestation()->format('H') * 60 + (int)$p->getDatePrestation()->format('i');
            $isMorning = $startMin < $pauseDebMin;

            // Only explicit FIXE acts as an anchor. MATIN/APREM/null are all flex —
            // null (legacy) is bucketed by time-of-day so older prestations reorder too.
            if ($creneau === CreneauPrestation::FIXE) {
                if ($isMorning) $matinFixed[] = $p; else $apremFixed[] = $p;
            } elseif ($creneau === CreneauPrestation::APREM) {
                $apremFlex[] = $p;
            } elseif ($creneau === CreneauPrestation::MATIN) {
                $matinFlex[] = $p;
            } else {
                if ($isMorning) $matinFlex[] = $p; else $apremFlex[] = $p;
            }
        }

        // Plan matin (from depot, first at 09:00)
        $matinTail = $this->planSegment(
            $date, $heurePremiere, $heurePauseDeb,
            $depotCoords, $matinFlex, $matinFixed,
        );

        // Aprem starts from the last morning location — depot fallback
        $apremStart = $matinTail['lastCoords'] ?? $depotCoords;
        $this->planSegment(
            $date, $heurePauseFin, $heureFin,
            $apremStart, $apremFlex, $apremFixed,
        );

        $this->em->flush();

        return ['depotMissing' => $depotMissing, 'notGeocoded' => array_values($notGeocoded)];
    }

    /**
     * Places flexible prestations into a [startTime, endTime] window using nearest-neighbor
     * from $startCoords, while skipping over fixed anchors. Returns the last-visited coords.
     *
     * @param array<int, Prestation> $flex
     * @param array<int, Prestation> $fixed
     */
    private function planSegment(
        \DateTimeImmutable $date,
        string $startTime,
        string $endTime,
        ?array $startCoords,
        array $flex,
        array $fixed,
    ): array {
        if (empty($flex) && empty($fixed)) return ['lastCoords' => $startCoords];

        $speed   = $this->parametres->getFloat(ParametreService::VITESSE_MOYENNE_KMH);
        $detour  = $this->parametres->getFloat(ParametreService::TRAJET_FACTEUR_DETOUR);
        $defDur  = $this->parametres->getInt(ParametreService::DUREE_DEFAUT_MINUTES);

        // Sort fixed by their current start time
        usort($fixed, fn(Prestation $a, Prestation $b) => $a->getDatePrestation() <=> $b->getDatePrestation());

        // Build "blocked" intervals from fixed prestations [startMin, endMin]
        $blocks = [];
        foreach ($fixed as $f) {
            $s = $this->dtToMin($f->getDatePrestation());
            $e = $s + $this->durationOf($f, $defDur);
            $blocks[] = ['start' => $s, 'end' => $e, 'coords' => $this->coordsOf($f)];
        }

        $cursorMin   = $this->hmToMin($startTime);
        $endWindow   = $this->hmToMin($endTime);

        // Build an initial tour via nearest-neighbor, then improve with 2-opt.
        $ordered = $this->nearestNeighborOrder($startCoords, $flex);
        $ordered = $this->twoOptImprove($startCoords, $ordered, $speed, $detour);

        $cursorCoords = $startCoords;
        $isFirstFlex  = true;

        foreach ($ordered as $next) {
            $nextCoords = $this->coordsOf($next);
            $duration   = $this->durationOf($next, $defDur);
            $travel     = $this->travelMinutes($cursorCoords, $nextCoords, $speed, $detour);

            // First flex of the segment: start at startTime sharp (rule for 9h matin)
            $proposedStart = $isFirstFlex ? $cursorMin : ($cursorMin + $travel);
            $proposedEnd   = $proposedStart + $duration;

            // Skip past any fixed block that would overlap
            foreach ($blocks as $b) {
                if ($proposedStart < $b['end'] && $proposedEnd > $b['start']) {
                    $proposedStart = $b['end'];
                    $proposedEnd   = $proposedStart + $duration;
                    // Travel from the fixed anchor's location to next instead
                    $travel = $this->travelMinutes($b['coords'], $nextCoords, $speed, $detour);
                }
            }

            // Persist the new schedule
            $newStart = $date->setTime((int) floor($proposedStart / 60), $proposedStart % 60);
            $next->setDatePrestation($newStart);
            $next->setDureeTrajetMinutes($isFirstFlex ? 0 : $travel);
            if ($next->getDureeMinutes() === null) $next->setDureeMinutes($duration);

            $cursorMin    = $proposedEnd;
            $cursorCoords = $nextCoords ?? $cursorCoords;
            $isFirstFlex  = false;

            // Stop placing if we've blown past the end of the window — leave remainder where it falls
            if ($cursorMin > $endWindow + 60) break;
        }

        // Compute travel for fixed prestations from whatever was previously visited
        $allInWindow = array_merge($flex, $fixed);
        usort($allInWindow, fn(Prestation $a, Prestation $b) => $a->getDatePrestation() <=> $b->getDatePrestation());
        $prevCoords = $startCoords;
        $lastCoords = $startCoords;
        foreach ($allInWindow as $p) {
            $coords = $this->coordsOf($p);
            if ($p->getCreneau() === CreneauPrestation::FIXE) {
                $p->setDureeTrajetMinutes($this->travelMinutes($prevCoords, $coords, $speed, $detour));
                if ($p->getDureeMinutes() === null) $p->setDureeMinutes($this->durationOf($p, $defDur));
            }
            if ($coords) { $prevCoords = $coords; $lastCoords = $coords; }
        }

        return ['lastCoords' => $lastCoords];
    }

    /**
     * Builds an initial tour by repeatedly picking the nearest unscheduled prestation.
     * Prestations without coordinates are appended at the end (they don't participate in distance).
     *
     * @param array<int, Prestation> $flex
     * @return array<int, Prestation>
     */
    private function nearestNeighborOrder(?array $startCoords, array $flex): array
    {
        [$withCoords, $withoutCoords] = [[], []];
        foreach ($flex as $p) {
            $this->coordsOf($p) === null ? $withoutCoords[] = $p : $withCoords[] = $p;
        }

        $ordered = [];
        $cursor  = $startCoords;
        while (!empty($withCoords)) {
            $bestIdx = 0;
            if ($cursor !== null) {
                $bestDist = PHP_FLOAT_MAX;
                foreach ($withCoords as $i => $p) {
                    $c = $this->coordsOf($p);
                    $d = GeocodingService::haversineKm($cursor['lat'], $cursor['lng'], $c['lat'], $c['lng']);
                    if ($d < $bestDist) { $bestDist = $d; $bestIdx = $i; }
                }
            }
            $ordered[] = $withCoords[$bestIdx];
            $cursor = $this->coordsOf($withCoords[$bestIdx]);
            array_splice($withCoords, $bestIdx, 1);
        }

        return array_merge($ordered, $withoutCoords);
    }

    /**
     * Applies 2-opt swaps until no improvement is found. Reduces crossings like R-L-R → R-R-L.
     * Only swaps among prestations that have coordinates (others stay pinned at the end).
     *
     * @param array<int, Prestation> $tour
     * @return array<int, Prestation>
     */
    private function twoOptImprove(?array $startCoords, array $tour, float $speed, float $detour): array
    {
        if ($startCoords === null || count($tour) < 3) return $tour;

        // Separate the geocoded head from the uncoded tail (tail order is preserved)
        $head = []; $tail = [];
        foreach ($tour as $p) {
            $this->coordsOf($p) === null ? $tail[] = $p : $head[] = $p;
        }
        $n = count($head);
        if ($n < 3) return array_merge($head, $tail);

        $cost = fn(?array $a, ?array $b) => $a && $b
            ? GeocodingService::haversineKm($a['lat'], $a['lng'], $b['lat'], $b['lng'])
            : 0.0;

        $tourCost = function (array $seq) use ($startCoords, $cost) {
            $total = 0.0;
            $prev = $startCoords;
            foreach ($seq as $p) {
                $c = $this->coordsOf($p);
                $total += $cost($prev, $c);
                $prev = $c ?? $prev;
            }
            return $total;
        };

        $improved = true;
        $guard = 0;
        while ($improved && $guard++ < 50) {
            $improved = false;
            $best = $tourCost($head);
            for ($i = 0; $i < $n - 1; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $candidate = $head;
                    $slice = array_reverse(array_slice($candidate, $i, $j - $i + 1));
                    array_splice($candidate, $i, $j - $i + 1, $slice);
                    $c = $tourCost($candidate);
                    if ($c + 1e-9 < $best) {
                        $head = $candidate;
                        $best = $c;
                        $improved = true;
                    }
                }
            }
        }

        return array_merge($head, $tail);
    }

    private function travelMinutes(?array $from, ?array $to, float $speed, float $detour): int
    {
        if ($from === null || $to === null) return 0;
        $km = GeocodingService::haversineKm($from['lat'], $from['lng'], $to['lat'], $to['lng']);
        return GeocodingService::travelMinutes($km, $speed, $detour);
    }

    private function coordsOf(Prestation $p): ?array
    {
        $bon = $p->getBonDeCommande();
        if (!$bon || !$bon->hasCoordonnees()) return null;
        return ['lat' => (float) $bon->getLatitude(), 'lng' => (float) $bon->getLongitude()];
    }

    private function durationOf(Prestation $p, int $default): int
    {
        if ($p->getDureeMinutes() !== null) return $p->getDureeMinutes();
        $type = $p->getTypePrestation();
        if ($type && $type->getDureeTheoriqueMinutes() !== null) return $type->getDureeTheoriqueMinutes();
        return $default;
    }

    private function hmToMin(string $hm): int
    {
        [$h, $m] = array_pad(explode(':', $hm), 2, 0);
        return ((int) $h) * 60 + ((int) $m);
    }

    private function dtToMin(\DateTimeImmutable $dt): int
    {
        return ((int) $dt->format('H')) * 60 + ((int) $dt->format('i'));
    }
}
