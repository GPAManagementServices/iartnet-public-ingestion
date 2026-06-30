<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modello per la tabella added_kv nello schema mirror dinamico.
 */
class MirrorAddedKv extends Model
{
    protected $connection = 'pgsql';

    public $timestamps = false;

    protected $primaryKey = 'id';

    protected $keyType = 'integer';

    public $incrementing = true;

    protected $fillable = [
        'record_id',
        'field_name',
        'value_text',
        'promoted',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'promoted' => 'boolean',
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
        $this->setTable($schema.'.added_kv');

        return $this;
    }

    /**
     * Get the record that owns this added KV entry.
     * Nota: per usare questa relazione, il modello MirrorRecord deve avere
     * la tabella corretta impostata tramite forSchema() prima della query.
     *
     * @return BelongsTo<MirrorRecord, MirrorAddedKv>
     */
    public function record(): BelongsTo
    {
        return $this->belongsTo(MirrorRecord::class, 'record_id', 'record_id');
    }
}
