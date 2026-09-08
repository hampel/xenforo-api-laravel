<?php

declare(strict_types=1);

namespace Hampel\XenForo\Api\Laravel;

use BadMethodCallException;
use Hampel\XenForo\Api\Authentication\ApiKey;
use Hampel\XenForo\Api\Authentication\Authentication;
use Hampel\XenForo\Api\Authentication\BearerToken;
use Hampel\XenForo\Api\Authentication\Guest;
use Hampel\XenForo\Api\Authentication\SuperUserKey;
use Hampel\XenForo\Api\Client;
use Hampel\XenForo\Api\Config as ForumConfig;
use Hampel\XenForo\Api\Laravel\Exception\InvalidConfiguration;
use Hampel\XenForo\Api\Laravel\Exception\UnknownForum;
use Illuminate\Contracts\Config\Repository as Config;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * One XenForo client per configured forum.
 *
 * Every application that has used this API on more than one forum has written this class:
 * a URL and a credential per site, a default, and something to look one up by name. It is
 * the shape Laravel's own database and mail managers take, and the reason it belongs here
 * rather than in each application.
 *
 *     XenForo::users()->get(1);                     // the default forum
 *     XenForo::forum('support')->users()->get(1);   // a named one
 *
 * Clients are memoised per name. The transport underneath them is not - see
 * PendingRequestClient, which resolves Laravel's HTTP factory at the moment of sending so
 * that `Http::fake()` works whenever it is called.
 *
 * The @mixin is what makes $manager->users() analysable: __call() forwards anything the
 * client answers to, and one line that cannot drift says so. The facade repeats the list
 * explicitly because @method static is the only form __callStatic() can carry, and a test
 * keeps that copy honest.
 *
 * @mixin Client
 */
final class XenForoManager
{
    /** @var array<string, Client> */
    private array $forums = [];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The client for a configured forum, or for the default when no name is given.
     */
    public function forum(?string $name = null): Client
    {
        $name ??= $this->getDefaultForum();

        return $this->forums[$name] ??= $this->build($name);
    }

    public function getDefaultForum(): string
    {
        $default = $this->config->get('xenforo.default');

        return is_string($default) && $default !== '' ? $default : 'main';
    }

    /**
     * The configured forum names, in the order they were declared.
     *
     * @return list<string>
     */
    public function configuredForums(): array
    {
        $forums = $this->config->get('xenforo.forums');

        return is_array($forums) ? array_values(array_filter(array_keys($forums), 'is_string')) : [];
    }

    private function build(string $name): Client
    {
        $settings = $this->config->get('xenforo.forums.' . $name);

        if (! is_array($settings)) {
            throw UnknownForum::named($name, $this->configuredForums());
        }

        $url = $this->string($settings, 'url');

        if ($url === null) {
            throw InvalidConfiguration::missingUrl($name);
        }

        $version = $settings['version'] ?? null;

        return new Client(
            new ForumConfig($url, is_numeric($version) ? (int) $version : null),
            $this->credential($name, $settings),
            $this->client,
            $this->requestFactory,
            $this->streamFactory,
            $this->logger,
        );
    }

    /**
     * Which of XenForo's credentials the configuration describes.
     *
     * A bearer token wins where both are present: it is what an OAuth2 exchange leaves
     * behind, so a configuration carrying one is describing a token that has replaced the
     * key rather than sitting beside it. `bypassPermissions` is deliberately not modelled
     * here - it is per-call by nature, and `SuperUserKey::withBypassPermissions()` is one
     * line at the call site.
     *
     * @param  array<mixed>  $settings
     */
    private function credential(string $name, array $settings): Authentication
    {
        $bearer = $this->string($settings, 'bearer');

        if ($bearer !== null) {
            return new BearerToken($bearer);
        }

        $key = $this->string($settings, 'key');
        $user = $this->string($settings, 'user');

        if ($key === null) {
            if ($user !== null) {
                throw InvalidConfiguration::actingUserWithoutKey($name);
            }

            // Not an error: XenForo treats a missing key as a guest rather than as a
            // failure, so the unauthenticated endpoints - and the OAuth2 token exchange
            // that has no key yet - answer normally.
            return new Guest();
        }

        return $user === null ? new ApiKey($key) : new SuperUserKey($key, (int) $user);
    }

    /**
     * One setting, as a non-empty string.
     *
     * Empty is treated as absent throughout, because an unset environment variable reaches
     * config as an empty string as readily as it reaches it as null, and "" is never a
     * meaningful URL, key or token.
     *
     * @param  array<mixed>  $settings
     */
    private function string(array $settings, string $key): ?string
    {
        $value = $settings[$key] ?? null;

        if (is_int($value)) {
            $value = (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Anything else goes to the default forum's client, so a single-forum application never
     * has to name one: `XenForo::users()` rather than `XenForo::forum('main')->users()`.
     *
     * The facade carries a @method line for each of these, which is where the types come
     * from - see Facades\XenForo, and the test that keeps the two in step.
     *
     * @param  array<mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        $forum = $this->forum();

        if (! method_exists($forum, $method)) {
            throw new BadMethodCallException(sprintf(
                'Call to undefined method %s::%s(). The manager forwards to %s.',
                self::class,
                $method,
                Client::class
            ));
        }

        return $forum->{$method}(...$arguments);
    }
}
