<?php

namespace Amp\File\Driver;

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\File\File;

final class BlockingFile implements File
{
    /** @var resource|null */
    private $handle;
    private string $path;
    private string $mode;

    /**
     * @param resource $handle An open filesystem descriptor.
     * @param string $path File path.
     * @param string $mode File open mode.
     */
    public function __construct($handle, string $path, string $mode)
    {
        $this->handle = $handle;
        $this->path = $path;
        $this->mode = $mode;
    }

    public function __destruct()
    {
        if ($this->handle !== null) {
            @\fclose($this->handle);
        }
    }

    public function read(?Cancellation $cancellation = null, int $length = self::DEFAULT_READ_LENGTH): ?string
    {
        if ($this->handle === null) {
            throw new ClosedException("The file '{$this->path}' has been closed");
        }

        try {
            \set_error_handler(function ($type, $message) {
                throw new StreamException("Failed reading from file '{$this->path}': {$message}");
            });

            $data = \fread($this->handle, $length);
            if ($data === false) {
                throw new StreamException("Failed reading from file '{$this->path}'");
            }

            return $data !== '' ? $data : null;
        } finally {
            \restore_error_handler();
        }
    }

    public function write(string $bytes): void
    {
        if ($this->handle === null) {
            throw new ClosedException("The file '{$this->path}' has been closed");
        }

        try {
            \set_error_handler(function ($type, $message) {
                throw new StreamException("Failed writing to file '{$this->path}': {$message}");
            });

            $length = \fwrite($this->handle, $bytes);
            if ($length === false) {
                throw new StreamException("Failed writing to file '{$this->path}'");
            }
        } finally {
            \restore_error_handler();
        }
    }

    public function end(): void
    {
        try {
            $this->close();
        } catch (\Throwable) {
            // ignore any errors
        }
    }

    public function close(): void
    {
        if ($this->handle === null) {
            return;
        }

        $handle = $this->handle;
        $this->handle = null;

        try {
            \set_error_handler(function ($type, $message) {
                throw new StreamException("Failed closing file '{$this->path}': {$message}");
            });

            if (\fclose($handle)) {
                return;
            }

            throw new StreamException("Failed closing file '{$this->path}'");
        } finally {
            \restore_error_handler();
        }
    }

    public function isClosed(): bool
    {
        return $this->handle === null;
    }

    public function truncate(int $size): void
    {
        if ($this->handle === null) {
            throw new ClosedException("The file '{$this->path}' has been closed");
        }

        try {
            \set_error_handler(function ($type, $message) {
                throw new StreamException("Could not truncate file '{$this->path}': {$message}");
            });

            if (!\ftruncate($this->handle, $size)) {
                throw new StreamException("Could not truncate file '{$this->path}'");
            }
        } finally {
            \restore_error_handler();
        }
    }

    public function seek(int $position, int $whence = self::SEEK_SET): int
    {
        if ($this->handle === null) {
            throw new ClosedException("The file '{$this->path}' has been closed");
        }

        switch ($whence) {
            case self::SEEK_SET:
            case self::SEEK_CUR:
            case self::SEEK_END:
                try {
                    \set_error_handler(function ($type, $message) {
                        throw new StreamException("Could not seek in file '{$this->path}': {$message}");
                    });

                    if (\fseek($this->handle, $position, $whence) === -1) {
                        throw new StreamException("Could not seek in file '{$this->path}'");
                    }

                    return $this->tell();
                } finally {
                    \restore_error_handler();
                }
            default:
                throw new \Error("Invalid whence parameter; SEEK_SET, SEEK_CUR or SEEK_END expected");
        }
    }

    public function tell(): int
    {
        if ($this->handle === null) {
            throw new ClosedException("The file '{$this->path}' has been closed");
        }

        return \ftell($this->handle);
    }

    public function eof(): bool
    {
        if ($this->handle === null) {
            throw new ClosedException("The file '{$this->path}' has been closed");
        }

        return \feof($this->handle);
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function isReadable(): bool
    {
        return $this->handle !== null;
    }

    public function isSeekable(): bool
    {
        return $this->handle !== null;
    }

    public function isWritable(): bool
    {
        return $this->handle !== null;
    }
}
