<?php
declare(strict_types=1);

namespace LeanCommerce\ProductVideoExport\Test\Integration;

use Closure;
use InvalidArgumentException;
use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryExtensionFactory;
use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterface;
use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterfaceFactory;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\ProductAttributeMediaGalleryManagementInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Eav\Model\Config;
use Magento\Framework\Api\Data\ImageContentInterfaceFactory;
use Magento\Framework\Api\Data\VideoContentInterfaceFactory;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Filesystem;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use RuntimeException;
use Throwable;

/**
 * Builds synthetic owned fixtures through Magento service contracts only.
 *
 * This helper does not bootstrap Magento, authorize execution or persist a ledger by itself.
 * The runner supplies a ledger callback before allowing writes and calls cleanup in finally.
 */
class FixtureFactory
{
    /** One red RGBA pixel, generated locally; no video provider or image URL is contacted. */
    private const PNG_BASE64 =
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/iZk9HQAAAABJRU5ErkJggg==';

    private ProductRepositoryInterface $products;
    private ProductAttributeMediaGalleryManagementInterface $gallery;
    private StoreManagerInterface $stores;
    private ?Closure $onLedgerChange;
    private array $ledger = [];
    private string $prefix;

    public function __construct(
        private readonly ObjectManagerInterface $objects,
        private readonly string $runId,
        ?callable $onLedgerChange = null
    ) {
        if (!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9-]{3,39}\z/', $runId)) {
            throw new InvalidArgumentException('Fixture run ID must contain 4–40 letters, digits or hyphens.');
        }
        $this->prefix = 'LCVX-' . $runId . '-';
        $this->products = $objects->get(ProductRepositoryInterface::class);
        $this->gallery = $objects->get(ProductAttributeMediaGalleryManagementInterface::class);
        $this->stores = $objects->get(StoreManagerInterface::class);
        $this->onLedgerChange = $onLedgerChange === null ? null : Closure::fromCallable($onLedgerChange);
    }

    /**
     * Create two empty products first, then later matching products for small-page export tests.
     *
     * @param list<int> $storeIds At least two distinct existing non-admin store IDs, e.g. [1, 5].
     * @return array<string, mixed>
     */
    public function create(array $storeIds): array
    {
        if ($this->ledger !== []) {
            throw new RuntimeException('This fixture factory has already started an execution.');
        }
        if (count($storeIds) < 2 || count(array_unique($storeIds, SORT_REGULAR)) !== count($storeIds)) {
            throw new InvalidArgumentException('At least two distinct store IDs are required.');
        }
        $websiteIds = [];
        foreach ($storeIds as $storeId) {
            if (!is_int($storeId) || $storeId <= 0) {
                throw new InvalidArgumentException('Fixture store IDs must be positive integers.');
            }
            $store = $this->stores->getStore($storeId);
            $websiteIds[] = (int) $store->getWebsiteId();
        }
        $websiteIds = array_values(array_unique($websiteIds));
        $attributeSetId = (int) $this->objects->get(Config::class)
            ->getEntityType('catalog_product')->getDefaultAttributeSetId();
        if ($attributeSetId <= 0 || in_array(0, $websiteIds, true)) {
            throw new RuntimeException('The existing attribute set or commercial website context is invalid.');
        }

        return $this->inDefaultStore(function () use ($storeIds, $websiteIds, $attributeSetId): array {
            foreach (['empty-first', 'empty-second', 'base', 'multiple', 'scoped'] as $name) {
                $this->createProduct($name, $attributeSetId, $websiteIds);
            }

            $baseEnabled = $this->addVideo('base', 'base-enabled', 'https://example.invalid/video/base', 'Base', 10);
            $baseDisabled = $this->addVideo(
                'base',
                'base-disabled',
                'https://example.invalid/video/disabled',
                'Disabled in base',
                20,
                true
            );
            $this->overrideEntry('base', $storeIds[0], $baseDisabled, ['disabled' => false]);

            $sharedUrl = 'https://example.invalid/video/' . $this->runId . '?value=a|b\\c';
            $multiple = [
                $this->addVideo('multiple', 'multi-empty', $sharedUrl, '', 0),
                $this->addVideo('multiple', 'multi-escapes', $sharedUrl, "Demo|\"quotes\"\\path\r\nLínea\tñ", 0),
                $this->addVideo('multiple', 'multi-zero', 'https://example.invalid/video/zero', '0', 5),
            ];

            $scoped = $this->addVideo('scoped', 'scoped', 'https://example.invalid/video/scoped', 'Base title', 10);
            $this->overrideEntry('scoped', $storeIds[0], $scoped, ['title' => '', 'position' => 0]);
            $this->overrideEntry('scoped', $storeIds[1], $scoped, ['disabled' => true]);

            $result = [
                'run_id' => $this->runId,
                'sku_prefix' => $this->prefix,
                'store_ids' => $storeIds,
                'website_ids' => $websiteIds,
                'products' => $this->productManifest(),
                'entry_ids' => [
                    'base_enabled' => $baseEnabled,
                    'base_disabled' => $baseDisabled,
                    'multiple' => $multiple,
                    'scoped' => $scoped,
                ],
                'expected' => [
                    'base' => ['scope_0_count' => 1, 'override_store_count' => 2],
                    'multiple' => ['count' => 3, 'titles' => ['', 'Demo|"quotes"\\path Línea ñ', '0']],
                    'scoped' => ['scope_0_count' => 1, 'override_title' => '', 'disabled_store_count' => 0],
                ],
                'contract_limits' => [
                    'No explicit global gallery disabled flag through the entry service contract.',
                    'No explicit NULL position or metadata through string/int service fields; inheritance uses absence.',
                    'Provider URLs are synthetic; no external provider validity or remote fetch is tested.',
                ],
                'ledger' => $this->getLedger(),
            ];
            return $result;
        });
    }

    /** Edit only an already recorded fixture video through the same repository contract as setup. */
    public function updateOwnedVideo(string $name, int $storeId, int $entryId, array $changes): void
    {
        if (!isset($this->ledger[$name]['entries'][$entryId]) || $storeId < 0
            || array_diff(array_keys($changes), ['title', 'disabled']) !== []
            || (array_key_exists('title', $changes) && !is_string($changes['title']))
            || (array_key_exists('disabled', $changes) && !is_bool($changes['disabled']))
        ) {
            throw new InvalidArgumentException('Only a recorded fixture video and valid title/disabled edits are allowed.');
        }
        $this->inDefaultStore(function () use ($name, $storeId, $entryId, $changes): void {
            $this->overrideEntry($name, $storeId, $entryId, $changes);
        });
    }

    /** Return exact owned identities and paths; no prefix scan is used for cleanup. */
    public function getLedger(): array
    {
        return ['run_id' => $this->runId, 'sku_prefix' => $this->prefix, 'products' => $this->ledger];
    }

    /** Restore only a ledger belonging to this exact run before retrying cleanup. */
    public function restoreLedger(array $ledger): void
    {
        if ($this->ledger !== [] || ($ledger['run_id'] ?? null) !== $this->runId
            || ($ledger['sku_prefix'] ?? null) !== $this->prefix || !is_array($ledger['products'] ?? null)
        ) {
            throw new InvalidArgumentException('The fixture ledger does not belong to this empty factory and run.');
        }
        $ids = [];
        foreach ($ledger['products'] as $name => $record) {
            if (!is_string($name) || !preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $name)
                || !is_array($record) || ($record['sku'] ?? null) !== $this->prefix . $name
                || !array_key_exists('product_id', $record) || !is_array($record['entries'] ?? null)
                || !is_string($record['status'] ?? null)
            ) {
                throw new InvalidArgumentException('The fixture ledger contains an invalid product record.');
            }
            $id = $record['product_id'];
            if ($id !== null) {
                if (!is_int($id) || $id <= 0 || isset($ids[$id])) {
                    throw new InvalidArgumentException('The fixture ledger contains an invalid or repeated product ID.');
                }
                $ids[$id] = true;
            }
            foreach ($record['entries'] as $entryId => $entry) {
                if (!is_array($entry) || !is_int($entry['value_id'] ?? null) || $entry['value_id'] <= 0
                    || (string) $entryId !== (string) $entry['value_id']
                    || !is_string($entry['preview_name'] ?? null)
                    || !str_starts_with($entry['preview_name'], $this->prefix)
                    || basename($entry['preview_name']) !== $entry['preview_name']
                    || !array_key_exists('file', $entry)
                ) {
                    throw new InvalidArgumentException('The fixture ledger contains an invalid media identity.');
                }
                if ($entry['file'] !== null) {
                    $this->validateMediaPath($entry['file']);
                }
            }
        }
        $this->ledger = $ledger['products'];
        $this->notifyLedger();
    }

    /**
     * Delete exact owned products through the repository, then remove only unreferenced recorded previews.
     *
     * An unknown identity is retained and reported, never guessed from a prefix.
     */
    public function cleanup(): array
    {
        return $this->inDefaultStore(function (): array {
            $results = [];
            foreach (array_reverse(array_keys($this->ledger)) as $name) {
                try {
                    $this->cleanupProduct($name);
                    $results[$name] = ['status' => $this->ledger[$name]['status']];
                } catch (Throwable $failure) {
                    $this->ledger[$name]['status'] = 'cleanup_failed';
                    $this->ledger[$name]['cleanup_error_class'] = $failure::class;
                    $this->notifyLedger();
                    $results[$name] = ['status' => 'cleanup_failed', 'error_class' => $failure::class];
                }
            }
            return ['products' => $results, 'ledger' => $this->getLedger()];
        });
    }

    private function createProduct(string $name, int $attributeSetId, array $websiteIds): void
    {
        $sku = $this->prefix . $name;
        try {
            $this->products->get($sku, true, 0, true);
            throw new RuntimeException('A fixture SKU already exists; it will not be overwritten.');
        } catch (NoSuchEntityException) {
            // Absence is verified before recording the intended creation.
        }

        $this->ledger[$name] = [
            'sku' => $sku,
            'product_id' => null,
            'status' => 'creating',
            'entries' => [],
            'attempted_previews' => [],
            'store_overrides' => [],
        ];
        $this->notifyLedger();

        $product = $this->objects->get(ProductInterfaceFactory::class)->create();
        $product->setStoreId(0);
        $product->setSku($sku);
        $product->setName($sku);
        $product->setAttributeSetId($attributeSetId);
        $product->setTypeId('simple');
        $product->setPrice('10.00');
        $product->setWeight('1.00');
        $product->setStatus(2);
        $product->setVisibility(1);
        $product->setWebsiteIds($websiteIds);
        $product->setCustomAttribute('tax_class_id', 0);
        $product->setCustomAttribute('url_key', strtolower($sku));
        $saved = $this->products->save($product);
        if ((string) $saved->getSku() !== $sku || (int) $saved->getId() <= 0) {
            throw new RuntimeException('The repository did not return the expected fixture identity.');
        }
        $this->ledger[$name]['product_id'] = (int) $saved->getId();
        $this->ledger[$name]['status'] = 'created';
        $this->notifyLedger();
    }

    private function addVideo(
        string $name,
        string $previewSuffix,
        string $url,
        string $title,
        int $position,
        bool $disabled = false
    ): int {
        $owned = $this->ownedProduct($name);
        $previewName = $this->prefix . $previewSuffix . '.png';
        $this->ledger[$name]['attempted_previews'][] = $previewName;
        $this->notifyLedger();

        $entry = $this->objects->get(ProductAttributeMediaGalleryEntryInterfaceFactory::class)->create();
        $entry->setMediaType('external-video');
        $entry->setLabel($this->prefix . $previewSuffix);
        $entry->setPosition($position);
        $entry->setDisabled($disabled);
        $entry->setTypes([]);

        $image = $this->objects->get(ImageContentInterfaceFactory::class)->create();
        $image->setBase64EncodedData(self::PNG_BASE64);
        $image->setType('image/png');
        $image->setName($previewName);
        $entry->setContent($image);

        $video = $this->objects->get(VideoContentInterfaceFactory::class)->create();
        $video->setMediaType('external-video');
        $video->setVideoProvider('youtube');
        $video->setVideoUrl($url);
        $video->setVideoTitle($title);
        $video->setVideoDescription('Synthetic ' . $this->prefix . $previewSuffix);
        $video->setVideoMetadata('{}');
        $extension = $this->objects->get(ProductAttributeMediaGalleryEntryExtensionFactory::class)->create();
        $extension->setVideoContent($video);
        $entry->setExtensionAttributes($extension);

        $entryId = (int) $this->gallery->create($owned->getSku(), $entry);
        if ($entryId <= 0) {
            throw new RuntimeException('The gallery contract did not return an entry identity.');
        }
        $this->ledger[$name]['entries'][$entryId] = [
            'value_id' => $entryId,
            'preview_name' => $previewName,
            'file' => null,
            'status' => 'created',
        ];
        $this->notifyLedger();
        $savedEntry = $this->findEntry($this->ownedProduct($name), $entryId);
        $this->ledger[$name]['entries'][$entryId]['file'] = $savedEntry->getFile();
        $this->notifyLedger();
        return $entryId;
    }

    private function overrideEntry(string $name, int $storeId, int $entryId, array $changes): void
    {
        $product = $this->ownedProduct($name, $storeId);
        $entries = $product->getMediaGalleryEntries() ?? [];
        $entry = $this->findEntryIn($entries, $entryId);
        if (array_key_exists('disabled', $changes)) {
            $entry->setDisabled($changes['disabled']);
        }
        if (array_key_exists('position', $changes)) {
            $entry->setPosition($changes['position']);
        }
        if (array_key_exists('title', $changes)) {
            $video = $entry->getExtensionAttributes()?->getVideoContent();
            if ($video === null) {
                throw new RuntimeException('The fixture video extension attributes were not loaded.');
            }
            $video->setVideoTitle($changes['title']);
        }
        $product->setStoreId($storeId);
        $product->setMediaGalleryEntries($entries);
        $this->products->save($product);
        $this->ledger[$name]['store_overrides'][] = [
            'store_id' => $storeId,
            'value_id' => $entryId,
            'changes' => $changes,
        ];
        $this->notifyLedger();
    }

    private function cleanupProduct(string $name): void
    {
        $record = $this->ledger[$name];
        if ($record['product_id'] === null) {
            throw new RuntimeException('Creation did not return an owned ID; recovery requires inspection.');
        }
        $product = null;
        try {
            $product = $this->ownedProduct($name);
        } catch (NoSuchEntityException) {
            // A previously deleted product can still have an owned preview awaiting cleanup.
        }
        $actualEntries = $product?->getMediaGalleryEntries() ?? [];
        foreach ($actualEntries as $entry) {
            $entryId = (int) $entry->getId();
            if (!isset($record['entries'][$entryId])) {
                throw new RuntimeException('An unrecorded gallery entry exists; cleanup will not delete it.');
            }
            $knownFile = $record['entries'][$entryId]['file'];
            if ($knownFile !== null && $entry->getFile() !== $knownFile) {
                throw new RuntimeException('A gallery path changed after creation; cleanup requires inspection.');
            }
            $this->validateMediaPath($entry->getFile());
            if ($knownFile === null) {
                $this->ledger[$name]['entries'][$entryId]['file'] = $entry->getFile();
                $this->notifyLedger();
            }
        }
        if ($product !== null) {
            if (!$this->products->delete($product)) {
                throw new RuntimeException('The repository did not confirm fixture deletion.');
            }
            $this->ledger[$name]['product_deleted'] = true;
            $this->notifyLedger();
        }
        foreach ($this->ledger[$name]['entries'] as $entryId => $entry) {
            $this->cleanupPreview($entry);
            $this->ledger[$name]['entries'][$entryId]['status'] = 'removed';
            $this->notifyLedger();
        }
        $this->ledger[$name]['status'] = $product === null ? 'already_absent' : 'deleted';
        unset($this->ledger[$name]['cleanup_error_class']);
        $this->notifyLedger();
    }

    private function cleanupPreview(array $entry): void
    {
        $file = $entry['file'];
        if ($file === null) {
            throw new RuntimeException('The owned preview path was not recorded; cleanup requires inspection.');
        }
        $this->validateMediaPath($file);
        $resource = $this->objects->get(ResourceConnection::class);
        $connection = $resource->getConnection();
        $references = $connection->select()
            ->from(['gallery' => $resource->getTableName('catalog_product_entity_media_gallery')], ['value_id'])
            ->joinInner(
                ['association' => $resource->getTableName('catalog_product_entity_media_gallery_value_to_entity')],
                'association.value_id = gallery.value_id',
                []
            )
            ->where('gallery.value = ?', $file)
            ->limit(1);
        if ((int) $connection->fetchOne($references) > 0) {
            throw new RuntimeException('The recorded preview is still associated with a product and will be retained.');
        }
        $directory = $this->objects->get(Filesystem::class)->getDirectoryWrite(DirectoryList::MEDIA);
        $relative = 'catalog/product' . $file;
        if ($directory->isExist($relative)) {
            if (!$directory->isFile($relative)) {
                throw new RuntimeException('The recorded preview path is not a regular media file.');
            }
            $directory->delete($relative);
        }
    }

    private function validateMediaPath(mixed $file): void
    {
        if (!is_string($file) || !str_starts_with($file, '/') || str_contains($file, '\\')
            || str_contains($file, "\0") || !str_starts_with(basename($file), $this->prefix)
            || !str_ends_with($file, '.png')
        ) {
            throw new InvalidArgumentException('A media path does not belong to this fixture run.');
        }
        foreach (explode('/', ltrim($file, '/')) as $component) {
            if ($component === '.' || $component === '..' || !preg_match('/\A[A-Za-z0-9._-]+\z/', $component)) {
                throw new InvalidArgumentException('The fixture media path is not canonical.');
            }
        }
    }

    private function ownedProduct(string $name, int $storeId = 0): ProductInterface
    {
        $record = $this->ledger[$name] ?? null;
        if ($record === null || $record['product_id'] === null) {
            throw new RuntimeException('No owned product identity is recorded.');
        }
        $product = $this->products->get($record['sku'], true, $storeId, true);
        if ((int) $product->getId() !== $record['product_id'] || $product->getSku() !== $record['sku']) {
            throw new RuntimeException('The current product differs from the recorded fixture identity.');
        }
        return $product;
    }

    private function findEntry(ProductInterface $product, int $entryId): ProductAttributeMediaGalleryEntryInterface
    {
        return $this->findEntryIn($product->getMediaGalleryEntries() ?? [], $entryId);
    }

    private function findEntryIn(array $entries, int $entryId): ProductAttributeMediaGalleryEntryInterface
    {
        foreach ($entries as $entry) {
            if ((int) $entry->getId() === $entryId) {
                return $entry;
            }
        }
        throw new RuntimeException('The recorded fixture gallery entry was not found.');
    }

    private function productManifest(): array
    {
        $manifest = [];
        foreach ($this->ledger as $name => $record) {
            $manifest[$name] = ['id' => $record['product_id'], 'sku' => $record['sku']];
        }
        return $manifest;
    }

    private function notifyLedger(): void
    {
        if ($this->onLedgerChange !== null) {
            ($this->onLedgerChange)($this->getLedger());
        }
    }

    private function inDefaultStore(callable $operation): mixed
    {
        $previous = (int) $this->stores->getStore()->getId();
        $this->stores->setCurrentStore(0);
        try {
            return $operation();
        } finally {
            $this->stores->setCurrentStore($previous);
        }
    }
}
