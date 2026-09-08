<?php

declare(strict_types=1);

namespace Hampel\XenForo\Api\Laravel\Tests;

use Hampel\XenForo\Api\Authentication\ApiKey;
use Hampel\XenForo\Api\Authentication\BearerToken;
use Hampel\XenForo\Api\Authentication\Guest;
use Hampel\XenForo\Api\Authentication\SuperUserKey;
use Hampel\XenForo\Api\Client;
use Hampel\XenForo\Api\Exception\ExceptionInterface;
use Hampel\XenForo\Api\Laravel\Exception\InvalidConfiguration;
use Hampel\XenForo\Api\Laravel\Exception\UnknownForum;
use Hampel\XenForo\Api\Laravel\Facades\XenForo;
use Hampel\XenForo\Api\Laravel\XenForoManager;
use Illuminate\Contracts\Config\Repository as Config;
use PHPUnit\Framework\Attributes\Test;

final class ManagerTest extends TestCase
{
    #[Test]
    public function it_resolves_a_client_for_a_named_forum(): void
    {
        $client = $this->manager()->forum('second');

        $this->assertInstanceOf(Client::class, $client);
        $this->assertSame('https://other.example.com/api', $client->config()->baseUri);
        $this->assertSame(1, $client->config()->version);
    }

    #[Test]
    public function it_defaults_to_the_configured_forum(): void
    {
        $this->assertSame('main', $this->manager()->getDefaultForum());
        $this->assertSame(
            $this->manager()->forum('main')->config()->baseUri,
            $this->manager()->forum()->config()->baseUri,
        );
    }

    #[Test]
    public function the_board_url_is_accepted_as_readily_as_the_api_url(): void
    {
        // The core package normalises both, which matters because getting it wrong answers
        // with the forum's front end rather than an API error.
        $this->assertSame('https://forum.example.com/api', $this->manager()->forum('main')->config()->baseUri);
    }

    #[Test]
    public function clients_are_memoised_per_forum(): void
    {
        $manager = $this->manager();

        $this->assertSame($manager->forum('main'), $manager->forum('main'));
        $this->assertNotSame($manager->forum('main'), $manager->forum('second'));
    }

    #[Test]
    public function the_manager_is_a_singleton_and_the_facade_resolves_it(): void
    {
        $this->assertSame($this->manager(), $this->container()->make(XenForoManager::class));
        $this->assertSame($this->manager(), XenForo::getFacadeRoot());
    }

    #[Test]
    public function it_lists_the_configured_forums(): void
    {
        $this->assertSame(['main', 'second'], $this->manager()->configuredForums());
    }

    #[Test]
    public function an_unknown_forum_names_the_ones_that_are_configured(): void
    {
        $this->expectException(UnknownForum::class);
        $this->expectExceptionMessage('Configured forums: main, second.');

        $this->manager()->forum('nope');
    }

    #[Test]
    public function the_packages_exceptions_are_catchable_alongside_the_cores(): void
    {
        // Extending the core's base exception rather than declaring a hierarchy of our own,
        // so an application already catching ExceptionInterface catches misconfiguration
        // too rather than meeting it as an unhandled error.
        $this->expectException(ExceptionInterface::class);

        $this->manager()->forum('nope');
    }

    #[Test]
    public function a_key_alone_is_an_api_key(): void
    {
        $this->assertInstanceOf(ApiKey::class, $this->manager()->forum('main')->authentication());
        $this->assertNotInstanceOf(SuperUserKey::class, $this->manager()->forum('main')->authentication());
    }

    #[Test]
    public function a_key_with_a_user_is_a_super_user_key_acting_as_that_user(): void
    {
        $credential = $this->manager()->forum('second')->authentication();

        $this->assertInstanceOf(SuperUserKey::class, $credential);
        $this->assertSame(42, $credential->actingAs);
        $this->assertFalse($credential->bypassPermissions);
    }

    #[Test]
    public function no_credential_at_all_is_a_guest(): void
    {
        // Not an error: XenForo treats a missing key as a guest, so unauthenticated
        // endpoints answer normally and the OAuth2 exchange - which has no key yet - runs.
        $this->configure('anon', ['url' => 'https://anon.example.com']);

        $this->assertInstanceOf(Guest::class, $this->manager()->forum('anon')->authentication());
    }

    #[Test]
    public function a_bearer_token_takes_precedence_over_a_key(): void
    {
        $this->configure('token', [
            'url' => 'https://token.example.com',
            'key' => 'ignored',
            'bearer' => 'access-token',
        ]);

        $this->assertInstanceOf(BearerToken::class, $this->manager()->forum('token')->authentication());
    }

    #[Test]
    public function an_empty_setting_is_treated_as_absent(): void
    {
        // An unset environment variable reaches config as "" as readily as it reaches it as
        // null, and "" is never a meaningful key. Without this the client would present an
        // empty XF-Api-Key header, which XenForo answers differently from no header at all.
        $this->configure('blank', ['url' => 'https://blank.example.com', 'key' => '', 'bearer' => '']);

        $this->assertInstanceOf(Guest::class, $this->manager()->forum('blank')->authentication());
    }

    #[Test]
    public function a_forum_without_a_url_is_refused(): void
    {
        $this->configure('nowhere', ['key' => 'k']);

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('has no url');

        $this->manager()->forum('nowhere');
    }

    #[Test]
    public function an_acting_user_without_a_key_is_refused(): void
    {
        // The failure this prevents is silent: XenForo answers a request with no key as a
        // guest, so the call succeeds and returns whatever a guest may see.
        $this->configure('halfway', ['url' => 'https://halfway.example.com', 'user' => 7]);

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('names a user to act as but has no key');

        $this->manager()->forum('halfway');
    }

    #[Test]
    public function unnamed_calls_are_forwarded_to_the_default_forum(): void
    {
        $this->assertSame(
            $this->manager()->forum()->users(),
            $this->manager()->users(),
        );
    }

    #[Test]
    public function a_method_the_client_does_not_have_is_a_bad_method_call(): void
    {
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Call to undefined method');

        // Through __call() explicitly. That is the method under test - the guard that
        // turns a typo into a named error instead of a fatal one hop further in.
        $this->manager()->__call('noSuchEndpoint', []);
    }

    #[Test]
    public function an_empty_inventory_is_inspectable_rather_than_fatal(): void
    {
        // An application whose forum list comes from a file rather than from config needs
        // to report WHY it is empty - a missing or unparseable inventory - rather than have
        // every command die. Nothing here throws until a specific forum is named, so a
        // diagnostic command can resolve the manager and describe the situation.
        $this->container()->make(Config::class)->set('xenforo.forums', []);

        $manager = $this->manager();

        $this->assertSame([], $manager->configuredForums());
        $this->assertSame('main', $manager->getDefaultForum());

        $this->expectException(UnknownForum::class);
        $this->expectExceptionMessage('No forums are configured');

        $manager->forum('somesite');
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function configure(string $name, array $settings): void
    {
        $config = $this->container()->make(Config::class);

        $forums = $config->get('xenforo.forums');
        $config->set('xenforo.forums', array_merge(is_array($forums) ? $forums : [], [$name => $settings]));
    }

    private function manager(): XenForoManager
    {
        return $this->container()->make(XenForoManager::class);
    }
}
