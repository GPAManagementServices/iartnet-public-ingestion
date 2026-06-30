<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Interview
 *
 * Model per la tabella iartnet_master.interviews.
 * Struttura condivisa con iartnet_master.narrations (stessi campi).
 */
class Interview extends Model
{
    use HasFactory, HasUuids;

    /** @var string|null */
    protected $connection = 'pgsql';

    /** @var string */
    protected $table = 'iartnet_master.interviews';

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
        'record_id',
        'name',
        'description',
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
