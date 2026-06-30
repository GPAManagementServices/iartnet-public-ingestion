<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Modalità di sincronizzazione immagini Mirror → Master (Synchronize Images).
 */
enum MirrorImageSyncMode: string
{
    /** Copia diretta in IMAGES_ROOT con estensione originale ({uuid}.ext). */
    case Copy = 'copy';

    /** Preparazione IIIF via libvips: TIFF tiled, piramide opzionale ({uuid}.tif). */
    case Vips = 'vips';

    public function label(): string
    {
        return match ($this) {
            self::Copy => 'Copia diretta',
            self::Vips => 'Preparazione IIIF (vips)',
        };
    }

    public static function tryFromString(string $value): ?self
    {
        return self::tryFrom($value);
    }
}
