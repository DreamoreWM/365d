<?php

namespace App\Service;

use App\Entity\PdfImportConfig;
use App\Entity\TypePrestation;
use Doctrine\ORM\EntityManagerInterface;

class PdfConfigExtractor
{
    public function __construct(private EntityManagerInterface $em) {}

    /** @return array<string,string> keyed by dbField */
    public function extract(array $lines, string $fullText, PdfImportConfig $config): array
    {
        $result = [];
        foreach ($config->getFieldMappings() as $mapping) {
            $dbField  = $mapping['dbField'] ?? '';
            $strategy = $mapping['strategy'] ?? '';

            $result[$dbField] = match ($strategy) {
                'line_index'           => $this->byLineIndex($lines, $mapping),
                'line_index_regex'     => $this->byLineIndexRegex($lines, $mapping),
                'line_index_date'      => $this->byLineIndexDate($lines, $mapping),
                'full_text_regex'      => $this->byFullTextRegex($fullText, $mapping),
                'type_prestation_code' => $this->detectType($fullText),
                default                => '',
            };
        }
        return $result;
    }

    private function byLineIndex(array $lines, array $m): string
    {
        return trim($lines[$m['lineIndex'] ?? -1] ?? '');
    }

    private function byLineIndexRegex(array $lines, array $m): string
    {
        $line = $lines[$m['lineIndex'] ?? -1] ?? '';
        $regex = $m['regex'] ?? '';
        if ($regex && preg_match($regex, $line, $matches)) {
            return trim($matches[$m['regexGroup'] ?? 0] ?? '');
        }
        return trim($line);
    }

    private function byLineIndexDate(array $lines, array $m): string
    {
        $line = $lines[$m['lineIndex'] ?? -1] ?? '';
        if (preg_match('/(\d{2}\/\d{2}\/\d{4})/', $line, $matches)) {
            $parts = explode('/', $matches[1]);
            return $parts[2] . '-' . $parts[1] . '-' . $parts[0];
        }
        return '';
    }

    private function byFullTextRegex(string $fullText, array $m): string
    {
        $regex = $m['regex'] ?? '';
        if ($regex && preg_match($regex, $fullText, $matches)) {
            return trim($matches[$m['regexGroup'] ?? 1] ?? '');
        }
        return '';
    }

    private function detectType(string $text): string
    {
        $types = $this->em->getRepository(TypePrestation::class)->findAll();
        foreach ($types as $tp) {
            if ($tp->getCode() && stripos($text, $tp->getCode()) !== false) {
                return (string) $tp->getId();
            }
        }
        return '';
    }
}
