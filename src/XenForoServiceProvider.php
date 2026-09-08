<?php

declare(strict_types=1);

namespace Hampel\XenForo\Api\Laravel;

use GuzzleHttp\Psr7\HttpFactory as Psr17Factory;
use Hampel\XenForo\Api\Laravel\Http\PendingRequestClient;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Factory as HttpClientFactory;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Wires the XenForo API manager into the container.
 *
 * ClientInterface is bound separately, and by interface, because it is the package's
 * extension point: rebind or decorate it and every forum's client picks the replacement up.
 * The default sends through Laravel's HTTP client, which is what makes the package's
 * traffic visible to Http::fake() - see PendingRequestClient.
 */
final class XenForoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/xenforo.php', 'xenforo');

        // bindIf, so an application that has already bound PSR-17 factories keeps its own.
        // Guzzle's fills both roles and laravel/framework requires it, so the fallback
        // resolves in every application this package can run in.
        $this->app->bindIf(RequestFactoryInterface::class, static fn (): RequestFactoryInterface => new Psr17Factory());
        $this->app->bindIf(StreamFactoryInterface::class, static fn (): StreamFactoryInterface => new Psr17Factory());

        $this->app->singleton(ClientInterface::class, function (): ClientInterface {
            $config = $this->app->make(Config::class);

            // The same Factory instance the Http facade resolves, which is what puts this
            // package's requests among the ones Http::fake() and Http::assertSent() see.
            return new PendingRequestClient(
                $this->app->make(HttpClientFactory::class),
                $this->seconds($config->get('xenforo.timeout'), 10.0),
                $this->seconds($config->get('xenforo.connect_timeout'), 5.0),
            );
        });

        $this->app->singleton(XenForoManager::class, function (): XenForoManager {
            return new XenForoManager(
                $this->app->make(Config::class),
                $this->app->make(ClientInterface::class),
                $this->app->make(RequestFactoryInterface::class),
                $this->app->make(StreamFactoryInterface::class),
                $this->app->make(LoggerInterface::class),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // configPath() rather than the config_path() helper: the helper is defined by
            // illuminate/foundation, which this package does not require and should not.
            // Requiring only the components it uses is a claim that the package needs no
            // full application, and Testbench -- which boots one -- would never catch the
            // helper contradicting it.
            $this->publishes([
                __DIR__ . '/../config/xenforo.php' => $this->app->configPath('xenforo.php'),
            ], 'xenforo-config');
        }
    }

    private function seconds(mixed $value, float $default): float
    {
        return is_numeric($value) ? (float) $value : $default;
    }
}
