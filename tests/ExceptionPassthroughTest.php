<?php

declare(strict_types=1);

namespace Hampel\XenForo\Api\Laravel\Tests;

use Hampel\XenForo\Api\Exception\ClientException;
use Hampel\XenForo\Api\Exception\EndpointNotFoundException;
use Hampel\XenForo\Api\Exception\MalformedResponseException;
use Hampel\XenForo\Api\Exception\NotAuthenticatedException;
use Hampel\XenForo\Api\Exception\NotFoundException;
use Hampel\XenForo\Api\Exception\NotPermittedException;
use Hampel\XenForo\Api\Exception\ServerException;
use Hampel\XenForo\Api\Exception\TooManyRequestsException;
use Hampel\XenForo\Api\Laravel\Facades\XenForo;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The status-to-exception mapping survives the trip through Laravel's HTTP client.
 *
 * This is the property to protect above ergonomics, and the package's first consumer said
 * so plainly: what the core package is worth is not its transport but its taxonomy. A
 * client that reports every unsuccessful status the same way cannot tell a rejected
 * credential from a user who is not a member - both are an empty result - and the first is
 * a configuration error that should fail loudly.
 *
 * Laravel's own Http:: is the thing that flattens them, which is why an application reaching
 * for this package must not get that flattening back by the side door. Each case below would
 * be an indistinguishable "unsuccessful response" through the facade.
 */
final class ExceptionPassthroughTest extends TestCase
{
    /**
     * @return array<string, array{int, string, class-string<\Throwable>}>
     */
    public static function statuses(): array
    {
        return [
            'an unusable key is not an empty result' => [401, 'no_permission', NotAuthenticatedException::class],
            'a forbidden action is its own failure' => [403, 'no_permission', NotPermittedException::class],
            'a missing record' => [404, 'requested_page_not_found', NotFoundException::class],
            'a missing route is configuration, not absence' => [404, 'endpoint_not_found', EndpointNotFoundException::class],
            'rate limiting is typed so a caller can retry' => [429, 'rate_limit_exceeded', TooManyRequestsException::class],
            'any other caller mistake' => [400, 'invalid_page', ClientException::class],
            'the forum broke, not the caller' => [500, 'server_error', ServerException::class],
        ];
    }

    /**
     * @param  class-string<\Throwable>  $expected
     */
    #[Test]
    #[DataProvider('statuses')]
    public function a_status_arrives_as_its_own_exception(int $status, string $code, string $expected): void
    {
        Http::fake([
            'forum.example.com/*' => Http::response(
                ['errors' => [['code' => $code, 'message' => 'Something the forum said.']]],
                $status
            ),
        ]);

        $this->expectException($expected);

        XenForo::users()->get(1);
    }

    #[Test]
    public function a_success_whose_body_is_not_json_is_somebody_elses_answer(): void
    {
        // A maintenance page, a WAF challenge, a CDN interstitial and a truncated body are
        // all a 200 with HTML in it. Returned as an empty result they would read as "no such
        // record" everywhere downstream.
        Http::fake([
            'forum.example.com/*' => Http::response('<html><body>Down for maintenance</body></html>', 200),
        ]);

        $this->expectException(MalformedResponseException::class);

        XenForo::users()->get(1);
    }

    #[Test]
    public function an_error_code_is_still_readable_on_the_exception(): void
    {
        // Read the code, not the status: XenForo answers 400 for most caller mistakes.
        Http::fake([
            'forum.example.com/*' => Http::response(
                ['errors' => [['code' => 'invalid_page', 'message' => 'Requested page is past the end.']]],
                400
            ),
        ]);

        try {
            XenForo::users()->get(1);
            $this->fail('Expected a ClientException.');
        } catch (ClientException $e) {
            $this->assertTrue($e->hasCode('invalid_page'));
            $this->assertFalse($e->hasCode('no_permission'));
        }
    }
}
