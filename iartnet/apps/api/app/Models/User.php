<?php

declare(strict_types=1);

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    use HasFactory;
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'flag_institution',
        'institution_id',
    ];
    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'flag_institution' => 'boolean',
            'institution_id' => 'string',
        ];
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class, 'institution_id');
    }

    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (User $user): void {
            if (! $user->flag_institution) {
                $user->institution_id = null;
            }
        });
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->canAccessFilament();
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function hasRole(string $name): bool
    {
        return $this->roles()->where('name', $name)->exists();
    }

    /**
     * Verifica se l'utente ha il ruolo di amministratore.
     */
    public function isAdministrator(): bool
    {
        return $this->hasRole('admin');
    }

    public function isOperatore(): bool
    {
        return $this->hasRole('operatore');
    }

    public function isPartner(): bool
    {
        return $this->hasRole('partner');
    }

    public function hasInstitutionAssociation(): bool
    {
        return $this->flag_institution && filled($this->institution_id);
    }

    /**
     * True se lo User ha esclusivamente il ruolo partner.
     */
    public function hasOnlyPartnerRole(): bool
    {
        $roles = $this->roles()->pluck('name')->all();

        return count($roles) === 1 && $roles[0] === 'partner';
    }

    /**
     * UUID istituzione vincolante per utenti con solo ruolo partner.
     */
    public function getScopedInstitutionId(): ?string
    {
        if ($this->hasOnlyPartnerRole() && $this->hasInstitutionAssociation()) {
            return $this->institution_id;
        }

        return null;
    }

    /**
     * Opzioni istituzione per i form: tutte per admin/operatore, solo la propria per partner.
     *
     * @return array<string, string>
     */
    public function allowedInstitutionOptions(): array
    {
        $query = Institution::query()->orderBy('name');
        $scopedId = $this->getScopedInstitutionId();

        if ($scopedId !== null) {
            $query->where('id', $scopedId);
        }

        return $query->pluck('name', 'id')->all();
    }

    public function assertCanAccessInstitution(?string $institutionId): void
    {
        $scopedId = $this->getScopedInstitutionId();

        if ($scopedId !== null && $institutionId !== $scopedId) {
            throw new \RuntimeException('Accesso negato a questa istituzione');
        }
    }

    /**
     * Accesso alle sezioni operative complete (admin o operatore).
     */
    public function canAccessOperatoreSections(): bool
    {
        return $this->isAdministrator() || $this->isOperatore();
    }

    /**
     * Accesso partner: solo ruolo partner con istituzione associata.
     */
    public function canAccessPartnerSections(): bool
    {
        return $this->hasOnlyPartnerRole() && $this->hasInstitutionAssociation();
    }

    /**
     * Accesso al pannello Filament (admin, operatore, oppure partner con istituzione).
     */
    public function canAccessFilament(): bool
    {
        return $this->canAccessOperatoreSections() || $this->canAccessPartnerSections();
    }

}