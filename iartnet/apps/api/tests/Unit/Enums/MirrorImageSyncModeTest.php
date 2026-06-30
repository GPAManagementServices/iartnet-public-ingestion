<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\MirrorImageSyncMode;
use PHPUnit\Framework\TestCase;

final class MirrorImageSyncModeTest extends TestCase
{
    public function test_try_from_string(): void
    {
        $this->assertSame(MirrorImageSyncMode::Copy, MirrorImageSyncMode::tryFromString('copy'));
        $this->assertSame(MirrorImageSyncMode::Vips, MirrorImageSyncMode::tryFromString('vips'));
        $this->assertNull(MirrorImageSyncMode::tryFromString('invalid'));
    }

    public function test_labels(): void
    {
        $this->assertSame('Copia diretta', MirrorImageSyncMode::Copy->label());
        $this->assertSame('Preparazione IIIF (vips)', MirrorImageSyncMode::Vips->label());
    }
}
