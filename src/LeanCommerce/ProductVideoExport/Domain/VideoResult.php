<?php
declare(strict_types=1);

namespace LeanCommerce\ProductVideoExport\Domain;

use InvalidArgumentException;

/**
 * Effective video block for a single product and store context.
 */
class VideoResult
{
    public const COLUMNS = ['has_video', 'video_count', 'video_urls', 'video_titles'];

    /**
     * @param int $count
     * @param string $urls
     * @param string $titles
     * @param int $missingUrlCount
     */
    public function __construct(
        private readonly int $count,
        private readonly string $urls,
        private readonly string $titles,
        private readonly int $missingUrlCount = 0
    ) {
        if ($count < 0 || $missingUrlCount < 0 || $missingUrlCount > $count) {
            throw new InvalidArgumentException('Invalid video result count.');
        }
        if ($count === 0 && ($urls !== '' || $titles !== '')) {
            throw new InvalidArgumentException('An empty video result cannot contain list values.');
        }
    }

    /**
     * Get the association count used by output and presence selection.
     *
     * @return int
     */
    public function getCount(): int
    {
        return $this->count;
    }

    /**
     * Get a bounded diagnostic count without exposing URLs or titles.
     *
     * @return int
     */
    public function getMissingUrlCount(): int
    {
        return $this->missingUrlCount;
    }

    /**
     * Return the complete logical block before native CSV serialization.
     *
     * @return array{has_video: int, video_count: int, video_urls: string, video_titles: string}
     */
    public function toColumns(): array
    {
        return [
            'has_video' => $this->count > 0 ? 1 : 0,
            'video_count' => $this->count,
            'video_urls' => $this->urls,
            'video_titles' => $this->titles,
        ];
    }
}
