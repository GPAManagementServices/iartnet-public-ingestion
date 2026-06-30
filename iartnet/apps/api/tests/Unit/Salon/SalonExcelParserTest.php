<?php

declare(strict_types=1);

namespace Tests\Unit\Salon;

use App\Services\Salon\SalonExcelParser;
use PHPUnit\Framework\TestCase;

final class SalonExcelParserTest extends TestCase
{
    public function test_sheet2_constants_are_documented(): void
    {
        $this->assertSame('Foglio 1', SalonExcelParser::SHEET1_NAME);
        $this->assertSame('Foglio 2', SalonExcelParser::SHEET2_NAME);
        $this->assertSame(3, SalonExcelParser::SHEET2_FIRST_DATA_ROW_INDEX);
        $this->assertSame(3, SalonExcelParser::SHEET2_CONSECUTIVE_EMPTY_ROWS_TO_STOP);
    }

    public function test_build_field_pairs_groups_students_per_page(): void
    {
        $parser = new SalonExcelParser;
        $method = new \ReflectionMethod(SalonExcelParser::class, 'buildFieldPairs');
        $method->setAccessible(true);

        $sheet1 = [
            'Titolo' => 'Salon 1997',
            'Anno' => '1997',
            'Ente' => 'Ente X',
            'SedeEspositiva' => 'Sede Y',
            'Descrizione' => 'Desc',
        ];
        $pages = [
            8 => [
                'img' => 'SALON_1997_4.png',
                'students' => [
                    [
                        'StNome_1_pg_8' => 'Mario',
                        'StCognome_1_pg_8' => 'Rossi',
                    ],
                    [
                        'StNome_2_pg_8' => 'Luigi',
                        'StCognome_2_pg_8' => 'Verdi',
                    ],
                ],
            ],
            9 => [
                'img' => 'SALON_1997_5.png',
                'students' => [
                    [
                        'StNome_1_pg_9' => 'Anna',
                        'StCognome_1_pg_9' => 'Bianchi',
                    ],
                ],
            ],
            0 => [
                'img' => 'SALON_1997_Cover.jpg',
                'students' => [],
            ],
        ];

        /** @var list<array{key: string, value: string}> $pairs */
        $pairs = $method->invoke($parser, $sheet1, $pages);
        $byKey = [];
        foreach ($pairs as $p) {
            $byKey[$p['key']] = $p['value'];
        }

        $this->assertSame('SALON', $byKey['card_type']);
        $this->assertSame('Salon 1997', $byKey['Titolo']);
        $this->assertSame('SALON_1997_Cover.jpg', $byKey['Pg_0_img']);
        $this->assertSame('SALON_1997_4.png', $byKey['Pg_8_img']);
        $this->assertSame('Mario', $byKey['StNome_1_pg_8']);
        $this->assertSame('Luigi', $byKey['StNome_2_pg_8']);
        $this->assertSame('Anna', $byKey['StNome_1_pg_9']);
        $this->assertArrayNotHasKey('StNome_2_pg_9', $byKey);
    }
}
