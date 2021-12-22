<?php

namespace Amp\File\Internal;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Parallel\Worker\Worker;

/** @internal */
final class FileWorker implements Worker
{
    /** @var callable */
    private $push;

    private Worker $worker;

    /**
     * @param Worker $worker
     * @param callable $push Callable to push the worker back into the queue.
     */
    public function __construct(Worker $worker, callable $push)
    {
        $this->worker = $worker;
        $this->push = $push;
    }

    /**
     * Automatically pushes the worker back into the queue.
     */
    public function __destruct()
    {
        ($this->push)($this->worker);
    }

    public function isRunning(): bool
    {
        return $this->worker->isRunning();
    }

    public function isIdle(): bool
    {
        return $this->worker->isIdle();
    }

    public function execute(Task $task, ?Cancellation $cancellation = null): mixed
    {
        return $this->worker->execute($task, $cancellation);
    }

    public function shutdown(): int
    {
        return $this->worker->shutdown();
    }

    public function kill(): void
    {
        $this->worker->kill();
    }
}
