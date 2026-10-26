<?php
declare(strict_types=1);

namespace LeanCommerce\ProductVideoExport\Test\Unit\Plugin;

use LeanCommerce\ProductVideoExport\Model\PresenceParameters;
use LeanCommerce\ProductVideoExport\Model\Source\VideoPresenceOptions;
use LeanCommerce\ProductVideoExport\Plugin\ExportFilterPlugin;
use Magento\Eav\Model\Entity\Attribute;
use Magento\Framework\Data\Collection;
use Magento\Framework\DataObject;
use Magento\ImportExport\Block\Adminhtml\Export\Filter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/admin-stubs.php';

final class ExportFilterPluginTest extends TestCase
{
    public function testOwnLabelIsResolvedBeforeRenderingWithoutChangingOtherMetadata(): void
    {
        $own = new Attribute([
            'attribute_code' => PresenceParameters::KEY,
            'source_model' => VideoPresenceOptions::class,
            'frontend_label' => 'Raw provider label',
            'backend_type' => 'varchar',
        ]);
        $native = new Attribute(['attribute_code' => 'sku', 'frontend_label' => 'Native SKU']);
        $nativeBefore = $native->getData();
        $collection = new Collection([$native, $own]);

        $result = $this->plugin()->beforePrepareCollection(new Filter(), $collection);

        self::assertSame([$collection], $result);
        self::assertSame('Video presence', $own->getData('frontend_label'));
        self::assertSame('varchar', $own->getData('backend_type'));
        self::assertSame(PresenceParameters::KEY, $own->getAttributeCode());
        self::assertSame(VideoPresenceOptions::class, $own->getSourceModel());
        self::assertSame($nativeBefore, $native->getData());
    }

    #[DataProvider('unrelatedAttributes')]
    public function testUnrelatedLabelsAndNativeRenderingArePreserved(array $data): void
    {
        $attribute = new Attribute($data);
        $collection = new Collection([$attribute]);
        $plugin = $this->plugin();

        self::assertSame([$collection], $plugin->beforePrepareCollection(new Filter(), $collection));
        self::assertSame($data, $attribute->getData());
        self::assertSame(
            '<native data-preserved="yes">Native label</native>',
            $plugin->afterDecorateFilter(
                new Filter(),
                '<native data-preserved="yes">Native label</native>',
                null,
                $attribute,
                new DataObject(),
                false
            )
        );
    }

    public static function unrelatedAttributes(): iterable
    {
        yield 'native attribute' => [[
            'attribute_code' => 'sku', 'source_model' => null, 'frontend_label' => 'SKU label',
        ]];
        yield 'same code from another source' => [[
            'attribute_code' => PresenceParameters::KEY,
            'source_model' => 'Other\\Module\\Source',
            'frontend_label' => 'Other source label',
        ]];
        yield 'another code using the source' => [[
            'attribute_code' => 'unrelated',
            'source_model' => VideoPresenceOptions::class,
            'frontend_label' => 'Unrelated label',
        ]];
    }

    private function plugin(): ExportFilterPlugin
    {
        return new ExportFilterPlugin(new VideoPresenceOptions(), new PresenceParameters());
    }
}
