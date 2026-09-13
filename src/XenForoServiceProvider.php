<?php

declare(strict_types=1);

namespace Hampel\XenForo\Api\Laravel;

use GuzzleHttp\Psr7\HttpFactory as Psr17Factory;
use Hampel\XenForo\Api\Laravel\Exception\InvalidConfiguration;
use Hampel\XenForo\Api\Laravel\Http\PendingRequestClient;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory as HttpClientFactory;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Wires the XenForo API manager into the container.
 *
 * The transport is bound under this package's own key, xenforo.http_client, and the manager is
 * built from that key alone. It is the extension point: rebind or decorate it and every forum's
 * client picks the replacement up. The default sends through Laravel's HTTP client, which is
 * what makes the package's traffic visible to Http::fake() - see PendingRequestClient.
 *
 * NOT Psr\Http\Client\ClientInterface, which is one key shared by every package that binds it.
 * Each Laravel API wrapper once bound its adapter there, and singleton() on a bound key replaces
 * it, so with two installed the last provider registered supplied its adapter and timeouts to
 * both, and took the other's binding over. Nor does the manager fall back to a ClientInterface
 * binding when one exists: an older sibling or an unrelated library may have bound it, and
 * picking that up silently would bring the collision back and put the traffic outside
 * Http::fake().
 */
final class XenForoServiceProvider extends ServiceProvider
{
    /**
     * The container key the transport is bound under, and the one an application rebinds to
     * replace it.
     */
    public const HTTP_CLIENT = 'xenforo.http_client';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/xenforo.php', 'xenforo');

        // Laravel binds the HTTP client factory as a singleton in FoundationServiceProvider,
        // which a full application registers and a Laravel Zero one does NOT - its provider
        // set is Build, Cache, Collision, CommandRecorder, Composer, Filesystem, GitVersion
        // and NullLogger, and nothing there binds it. Unbound, the container builds a fresh
        // Factory on every make(), so the one this package holds is not the one the Http
        // facade configures, and Http::fake() silently fails to intercept: the request goes
        // to the real forum.
        //
        // Http::fake() hides the ordering, which is what makes it dangerous. fake() calls
        // Facade::swap(), which binds its instance into the container - so faking BEFORE the
        // client is resolved happens to work, and faking after it does not. Binding a
        // singleton here removes the ordering question on both platforms.
        //
        // singletonIf, so a full Laravel application keeps the framework's own binding and
        // this is a no-op there.
        $this->app->singletonIf(HttpClientFactory::class, static fn (Container $app): HttpClientFactory => new HttpClientFactory(
            $app->bound(Dispatcher::class) ? $app->make(Dispatcher::class) : null,
        ));

        // bindIf, so an application that has already bound PSR-17 factories keeps its own.
        // Guzzle's fills both roles and laravel/framework requires it, so the fallback
        // resolves in every application this package can run in.
        $this->app->bindIf(RequestFactoryInterface::class, static fn (): RequestFactoryInterface => new Psr17Factory());
        $this->app->bindIf(StreamFactoryInterface::class, static fn (): StreamFactoryInterface => new Psr17Factory());

        $this->app->singleton(self::HTTP_CLIENT, function (): ClientInterface {
            $config = $this->app->make(Config::class);

            // A resolver rather than the factory itself: looked up on every send, so it is
            // always the instance the Http facade resolves - including one bound later by
            // Http::swap(). That is what puts this package's requests among the ones
            // Http::fake() and Http::assertSent() see.
            return new PendingRequestClient(
                fn (): HttpClientFactory => $this->app->make(HttpClientFactory::class),
                $this->seconds($config->get('xenforo.timeout'), 10.0),
                $this->seconds($config->get('xenforo.connect_timeout'), 5.0),
            );
        });

        $this->app->singleton(XenForoManager::class, function (): XenForoManager {
            return new XenForoManager(
                $this->app->make(Config::class),
                $this->httpClient(),
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

    /**
     * The transport, from this package's key, checked rather than trusted: an application may
     * rebind the key, and a wrong type should name the key rather than surface as a TypeError in
     * the manager's constructor.
     */
    private function httpClient(): ClientInterface
    {
        $client = $this->app->make(self::HTTP_CLIENT);

        if (! $client instanceof ClientInterface) {
            throw InvalidConfiguration::httpClientNotPsr18(self::HTTP_CLIENT, get_debug_type($client));
        }

        return $client;
    }

    private function seconds(mixed $value, float $default): float
    {
        return is_numeric($value) ? (float) $value : $default;
    }
}
