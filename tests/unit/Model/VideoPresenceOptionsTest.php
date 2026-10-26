<?php
declare(strict_types=1);

namespace LeanCommerce\ProductVideoExport\Test\Unit\Model;

use LeanCommerce\ProductVideoExport\Model\Source\VideoPresenceOptions;
use PHPUnit\Framework\TestCase;

final class VideoPresenceOptionsTest extends TestCase
{
    public function testAllIsFirstAndNoEmptyOptionIsOffered(): void
    {
        $options = (new VideoPresenceOptions())->getAllOptions();

        self::assertSame(['all', 'with', 'without'], array_column($options, 'value'));
        self::assertSame(
            ['All', 'With video', 'Without video'],
            array_map(static fn (array $option): string => (string)$option['label'], $options)
        );
    }
}
