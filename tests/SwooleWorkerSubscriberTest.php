<?php

declare(strict_types=1);

namespace Tracewick\Bridge\Laravel\Tests;

use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Laravel\Octane\Events\OperationReleased;
use Laravel\Octane\Events\WorkerStarting;
use Laravel\Octane\Events\WorkerStopping;
use PHPUnit\Framework\TestCase;
use Tracewick\Bridge\Laravel\Octane\SwooleWorkerSubscriber;
use Tracewick\Clock\Clock;
use Tracewick\Profile\Labels;
use Tracewick\Profile\Profile;
use Tracewick\Runtime\ContinuousProfiler;
use Tracewick\Runtime\ContinuousProfilerConfig;
use Tracewick\Sampler\EventType;
use Tracewick\Sampler\Frame;
use Tracewick\Sampler\Sample;
use Tracewick\Sampler\SampleBatch;
use Tracewick\Sampler\Sampler;
use Tracewick\Sink\Sink;
use Tracewick\Transport\Delivery;

final class SwooleWorkerSubscriberTest extends TestCase
{
    public function testOperationReleaseDeliversClosedWindowsAndStopDrainsTheLastWindow(): void
    {
        $app = new Application();
        $events = new Dispatcher($app);
        $starting = new WorkerStarting($app);
        $released = new OperationReleased($app);
        $stopping = new WorkerStopping($app);
        $calls = [];
        $labels = [];
        $flushTimes = [];
        $now = 2_000_000_000;
        $clock = $this->createStub(Clock::class);
        $clock->method('nowNs')->willReturnCallback(static function () use (&$now): int {
            return $now;
        });
        $sampler = $this->createMock(Sampler::class);
        $sampler->expects(self::once())->method('start');
        $sampler->expects(self::once())->method('stop')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'stop';
        });
        $sampler->method('isRunning')->willReturn(true);
        $sampler->expects(self::exactly(5))->method('flush')->willReturnCallback(static function () use (&$now, &$flushTimes): SampleBatch {
            $flushTimes[] = $now;

            return new SampleBatch(EventType::Wall, 100, $now - 200, $now, [
                new Sample([new Frame('worker')], 1, $now - 100),
            ]);
        });
        $sink = $this->createStub(Sink::class);
        $sink->method('send')->willReturnCallback(static function (Profile $profile) use (&$labels): Delivery {
            $labels[] = $profile->labels->toArray();

            return Delivery::delivered();
        });
        $profiler = new ContinuousProfiler(
            $sampler,
            $sink,
            new ContinuousProfilerConfig('laravel', new Labels(['environment' => 'testing'])),
            clock: $clock,
            registerShutdownFunction: static function () use (&$calls): void {
                $calls[] = 'registered shutdown';
            },
        );
        $events->subscribe(new SwooleWorkerSubscriber(
            static function () use (&$calls, $profiler): ContinuousProfiler {
                $calls[] = 'create';

                return $profiler;
            },
            $clock,
        ));

        $events->dispatch($starting);
        $app->instance(\Swoole\Http\Server::class, (object) ['taskworker' => true]);
        $events->dispatch($starting);
        $app->instance(\Swoole\Http\Server::class, (object) []);
        $events->dispatch($starting);
        $events->dispatch($released);
        self::assertSame([], $calls);
        $app->instance(\Swoole\Http\Server::class, (object) ['taskworker' => false]);
        $events->dispatch($starting);
        $events->dispatch($starting);
        self::assertSame(['create', 'registered shutdown'], $calls);

        $events->dispatch($released);
        $events->dispatch($released);
        self::assertSame([], $labels);
        $now = 2_999_999_999;
        $events->dispatch($released);
        self::assertNotContains($now, $flushTimes);
        $now = 3_000_000_000;
        $events->dispatch($released);
        self::assertContains($now, $flushTimes);
        $now = 18_000_000_000;
        $events->dispatch($released);
        self::assertSame([['environment' => 'testing']], $labels);
        $events->dispatch($stopping);
        $events->dispatch($stopping);
        $events->dispatch($released);
        self::assertSame([['environment' => 'testing'], ['environment' => 'testing']], $labels);
        self::assertSame(['create', 'registered shutdown', 'stop'], $calls);
        self::assertFalse($profiler->isRunning());
    }

    public function testMissingProfilerLeavesTheWorkerUp(): void
    {
        $app = new Application();
        $app->instance(\Swoole\Http\Server::class, (object) ['taskworker' => false]);
        $clock = $this->createMock(Clock::class);
        $clock->expects(self::never())->method('nowNs');
        $listener = new SwooleWorkerSubscriber(static fn(): ?ContinuousProfiler => null, $clock);
        $listener->starting(new WorkerStarting($app));
        $listener->release();
        $listener->stopping();
    }
}
