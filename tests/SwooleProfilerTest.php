<?php

declare(strict_types=1);

namespace Tracewick\Bridge\Laravel\Tests;

use Illuminate\Foundation\Application;
use Laravel\Octane\Events\WorkerStarting;
use PHPUnit\Framework\TestCase;
use Tracewick\Bridge\Laravel\Octane\SwooleProfiler;
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

final class SwooleProfilerTest extends TestCase
{
    public function testOperationReleaseDeliversClosedWindowsAndStopDrainsTheLastWindow(): void
    {
        $app = new Application();
        $starting = new WorkerStarting($app);
        $calls = [];
        $labels = [];
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
        $sampler->expects(self::exactly(4))->method('flush')->willReturnCallback(static function () use (&$now): SampleBatch {
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
        $listener = new SwooleProfiler(
            static function () use (&$calls, $profiler): ContinuousProfiler {
                $calls[] = 'create';

                return $profiler;
            },
            $clock,
        );

        $listener->starting($starting);
        $app->instance(\Swoole\Http\Server::class, (object) ['taskworker' => true]);
        $listener->starting($starting);
        $listener->release();
        self::assertSame([], $calls);
        $app->instance(\Swoole\Http\Server::class, (object) ['taskworker' => false]);
        $listener->starting($starting);
        $listener->starting($starting);
        self::assertSame(['create', 'registered shutdown'], $calls);

        $listener->release();
        $listener->release();
        self::assertSame([], $labels);
        $now = 18_000_000_000;
        $listener->release();
        self::assertSame([['environment' => 'testing']], $labels);
        $listener->stopping();
        $listener->stopping();
        $listener->release();
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
        $listener = new SwooleProfiler(static fn(): ?ContinuousProfiler => null, $clock);
        $listener->starting(new WorkerStarting($app));
        $listener->release();
        $listener->stopping();
    }
}
