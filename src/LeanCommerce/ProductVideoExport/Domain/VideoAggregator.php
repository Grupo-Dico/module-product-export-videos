<?php
declare(strict_types=1);

namespace LeanCommerce\ProductVideoExport\Domain;

use DomainException;

/**
 * Resolves, selects and orders associations once for all four output fields.
 */
class VideoAggregator
{
    /**
     * @param VideoListCodec $codec
     */
    public function __construct(private readonly VideoListCodec $codec)
    {
    }

    /**
     * Aggregate exactly one product and store context, without deduplicating by URL.
     *
     * @param iterable<VideoAssociation> $associations
     * @return VideoResult
     */
    public function aggregate(iterable $associations): VideoResult
    {
        $seen = [];
        $eligible = [];
        foreach ($associations as $association) {
            $valueId = $association->valueId;
            if (isset($seen[$valueId])) {
                if (!$this->sameAssociation($seen[$valueId], $association)) {
                    throw new DomainException('Conflicting rows for one video association.');
                }
                continue;
            }
            $seen[$valueId] = $association;
            $base = $association->defaultMetadata;
            $store = $association->storeMetadata;
            $disabled = $store?->disabled ?? $base->disabled ?? 0;
            if ($association->mediaType !== 'external-video' || $association->globallyDisabled || $disabled !== 0) {
                continue;
            }
            $eligible[] = [
                'value_id' => $valueId,
                'position' => $store?->position ?? $base->position,
                'url' => $store?->url ?? $base->url,
                'title' => $store?->title ?? $base->title ?? '',
            ];
        }
        usort($eligible, static function (array $left, array $right): int {
            return ($left['position'] === null) <=> ($right['position'] === null)
                ?: $left['position'] <=> $right['position']
                ?: $left['value_id'] <=> $right['value_id'];
        });

        $urls = [];
        $titles = [];
        $missingUrlCount = 0;
        foreach ($eligible as $video) {
            $urls[] = $video['url'] ?? '';
            $titles[] = $this->codec->normalizeTitle($video['title']);
            if ($video['url'] === null || $video['url'] === '') {
                $missingUrlCount++;
            }
        }
        return new VideoResult(
            count($eligible),
            $this->codec->encode($urls),
            $this->codec->encode($titles),
            $missingUrlCount
        );
    }

    /**
     * Compare association values strictly while accepting distinct objects with identical values.
     *
     * @param VideoAssociation $left
     * @param VideoAssociation $right
     * @return bool
     */
    private function sameAssociation(VideoAssociation $left, VideoAssociation $right): bool
    {
        return $left->valueId === $right->valueId
            && $left->mediaType === $right->mediaType
            && $left->globallyDisabled === $right->globallyDisabled
            && $this->sameMetadata($left->defaultMetadata, $right->defaultMetadata)
            && $this->sameMetadata($left->storeMetadata, $right->storeMetadata);
    }

    /**
     * Preserve the distinctions between inherited NULL, explicit zero, empty and numeric strings.
     *
     * @param VideoMetadata|null $left
     * @param VideoMetadata|null $right
     * @return bool
     */
    private function sameMetadata(?VideoMetadata $left, ?VideoMetadata $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }
        return $left->disabled === $right->disabled
            && $left->position === $right->position
            && $left->url === $right->url
            && $left->title === $right->title;
    }
}
