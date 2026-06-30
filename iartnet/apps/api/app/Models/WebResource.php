<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * WebResource
 *
 * Model per la tabella iartnet_master.web_resources.
 * Risorse collegate ai record Master (immagini IIIF, manifest, ecc.).
 * Schema esistente: nessuna modifica.
 */
class WebResource extends Model
{
    /** @var string|null */
    protected $connection = 'pgsql';

    /** @var string */
    protected $table = 'iartnet_master.web_resources';

    /** @var string */
    protected $keyType = 'string';

    /** @var bool */
    public $incrementing = false;

    /**
     * Attributi assegnabili in massa (solo per riferimento; lettura via API).
     *
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'record_id',
        'role',
        'url',
        'mime_type',
        'checksum_sha256',
        'width',
        'height',
        'duration_ms',
        'rights_uri',
        'iiif_manifest_url',
        'iiif_image_api_url',
        'ord',
        'ext_json',
        'created_at',
        'updated_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'duration_ms' => 'integer',
            'ord' => 'integer',
            'ext_json' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
