<?php
declare(strict_types=1);

namespace Panth\IndexNow\Test\Unit\Etc;

use Panth\IndexNow\Model\Config\Backend\ApiKey;
use Panth\IndexNow\Model\Config\Source\SubmissionMode;
use Panth\IndexNow\Model\IndexNow\Submitter;
use PHPUnit\Framework\TestCase;

class AdminConfigTest extends TestCase
{
    private function moduleFile(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;
        $this->assertTrue(is_file($path), $relative . ' is missing');
        return (string) file_get_contents($path);
    }

    private function field(string $id): \SimpleXMLElement
    {
        $xml = simplexml_load_string($this->moduleFile('etc/adminhtml/system.xml'));
        $this->assertInstanceOf(\SimpleXMLElement::class, $xml);
        $fields = $xml->xpath('//section[@id="panth_index_now"]/group[@id="indexnow"]/field[@id="' . $id . '"]');
        $this->assertCount(1, $fields, 'field ' . $id);
        return $fields[0];
    }

    public function testApiKeyFieldValidatesInTheBrowserAndOnSave(): void
    {
        $field = $this->field('api_key');

        $this->assertSame('validate-indexnow-key', trim((string) $field->validate));
        $this->assertSame(ApiKey::class, trim((string) $field->backend_model));
        $this->assertSame('1', trim((string) $field->depends->field));
        $this->assertSame('1', (string) $field['showInStore']);
    }

    public function testDependentFieldsFollowTheEnableSwitch(): void
    {
        $this->assertSame('1', trim((string) $this->field('submit_deletions')->depends->field));
        $this->assertSame('', trim((string) $this->field('enabled')->depends));
    }

    public function testSubmissionModeIsGlobalAndUsesTheModeSource(): void
    {
        $field = $this->field('submission_mode');

        $this->assertSame(SubmissionMode::class, trim((string) $field->source_model));
        $this->assertSame('0', (string) $field['showInWebsite']);
        $this->assertSame('0', (string) $field['showInStore']);
    }

    public function testBrowserRuleUsesTheSameKeyFormatAsTheServer(): void
    {
        $script = $this->moduleFile('view/adminhtml/web/js/validation-mixin.js');

        $this->assertStringContainsString('"validate-indexnow-key"', $script);
        $this->assertSame(1, preg_match('#(/\^\[[^/]+\]\{\d+,\d+\}\$/)\.test\(key\)#', $script, $match));
        $this->assertSame(Submitter::KEY_PATTERN, $match[1]);
        $this->assertStringContainsString('key === ""', $script);
    }

    public function testValidationMixinIsRegisteredForTheAdminArea(): void
    {
        $config = $this->moduleFile('view/adminhtml/requirejs-config.js');

        $this->assertStringContainsString('"mage/validation"', $config);
        $this->assertStringContainsString('"Panth_IndexNow/js/validation-mixin": true', $config);
    }
}
