<?php
declare(strict_types=1);

namespace LeanCommerce\ProductVideoExport\Domain;

use InvalidArgumentException;

/**
 * Presence selection over the same effective result used for output.
 */
class VideoPresence
{
    /**
     * @param string $value
     */
    private function __construct(private readonly string $value)
    {
    }

    /**
     * Validate a present parameter; absence belongs to the legacy adapter path.
     *
     * @param mixed $input
     * @return self
     */
    public static function fromInput(mixed $input): self
    {
        if (!in_array($input, ['all', 'with', 'without'], true)) {
            throw new InvalidArgumentException('Invalid video presence mode.');
        }
        return new self($input);
    }

    /**
     * Get the validated transport value.
     *
     * @return string
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * Select a context without inspecting serialized text cells.
     *
     * @param VideoResult $result
     * @return bool
     */
    public function accepts(VideoResult $result): bool
    {
        return match ($this->value) {
            'with' => $result->getCount() > 0,
            'without' => $result->getCount() === 0,
            default => true,
        };
    }
}
