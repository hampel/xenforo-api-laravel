<?php

declare(strict_types=1);

namespace Hampel\XenForo\Api\Laravel\Tests;

use Hampel\XenForo\Api\Laravel\Facades\XenForo;
use Illuminate\Http\Client\Request;
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
}
