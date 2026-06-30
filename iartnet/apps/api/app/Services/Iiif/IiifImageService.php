<?php

declare(strict_types=1);

namespace App\Services\Iiif;

use RuntimeException;

/**
 * Servizio per la costruzione degli URL IIIF e utilità su file immagine.
 *
 * Le immagini sono copiate nel folder IMAGES_ROOT (configurazione esterna);
 * questo servizio fornisce URL IIIF (Image API 2.x), checksum, MIME type e dimensioni.
 */
class IiifImageService
{
    /**
     * Base URL pubblico del server IIIF.
     */
    private string $publicBaseUrl;

    /**
     * Porta del server IIIF (opzionale).
     */
    private ?int $port;

    /**
     * Costruttore.
     *
     * Legge la configurazione dal file .env (IIIF_PUBLIC_BASE obbligatorio).
     */
    public function __construct()
    {
        $publicBaseUrl = config('services.iiif.public_base', env('IIIF_PUBLIC_BASE', ''));
        $this->port = config('services.iiif.port') !== null ? (int) config('services.iiif.port') : null;

        if (empty($publicBaseUrl)) {
            throw new RuntimeException('IIIF_PUBLIC_BASE non configurato nel file .env');
        }

        $this->publicBaseUrl = $this->applyPortToUrl($publicBaseUrl);
    }

    /**
     * Applica la porta configurata all'URL se specificata.
     *
     * Se la porta è configurata nel .env, la sostituisce o aggiunge all'URL.
     * Se la porta non è configurata, ritorna l'URL originale.
     *
     * @param  string  $url  URL originale
     * @return string  URL con la porta applicata
     */
    private function applyPortToUrl(string $url): string
    {
        if ($this->port === null) {
            return $url;
        }

        // Parse dell'URL
        $parsed = parse_url($url);

        if ($parsed === false) {
            // Se il parsing fallisce, ritorna l'URL originale
            return $url;
        }

        // Ricostruisce l'URL con la porta specificata
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $port = $this->port;
        $path = $parsed['path'] ?? '';
        $query = isset($parsed['query']) ? '?'.$parsed['query'] : '';
        $fragment = isset($parsed['fragment']) ? '#'.$parsed['fragment'] : '';

        // Costruisce l'URL con la porta
        $newUrl = "{$scheme}://{$host}:{$port}{$path}{$query}{$fragment}";

        return $newUrl;
    }

    /**
     * Costruisce l'URL IIIF completo secondo IIIF Image API 2.x.
     *
     * @param  string  $baseUrl  Base URL IIIF (es: https://94.23.181.145/iiif/2/abcd1234)
     * @param  string  $region  Regione (default: 'full')
     * @param  string  $size  Dimensione (default: 'max')
     * @param  string  $rotation  Rotazione (default: '0')
     * @param  string  $quality  Qualità (default: 'default')
     * @param  string  $format  Formato (default: 'jpg')
     * @return string  URL IIIF completo
     */
    public function buildIiifUrl(
        string $baseUrl,
        string $region = 'full',
        string $size = 'max',
        string $rotation = '0',
        string $quality = 'default',
        string $format = 'jpg'
    ): string {
        // Rimuove trailing slash dal base_url
        $baseUrl = rtrim($baseUrl, '/');

        // Costruisce l'URL secondo IIIF Image API 2.x
        // Formato: {base_url}/{region}/{size}/{rotation}/{quality}.{format}
        return sprintf(
            '%s/%s/%s/%s/%s.%s',
            $baseUrl,
            $region,
            $size,
            $rotation,
            $quality,
            $format
        );
    }

    /**
     * Calcola il checksum SHA256 di un file.
     *
     * @param  string  $filePath  Path del file
     * @return string  Checksum SHA256 in formato esadecimale
     *
     * @throws RuntimeException Se il file non esiste o non è leggibile
     */
    public function calculateSha256(string $filePath): string
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("File non trovato: {$filePath}");
        }

        if (!is_readable($filePath)) {
            throw new RuntimeException("File non leggibile: {$filePath}");
        }

        $hash = hash_file('sha256', $filePath);

        if ($hash === false) {
            throw new RuntimeException("Impossibile calcolare SHA256 per: {$filePath}");
        }

        return $hash;
    }

    /**
     * Determina il MIME type di un file immagine.
     *
     * @param  string  $filePath  Path del file
     * @return string  MIME type (es: image/jpeg, image/png)
     */
    public function getMimeType(string $filePath): string
    {
        $mimeType = mime_content_type($filePath);

        if ($mimeType === false) {
            // Fallback basato sull'estensione
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $mimeTypes = [
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                'tiff' => 'image/tiff',
                'tif' => 'image/tiff',
            ];

            return $mimeTypes[$extension] ?? 'image/jpeg';
        }

        return $mimeType;
    }

    /**
     * Ottiene le dimensioni di un'immagine.
     *
     * @param  string  $filePath  Path del file
     * @return array{width: int|null, height: int|null}  Dimensioni dell'immagine
     */
    public function getImageDimensions(string $filePath): array
    {
        $imageInfo = @getimagesize($filePath);

        if ($imageInfo === false) {
            return [
                'width' => null,
                'height' => null,
            ];
        }

        return [
            'width' => $imageInfo[0] ?? null,
            'height' => $imageInfo[1] ?? null,
        ];
    }
}
