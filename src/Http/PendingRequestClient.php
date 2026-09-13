<?php

declare(strict_types=1);

namespace Hampel\XenForo\Api\Laravel\Http;

use Closure;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\TransferStats;
use GuzzleHttp\Utils;
use Illuminate\Http\Client\Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that sends through Laravel's HTTP client.
 *
 * This is the whole reason the package exists. hampel/xenforo-api holds a PSR-18 client of
 * its own, so by default nothing it sends is visible to `Http::fake()` - an application
 * testing against it has to fake at the transport library instead, in a vocabulary its
 * other tests do not use. Handing the client this adapter instead puts every request the
 * package makes on the same handler stack `Http::` builds, so:
 *
 *     Http::fake(['forum.example.com/*' => Http::response(['user' => [...]])]);
 *
 *     $user = XenForo::users()->get(1);          // the package's real code path
 *
 *     Http::assertSent(fn ($request) => $request->hasHeader('XF-Api-Key'));
 *
 * `Http::preventStrayRequests()` works too, and reports the escape as its own
 * StrayRequestException rather than as a transport failure - the package's
 * Connection::dispatch() catches ClientExceptionInterface, and Laravel's exception is a
 * plain RuntimeException, so it passes through with the URL still in the message.
 *
 * REBUILT PER REQUEST, DELIBERATELY. Factory::fake() REPLACES the factory's stub collection
 * rather than adding to it, and createPendingRequest() copies whatever is there at the
 * moment it is called. A pending request built once and kept therefore holds a snapshot: a
 * fake registered after the client was first resolved would never be consulted, and the
 * request would go to the real forum. Rebuilding here means the stubs, the stray-request
 * setting, `Http::globalRequestMiddleware()` and the transport half of `Http::globalOptions()`
 * are all read at the moment of sending, so ordering stops mattering.
 *
 * THE FACTORY IS RESOLVED PER REQUEST TOO, for the same reason one level up. Http::swap()
 * binds a NEW factory into the container - the usual way for a test suite to start from a
 * clean set of fakes, since fake() merges. Holding the factory this client was built with
 * would send past the new fakes and past the new factory's preventStrayRequests(), which is a
 * real request carrying the configured key. So the constructor takes a resolver. A Factory
 * passed directly is held as given, which is the caller's choice for a client built by hand.
 *
 * The Guzzle handler underneath is built once and reused, which is what stops that costing
 * anything: the handler owns curl's connection pool, so keep-alive survives between
 * requests even though the stack around it is new each time. Without this an API client
 * paging through results would pay a fresh TLS handshake per page.
 *
 * REDIRECTS ARE NOT FOLLOWED, and there is no setting for it. Guzzle's PSR-18 entry point
 * hard-codes `allow_redirects => false` (Client::sendRequest()), so the redirect middleware
 * on the stack never runs and the 3xx is handed back whole. That differs from `Http::get()`,
 * which does follow, and it is the behaviour this package wants: the two attachment
 * thumbnail endpoints answer 301 with the image URL in the Location header, and that
 * redirect IS their documented output. Connection::sendRaw() treats it as a success for
 * exactly that reason.
 *
 * SENT WITH send() RATHER THAN sendRequest(), which needs explaining because sendRequest()
 * is the PSR-18 method and this class is a PSR-18 client.
 *
 * `laravel_data` and `on_stats` are PendingRequest's contract with the handler stack it
 * builds: PendingRequest::sendRequest() sets both on every call, and the recorder and stub
 * handlers assume they are there. Anything that drives that stack without going through
 * that method - as this class must, because the request it is given is already built by the
 * core package and has to reach the forum byte for byte - has to supply them itself.
 *
 * Laravel 13 reads both defensively. Laravel 12 does not, and without them every request
 * raises "Undefined array key laravel_data" from the recorder and "Undefined array key
 * on_stats" from the stub - which an application with debug error handling turns into an
 * ErrorException, so it is a hard failure rather than a notice in a log.
 *
 * The three options beside them reproduce what Guzzle's own sendRequest() sets, so the
 * PSR-18 contract is unchanged. http_errors in particular must stay off: with it on a 404
 * would arrive as a Guzzle exception, and the core package would report it as a transport
 * failure instead of mapping it to NotFoundException.
 *
 * TRANSPORT OPTIONS ARE PASSED BY HAND, AND ONLY BY NAME. PendingRequest merges its options
 * - the timeouts set above, and everything from Http::globalOptions() - only inside its own
 * sendRequest(), which this class does not call. buildClient() returns a Guzzle client built
 * from the handler stack alone, so without transportOptions() the configured timeouts and a
 * global CA bundle or proxy are silently dropped, and a stalled forum holds the request for as
 * long as the operating system allows. The options go through an allowlist - timeouts, TLS
 * verification and client certificates, proxy, protocol version, curl settings - each only when
 * its value has the type Guzzle declares, rather than wholesale. The request arrives here built
 * by the core package and has to reach the forum as built: a global `headers` entry would
 * overwrite its XF-Api-Key or Accept, and a global `query` or `form_params` its query string or
 * body. An allowlist rather than a list of exclusions, so an option a later Guzzle adds is left
 * out until someone decides it belongs.
 *
 * WHAT IT DOES NOT DO: raise ResponseReceived or ConnectionFailed. Laravel dispatches both
 * from PendingRequest::send(), a layer above the handler stack, so anything listening for
 * them - Telescope's HTTP client watcher among them - will not show this traffic. The package
 * logs every request through PSR-3 instead, which under Laravel reaches the application log.
 *
 * RequestSending DOES fire, which is the half that is easy to get wrong. PendingRequest's
 * constructor registers the callback that raises it, and buildBeforeSendingHandler() runs
 * that callback inside the stack this class drives. So a listener pairing RequestSending
 * with one of the other two sees every request announced and none of them concluded - a
 * connection failure included.
 */
final class PendingRequestClient implements ClientInterface
{
    /**
     * Guzzle's default handler, kept so curl can reuse connections. Created on first use
     * rather than in the constructor: a client that is resolved and never sent through -
     * an application with a configured forum it does not touch on this request - should
     * not pay for one.
     *
     * @var callable|null
     */
    private $handler = null;

    /**
     * @var Closure(): Factory
     */
    private readonly Closure $factory;

    /**
     * @param  Factory|(Closure(): Factory)  $factory  a resolver, so the factory is looked up
     *                                               on every send. A Factory is accepted for a
     *                                               client built by hand, and is held as given
     */
    public function __construct(
        Factory|Closure $factory,
        private readonly float $timeout,
        private readonly float $connectTimeout,
    ) {
        $this->factory = $factory instanceof Factory ? static fn (): Factory => $factory : $factory;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        // Into a local: calling the resolver below would otherwise lose PHPStan's non-null
        // narrowing of the property.
        $handler = $this->handler ??= Utils::chooseHandler();

        $pending = ($this->factory)()->createPendingRequest()
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->setHandler($handler);

        return $pending->buildClient()
            ->send($request, [
                // First, so the options below always win over anything global.
                ...self::transportOptions($pending->getOptions()),

                RequestOptions::SYNCHRONOUS => true,
                RequestOptions::ALLOW_REDIRECTS => false,
                RequestOptions::HTTP_ERRORS => false,

                // Left empty rather than filled: Request::data() parses a form or JSON
                // body out of the request itself, so `$request['field']` works in an
                // assertion without it. A multipart body is the exception - data() has
                // nothing to parse there, so Request::hasFile() sees nothing.
                'laravel_data' => [],

                // Discarded. Laravel's own callback records TransferStats on the
                // PendingRequest, and this one is thrown away with the pending request
                // that built it.
                'on_stats' => static function (TransferStats $stats): void {
                },
            ]);
    }

    /**
     * The transport half of the pending request's options, by name and by type.
     *
     * One key at a time rather than a loop over names: an array built with a variable key loses
     * its shape on the oldest PHPStan the package supports, and Guzzle 8 declares send()'s
     * options as a shape, so an untyped array does not satisfy it.
     *
     * @param  array<mixed>  $options
     * @return array{
     *     timeout?: int|float,
     *     connect_timeout?: int|float,
     *     read_timeout?: int|float,
     *     verify?: bool|string,
     *     version?: string|int|float,
     *     force_ip_resolve?: string,
     *     crypto_method?: int,
     *     crypto_method_max?: int,
     *     decode_content?: bool|string,
     *     cert?: string|array{0: string, 1?: string|null},
     *     cert_type?: string,
     *     ssl_key?: string|array{0: string, 1?: string|null},
     *     ssl_key_type?: string,
     *     proxy?: string|array{http?: string|null, https?: string|null, no?: string|array<array-key, string>|null},
     *     curl?: array<int|string, mixed>
     * }
     */
    private static function transportOptions(array $options): array
    {
        $transport = [];

        if (self::isNumber($options['timeout'] ?? null)) {
            $transport['timeout'] = $options['timeout'];
        }

        if (self::isNumber($options['connect_timeout'] ?? null)) {
            $transport['connect_timeout'] = $options['connect_timeout'];
        }

        if (self::isNumber($options['read_timeout'] ?? null)) {
            $transport['read_timeout'] = $options['read_timeout'];
        }

        if (isset($options['verify']) && (is_bool($options['verify']) || is_string($options['verify']))) {
            $transport['verify'] = $options['verify'];
        }

        if (isset($options['version']) && (is_string($options['version']) || self::isNumber($options['version']))) {
            $transport['version'] = $options['version'];
        }

        if (isset($options['force_ip_resolve']) && is_string($options['force_ip_resolve'])) {
            $transport['force_ip_resolve'] = $options['force_ip_resolve'];
        }

        if (isset($options['crypto_method']) && is_int($options['crypto_method'])) {
            $transport['crypto_method'] = $options['crypto_method'];
        }

        if (isset($options['crypto_method_max']) && is_int($options['crypto_method_max'])) {
            $transport['crypto_method_max'] = $options['crypto_method_max'];
        }

        if (isset($options['decode_content']) && (is_bool($options['decode_content']) || is_string($options['decode_content']))) {
            $transport['decode_content'] = $options['decode_content'];
        }

        $cert = self::pathWithPassword($options['cert'] ?? null);

        if ($cert !== null) {
            $transport['cert'] = $cert;
        }

        if (isset($options['cert_type']) && is_string($options['cert_type'])) {
            $transport['cert_type'] = $options['cert_type'];
        }

        $sslKey = self::pathWithPassword($options['ssl_key'] ?? null);

        if ($sslKey !== null) {
            $transport['ssl_key'] = $sslKey;
        }

        if (isset($options['ssl_key_type']) && is_string($options['ssl_key_type'])) {
            $transport['ssl_key_type'] = $options['ssl_key_type'];
        }

        $proxy = self::proxy($options['proxy'] ?? null);

        if ($proxy !== null) {
            $transport['proxy'] = $proxy;
        }

        if (isset($options['curl']) && is_array($options['curl'])) {
            $transport['curl'] = $options['curl'];
        }

        return $transport;
    }

    /**
     * @phpstan-assert-if-true int|float $value
     */
    private static function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }

    /**
     * A certificate or key: a path, or a path and its password.
     *
     * @return string|array{0: string, 1?: string|null}|null
     */
    private static function pathWithPassword(mixed $value): string|array|null
    {
        if (is_string($value)) {
            return $value;
        }

        if (! is_array($value) || ! isset($value[0]) || ! is_string($value[0])) {
            return null;
        }

        $password = $value[1] ?? null;

        return is_string($password) ? [$value[0], $password] : [$value[0]];
    }

    /**
     * A proxy: one URI for every scheme, or one per scheme with an exclusion list.
     *
     * @return string|array{http?: string|null, https?: string|null, no?: string|array<array-key, string>|null}|null
     */
    private static function proxy(mixed $value): string|array|null
    {
        if (is_string($value)) {
            return $value;
        }

        if (! is_array($value)) {
            return null;
        }

        $proxy = [];

        if (isset($value['http']) && is_string($value['http'])) {
            $proxy['http'] = $value['http'];
        }

        if (isset($value['https']) && is_string($value['https'])) {
            $proxy['https'] = $value['https'];
        }

        if (isset($value['no'])) {
            if (is_string($value['no'])) {
                $proxy['no'] = $value['no'];
            } elseif (is_array($value['no'])) {
                $proxy['no'] = array_values(array_filter($value['no'], is_string(...)));
            }
        }

        return $proxy === [] ? null : $proxy;
    }
}
