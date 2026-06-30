<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * MasterDcRecord
 *
 * Model per la view v_dc_rec_table dello schema iartnet_master.
 * La view espone record + institution + record_kv (card_type, title, subject, subjectb, …).
 * Il campo id è a.id (record id, UUID).
 */
class MasterDcRecord extends Model
{
    /**
     * The connection name for the model.
     *
     * @var string|null
     */
    protected $connection = 'pgsql';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'iartnet_master.v_dc_rec_table';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The primary key for the model (a.id = record id).
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The "type" of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'stable_id',
        'primary_institution_id',
        'edm_type',
        'publish_state',
        'rights_uri',
        'rights_text',
        'source_landing_url',
        'primary_lang',
        'ext_json',
        'is_publishable',
        'is_translated',
        'relations',
        'institution',
        'c_type',
        'title',
        'subject',
        'subjectb',
    ];

    /**
     * Titolo mostrato in Master Data: title se non null, altrimenti subject, altrimenti subjectb
     * (allineato ai campi della view v_dc_rec_table).
     */
    protected function resolvedTitle(): Attribute
    {
        return Attribute::get(function (): ?string {
            if (($this->attributes['title'] ?? null) !== null) {
                return $this->attributes['title'];
            }
            if (($this->attributes['subject'] ?? null) !== null) {
                return $this->attributes['subject'];
            }
            if (($this->attributes['subjectb'] ?? null) !== null) {
                return $this->attributes['subjectb'];
            }

            return null;
        });
    }

    /**
     * Attribute casting.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_translated' => 'boolean',
    ];
}
