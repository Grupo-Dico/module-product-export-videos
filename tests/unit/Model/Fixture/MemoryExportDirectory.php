<?php
declare(strict_types=1);

namespace LeanCommerce\ProductVideoExport\Test\Unit\Model\Fixture;

use RuntimeException;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Filesystem\File\WriteInterface as FileWriteInterface;

final class MemoryExportDirectory implements WriteInterface
{
    public array $files = [];
    public array $opened = [];
    public array $deleted = [];
    public bool $failDelete = false;
    public bool $raceOnExclusiveOpen = false;
    private array $inodes = [];
    private int $nextInode = 1;

    public function isExist(string $path): bool
    {
        return array_key_exists($path, $this->files);
    }

    public function isFile(string $path): bool
    {
        return $this->isExist($path);
    }

    public function openFile(string $path, string $mode): object
    {
        $this->opened[] = [$path, $mode];
        if ($mode === 'x') {
            if ($this->raceOnExclusiveOpen) {
                $this->files[$path] = 'other owner';
            }
            if ($this->isExist($path)) {
                throw new RuntimeException('exclusive open failed');
            }
        }
        if (!$this->isExist($path)) {
            $this->inodes[$path] = $this->nextInode++;
        }
        if ($mode !== 'r') {
            $this->files[$path] = '';
        }
        return new class implements FileWriteInterface {
            private bool $closed = false;
            public function close(): void
            {
                if ($this->closed) {
                    throw new RuntimeException('handle already closed');
                }
                $this->closed = true;
            }
        };
    }

    public function stat(string $path): array
    {
        $this->inodes[$path] ??= $this->nextInode++;
        return ['dev' => 1, 'ino' => $this->inodes[$path]];
    }

    public function renameFile(string $path, string $destination): void
    {
        $this->files[$destination] = $this->files[$path];
        $this->inodes[$destination] = $this->inodes[$path];
        unset($this->files[$path], $this->inodes[$path]);
    }

    public function readFile(string $path): string
    {
        return $this->files[$path];
    }

    public function delete(string $path): void
    {
        if ($this->failDelete) {
            throw new RuntimeException('cleanup failed');
        }
        $this->deleted[] = $path;
        unset($this->files[$path], $this->inodes[$path]);
    }
}
