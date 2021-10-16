<?php

namespace Amp\File\Driver;

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\StreamException;
use Amp\File\File;
use Amp\File\Internal;
use Amp\File\PendingOperationError;
use Amp\Future;
use Amp\Parallel\Worker\TaskException;
use Amp\Parallel\Worker\Worker;
use Amp\Parallel\Worker\WorkerException;
use function Amp\coroutine;
use function Revolt\launch;

final class ParallelFile implements File
{
    private Worker $worker;

    private ?int $id;

    private string $path;

    private int $position;

    private int $size;

    private string $mode;

    /** @var bool True if an operation is pending. */
    private bool $busy = false;

    /** @var int Number of pending write operations. */
    private int $pendingWrites = 0;

    private bool $writable = true;

    private ?Future $closing = null;

    /**
     * @param Worker $worker
     * @param int    $id
     * @param string $path
     * @param int    $size
     * @param string $mode
     */
    public function __construct(Worker $worker, int $id, string $path, int $size, string $mode)
    {
        $this->worker = $worker;
        $this->id = $id;
        $this->path = $path;
        $this->size = $size;
        $this->mode = $mode;
        $this->position = $this->mode[0] === 'a' ? $this->size : 0;
    }

    public function __destruct()
    {
        if ($this->id !== null && $this->worker->isRunning()) {
            $id = $this->id;
            $worker = $this->worker;
            launch(static fn () => $worker->enqueue(new Internal\FileTask('fclose', [], $id)));
        }
    }

    public function close(): void
    {
        if (!$this->worker->isRunning()) {
            return;
        }

        if ($this->closing) {
            $this->closing->await();
            return;
        }

        $this->writable = false;

        $this->closing = coroutine(function (): void {
            $id = $this->id;
            $this->id = null;
            $this->worker->enqueue(new Internal\FileTask('fclose', [], $id));
        });

        $this->closing->await();
    }

    public function truncate(int $size): void
    {
        if ($this->id === null) {
            throw new ClosedException("The file has been closed");
        }

        if ($this->busy) {
            throw new PendingOperationError;
        }

        if (!$this->writable) {
            throw new ClosedException("The file is no longer writable");
        }

        ++$this->pendingWrites;
        $this->busy = true;

        try {
            $this->worker->enqueue(new Internal\FileTask('ftruncate', [$size], $this->id));
        } catch (TaskException $exception) {
            throw new StreamException("Reading from the file failed", 0, $exception);
        } catch (WorkerException $exception) {
            throw new StreamException("Sending the task to the worker failed", 0, $exception);
        } finally {
            if (--$this->pendingWrites === 0) {
                $this->busy = false;
            }
        }
    }

    public function eof(): bool
    {
        return $this->pendingWrites === 0 && $this->size <= $this->position;
    }

    public function read(int $length = self::DEFAULT_READ_LENGTH): ?string
    {
        if ($this->id === null) {
            throw new ClosedException("The file has been closed");
        }

        if ($this->busy) {
            throw new PendingOperationError;
        }

        $this->busy = true;

        try {
            $data = $this->worker->enqueue(new Internal\FileTask('fread', [$length], $this->id));

            if ($data !== null) {
                $this->position += \strlen($data);
            }
        } catch (TaskException $exception) {
            throw new StreamException("Reading from the file failed", 0, $exception);
        } catch (WorkerException $exception) {
            throw new StreamException("Sending the task to the worker failed", 0, $exception);
        } finally {
            $this->busy = false;
        }

        return $data;
    }

    public function write(string $data): Future
    {
        if ($this->id === null) {
            throw new ClosedException("The file has been closed");
        }

        if ($this->busy && $this->pendingWrites === 0) {
            throw new PendingOperationError;
        }

        if (!$this->writable) {
            throw new ClosedException("The file is no longer writable");
        }

        ++$this->pendingWrites;
        $this->busy = true;

        return coroutine(function () use ($data): void {
            try {
                $this->worker->enqueue(new Internal\FileTask('fwrite', [$data], $this->id));
                $this->position += \strlen($data);
            } catch (TaskException $exception) {
                throw new StreamException("Writing to the file failed", 0, $exception);
            } catch (WorkerException $exception) {
                throw new StreamException("Sending the task to the worker failed", 0, $exception);
            } finally {
                if (--$this->pendingWrites === 0) {
                    $this->busy = false;
                }
            }
        });
    }

    public function end(string $data = ""): Future
    {
        return coroutine(function () use ($data): void {
            try {
                $future = $this->write($data);
                $this->writable = false;
                $future->await();
            } finally {
                $this->close();
            }
        });
    }

    public function seek(int $offset, int $whence = SEEK_SET): int
    {
        if ($this->id === null) {
            throw new ClosedException("The file has been closed");
        }

        if ($this->busy) {
            throw new PendingOperationError;
        }

        switch ($whence) {
            case self::SEEK_SET:
            case self::SEEK_CUR:
            case self::SEEK_END:
                try {
                    $this->position = $this->worker->enqueue(
                        new Internal\FileTask('fseek', [$offset, $whence], $this->id)
                    );

                    if ($this->position > $this->size) {
                        $this->size = $this->position;
                    }

                    return $this->position;
                } catch (TaskException $exception) {
                    throw new StreamException('Seeking in the file failed.', 0, $exception);
                } catch (WorkerException $exception) {
                    throw new StreamException("Sending the task to the worker failed", 0, $exception);
                }

            default:
                throw new \Error('Invalid whence value. Use SEEK_SET, SEEK_CUR, or SEEK_END.');
        }
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getMode(): string
    {
        return $this->mode;
    }
}
