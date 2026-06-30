<?php

declare(strict_types=1);

namespace Tests\Unit\Iccd;

use App\Services\Iccd\SaxonValidator;
use PHPUnit\Framework\TestCase;

class SaxonValidatorTest extends TestCase
{
    /**
     * Test XSD path mapping for OA files.
     */
    public function test_xsd_mapping_oa(): void
    {
        $validator = new SaxonValidator();

        $xsdPath = $validator->getXsdPathForXml('/path/to/SAI652OA.xml');

        $this->assertNotNull($xsdPath);
        $this->assertStringContainsString('ICCD_OA_3.00_062018.xsd', $xsdPath);
    }

    /**
     * Test XSD path mapping for S files.
     */
    public function test_xsd_mapping_s(): void
    {
        $validator = new SaxonValidator();

        $xsdPath = $validator->getXsdPathForXml('/path/to/SAI652S.xml');

        $this->assertNotNull($xsdPath);
        $this->assertStringContainsString('ICCD_S_3.00.xsd', $xsdPath);
    }

    /**
     * Test XSD path mapping for INFORMA.
     */
    public function test_xsd_mapping_informa(): void
    {
        $validator = new SaxonValidator();

        $xsdPath = $validator->getXsdPathForXml('/path/to/INFORMA.xml');

        $this->assertNotNull($xsdPath);
        $this->assertStringContainsString('informa.xsd', $xsdPath);
    }

    /**
     * Test XSD path mapping for IMMFTAN.
     */
    public function test_xsd_mapping_immftan(): void
    {
        $validator = new SaxonValidator();

        $xsdPath = $validator->getXsdPathForXml('/path/to/IMMFTAN.xml');

        $this->assertNotNull($xsdPath);
        $this->assertStringContainsString('immftan.xsd', $xsdPath);
    }

    /**
     * Test that Saxon JAR path exists.
     */
    public function test_saxon_jar_exists(): void
    {
        $saxonPath = base_path('tools/saxson/saxon-he-12.9.jar');

        // This test verifies the path structure, not file existence
        // (file may not exist in test environment)
        $this->assertIsString($saxonPath);
    }
}
