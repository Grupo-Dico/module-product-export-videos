<?php
declare(strict_types=1);

namespace LeanCommerce\ProductVideoExport\Domain;

use InvalidArgumentException;

/**
 * Encodes RF list cells before the native CSV writer handles serialization.
 */
class VideoListCodec
{
    /**
     * Encode a list, retaining every empty position.
     *
     * @param list<string> $values
     * @return string
     */
    public function encode(array $values): string
    {
        $escaped = [];
        foreach ($values as $value) {
            $escaped[] = str_replace(['\\', '|'], ['\\\\', '\\|'], $value);
        }
        return implode('|', $escaped);
    }

    /**
     * Decode an RF list using the count to distinguish no entries from one empty entry.
     *
     * @param string $encoded
     * @param int $expectedCount
     * @return list<string>
     */
    public function decode(string $encoded, int $expectedCount): array
    {
        if ($expectedCount < 0 || ($expectedCount === 0 && $encoded !== '')) {
            throw new InvalidArgumentException('Video list count does not match its contents.');
        }
        if ($expectedCount === 0) {
            return [];
        }

        $values = [''];
        $index = 0;
        $escaped = false;
        $length = strlen($encoded);
        for ($offset = 0; $offset < $length; $offset++) {
            $character = $encoded[$offset];
            if ($escaped) {
                if ($character !== '\\' && $character !== '|') {
                    throw new InvalidArgumentException('Video list contains an invalid escape.');
                }
                $values[$index] .= $character;
                $escaped = false;
            } elseif ($character === '\\') {
                $escaped = true;
            } elseif ($character === '|') {
                $values[++$index] = '';
            } else {
                $values[$index] .= $character;
            }
        }
        if ($escaped || count($values) !== $expectedCount) {
            throw new InvalidArgumentException('Video list is incomplete or its count does not match.');
        }
        return $values;
    }

    /**
     * Apply the title normalization without trimming or collapsing spaces.
     *
     * @param string $title
     * @return string
     */
    public function normalizeTitle(string $title): string
    {
        return str_replace(["\r\n", "\r", "\n", "\t"], ' ', $title);
    }
}
