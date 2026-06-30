<?php

declare(strict_types=1);

namespace Tests\Unit\MasterData;

use App\Services\MasterData\CardDetailI18nTextManualWriter;
use PHPUnit\Framework\TestCase;

final class CardDetailI18nTextManualWriterTest extends TestCase
{
    public function test_display_key_to_field_name_strips_record_fields_prefix(): void
    {
        $this->assertSame(
            'AU/AUTN',
            CardDetailI18nTextManualWriter::displayKeyToFieldName('record_fields.AU/AUTN')
        );
    }

    public function test_display_key_to_field_name_returns_null_without_prefix(): void
    {
        $this->assertNull(CardDetailI18nTextManualWriter::displayKeyToFieldName('media.0.title'));
    }

    public function test_display_key_to_field_name_returns_null_for_empty_suffix(): void
    {
        $this->assertNull(CardDetailI18nTextManualWriter::displayKeyToFieldName('record_fields.'));
    }
}
