<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modello per la tabella record nello schema mirror dinamico.
 */
class MirrorRecord extends Model
{
    protected $connection = 'pgsql';

    public $timestamps = false;

    protected $primaryKey = 'record_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'record_id',
        'import_run_id',
        'scheda_idx',
        'normativa_code',
        'normativa_version',
        'nctr',
        'nctn',
        'title',
        'valid_xsd',
        'promoted',
        'error_count',
    ];

    protected function casts(): array
    {
        return [
            'scheda_idx' => 'integer',
            'valid_xsd' => 'boolean',
            'promoted' => 'boolean',
            'error_count' => 'integer',
        ];
    }

    /**
     * Crea un'istanza del modello per uno schema mirror specifico.
     *
     * @param  string  $schemaName  Nome dello schema mirror
     * @return static
     */
    public static function forSchema(string $schemaName): static
    {
        $instance = new static();
        $instance->setSchema($schemaName);

        return $instance;
    }

    /**
     * Imposta lo schema dinamico per questo modello.
     *
     * @param  string  $schema  Nome dello schema mirror
     * @return static
     */
    public function setSchema(string $schema): static
    {
        $this->setTable($schema.'.record');

        return $this;
    }

    /**
     * Get the KV records for this record.
     * Nota: per usare questa relazione, il modello MirrorRecordKv deve avere
     * la tabella corretta impostata tramite forSchema() prima della query.
     *
     * @return HasMany<MirrorRecordKv, MirrorRecord>
     */
    public function kvRecords(): HasMany
    {
        return $this->hasMany(MirrorRecordKv::class, 'record_id', 'record_id');
    }
}
