<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Mirror;

use App\Services\Mirror\UnsynchronizeMirrorImagesService;
use PHPUnit\Framework\TestCase;

final class UnsynchronizeMirrorImagesServiceTest extends TestCase
{
    private UnsynchronizeMirrorImagesService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new UnsynchronizeMirrorImagesService();
    }

    public function test_parse_extracts_uuid_and_basename_from_full_iiif_url(): void
    {
        $url = 'http://localhost:8182/iiif/2/4140d7d3-28a8-40d5-bbdf-0a94dd8fd2fa.jpg/full/max/0/default.jpg';
        $parsed = $this->service->parseIiifUuidImageFromFilename($url);

        self::assertNotNull($parsed);
        self::assertSame('4140d7d3-28a8-40d5-bbdf-0a94dd8fd2fa', $parsed['web_resource_id']);
        self::assertSame('4140d7d3-28a8-40d5-bbdf-0a94dd8fd2fa.jpg', $parsed['storage_basename']);
    }

    public function test_parse_returns_null_for_plain_filename(): void
    {
        self::assertNull($this->service->parseIiifUuidImageFromFilename('foto_123.jpg'));
    }

    public function test_parse_returns_null_for_legacy_percent_encoded_identifier(): void
    {
        $url = 'https://host/iiif/2/abc%2F123_image.jpg/full/max/0/default.jpg';
        self::assertNull($this->service->parseIiifUuidImageFromFilename($url));
    }

    public function test_parse_handles_uppercase_uuid_in_url(): void
    {
        $url = 'https://x.example/IIIF/2/4140D7D3-28A8-40D5-BBDF-0A94DD8FD2FA.PNG/info.json';
        $parsed = $this->service->parseIiifUuidImageFromFilename($url);

        self::assertNotNull($parsed);
        self::assertSame('4140d7d3-28a8-40d5-bbdf-0a94dd8fd2fa', $parsed['web_resource_id']);
        self::assertSame('4140d7d3-28a8-40d5-bbdf-0a94dd8fd2fa.png', $parsed['storage_basename']);
    }
}
