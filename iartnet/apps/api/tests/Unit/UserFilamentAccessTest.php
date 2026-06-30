<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\User;
use Filament\Panel;
use PHPUnit\Framework\TestCase;

final class UserFilamentAccessTest extends TestCase
{
    public function test_operatore_can_access_filament(): void
    {
        $user = new class extends User {
            public function hasRole(string $name): bool
            {
                return $name === 'operatore';
            }

            public function hasOnlyPartnerRole(): bool
            {
                return false;
            }
        };

        $this->assertTrue($user->isOperatore());
        $this->assertFalse($user->isAdministrator());
        $this->assertTrue($user->canAccessFilament());
        $this->assertTrue($user->canAccessOperatoreSections());
        $this->assertFalse($user->canAccessPartnerSections());
    }

    public function test_administrator_can_access_filament(): void
    {
        $user = new class extends User {
            public function hasRole(string $name): bool
            {
                return $name === 'admin';
            }

            public function hasOnlyPartnerRole(): bool
            {
                return false;
            }
        };

        $this->assertTrue($user->isAdministrator());
        $this->assertFalse($user->isOperatore());
        $this->assertTrue($user->canAccessFilament());
        $this->assertTrue($user->canAccessOperatoreSections());
        $this->assertFalse($user->canAccessPartnerSections());
    }

    public function test_partner_with_institution_can_access_filament(): void
    {
        $user = new class extends User {
            public function hasRole(string $name): bool
            {
                return $name === 'partner';
            }

            public function hasOnlyPartnerRole(): bool
            {
                return true;
            }
        };

        $user->flag_institution = true;
        $user->institution_id = '84dc8673-8a80-4755-bcf6-3c87d072fa23';

        $this->assertTrue($user->isPartner());
        $this->assertTrue($user->hasOnlyPartnerRole());
        $this->assertTrue($user->canAccessPartnerSections());
        $this->assertTrue($user->canAccessFilament());
        $this->assertFalse($user->canAccessOperatoreSections());
        $this->assertSame('84dc8673-8a80-4755-bcf6-3c87d072fa23', $user->getScopedInstitutionId());
    }

    public function test_partner_without_institution_cannot_access_filament(): void
    {
        $user = new class extends User {
            public function hasRole(string $name): bool
            {
                return $name === 'partner';
            }

            public function hasOnlyPartnerRole(): bool
            {
                return true;
            }
        };

        $user->flag_institution = false;
        $user->institution_id = null;

        $this->assertTrue($user->isPartner());
        $this->assertFalse($user->canAccessPartnerSections());
        $this->assertFalse($user->canAccessFilament());
    }

    public function test_user_without_roles_cannot_access_filament(): void
    {
        $user = new class extends User {
            public function hasRole(string $name): bool
            {
                return false;
            }

            public function hasOnlyPartnerRole(): bool
            {
                return false;
            }
        };

        $this->assertFalse($user->canAccessFilament());
    }

    public function test_can_access_panel_matches_filament_access(): void
    {
        $user = new class extends User {
            public function hasRole(string $name): bool
            {
                return $name === 'operatore';
            }

            public function hasOnlyPartnerRole(): bool
            {
                return false;
            }
        };

        $panel = $this->createMock(Panel::class);

        $this->assertTrue($user->canAccessPanel($panel));
    }

    public function test_partner_assert_institution_access(): void
    {
        $user = new class extends User {
            public function hasRole(string $name): bool
            {
                return $name === 'partner';
            }

            public function hasOnlyPartnerRole(): bool
            {
                return true;
            }
        };

        $user->flag_institution = true;
        $user->institution_id = '84dc8673-8a80-4755-bcf6-3c87d072fa23';

        $user->assertCanAccessInstitution('84dc8673-8a80-4755-bcf6-3c87d072fa23');
        $this->expectException(\RuntimeException::class);
        $user->assertCanAccessInstitution('other-institution-id');
    }
}
