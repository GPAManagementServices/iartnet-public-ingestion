<?php

declare(strict_types=1);

namespace Tests\Unit\Narration;

use App\Models\Narration;
use App\Services\Narration\NarrationStoryPayloadMapper;
use Tests\TestCase;

final class NarrationStoryPayloadMapperTest extends TestCase
{
    public function test_maps_narration_to_story_editor_payload(): void
    {
        $narration = new Narration([
            'id' => '11111111-1111-1111-1111-111111111111',
            'name' => 'Test story',
            'description' => 'Descrizione',
            'publish_state' => 'published',
            'ext_json' => [
                'Header' => [
                    'Layout' => 'None',
                    'Title' => 'Titolo',
                ],
                'sections' => [],
            ],
            'created_at' => '2026-01-01T10:00:00Z',
            'updated_at' => '2026-01-02T10:00:00Z',
        ]);

        $payload = NarrationStoryPayloadMapper::toStoryEditorPayload($narration);

        $this->assertSame('11111111-1111-1111-1111-111111111111', $payload['id']);
        $this->assertSame('Test story', $payload['name']);
        $this->assertSame('Descrizione', $payload['description']);
        $this->assertSame('published', $payload['publish_state']);
        $this->assertSame('Titolo', $payload['ext_json']['Header']['Title']);
        $this->assertSame([], $payload['ext_json']['sections']);
        $this->assertSame('2026-01-01T10:00:00+00:00', $payload['created_at']);
        $this->assertSame('2026-01-02T10:00:00+00:00', $payload['updated_at']);
        $this->assertSame([], $payload['ext_json']['sections']);
    }

    public function test_empty_ext_json_gets_default_structure(): void
    {
        $narration = new Narration([
            'id' => '22222222-2222-2222-2222-222222222222',
            'name' => 'Empty ext',
            'description' => '',
            'publish_state' => null,
            'ext_json' => [],
        ]);

        $payload = NarrationStoryPayloadMapper::toStoryEditorPayload($narration);

        $this->assertSame('draft', $payload['publish_state']);
        $this->assertSame('None', $payload['ext_json']['Header']['Layout']);
        $this->assertSame([], $payload['ext_json']['sections']);
    }

    public function test_round_trip_with_fixture_ext_json(): void
    {
        $fixturePath = dirname(__DIR__, 4).'/stories-editor/Stories/Mozart_and_the_Masonic_interpretation_of_The_Magic_Flute.json';
        $this->assertFileExists($fixturePath);

        $fixture = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);

        $narration = new Narration([
            'id' => $fixture['id'],
            'name' => $fixture['name'],
            'description' => $fixture['description'],
            'publish_state' => $fixture['publish_state'],
            'ext_json' => $fixture['ext_json'],
        ]);

        $payload = NarrationStoryPayloadMapper::toStoryEditorPayload($narration);
        $saved = NarrationStoryPayloadMapper::extJsonFromEditorSave($payload['ext_json']);

        $this->assertSame($fixture['ext_json']['Header']['Title'], $saved['Header']['Title']);
        $this->assertCount(count($fixture['ext_json']['sections']), $saved['sections']);
    }
}
