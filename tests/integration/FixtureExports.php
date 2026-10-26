<?php
declare(strict_types=1);

namespace LeanCommerce\ProductVideoExport\Test\Integration;

use Bss\CustomProductAttributeExport\Model\Rewrite\CatalogImportExport\Export\Product as BssExport;
use LeanCommerce\ProductVideoExport\Api\VideoReaderInterface;
use LeanCommerce\ProductVideoExport\Domain\VideoResult;
use LeanCommerce\ProductVideoExport\Model\PresenceParameters;
use LeanCommerce\ProductVideoExport\Model\ProductExport;
use LeanCommerce\ProductVideoExport\Model\ResourceModel\BatchVideoReader;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\ObjectManagerInterface;
use Magento\ImportExport\Model\Export\Adapter\Csv;
use Magento\Store\Model\StoreManagerInterface;
use RuntimeException;

/** Test-only paging control: the native algorithms remain inherited. */
class OneProductExport extends ProductExport
{
    protected function getItemsPerPage()
    {
        $this->_itemsPerPage = 1;
        return parent::getItemsPerPage();
    }
}

/** Matching native control uses exactly the same page size. */
class OneBssExport extends BssExport
{
    protected function getItemsPerPage()
    {
        $this->_itemsPerPage = 1;
        return parent::getItemsPerPage();
    }
}

/** Records batch boundaries while executing the real reader. */
class RecordingVideoReader implements VideoReaderInterface
{
    public array $batches = [];

    public function __construct(private readonly BatchVideoReader $inner)
    {
    }

    public function read(array $productIds, int $storeId): array
    {
        $this->batches[] = ['ids' => $productIds, 'store_id' => $storeId];
        return $this->inner->read($productIds, $storeId);
    }
}

/** Exports owned fixture products through native paging, Bss and the new model. */
class FixtureExports
{
    private int $serial = 0;

    public function __construct(private readonly ObjectManagerInterface $objects)
    {
    }

    public function run(array $manifest, FixtureFactory $fixtures): array
    {
        $filters = ['sku' => $manifest['sku_prefix']];
        $control = $this->export(OneBssExport::class, $filters, null);
        $legacy = $this->export(OneProductExport::class, $filters, null);
        $this->assert($control['csv'] === $legacy['csv'], 'Legacy fixture output differs from native Bss bytes.');
        $all = $this->export(OneProductExport::class, $filters, 'all');
        $with = $this->export(OneProductExport::class, $filters, 'with');
        $without = $this->export(OneProductExport::class, $filters, 'without');
        [$nativeHeader, $nativeRows] = $this->parse($control['csv']);
        [$header, $allRows] = $this->parse($all['csv']);
        [, $withRows] = $this->parse($with['csv']);
        [, $withoutRows] = $this->parse($without['csv']);
        $this->assert($header === array_merge($nativeHeader, VideoResult::COLUMNS), 'Native headers changed.');
        $allByContext = $this->index($allRows);
        foreach ($nativeRows as $row) {
            $key = $this->key($row);
            $this->assert(isset($allByContext[$key]), 'An existing native context disappeared.');
            $projection = array_intersect_key($allByContext[$key], array_flip($nativeHeader));
            $this->assert($projection === $row, 'A native field changed in an existing context.');
        }

        $products = $manifest['products'];
        $expectedCounts = [];
        foreach (['', 'df', 'oriente', 'suroeste', 'exposhow', 'intro'] as $store) {
            foreach ($products as $name => $product) {
                $count = match ($name) {
                    'empty-first', 'empty-second' => 0,
                    'base' => $store === 'df' ? 2 : 1,
                    'multiple' => 3,
                    'scoped' => $store === 'oriente' ? 0 : 1,
                };
                $expectedCounts[$product['sku'] . '|' . $store] = $count;
            }
        }
        foreach (['with' => $withRows, 'without' => $withoutRows] as $mode => $rows) {
            $indexed = $this->index($rows);
            $accepted = array_filter($expectedCounts, static fn (int $count): bool =>
                $mode === 'with' ? $count > 0 : $count === 0);
            $this->assert(array_diff_key($accepted, $indexed) === []
                && array_diff_key($indexed, $accepted) === [], 'Filtered contexts are incomplete or incorrect.');
            foreach ($indexed as $key => $row) {
                $this->assert($row['video_count'] === (string)$accepted[$key], 'Count differs in a filtered context.');
                $this->assert($row['has_video'] === ($accepted[$key] > 0 ? '1' : '0'), 'Indicator differs from count.');
            }
        }
        $this->assert(count($with['batches']) === 30, 'Five single-product pages did not read all six stores.');
        foreach ($with['batches'] as $batch) {
            $this->assert(count($batch['ids']) === 1, 'The paging probe did not retain a one-product batch.');
        }
        $baseKey = $products['base']['sku'] . '|';
        $this->assert($allByContext[$baseKey]['video_count'] === '1', 'Base video count changed.');
        $this->assert($allByContext[$products['base']['sku'] . '|df']['video_count'] === '2', 'Local enable override lost.');
        $this->assert($allByContext[$products['scoped']['sku'] . '|df']['video_titles'] === '', 'Local empty title inherited.');
        $this->assert($allByContext[$products['scoped']['sku'] . '|oriente']['video_count'] === '0', 'Local disable lost.');

        $zero = $this->export(OneProductExport::class, ['sku' => $products['empty-first']['sku']], 'with');
        $this->assert($zero['csv'] === '', 'Zero accepted contexts emitted a header-only file.');
        $baseAbsent = $this->baseAbsent($manifest);
        $freshness = $this->freshnessAfterEdit($manifest, $fixtures);
        return [
            'result' => 'PASS', 'page_size' => 1, 'first_two_pages_have_no_with_matches' => true,
            'with_reader_batches' => count($with['batches']), 'legacy_identical_bytes' => true,
            'native_columns' => count($nativeHeader), 'enriched_columns' => count($header),
            'native_rows' => count($nativeRows), 'all_rows' => count($allRows),
            'with_rows' => count($withRows), 'without_rows' => count($withoutRows),
            'zero_matches_bytes' => strlen($zero['csv']),
            'base_absent' => $baseAbsent,
            'freshness_after_edit_same_instance' => $freshness,
            'limits' => ['Model channel; no queue or browser. Only test subclasses cap the native page size.'],
        ];
    }

    /** A native meta-title filter matches one owned store override, with no scope-0 row. */
    private function baseAbsent(array $manifest): array
    {
        $sku = $manifest['products']['scoped']['sku'];
        $storeId = (int)$manifest['store_ids'][0];
        $stores = $this->objects->get(StoreManagerInterface::class);
        $previousStore = $stores->getStore();
        $repository = $this->objects->get(ProductRepositoryInterface::class);
        $name = $manifest['sku_prefix'] . 'LocalOnly';
        $scopeDefinition = (int)$this->objects->get(\Magento\Eav\Model\Config::class)
            ->getAttribute('catalog_product', 'meta_title')->getIsGlobal();
        $this->assert($scopeDefinition === 0, 'The local-only fixture requires a store-view meta_title attribute.');
        try {
            // Native EAV filtering joins the default row even when the store value overrides it.
            $stores->setCurrentStore(0);
            $base = $repository->get($sku, false, 0, true);
            $this->assert((int)$base->getId() === $manifest['products']['scoped']['id'], 'Owned base product mismatch.');
            $repository->save($base->setMetaTitle($manifest['sku_prefix'] . 'BaseOnly'));
            $stores->setCurrentStore($storeId);
            $product = $repository->get($sku, false, $storeId, true);
            $this->assert((int)$product->getId() === $manifest['products']['scoped']['id'], 'Owned product mismatch.');
            $this->assert((int)$product->getStoreId() === $storeId, 'Fixture loaded in an unexpected store.');
            $repository->save($product->setMetaTitle($name));
        } finally {
            $stores->setCurrentStore($previousStore);
        }
        $filters = ['sku' => $sku, 'meta_title' => $name];
        $storedMatches = [];
        foreach ([0, $storeId, 5] as $scope) {
            $storedMatches[$scope] = $repository->get($sku, false, $scope, true)->getMetaTitle() === $name;
        }
        $this->assert($storedMatches === [0 => false, $storeId => true, 5 => false], 'Fixture override scope mismatch.');
        foreach (['all', 'with'] as $mode) {
            $result = $this->export(OneProductExport::class, $filters, $mode);
            [, $rows] = $this->parse($result['csv']);
            $this->assert(count($rows) === 1, 'Local-only filter must export exactly one context: '
                . $mode . '/' . json_encode(array_column($rows, 'store_view_code')));
            $row = $rows[0];
            $this->assert($row['store_view_code'] === (string)$stores->getStore($storeId)->getCode()
                && $row['has_video'] === '1' && $row['video_count'] === '1'
                && $row['video_titles'] === '' && $row['video_urls'] !== '',
                'Base-absent context lost its complete effective video block.');
        }
        $without = $this->export(OneProductExport::class, $filters, 'without');
        $this->assert($without['csv'] === '', 'Local-only video context incorrectly matched without videos.');
        return ['result' => 'PASS', 'native_filter' => 'meta_title', 'store_id' => $storeId,
            'all_rows' => 1, 'with_rows' => 1, 'without_bytes' => 0, 'scope_0_absent' => true];
    }

    /** Read, edit, and export again on one persistent model/reader; no extra fixture is created. */
    private function freshnessAfterEdit(array $manifest, FixtureFactory $fixtures): array
    {
        $sku = $manifest['products']['scoped']['sku'];
        $entryId = (int)$manifest['entry_ids']['scoped'];
        $reader = $this->objects->create(RecordingVideoReader::class);
        $model = $this->objects->create(OneProductExport::class, ['videoReader' => $reader]);
        $modelIdentity = spl_object_id($model);
        $readerIdentity = spl_object_id($reader);
        $stores = $this->objects->get(StoreManagerInterface::class);
        $codes = [''];
        foreach ($stores->getStores() as $store) {
            $codes[] = (string)$store->getCode();
        }
        $localEnabled = (string)$stores->getStore($manifest['store_ids'][0])->getCode();
        $localDisabled = (string)$stores->getStore($manifest['store_ids'][1])->getCode();
        $batches = [];
        $run = function (string $mode) use ($sku, $model, $reader, &$batches): array {
            $result = $this->export(OneProductExport::class, ['sku' => $sku], $mode, $model, $reader);
            $batches[] = count($result['batches']);
            [, $rows] = $this->parse($result['csv']);
            return $this->index($rows);
        };
        $check = function (array $rows, array $expectedCodes, int $count) use ($sku): void {
            $expected = array_fill_keys(array_map(static fn (string $code): string => $sku . '|' . $code, $expectedCodes), true);
            $this->assert(array_diff_key($rows, $expected) === [] && array_diff_key($expected, $rows) === [],
                'Presence filter retained stale contexts after a video edit.');
            foreach ($rows as $row) {
                $this->assert($row['video_count'] === (string)$count && $row['has_video'] === ($count > 0 ? '1' : '0'),
                    'Presence filter and effective video data disagree after an edit.');
                if ($count === 0) {
                    $this->assert($row['video_urls'] === '' && $row['video_titles'] === '',
                        'A disabled video left stale serialized lists.');
                }
            }
        };
        $baseKey = $sku . '|';
        $initial = $run('all');
        $this->assert($initial[$baseKey]['video_count'] === '1' && $initial[$baseKey]['video_titles'] === 'Base title',
            'Freshness fixture did not start with its enabled base video.');
        $updatedTitle = 'Updated after the first export';
        try {
            $fixtures->updateOwnedVideo('scoped', 0, $entryId, ['title' => $updatedTitle]);
            $changed = $run('all');
            $this->assert($changed[$baseKey]['video_count'] === '1' && $changed[$baseKey]['video_titles'] === $updatedTitle,
                'The same export instance reused the old video title after a contract edit.');
            $fixtures->updateOwnedVideo('scoped', 0, $entryId, ['disabled' => true]);
            $disabled = $run('all');
            $check([$baseKey => $disabled[$baseKey]], [''], 0);
            $check($run('with'), [$localEnabled], 1);
            $check($run('without'), array_values(array_diff($codes, [$localEnabled])), 0);
        } finally {
            // Only this owned entry is restored; outer fixture finally still deletes its ledger identities.
            $fixtures->updateOwnedVideo('scoped', 0, $entryId, ['title' => 'Base title', 'disabled' => false]);
        }
        $restored = $run('all');
        $this->assert($restored[$baseKey]['video_count'] === '1' && $restored[$baseKey]['video_titles'] === 'Base title',
            'The same export instance did not see the restored video metadata.');
        $check($run('with'), array_values(array_diff($codes, [$localDisabled])), 1);
        $check($run('without'), [$localDisabled], 0);
        $this->assert(count($batches) === 8 && count(array_filter($batches,
            static fn (int $count): bool => $count === count($codes))) === 8,
            'Each repeated export must read all contexts again.');
        return [
            'result' => 'PASS', 'fixture_products_used' => 1, 'additional_products_created' => 0,
            'exports_on_same_instance' => 8, 'same_model_instance' => spl_object_id($model) === $modelIdentity,
            'same_reader_instance' => spl_object_id($reader) === $readerIdentity,
            'title_refreshed' => true, 'disable_refreshed' => true, 'presence_filters_refreshed' => true,
            'restored_through_contract' => true, 'reader_batches_per_export' => $batches,
            'channel' => 'Same PHP process, ProductExport instance and reader; product repository edits between exports',
            'limits' => ['Direct model channel; this is not a separate-process queue-worker mutation test.'],
        ];
    }

    private function export(
        string $class,
        array $filters,
        ?string $mode,
        ?ProductExport $model = null,
        ?RecordingVideoReader $reader = null
    ): array
    {
        if ($mode !== null) {
            $filters[PresenceParameters::KEY] = $mode;
        }
        $reader ??= $this->objects->create(RecordingVideoReader::class);
        $model ??= $this->objects->create($class, ['videoReader' => $reader]);
        $batchStart = count($reader->batches);
        $filesystem = $this->objects->get(Filesystem::class);
        $directory = $filesystem->getDirectoryWrite(DirectoryList::VAR_IMPORT_EXPORT);
        $destination = 'LCVX-fixture-export-' . (++$this->serial) . '.csv';
        $this->assert(!$directory->isExist($destination), 'Probe destination already exists.');
        $writer = $this->objects->create(Csv::class, ['destination' => $destination]);
        try {
            $csv = $model->setParameters(['export_filter' => $filters, 'skip_attr' => []])
                ->setWriter($writer)->export();
            return ['csv' => $csv, 'batches' => array_slice($reader->batches, $batchStart)];
        } finally {
            $writer->destruct();
            if ($directory->isFile($destination)) {
                $directory->delete($destination);
            }
        }
    }

    private function parse(string $csv): array
    {
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, $csv);
        rewind($stream);
        $header = fgetcsv($stream, 0, ',', '"', "\0");
        $this->assert(is_array($header), 'CSV header is missing.');
        $rows = [];
        while (($values = fgetcsv($stream, 0, ',', '"', "\0")) !== false) {
            $this->assert(count($values) === count($header) && $values !== $header, 'CSV width/header repetition error.');
            $rows[] = array_combine($header, $values);
        }
        fclose($stream);
        return [$header, $rows];
    }

    private function index(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $key = $this->key($row);
            $this->assert(!isset($indexed[$key]), 'Unexpected duplicate product/store context in simple fixtures.');
            $indexed[$key] = $row;
        }
        return $indexed;
    }

    private function key(array $row): string
    {
        return $row['sku'] . '|' . $row['store_view_code'];
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
