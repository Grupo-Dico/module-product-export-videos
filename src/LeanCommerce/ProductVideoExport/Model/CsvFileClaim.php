<?php
declare(strict_types=1);

namespace LeanCommerce\ProductVideoExport\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Filesystem\File\WriteInterface as FileWriteInterface;
use Throwable;

/**
 * Retains the original inode until one cleanup attempt releases the exclusive reservation.
 */
class CsvFileClaim
{
    /** @var array */
    private readonly array $identity;

    /** @var bool */
    private bool $released = false;

    /**
     * Capture identity immediately after exclusive creation, before publication is possible.
     *
     * @param WriteInterface $directory
     * @param string $destination
     * @param FileWriteInterface $reservation
     * @throws LocalizedException
     */
    public function __construct(
        private readonly WriteInterface $directory,
        private readonly string $destination,
        private readonly FileWriteInterface $reservation
    ) {
        $identity = $directory->stat($destination);
        if (!isset($identity['dev'], $identity['ino']) || (int)$identity['ino'] === 0) {
            throw new LocalizedException(__('The product video export could not be completed.'));
        }
        $this->identity = $identity;
    }

    /**
     * Delete only the still-owned inode, once; a renamed or recreated path is not ours.
     *
     * @return void
     * @throws Throwable
     */
    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $this->released = true;
        $failure = null;
        try {
            if ($this->directory->isFile($this->destination)) {
                $current = $this->directory->stat($this->destination);
                if (($current['dev'] ?? null) === $this->identity['dev']
                    && ($current['ino'] ?? null) === $this->identity['ino']
                ) {
                    $this->directory->delete($this->destination);
                }
            }
        } catch (Throwable $exception) {
            $failure = $exception;
        }
        try {
            $this->reservation->close();
        } catch (Throwable $exception) {
            $failure ??= $exception;
        }
        if ($failure !== null) {
            throw $failure;
        }
    }
}
