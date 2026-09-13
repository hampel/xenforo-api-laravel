<?php

declare(strict_types=1);

namespace Hampel\XenForo\Api\Laravel\Tests;

use ArrayObject;
use Closure;
use Hampel\XenForo\Api\Laravel\Http\PendingRequestClient;
use Hampel\XenForo\Api\Laravel\XenForoManager;
use Hampel\XenForo\Api\Laravel\XenForoServiceProvider;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpClientFactory;
use Illuminate\Log\LogServiceProvider;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Monolog\Handler\NullHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\Http\Client\ClientInterface;

/**
 * This package installed beside another that binds Psr\Http\Client\ClientInterface.
 *
 * Every Laravel API wrapper here used to bind its adapter under that one container key and build
 * its manager from it. singleton() on a bound key replaces it, so in an application with two
 * wrappers the last provider registered supplied the adapter - and its timeouts - to both, and
 * each clobbered the other's binding. No package's own suite could see it, because each installs
 * one package. The stub below stands in for the sibling: registered before and after this
 * package, the manager must keep its own adapter and settings, and the sibling's binding must
 * survive.
 *
 * Not a Testbench test: registration order is the whole subject, and Testbench fixes it.
 */
final class SharedBindingTest extends BaseTestCase
{
    /**
     * @return array<string, array{bool}>
     */
    public static function orders(): array
    {
        return [
            'sibling registered before this package' => [true],
            'sibling registered after this package' => [false],
        ];
    }

    #[Test]
    #[DataProvider('orders')]
    public function the_manager_keeps_its_own_adapter_and_timeout(bool $siblingFirst): void
    {
        $recorder = new RecordingClient();
        $app = $this->application(fn (): ClientInterface => $recorder, $siblingFirst);

        try {
            $options = new ArrayObject();

            Http::fake(function ($request, array $received) use ($options) {
                $options['timeout'] = $received['timeout'] ?? null;

                return Http::response(['user' => ['user_id' => 1, 'username' => 'ada']]);
            });

            $client = $app->make(XenForoManager::class)
                ->build(['url' => 'https://forum.invalid', 'key' => 'key-under-test']);

            $this->assertSame('ada', $client->users()->get(1)->username);
            $this->assertSame(7.0, $options['timeout'] ?? null, 'the request did not carry xenforo.timeout');
            $this->assertSame([], $recorder->sent, "the manager sent through the sibling's client");
        } finally {
            $this->tearDownFacades();
        }
    }

    #[Test]
    #[DataProvider('orders')]
    public function the_siblings_binding_survives(bool $siblingFirst): void
    {
        // The other direction. This package must not claim a key it does not own, or it takes
        // the sibling's transport over instead of the other way round.
        $recorder = new RecordingClient();
        $app = $this->application(fn (): ClientInterface => $recorder, $siblingFirst);

        try {
            $this->assertSame($recorder, $app->make(ClientInterface::class));
        } finally {
            $this->tearDownFacades();
        }
    }

    #[Test]
    #[DataProvider('orders')]
    public function a_sibling_adapter_that_is_also_faked_does_not_lend_its_timeout(bool $siblingFirst): void
    {
        // The realistic sibling. Another wrapper's adapter is a PendingRequestClient too, so
        // Http::fake() intercepts whichever one sends and the response looks right either way.
        // Only the timeout tells them apart - which is how the defect showed up in an application
        // with three wrappers, where one package's calls were bounded by another's timeout.
        // Resolving the application's own factory, as a real sibling's provider does - so it is
        // the same faked factory, and a regression reaches the fake rather than the network.
        $app = $this->application(
            fn (Application $app): ClientInterface => new PendingRequestClient(
                fn (): HttpClientFactory => $app->make(HttpClientFactory::class),
                3.0,
                1.0,
            ),
            $siblingFirst,
        );

        try {
            $options = new ArrayObject();

            Http::fake(function ($request, array $received) use ($options) {
                $options['timeout'] = $received['timeout'] ?? null;

                return Http::response(['user' => ['user_id' => 1, 'username' => 'ada']]);
            });

            $client = $app->make(XenForoManager::class)
                ->build(['url' => 'https://forum.invalid', 'key' => 'key-under-test']);

            $this->assertSame('ada', $client->users()->get(1)->username);
            $this->assertSame(7.0, $options['timeout'] ?? null, "the request carried the sibling's timeout");
        } finally {
            $this->tearDownFacades();
        }
    }

    /**
     * @param  Closure(Application): ClientInterface  $sibling  builds the sibling's client, given
     *                                                          the application it is registered in
     */
    private function application(Closure $sibling, bool $siblingFirst): Application
    {
        $app = new Application(__DIR__ . '/..');
        $app->instance('config', new ConfigRepository());
        $app->register(new EventServiceProvider($app));
        $app->register(new LogServiceProvider($app));

        $config = $app->make('config');
        $config->set('logging.default', 'null');
        $config->set('logging.channels.null', ['driver' => 'monolog', 'handler' => NullHandler::class]);
        $config->set('xenforo.timeout', 7);

        $client = $sibling($app);

        $siblingProvider = new class ($app, $client) extends ServiceProvider {
            public function __construct(Application $app, private readonly ClientInterface $client)
            {
                parent::__construct($app);
            }

            public function register(): void
            {
                $this->app->singleton(ClientInterface::class, fn (): ClientInterface => $this->client);
            }
        };

        $providers = $siblingFirst
            ? [$siblingProvider, new XenForoServiceProvider($app)]
            : [new XenForoServiceProvider($app), $siblingProvider];

        foreach ($providers as $provider) {
            $provider->register();
        }

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        return $app;
    }

    private function tearDownFacades(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
    }
}
