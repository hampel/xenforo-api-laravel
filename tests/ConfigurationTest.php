<?php

declare(strict_types=1);

namespace Hampel\XenForo\Api\Laravel\Tests;

use Hampel\XenForo\Api\Laravel\Http\PendingRequestClient;
use Hampel\XenForo\Api\Laravel\XenForoManager;
use Hampel\XenForo\Api\Laravel\XenForoServiceProvider;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

final class ConfigurationTest extends TestCase
{
    #[Test]
    public function the_package_config_is_merged_into_the_application(): void
    {
        $config = $this->container()->make(Config::class);

        $this->assertTrue($config->has('xenforo.default'));
        $this->assertTrue($config->has('xenforo.forums'));
        $this->assertTrue($config->has('xenforo.timeout'));
        $this->assertTrue($config->has('xenforo.connect_timeout'));
    }

    #[Test]
    public function the_config_file_is_publishable_under_its_own_tag(): void
    {
        $published = ServiceProvider::pathsToPublish(XenForoServiceProvider::class, 'xenforo-config');

        $this->assertSame([config_path('xenforo.php')], array_values($published));
    }

    #[Test]
    public function the_shipped_config_names_one_forum_and_no_credentials(): void
    {
        // Read straight from the file rather than the merged config, which the test case
        // has already overridden. An unset environment must leave the forum unusable rather
        // than pointing somewhere.
        $defaults = require __DIR__ . '/../config/xenforo.php';
        $this->assertIsArray($defaults);

        $forums = $defaults['forums'] ?? null;
        $this->assertIsArray($forums);

        $this->assertSame('main', $defaults['default'] ?? null);
        $this->assertSame(['main'], array_keys($forums));
        $this->assertSame(
            ['url' => null, 'key' => null, 'user' => null, 'bearer' => null, 'version' => null],
            $forums['main'] ?? null,
        );
        $this->assertSame(10, $defaults['timeout'] ?? null);
        $this->assertSame(5, $defaults['connect_timeout'] ?? null);
    }

    #[Test]
    public function the_transport_is_bound_by_interface_so_it_can_be_replaced(): void
    {
        $this->assertInstanceOf(PendingRequestClient::class, $this->container()->make(ClientInterface::class));
    }

    #[Test]
    public function psr17_factories_are_bound_only_if_the_application_has_not_bound_its_own(): void
    {
        $this->assertInstanceOf(RequestFactoryInterface::class, $this->container()->make(RequestFactoryInterface::class));
        $this->assertInstanceOf(StreamFactoryInterface::class, $this->container()->make(StreamFactoryInterface::class));
    }

    #[Test]
    public function registering_the_provider_binds_the_manager_and_merges_the_config(): void
    {
        // Registered here, against an application built in the test body, rather than
        // relying on the registration Testbench already did in setUp. Two reasons, and the
        // second is the important one:
        //
        // The bindings are asserted against an application that did not have them, so the
        // assertions depend on this call rather than on setUp's.
        //
        // And register() only runs under PHPUnit's error handler if it runs from here.
        // Laravel's HandleExceptions bootstrapper replaces that handler while the
        // application boots, which in a Testbench suite is during parent::setUp() - before
        // withoutDeprecationHandling() puts it back. So a deprecation raised by the
        // provider's own registration during setUp is discarded, and phpunit.xml's
        // failOnDeprecation never sees it.
        $app = new Application(__DIR__ . '/..');
        $app->instance('config', new ConfigRepository());

        (new XenForoServiceProvider($app))->register();

        $this->assertTrue($app->bound(XenForoManager::class));
        $this->assertTrue($app->bound(ClientInterface::class));
        $this->assertTrue($app->bound(RequestFactoryInterface::class));
        $this->assertTrue($app->bound(StreamFactoryInterface::class));
        $this->assertSame('main', $app->make(Config::class)->get('xenforo.default'));
    }
}
