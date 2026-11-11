<?php

declare(strict_types=1);

namespace Yurkovskiy\Partitions\Tests\Support;

use Illuminate\Contracts\Events\Dispatcher;

class FakeDispatcher implements Dispatcher
{
    /** @var array<int, object|string> */
    public array $dispatched = [];

    public function listen($events, $listener = null): void {}

    public function hasListeners($eventName): bool
    {
        return false;
    }

    public function subscribe($subscriber): void {}

    public function until($event, $payload = [])
    {
        $this->dispatched[] = $event;

        return null;
    }

    public function dispatch($event, $payload = [], $halt = false)
    {
        $this->dispatched[] = $event;

        return null;
    }

    public function push($event, $payload = []): void
    {
        $this->dispatched[] = $event;
    }

    public function flush($event): void {}

    public function forget($event): void {}

    public function forgetPushed(): void {}

    /**
     * @template TEvent of object
     *
     * @param  class-string<TEvent>  $event
     * @return TEvent|null
     */
    public function firstOf(string $event): ?object
    {
        foreach ($this->dispatched as $dispatched) {
            if ($dispatched instanceof $event) {
                return $dispatched;
            }
        }

        return null;
    }
}
