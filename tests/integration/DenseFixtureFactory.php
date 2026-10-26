<?php
declare(strict_types=1);

namespace LeanCommerce\ProductVideoExport\Test\Integration;

use Closure;
use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryExtensionFactory;
use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterfaceFactory;
use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Eav\Model\Config;
use Magento\Framework\Api\Data\ImageContentInterfaceFactory;
use Magento\Framework\Api\Data\VideoContentInterfaceFactory;
use Magento\Framework\Api\ImageContentValidatorInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use RuntimeException;

/** Dense synthetic galleries, written once per product through the product repository. */
final class DenseFixtureFactory
{
    private const PNG =
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/iZk9HQAAAABJRU5ErkJggg==';

    private array $ledger;
    private Closure $persist;
    private string $prefix;

    public function __construct(
        private readonly ObjectManagerInterface $objects,
        private readonly string $runId,
        callable $persist
    ) {
        if (!preg_match('/\AIT-DENSE-[a-zA-Z0-9-]{4,31}\z/', $runId)) {
            throw new RuntimeException('LCVX: Invalid dense fixture run identity.');
        }
        $this->prefix = 'LCVX-' . $runId . '-';
        $this->ledger = ['run_id' => $runId, 'sku_prefix' => $this->prefix, 'products' => []];
        $this->persist = Closure::fromCallable($persist);
    }

    public function create(): array
    {
        $stores = $this->objects->get(StoreManagerInterface::class);
        $previousStore = $stores->getStore()->getId();
        $stores->setCurrentStore(0);
        try {
            $websites = [];
            foreach ($stores->getStores() as $store) {
                $websites[] = (int)$store->getWebsiteId();
            }
            $websites = array_values(array_unique($websites));
            if ($websites === [] || in_array(0, $websites, true)) {
                throw new RuntimeException('LCVX: No valid commercial website is available.');
            }
            $attributeSet = (int)$this->objects->get(Config::class)
                ->getEntityType('catalog_product')->getDefaultAttributeSetId();
            $manifest = ['run_id' => $this->runId, 'sku_prefix' => $this->prefix, 'products' => []];
            foreach (['empty' => 0, 'twenty' => 20, 'hundred' => 100] as $alias => $count) {
                $manifest['products'][$alias] = $this->createProduct($alias, $count, $attributeSet, $websites);
            }
            return $manifest;
        } finally {
            $stores->setCurrentStore($previousStore);
        }
    }

    public function cleanup(): array
    {
        // Reuse the validated exact-ID/repository/delete and exact-file/reference cleanup contract.
        $factory = new FixtureFactory($this->objects, $this->runId, $this->persist);
        $factory->restoreLedger($this->ledger);
        $result = $factory->cleanup();
        $this->ledger = $result['ledger'];
        return $result;
    }

    private function createProduct(string $alias, int $count, int $attributeSet, array $websites): array
    {
        $repository = $this->objects->get(ProductRepositoryInterface::class);
        $sku = $this->prefix . $alias;
        try {
            $repository->get($sku, true, 0, true);
            throw new RuntimeException('LCVX: A proposed dense fixture already exists.');
        } catch (NoSuchEntityException) {
            // Verified absence before the first repository write.
        }
        $this->ledger['products'][$alias] = [
            'sku' => $sku, 'product_id' => null, 'status' => 'creating',
            'entries' => [], 'attempted_previews' => [], 'store_overrides' => [],
        ];
        ($this->persist)($this->ledger);
        $product = $this->objects->get(ProductInterfaceFactory::class)->create();
        $product->setStoreId(0);
        $product->setSku($sku)->setName($sku)->setAttributeSetId($attributeSet)->setTypeId('simple');
        $product->setPrice('10.00')->setWeight('1.00')->setStatus(2)->setVisibility(1)->setWebsiteIds($websites);
        $product->setCustomAttribute('tax_class_id', 0);
        $product->setCustomAttribute('url_key', strtolower($sku));
        $saved = $repository->save($product);
        if ((string)$saved->getSku() !== $sku || (int)$saved->getId() <= 0) {
            throw new RuntimeException('LCVX: Dense fixture identity was not returned by the repository.');
        }
        $this->ledger['products'][$alias]['product_id'] = (int)$saved->getId();
        $this->ledger['products'][$alias]['status'] = 'created';
        ($this->persist)($this->ledger);

        $entries = [];
        $expected = ['urls' => [], 'titles' => []];
        $labels = [];
        for ($position = 0; $position < $count; $position++) {
            $label = $sku . '-' . sprintf('%03d', $position);
            $preview = $label . '.png';
            $labels[$label] = $preview;
            $this->ledger['products'][$alias]['attempted_previews'][] = $preview;
            // The first two associations deliberately have the same URL and distinct identities.
            $url = 'https://example.invalid/' . $this->runId . '/'
                . $alias . '/' . max(0, $position - 1) . '?path=a|b\\c&padding=' . str_repeat('u', 90);
            $head = ($position === 0 ? '=' : '') . 'Video ' . $position . '|"coma,"\\ruta\r\nLínea\tñ ';
            $head = str_replace(['\\r\\n', '\\t'], ["\r\n", "\t"], $head);
            $title = $position === 1 ? '' : $head . str_repeat('t', 255 - mb_strlen($head, 'UTF-8'));
            $expected['urls'][] = $url;
            // This is the independent fixture oracle, not the production domain codec.
            $expected['titles'][] = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $title);
            $entry = $this->objects->get(ProductAttributeMediaGalleryEntryInterfaceFactory::class)->create();
            $entry->setMediaType('external-video')->setLabel($label)->setPosition($position)
                ->setDisabled(false)->setTypes([]);
            $image = $this->objects->get(ImageContentInterfaceFactory::class)->create();
            $image->setBase64EncodedData(self::PNG)->setType('image/png')->setName($preview);
            if (!$this->objects->get(ImageContentValidatorInterface::class)->isValid($image)) {
                throw new RuntimeException('LCVX: The synthetic PNG failed native validation.');
            }
            $entry->setContent($image);
            $video = $this->objects->get(VideoContentInterfaceFactory::class)->create();
            $video->setMediaType('external-video')->setVideoProvider('youtube')->setVideoUrl($url)
                ->setVideoTitle($title)->setVideoDescription('Synthetic ' . $label)->setVideoMetadata('{}');
            $extension = $this->objects->get(ProductAttributeMediaGalleryEntryExtensionFactory::class)->create();
            $extension->setVideoContent($video);
            $entry->setExtensionAttributes($extension);
            $entries[] = $entry;
        }
        ($this->persist)($this->ledger);
        if ($entries !== []) {
            try {
                // Product's public DTO setter invokes the native external-video converter and save handlers.
                $saved->setMediaGalleryEntries($entries);
                $repository->save($saved);
            } finally {
                // Capture exact committed identities even if a later repository callback failed.
                $reloaded = $repository->get($sku, true, 0, true);
                if ((int)$reloaded->getId() !== $this->ledger['products'][$alias]['product_id']) {
                    throw new RuntimeException('LCVX: Dense fixture identity changed during gallery save.');
                }
                foreach ($reloaded->getMediaGalleryEntries() ?? [] as $entry) {
                    $label = (string)$entry->getLabel();
                    $id = (int)$entry->getId();
                    if (!isset($labels[$label]) || $id <= 0) {
                        throw new RuntimeException('LCVX: Unknown gallery entry; preserve it for inspection.');
                    }
                    $this->ledger['products'][$alias]['entries'][$id] = [
                        'value_id' => $id, 'preview_name' => $labels[$label],
                        'file' => $entry->getFile(), 'status' => 'created',
                    ];
                    ($this->persist)($this->ledger);
                }
            }
        }
        if (count($this->ledger['products'][$alias]['entries']) !== $count) {
            throw new RuntimeException('LCVX: Dense fixture did not persist every intended association.');
        }
        return ['id' => (int)$saved->getId(), 'sku' => $sku, 'count' => $count, 'expected' => $expected];
    }
}
