<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Narration
 *
 * Model per la tabella iartnet_master.narrations.
 * Struttura condivisa con iartnet_master.interviews (stessi campi).
 */
class Narration extends Model
{
    use HasFactory, HasUuids;

    /** @var string|null */
    protected $connection = 'pgsql';

    /** @var string */
    protected $table = 'iartnet_master.narrations';

    /** @var string */
    protected $keyType = 'string';

    /** @var bool */
    public $incrementing = false;

    /**
     * Campi assegnabili in massa.
     * Colonne tabella: id (uuid), name, description, created_at, updated_at, ext_json (jsonb).
     *
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'name',
        'description',
        'publish_state',
        'created_at',
        'updated_at',
        'ext_json',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'ext_json' => 'array',
        ];
    }
}
