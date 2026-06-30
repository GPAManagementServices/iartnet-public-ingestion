<?php

declare(strict_types=1);

namespace Tests\Unit\Interview;

use App\Services\Interview\InterviewDocxStructureBuilder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InterviewDocxStructureBuilderTest extends TestCase
{
    public function test_builds_header_bio_and_qa_blocks(): void
    {
        $main = [
            'Interview header',
            'First bio line.',
            "Luca Esposito (Q)\nFirst question?",
            "Federico Tosi (A)\nFirst answer.",
        ];
        $captions = ['Caption A', 'Caption B'];

        $json = InterviewDocxStructureBuilder::buildFromParagraphs($main, $captions);

        $this->assertSame('Interview header', $json['header']);
        $this->assertSame('First bio line.', $json['bio']);
        $this->assertSame(['Caption A', 'Caption B'], $json['archivio_didascalie']);
        $this->assertCount(1, $json['intervista']);
        $this->assertSame('domanda_risposta', $json['intervista'][0]['tipo']);
        $this->assertSame('Luca Esposito (Q)', $json['intervista'][0]['domanda']['autore']);
        $this->assertSame('First question?', $json['intervista'][0]['domanda']['testo']);
        $this->assertSame('Federico Tosi (A)', $json['intervista'][0]['risposta']['autore']);
        $this->assertSame('First answer.', $json['intervista'][0]['risposta']['testo']);
    }

    public function test_image_tag_pairs_with_archivio_order(): void
    {
        $main = [
            'H',
            'Bio text.',
            'Immagine: foto1.jpg',
            "(Q)\nQ?",
            "( A )\nA.",
        ];
        $captions = ['Didascalia 1'];

        $json = InterviewDocxStructureBuilder::buildFromParagraphs($main, $captions);

        $this->assertCount(2, $json['intervista']);
        $this->assertSame('inserimento_immagine', $json['intervista'][0]['tipo']);
        $this->assertSame('foto1.jpg', $json['intervista'][0]['file']);
        $this->assertSame('Didascalia 1', $json['intervista'][0]['didascalia_corrispondente']);
        $this->assertSame('domanda_risposta', $json['intervista'][1]['tipo']);
    }

    public function test_header_strips_header_label_and_bio_collects_after_image_placeholder(): void
    {
        $main = [
            'HEADERLuca Esposito and Federico Tosi Online, November 4, 2025',
            'Bio:',
            'Immagine: ritratto.jpg',
            'Federico Tosi (Milan, 1988) trained at the Brera Academy.',
            "Luca Esposito ( Q )\nIf you had to describe the Academy with an image?",
            "Federico Tosi (A)\nI attended the Academy about ten years ago.",
        ];
        $captions = ['Didascalie immagini:', '1. Federico Tosi, Ritratto, ph. Alberto Nidola'];

        $json = InterviewDocxStructureBuilder::buildFromParagraphs($main, $captions);

        $this->assertSame('Luca Esposito and Federico Tosi Online, November 4, 2025', $json['header']);
        $this->assertStringContainsString('Federico Tosi (Milan, 1988)', $json['bio']);
        $this->assertStringNotContainsString('Immagine:', $json['bio']);
        $this->assertCount(2, $json['intervista']);
        $this->assertSame('inserimento_immagine', $json['intervista'][0]['tipo']);
        $this->assertSame('ritratto.jpg', $json['intervista'][0]['file']);
        $this->assertSame('domanda_risposta', $json['intervista'][1]['tipo']);
        $this->assertStringContainsString('Luca Esposito', $json['intervista'][1]['domanda']['autore']);
        $this->assertStringContainsString('Q', $json['intervista'][1]['domanda']['autore']);
    }

    public function test_marker_q_a_on_own_lines(): void
    {
        $main = [
            'Title',
            'Bio paragraph.',
            "(Q)\nWhat is your view?",
            "(A)\nMy view is positive.",
        ];
        $captions = [];

        $json = InterviewDocxStructureBuilder::buildFromParagraphs($main, $captions);

        $this->assertCount(1, $json['intervista']);
        $this->assertSame('domanda_risposta', $json['intervista'][0]['tipo']);
        $this->assertSame('(Q)', $json['intervista'][0]['domanda']['autore']);
        $this->assertSame('What is your view?', $json['intervista'][0]['domanda']['testo']);
        $this->assertSame('(A)', $json['intervista'][0]['risposta']['autore']);
        $this->assertSame('My view is positive.', $json['intervista'][0]['risposta']['testo']);
    }

    public function test_two_question_answer_pairs(): void
    {
        $main = [
            'Title',
            'Bio.',
            "(Q)\nFirst question?",
            "(A)\nFirst answer.",
            "( Q )\nSecond question?",
            "( A )\nSecond answer.",
        ];
        $captions = [];

        $json = InterviewDocxStructureBuilder::buildFromParagraphs($main, $captions);

        $this->assertCount(2, $json['intervista']);
        $this->assertSame('First question?', $json['intervista'][0]['domanda']['testo']);
        $this->assertSame('Second question?', $json['intervista'][1]['domanda']['testo']);
    }

    public function test_question_and_answer_in_one_paragraph(): void
    {
        $main = [
            'Title',
            'Bio.',
            '(Q): If you had to describe the Academy with an image, which would you choose and why?'.
            '(A): I attended the Academy about ten years ago, and it was a different place from what it is now.',
        ];
        $captions = [];

        $json = InterviewDocxStructureBuilder::buildFromParagraphs($main, $captions);

        $this->assertCount(1, $json['intervista']);
        $b = $json['intervista'][0];
        $this->assertSame('domanda_risposta', $b['tipo']);
        $this->assertStringContainsString('If you had to describe', $b['domanda']['testo']);
        $this->assertStringNotContainsString('(A)', $b['domanda']['testo']);
        $this->assertStringContainsString('I attended the Academy', $b['risposta']['testo']);
        $this->assertStringContainsString('(A)', $b['risposta']['autore']);
        $this->assertNotSame('', trim($b['risposta']['testo']));
    }

    public function test_a_paragraph_not_detected_as_domanda(): void
    {
        $this->assertFalse(InterviewDocxStructureBuilder::isDomandaParagraph("(A)\nAnswer only."));
        $this->assertTrue(InterviewDocxStructureBuilder::isDomandaParagraph("(Q)\nQuestion?"));
        $this->assertTrue(InterviewDocxStructureBuilder::isDomandaParagraph('Intro (Q) inline question?'));
    }

    public function test_throws_when_no_question_answer_blocks(): void
    {
        $this->expectException(RuntimeException::class);
        $main = [
            'H',
            'Only bio.',
            'Immagine: solo.jpg',
        ];
        InterviewDocxStructureBuilder::buildFromParagraphs($main, ['c1']);
    }

    public function test_parse_image_tag_filename(): void
    {
        $this->assertSame('imgesempio.jpg', InterviewDocxStructureBuilder::parseImageTagFilename('Immagine: imgesempio.jpg'));
        $this->assertSame('foto.JPG', InterviewDocxStructureBuilder::parseImageTagFilename('immagine:  foto.JPG'));
        $this->assertNull(InterviewDocxStructureBuilder::parseImageTagFilename('INSERIRE QUI IMMAGINI'));
        $this->assertNull(InterviewDocxStructureBuilder::parseImageTagFilename('Bio: test'));
    }

    public function test_build_image_placement_ext_json_start_between_and_end(): void
    {
        $intervista = [
            ['tipo' => 'inserimento_immagine', 'file' => 'a.jpg'],
            ['tipo' => 'domanda_risposta'],
            ['tipo' => 'domanda_risposta'],
            ['tipo' => 'domanda_risposta'],
            ['tipo' => 'domanda_risposta'],
            ['tipo' => 'domanda_risposta'],
            ['tipo' => 'inserimento_immagine', 'file' => 'b.jpg'],
            ['tipo' => 'domanda_risposta'],
            ['tipo' => 'inserimento_immagine', 'file' => 'c.jpg'],
        ];

        $placement = InterviewDocxStructureBuilder::buildImagePlacementExtJson($intervista);

        $this->assertSame([
            ['indice_immagine' => 0, 'dopo_domanda' => 0, 'file' => 'a.jpg'],
            ['indice_immagine' => 1, 'dopo_domanda' => 5, 'file' => 'b.jpg'],
            ['indice_immagine' => 2, 'dopo_domanda' => 6, 'file' => 'c.jpg'],
        ], $placement['immagini']);
        $this->assertSame(6, InterviewDocxStructureBuilder::countDomandaRispostaBlocks($intervista));
    }

    public function test_count_domanda_risposta_from_built_structure(): void
    {
        $main = [
            'Title',
            'Bio.',
            "(Q)\nFirst?",
            "(A)\nFirst.",
            "( Q )\nSecond?",
            "( A )\nSecond.",
        ];

        $json = InterviewDocxStructureBuilder::buildFromParagraphs($main, []);

        $this->assertSame(2, InterviewDocxStructureBuilder::countDomandaRispostaBlocks($json['intervista']));
        $this->assertSame(['immagini' => []], InterviewDocxStructureBuilder::buildImagePlacementExtJson($json['intervista']));
    }

    public function test_image_placement_from_docx_flow(): void
    {
        $main = [
            'H',
            'Bio text.',
            'Immagine: imgesempio.jpg',
            "(Q)\nQ?",
            "( A )\nA.",
        ];
        $json = InterviewDocxStructureBuilder::buildFromParagraphs($main, ['Didascalia 1']);

        $placement = InterviewDocxStructureBuilder::buildImagePlacementExtJson($json['intervista']);

        $this->assertSame([
            ['indice_immagine' => 0, 'dopo_domanda' => 0, 'file' => 'imgesempio.jpg'],
        ], $placement['immagini']);
        $this->assertSame(1, InterviewDocxStructureBuilder::countDomandaRispostaBlocks($json['intervista']));
    }

    public function test_extracts_intervistatore_and_intervistato_on_same_line(): void
    {
        $main = [
            'Interview title',
            'Intervistatore: Luca Esposito',
            'Intervistato: Federico Tosi',
            'Artist biography paragraph.',
            "(Q)\nQuestion?",
            "(A)\nAnswer.",
        ];

        $json = InterviewDocxStructureBuilder::buildFromParagraphs($main, []);

        $this->assertSame('Luca Esposito', $json['intervistatore']);
        $this->assertSame('Federico Tosi', $json['intervistato']);
        $this->assertSame('Artist biography paragraph.', $json['bio']);
        $this->assertStringNotContainsString('Luca Esposito', $json['bio']);
    }

    public function test_extracts_intervistatore_and_intervistato_on_following_line(): void
    {
        $main = [
            'Interview title',
            'Intervistatore:',
            'Luca Esposito',
            'Intervistato:',
            'Federico Tosi',
            'Bio:',
            'Artist biography.',
            "(Q)\nQuestion?",
            "(A)\nAnswer.",
        ];

        $json = InterviewDocxStructureBuilder::buildFromParagraphs($main, []);

        $this->assertSame('Luca Esposito', $json['intervistatore']);
        $this->assertSame('Federico Tosi', $json['intervistato']);
        $this->assertStringContainsString('Artist biography.', $json['bio']);
        $this->assertStringNotContainsString('Luca Esposito', $json['bio']);
    }
}
