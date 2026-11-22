<?php

declare(strict_types=1);

namespace Tracewick\Bridge\Laravel\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase;
use Tracewick\Bridge\Laravel\TracewickServiceProvider;

final class PackageBootstrapTest extends TestCase
{
    /** @return list<class-string<TracewickServiceProvider>> */
    protected function getPackageProviders($app): array
    {
        return [TracewickServiceProvider::class];
    }

    public function testPackageRegistersInLaravel(): void
    {
        self::assertInstanceOf(Application::class, $this->app);
        self::assertInstanceOf(
            TracewickServiceProvider::class,
            $this->app->getProvider(TracewickServiceProvider::class),
        );
    }
}
