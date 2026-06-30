<?php

declare(strict_types=1);

namespace App\Services\Narration;

use App\Models\Narration;

/**
 * Maps iartnet_master.narrations records to/from StoriesEditor TStoriesTypeData payloads.
 */
final class NarrationStoryPayloadMapper
{
    /**
     * @return array<string, mixed>
     */
    public static function toStoryEditorPayload(Narration $narration): array
    {
        return [
            'id' => (string) $narration->id,
            'name' => $narration->name ?? '',
            'description' => $narration->description ?? '',
            'created_at' => $narration->created_at?->toIso8601String() ?? now()->toIso8601String(),
            'updated_at' => $narration->updated_at?->toIso8601String() ?? now()->toIso8601String(),
            'publish_state' => self::normalizePublishState($narration->publish_state),
            'ext_json' => self::normalizeExtJson($narration->ext_json),
        ];
    }

    /**
     * @param  array<string, mixed>  $extJson
     * @return array<string, mixed>
     */
    public static function extJsonFromEditorSave(array $extJson): array
    {
        return self::normalizeExtJson($extJson);
    }

    private static function normalizePublishState(mixed $state): string
    {
        $value = is_string($state) ? strtolower(trim($state)) : '';

        return $value === 'published' ? 'published' : 'draft';
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeExtJson(mixed $extJson): array
    {
        if (! is_array($extJson) || $extJson === []) {
            return self::defaultExtJson();
        }

        return $extJson;
    }

    /**
     * @return array<string, mixed>
     */
    private static function defaultExtJson(): array
    {
        return [
            'Header' => [
                'Layout' => 'None',
                'Title' => '',
                'SubTitle' => null,
                'SEO' => null,
                'FontColor' => 'rgba(0, 0, 0, 1)',
                'Chip' => '',
                'Image' => null,
                'IndexImage' => null,
                'HeaderLayoutTheme' => 'Light',
            ],
            'sections' => [],
        ];
    }
}
