<?php

declare(strict_types=1);

namespace Tracewick\Bridge\Laravel\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Console\Kernel;
use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Tracewick\Bridge\Laravel\HttpProfiler;
use Tracewick\Bridge\Laravel\TracewickServiceProvider;
use Tracewick\Bridge\Laravel\WorkProfiler;
use Tracewick\Profile\Profile;
use Tracewick\Runtime\ProfilerConfig;
use Tracewick\Runtime\UnitOfWorkProfiler;
use Tracewick\Sampler\EventType;
use Tracewick\Sampler\Frame;
use Tracewick\Sampler\Sample;
use Tracewick\Sampler\SampleBatch;
use Tracewick\Sampler\Sampler;
use Tracewick\Sink\Sink;
use Tracewick\Transport\Delivery;

final class ArtisanIntegrationTest extends TestCase
{
    /** @return list<class-string<TracewickServiceProvider>> */
    protected function getPackageProviders($app): array
    {
        return [TracewickServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        self::assertInstanceOf(Application::class, $app);
        $app->make(Repository::class)->set('tracewick.profile_console', true);
    }

    public function testAliasProfilesTheRegisteredCommandAndPreservesOutput(): void
    {
        $profiles = $this->recordProfiles();
        $kernel = $this->console();
        $kernel->registerCommand((new Command('app:report'))
            ->setAliases(['app:alias'])
            ->addArgument('identifier')
            ->setCode(static function (InputInterface $input, OutputInterface $output): int {
                $output->writeln('report output');

                return 7;
            }));
        $output = new BufferedOutput();

        self::assertSame(7, $kernel->call('app:alias', ['identifier' => 'private-argument'], $output));
        self::assertSame("report output\n", $output->fetch());
        self::assertEquals([['command' => 'app:report']], $this->labels($profiles));
    }

    public function testNestedCallsOfTheSameCommandUseOnlyTheOuterProfile(): void
    {
        $profiles = $this->recordProfiles();
        $kernel = $this->console();
        $kernel->registerCommand((new Command('app:nested'))
            ->addArgument('level')
            ->setCode(static function (InputInterface $input) use ($kernel, $profiles): int {
                if ($input->getArgument('level') === 'inner') {
                    return 5;
                }

                $status = $kernel->call('app:nested', ['level' => 'inner']);
                self::assertCount(0, $profiles);

                return $status;
            }));

        self::assertSame(5, $kernel->call('app:nested', ['level' => 'outer']));
        self::assertEquals([['command' => 'app:nested']], $this->labels($profiles));
    }

    public function testExceptionIsPreservedAndTheNextCommandCanBeProfiled(): void
    {
        $profiles = $this->recordProfiles();
        $kernel = $this->console();
        $failure = new \RuntimeException('Command failed.');
        $kernel->registerCommand((new Command('app:fail'))->setCode(static function () use ($failure): never {
            throw $failure;
        }));
        $kernel->registerCommand((new Command('app:next'))->setCode(static fn(): int => 0));

        try {
            $kernel->call('app:fail');
            self::fail('The command exception must escape Artisan::call.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertSame(0, $kernel->call('app:next'));
        self::assertEquals([['command' => 'app:fail'], ['command' => 'app:next']], $this->labels($profiles));
    }

    #[DataProvider('excludedCommands')]
    public function testExcludedRunnersDoNotProfileTheirNestedCommands(string $name): void
    {
        $profiles = $this->recordProfiles();
        $kernel = $this->console();
        $kernel->registerCommand((new Command('app:nested'))->setCode(static fn(): int => 2));
        $kernel->registerCommand((new Command($name))
            ->setCode(static fn(): int => $kernel->call('app:nested')));

        self::assertSame(2, $kernel->call($name));
        self::assertCount(0, $profiles);

        self::assertSame(2, $kernel->call('app:nested'));
        self::assertEquals([['command' => 'app:nested']], $this->labels($profiles));
    }

    /** @return iterable<string, array{string}> */
    public static function excludedCommands(): iterable
    {
        foreach (['queue:work', 'queue:listen', 'horizon', 'horizon:supervisor', 'octane:start', 'tracewick:maintain', 'schedule:work', 'serve'] as $name) {
            yield $name => [$name];
        }
    }

    public function testSinkFailureDoesNotChangeTheCommandExitCode(): void
    {
        $profiles = $this->recordProfiles(Delivery::failed('Test sink unavailable.'));
        $kernel = $this->console();
        $kernel->registerCommand((new Command('app:report'))->setCode(static fn(): int => 4));

        self::assertSame(4, $kernel->call('app:report'));
        self::assertEquals([['command' => 'app:report']], $this->labels($profiles));

        self::assertInstanceOf(Application::class, $this->app);
        self::assertSame(1, $this->app->make(WorkProfiler::class)->diagnostics()?->failedProfiles);
    }

    public function testProfilerResolutionFailureDoesNotChangeTheCommandExitCode(): void
    {
        self::assertInstanceOf(Application::class, $this->app);
        $this->app->singleton(WorkProfiler::class, static function (): never {
            throw new \RuntimeException('Profiler unavailable.');
        });
        $kernel = $this->console();
        $kernel->registerCommand((new Command('app:report'))->setCode(static fn(): int => 9));
        $kernel->registerCommand((new Command('app:next'))->setCode(static fn(): int => 0));

        self::assertSame(9, $kernel->call('app:report'));

        $profiles = $this->recordProfiles();
        self::assertSame(0, $kernel->call('app:next'));
        self::assertEquals([['command' => 'app:next']], $this->labels($profiles));
    }

    public function testArtisanDoesNotReplaceAnActiveHttpProfile(): void
    {
        $profiles = $this->recordProfiles();
        $kernel = $this->console();
        $kernel->registerCommand((new Command('app:report'))->setCode(static fn(): int => 4));

        self::assertInstanceOf(Application::class, $this->app);
        $http = $this->app->make(HttpProfiler::class);
        $request = Request::create('/items');
        self::assertTrue($http->begin($request));
        self::assertSame(4, $kernel->call('app:report'));
        self::assertCount(0, $profiles);

        $profiler = $this->app->make(WorkProfiler::class);
        $foreignOwner = new \stdClass();
        $profiler->label($foreignOwner, 'command', 'app:report');
        $profiler->finish($foreignOwner);
        self::assertCount(0, $profiles);

        $http->finish($request);
        self::assertEquals([['route' => 'unmatched', 'method' => 'GET']], $this->labels($profiles));
    }

    private function console(): Kernel
    {
        self::assertInstanceOf(Application::class, $this->app);
        $kernel = $this->app->make(ConsoleKernelContract::class);
        self::assertInstanceOf(Kernel::class, $kernel);

        // Laravel skips wiring Symfony's events while the application environment is testing.
        return $kernel->rerouteSymfonyCommandEvents();
    }

    /** @return \ArrayObject<int, Profile> */
    private function recordProfiles(?Delivery $outcome = null): \ArrayObject
    {
        /** @var \ArrayObject<int, Profile> $profiles */
        $profiles = new \ArrayObject();
        $sink = $this->createStub(Sink::class);
        $sink->method('send')->willReturnCallback(static function (Profile $profile) use ($profiles, $outcome): Delivery {
            $profiles[] = $profile;

            return $outcome ?? Delivery::delivered();
        });
        $sampler = $this->createStub(Sampler::class);
        $sampler->method('isRunning')->willReturn(true);
        $sampler->method('flush')->willReturn(new SampleBatch(
            EventType::Wall,
            100,
            1000,
            2000,
            [new Sample([new Frame('test_command')], 1, 1500)],
        ));

        self::assertInstanceOf(Application::class, $this->app);
        $this->app->instance(WorkProfiler::class, new WorkProfiler(
            new UnitOfWorkProfiler($sampler, $sink, new ProfilerConfig(sampleRate: 1.0)),
        ));

        return $profiles;
    }

    /**
     * @param \ArrayObject<int, Profile> $profiles
     *
     * @return list<array<string, string>>
     */
    private function labels(\ArrayObject $profiles): array
    {
        return array_map(static fn(Profile $profile): array => $profile->labels->toArray(), array_values($profiles->getArrayCopy()));
    }
}
