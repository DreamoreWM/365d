<?php

namespace App\Service;

class PdfTextExtractor
{
    public function extractLines(string $pdfPath): array
    {
        $raw   = $this->extractRaw($pdfPath);
        $fixed = $this->fixOcrText($raw);
        return array_values(array_filter(array_map('trim', explode("\n", $fixed))));
    }

    public function extractRaw(string $pdfPath): string
    {
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf    = $parser->parseFile($pdfPath);
            return $pdf->getText();
        } catch (\Throwable) {
            return '';
        }
    }

    public function fixOcrText(string $text): string
    {
        $text = preg_replace('/\[\s+(?=[A-ZÉÈÊÀÂ])/u', '1 ', $text);
        $text = preg_replace('/\]\s+(?=[A-ZÉÈÊÀÂ])/u', '1 ', $text);
        $text = preg_replace('/\[(?=[A-ZÉÈÊÀÂ])/u', '1', $text);
        $text = preg_replace('/\](?=[A-ZÉÈÊÀÂ])/u', '1', $text);
        $text = preg_replace('/\|\s+(?=RUE|AVENUE|BOULEVARD|BLVD|ALLEE|ALLÉE|IMPASSE|CHEMIN|PLACE|ROUTE|PASSAGE)\b/i', 'I ', $text);
        $text = preg_replace_callback('/\b([\d]{2}[\s.]?){4}[\d]{2}\b/', function ($m) {
            return str_replace(['O', 'o'], '0', $m[0]);
        }, $text);
        return $text;
    }
}
