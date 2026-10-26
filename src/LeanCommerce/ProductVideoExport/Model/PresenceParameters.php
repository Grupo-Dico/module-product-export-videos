<?php
declare(strict_types=1);

namespace LeanCommerce\ProductVideoExport\Model;

use InvalidArgumentException;
use LeanCommerce\ProductVideoExport\Domain\VideoPresence;
use Magento\Framework\Exception\LocalizedException;

/**
 * Resolve the same presence contract at native request and worker boundaries.
 */
class PresenceParameters
{
    public const KEY = 'lc_product_video_export_presence';

    /**
     * Resolve native model parameters or ExportInfoFactory's camelCase parameters.
     *
     * Missing module input preserves legacy behavior, including native validation.
     * A present null or empty value is invalid and never defaults to all.
     *
     * @param array $parameters
     * @return VideoPresence|null
     * @throws LocalizedException
     */
    public function resolve(array $parameters): ?VideoPresence
    {
        $presence = null;
        foreach (['export_filter', 'exportFilter'] as $group) {
            $filters = $parameters[$group] ?? null;
            if (!is_array($filters) || !array_key_exists(self::KEY, $filters)) {
                continue;
            }

            try {
                $candidate = VideoPresence::fromInput($filters[self::KEY]);
            } catch (InvalidArgumentException $exception) {
                throw new LocalizedException(__('Select a valid video presence option.'), $exception);
            }
            if ($presence !== null && $presence->getValue() !== $candidate->getValue()) {
                throw new LocalizedException(__('Select a valid video presence option.'));
            }
            $presence = $candidate;
        }
        return $presence;
    }
}
