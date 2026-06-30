<?php

declare(strict_types=1);

namespace App\Services\Salon;

use RuntimeException;
use Spatie\SimpleExcel\SimpleExcelReader;

/**
 * Parser Excel Salon («Foglio 1» metadati, «Foglio 2» pagine/studenti).
 */
final class SalonExcelParser
{
    public const SHEET1_NAME = 'Foglio 1';

    public const SHEET2_NAME = 'Foglio 2';

    /**
     * Riga 0-based del Foglio 1 con Titolo/Anno/… (Excel riga 3: celle B3–F3).
     */
    public const SHEET1_METADATA_ROW_INDEX = 2;

    /**
     * Prima riga dati 0-based sul Foglio 2 (Excel riga 4).
     * Righe 1–3 = note (@gpa), intestazioni IT (PAGINA) e EN (PAGE).
     */
    public const SHEET2_FIRST_DATA_ROW_INDEX = 3;

    /**
     * Righe consecutive vuote (colonne C–O) dopo le quali termina la lettura Foglio 2.
     */
    public const SHEET2_CONSECUTIVE_EMPTY_ROWS_TO_STOP = 3;

    /** Colonna C (0-based): nome file immagine pagina. */
    private const COL_IMAGE = 2;

    /** Colonna D (0-based): numero pagina. */
    private const COL_PAGE = 3;

    /** Colonne E–O (0-based): dati studente. */
    private const COL_STUDENT_START = 4;

    private const COL_STUDENT_END = 14;

    private const STUDENT_FIELD_KEYS = [
        'StNome',
        'StCognome',
        'StData',
        'StScuola',
        'StScuolaAnno',
        'StProfessore',
        'StRaccomandazione',
        'StTipoOggetto',
        'StTitolo',
        'StTecnica',
        'StDimensione',
    ];

    /**
     * @return array{
     *     sheet1: array{Titolo: string, Anno: string, Ente: string, SedeEspositiva: string, Descrizione: string},
     *     field_pairs: list<array{key: string, value: string}>
     * }
     */
    public function parse(string $excelFilePath): array
    {
        if (! is_file($excelFilePath)) {
            throw new RuntimeException("File Excel non trovato: {$excelFilePath}");
        }

        $sheet1 = $this->readSheet1($excelFilePath);
        $fieldPairs = $this->buildFieldPairs($sheet1, $this->readSheet2($excelFilePath));

        return [
            'sheet1' => $sheet1,
            'field_pairs' => $fieldPairs,
        ];
    }

    /**
     * @return array{Titolo: string, Anno: string, Ente: string, SedeEspositiva: string, Descrizione: string}
     */
    private function readSheet1(string $excelFilePath): array
    {
        $reader = $this->openSheet($excelFilePath, self::SHEET1_NAME, 1);
        $row = $reader->noHeaderRow()
            ->skip(self::SHEET1_METADATA_ROW_INDEX)
            ->getRows()
            ->first();

        if ($row === null) {
            throw new RuntimeException(self::SHEET1_NAME.': riga metadati (riga Excel 3) non trovata.');
        }

        $values = array_values(is_array($row) ? $row : []);

        return [
            'Titolo' => $this->cell($values, 1),
            'Anno' => $this->cell($values, 2),
            'Ente' => $this->cell($values, 3),
            'SedeEspositiva' => $this->cell($values, 4),
            'Descrizione' => $this->cell($values, 5),
        ];
    }

    /**
     * @return array<int, array{img: string, students: list<array<string, string>>}>
     */
    private function readSheet2(string $excelFilePath): array
    {
        $reader = $this->openSheet($excelFilePath, self::SHEET2_NAME, 2);
        $rows = $reader->noHeaderRow()
            ->skip(self::SHEET2_FIRST_DATA_ROW_INDEX)
            ->getRows();

        /** @var array<int, array{img: string, students: list<array<string, string>>}> $pages */
        $pages = [];
        $studentIndexByPage = [];
        $currentPage = null;
        $consecutiveEmpty = 0;
        $excelRow = self::SHEET2_FIRST_DATA_ROW_INDEX;

        foreach ($rows as $row) {
            $excelRow++;
            $values = array_values(is_array($row) ? $row : []);

            if ($this->isSheet2RowEmpty($values)) {
                $consecutiveEmpty++;
                if ($consecutiveEmpty >= self::SHEET2_CONSECUTIVE_EMPTY_ROWS_TO_STOP) {
                    break;
                }

                continue;
            }
            $consecutiveEmpty = 0;

            $pageRaw = $this->cell($values, self::COL_PAGE);
            $imageName = $this->cell($values, self::COL_IMAGE);

            if ($pageRaw !== '' && $this->isSheet2PageHeaderLabel($pageRaw)) {
                continue;
            }

            if ($pageRaw !== '') {
                $pageNum = $this->parsePageNumber($pageRaw);
                $currentPage = $pageNum;
                if (! isset($pages[$pageNum])) {
                    $pages[$pageNum] = ['img' => '', 'students' => []];
                }
                if ($imageName !== '') {
                    $pages[$pageNum]['img'] = $imageName;
                }
            } elseif ($imageName !== '' && $currentPage !== null) {
                $pages[$currentPage]['img'] = $imageName;
            }

            if ($currentPage === null) {
                continue;
            }

            if (! $this->rowHasStudentData($values)) {
                continue;
            }

            if (! isset($studentIndexByPage[$currentPage])) {
                $studentIndexByPage[$currentPage] = 0;
            }
            $studentIndexByPage[$currentPage]++;
            $idx = $studentIndexByPage[$currentPage];
            $pg = $currentPage;

            $studentValues = [];
            for ($c = self::COL_STUDENT_START; $c <= self::COL_STUDENT_END; $c++) {
                $studentValues[] = $this->cell($values, $c);
            }

            $studentFields = [];
            foreach (self::STUDENT_FIELD_KEYS as $i => $prefix) {
                $studentFields[$prefix.'_'.$idx.'_pg_'.$pg] = $studentValues[$i] ?? '';
            }
            $pages[$currentPage]['students'][] = $studentFields;
        }

        ksort($pages, SORT_NUMERIC);

        return $pages;
    }

    /**
     * @param  array{Titolo: string, Anno: string, Ente: string, SedeEspositiva: string, Descrizione: string}  $sheet1
     * @param  array<int, array{img: string, students: list<array<string, string>>}>  $pages
     * @return list<array{key: string, value: string}>
     */
    private function buildFieldPairs(array $sheet1, array $pages): array
    {
        $pairs = [
            ['key' => 'card_type', 'value' => 'SALON'],
            ['key' => 'Titolo', 'value' => $sheet1['Titolo']],
            ['key' => 'Anno', 'value' => $sheet1['Anno']],
            ['key' => 'Ente', 'value' => $sheet1['Ente']],
            ['key' => 'SedeEspositiva', 'value' => $sheet1['SedeEspositiva']],
            ['key' => 'Descrizione', 'value' => $sheet1['Descrizione']],
        ];

        foreach ($pages as $pageNum => $pageData) {
            $imgKey = 'Pg_'.$pageNum.'_img';
            $pairs[] = ['key' => $imgKey, 'value' => $pageData['img']];
            foreach ($pageData['students'] as $studentFields) {
                foreach ($studentFields as $key => $value) {
                    $pairs[] = ['key' => $key, 'value' => $value];
                }
            }
        }

        return $pairs;
    }

    private function openSheet(string $excelFilePath, string $preferredName, int $sheetNumber): SimpleExcelReader
    {
        try {
            return SimpleExcelReader::create($excelFilePath)->fromSheetName($preferredName);
        } catch (\Throwable) {
            return SimpleExcelReader::create($excelFilePath)->fromSheet($sheetNumber);
        }
    }

    /**
     * @param  list<mixed>  $values
     */
    private function isSheet2RowEmpty(array $values): bool
    {
        for ($c = self::COL_IMAGE; $c <= self::COL_STUDENT_END; $c++) {
            if ($this->cell($values, $c) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<mixed>  $values
     */
    private function rowHasStudentData(array $values): bool
    {
        for ($c = self::COL_STUDENT_START; $c <= self::COL_STUDENT_END; $c++) {
            if ($this->cell($values, $c) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Etichette intestazione colonna pagina (righe 2–3 del template Salon), non numeri.
     */
    private function isSheet2PageHeaderLabel(string $raw): bool
    {
        $normalized = strtoupper(trim($raw));

        return in_array($normalized, ['PAGE', 'PAGINA', 'PAG.'], true);
    }

    private function parsePageNumber(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '') {
            throw new RuntimeException('Numero pagina vuoto nel '.self::SHEET2_NAME.'.');
        }
        if ($this->isSheet2PageHeaderLabel($raw)) {
            throw new RuntimeException("Numero pagina non valido: '{$raw}'.");
        }
        if (is_numeric($raw)) {
            return (int) $raw;
        }
        if (preg_match('/-?\d+/', $raw, $m)) {
            return (int) $m[0];
        }

        throw new RuntimeException("Numero pagina non valido: '{$raw}'.");
    }

    /**
     * @param  list<mixed>  $values
     */
    private function cell(array $values, int $index): string
    {
        return isset($values[$index]) ? trim((string) $values[$index]) : '';
    }
}
