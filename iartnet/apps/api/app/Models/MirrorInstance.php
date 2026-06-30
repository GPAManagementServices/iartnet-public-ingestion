<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Concerns\HasUuids; // Obbligatorio per UUID

class MirrorInstance extends Model
{
    use HasFactory, HasUuids; // HasUuids gestisce automaticamente $incrementing e $keyType

    /**
     * The connection name for the model.
     *
     * @var string|null
     */
    protected $connection = 'pgsql';

    /**
     * Informa Eloquent che la chiave primaria non è un intero incrementale.
     * Sebbene HasUuids lo faccia internamente, definirlo qui previene conflitti con Filament.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * Il tipo di dato della chiave primaria.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'iartnet_master.mirror_instances';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'display_name',
        'description',
        'institution_id',
        'data_provider',
        'is_protected',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_protected' => 'boolean',
            'institution_id' => 'string', // Assicura che l'UUID dell'istituzione sia trattato come stringa
        ];
    }

    /**
     * Get the institution that owns the mirror instance.
     *
     * @return BelongsTo<Institution, MirrorInstance>
     */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class, 'institution_id');
    }
}