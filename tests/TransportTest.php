<?php

declare(strict_types=1);

namespace Hampel\XenForo\Api\Laravel\Tests;

use ArrayObject;
use Hampel\XenForo\Api\Client;
use Hampel\XenForo\Api\Exception\RequestException;
use Hampel\XenForo\Api\Laravel\Facades\XenForo;
use Hampel\XenForo\Api\Laravel\XenForoManager;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;

final class TransportTest extends TestCase
{
    #[Test]
    public function a_replacement_transport_is_used_by_every_forum(): void
    {
        // The reason ClientInterface is bound by interface rather than constructed inside
        // the manager: an application with its own outbound HTTP policy - a proxy-aware,
        // SSRF-guarded client everything is required to go through - binds it here and this
        // package uses it, instead of the application writing a second API client.
        $recorder = new class () implements ClientInterface {
            /** @var list<string> */
            public array $sent = [];

            public function sendRequest(RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $this->sent[] = (string) $request->getUri();

                return new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], '{"user":{"user_id":1}}');
            }
        };

        $this->container()->instance(ClientInterface::class, $recorder);

        XenForo::users()->get(1);
        XenForo::forum('second')->users()->get(2);

        $this->assertSame([
            'https://forum.example.com/api/users/1/',
            'https://other.example.com/api/v1/users/2/',
        ], $recorder->sent);
    }

    #[Test]
    public function a_redirect_is_handed_back_rather_than_followed(): void
    {
        // Not a setting, and worth pinning because it differs from Http::get(). Guzzle's
        // PSR-18 entry point hard-codes allow_redirects => false, so the redirect
        // middleware on Laravel's stack never runs. That is the behaviour this package
        // needs: the two attachment thumbnail endpoints answer 301 with the image URL in
        // the Location header, and that redirect IS their documented output, which is why
        // Connection::sendRaw() treats it as a success rather than an error.
        Http::fake([
            'forum.example.com/api/attachments/*' => Http::response('', 301, ['Location' => 'https://cdn.example.com/thumb.jpg']),
            'cdn.example.com/*' => Http::response('image-bytes', 200),
        ]);

        $response = XenForo::forum()->connection()->getRaw('attachments/1/thumbnail');

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('https://cdn.example.com/thumb.jpg', $response->getHeaderLine('Location'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'cdn.example.com'));
    }

    #[Test]
    public function global_request_middleware_reaches_this_packages_requests(): void
    {
        // Rebuilding the pending request per send is what buys this: an application's own
        // Http:: configuration applies to the package's traffic without the package knowing
        // anything about it.
        Http::globalRequestMiddleware(fn (RequestInterface $request): RequestInterface => $request->withHeader('X-Application', 'under-test'));

        Http::fake(['forum.example.com/*' => Http::response(['user' => ['user_id' => 1]])]);

        XenForo::users()->get(1);

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Application', 'under-test'));
    }

    #[Test]
    public function request_sending_fires_and_response_received_does_not(): void
    {
        // Measured rather than reasoned, because the reasoning is easy to get half right - an
        // earlier version of this package's documentation said neither fires. RequestSending
        // is dispatched from a before-sending callback that PendingRequest's constructor
        // registers, and buildBeforeSendingHandler() runs those callbacks INSIDE the handler
        // stack this adapter drives, so it fires. ResponseReceived is dispatched from
        // PendingRequest::send(), a layer above the stack, which the adapter never calls.
        $events = $this->countHttpClientEvents();

        Http::fake(['forum.example.com/*' => Http::response(['user' => ['user_id' => 1]])]);

        XenForo::users()->get(1);

        $this->assertSame(
            [RequestSending::class => 1, ResponseReceived::class => 0, ConnectionFailed::class => 0],
            $events->getArrayCopy(),
        );
    }

    #[Test]
    public function a_connection_failure_raises_request_sending_and_nothing_to_match_it(): void
    {
        // The case that makes the half-fired pair a hazard rather than a curiosity. The
        // request is announced, the connection fails, and ConnectionFailed - dispatched from
        // PendingRequest::send() like ResponseReceived - never follows. A listener pairing
        // RequestSending with one of the other two sees a request that neither succeeded nor
        // failed. The failure still reaches the caller: the core package maps Guzzle's
        // ConnectException to its own RequestException.
        $events = $this->countHttpClientEvents();

        Http::fake(['forum.example.com/*' => Http::failedConnection()]);

        try {
            XenForo::users()->get(1);
            $this->fail('Expected the connection failure to raise RequestException.');
        } catch (RequestException) {
            // expected
        }

        $this->assertSame(
            [RequestSending::class => 1, ResponseReceived::class => 0, ConnectionFailed::class => 0],
            $events->getArrayCopy(),
        );
    }

    /**
     * Listens for all three of Laravel's HTTP client events and counts them.
     *
     * An ArrayObject rather than an array because the listeners are closures: they need a
     * handle on the counts, and an object is one without a by-reference capture.
     *
     * @return ArrayObject<class-string, int>
     */
    private function countHttpClientEvents(): ArrayObject
    {
        /** @var ArrayObject<class-string, int> $counts */
        $counts = new ArrayObject([
            RequestSending::class => 0,
            ResponseReceived::class => 0,
            ConnectionFailed::class => 0,
        ]);

        foreach (array_keys($counts->getArrayCopy()) as $event) {
            Event::listen($event, function () use ($counts, $event): void {
                $counts[$event] = (int) $counts[$event] + 1;
            });
        }

        return $counts;
    }

    #[Test]
    public function configured_timeouts_reach_the_request(): void
    {
        // xenforo.timeout and connect_timeout are set on Laravel's pending request, which only
        // merges its options inside its own sendRequest() - a method this adapter does not
        // call. So unless the adapter hands them to Guzzle itself, they are silently dropped
        // and a stalled forum holds the request for as long as the operating system allows.
        $config = $this->container()->make('config');
        $config->set('xenforo.timeout', 7);
        $config->set('xenforo.connect_timeout', 3);

        $options = $this->captureRequestOptions();

        $this->freshClient()->users()->get(1);

        $this->assertSame(7.0, $options['timeout'] ?? null);
        $this->assertSame(3.0, $options['connect_timeout'] ?? null);
    }

    #[Test]
    public function global_transport_options_reach_the_request(): void
    {
        // The application's own Http::globalOptions() - a CA bundle, a proxy - is what an
        // outbound HTTP policy is expressed in, and it has to apply to this traffic too.
        Http::globalOptions([
            'verify' => '/etc/ssl/certs/ca-under-test.pem',
            'proxy' => 'http://proxy.invalid:3128',
        ]);

        $options = $this->captureRequestOptions();

        $this->freshClient()->users()->get(1);

        $this->assertSame('/etc/ssl/certs/ca-under-test.pem', $options['verify'] ?? null);
        $this->assertSame('http://proxy.invalid:3128', $options['proxy'] ?? null);
    }

    #[Test]
    public function global_headers_query_and_body_do_not_rewrite_the_request(): void
    {
        // The other side of the test above, and the reason global options pass through an
        // allowlist rather than wholesale. The core package builds the request - its key, its
        // Accept header, its query string and its body - and a global option must not replace
        // any of them.
        Http::globalOptions([
            'headers' => ['XF-Api-Key' => 'hijacked', 'Accept' => 'text/html'],
            'query' => ['injected' => '1'],
            'form_params' => ['injected' => '1'],
        ]);

        Http::fake(['forum.invalid/*' => Http::response(['user' => ['user_id' => 1]])]);

        $this->freshClient()->connection()->post('users/', ['username' => 'grace']);

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('XF-Api-Key', 'key-under-test')
            && $request->header('Accept') === ['application/json']
            && ! str_contains($request->url(), 'injected')
            && $request['username'] === 'grace'
            && ! isset($request['injected']));
    }

    /**
     * Fakes every request, recording the Guzzle options it arrived with.
     *
     * @return ArrayObject<string, mixed>
     */
    private function captureRequestOptions(): ArrayObject
    {
        /** @var ArrayObject<string, mixed> $seen */
        $seen = new ArrayObject();

        Http::fake(function ($request, array $options) use ($seen) {
            foreach ($options as $key => $value) {
                $seen[$key] = $value;
            }

            return Http::response(['user' => ['user_id' => 1]]);
        });

        return $seen;
    }

    /**
     * A client built after this test's configuration, rather than the one setUp resolved.
     */
    private function freshClient(): Client
    {
        $this->container()->forgetInstance(ClientInterface::class);
        $this->container()->forgetInstance(XenForoManager::class);

        return $this->container()->make(XenForoManager::class)
            ->build(['url' => 'https://forum.invalid', 'key' => 'key-under-test']);
    }
}
