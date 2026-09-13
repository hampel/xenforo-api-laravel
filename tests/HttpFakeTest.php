<?php

declare(strict_types=1);

namespace Hampel\XenForo\Api\Laravel\Tests;

use Hampel\XenForo\Api\Exception\NotFoundException;
use Hampel\XenForo\Api\Generated\Schema\User;
use Hampel\XenForo\Api\Laravel\Facades\XenForo;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * The reason the package exists.
 *
 * hampel/xenforo-api holds its own PSR-18 client, so by default nothing it sends is visible
 * to Http::fake() and an application testing against it has to fake at the transport
 * library instead - in a vocabulary the rest of its suite does not use. Everything below
 * goes through the package's real request building, status mapping and exception hierarchy;
 * only the socket is replaced.
 *
 * If this file does not pass, the package has no reason to exist.
 */
final class HttpFakeTest extends TestCase
{
    #[Test]
    public function a_faked_response_reaches_the_caller_as_a_typed_entity(): void
    {
        Http::fake([
            'forum.example.com/*' => Http::response(['user' => ['user_id' => 7, 'username' => 'ada']]),
        ]);

        $user = XenForo::users()->get(7);

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame(7, $user->user_id);
        $this->assertSame('ada', $user->username);
    }

    #[Test]
    public function the_request_is_recorded_for_assertion(): void
    {
        Http::fake([
            'forum.example.com/*' => Http::response(['user' => ['user_id' => 7, 'username' => 'ada']]),
        ]);

        XenForo::users()->get(7);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://forum.example.com/api/users/7/'
            && $request->hasHeader('XF-Api-Key', 'key-under-test')
            && $request->hasHeader('Accept', 'application/json'));
    }

    #[Test]
    public function a_form_encoded_body_is_readable_by_the_assertion(): void
    {
        // Not incidental. Illuminate\Http\Client\Request::isForm() matches the Content-Type
        // header EXACTLY against "application/x-www-form-urlencoded", and isForm() is what
        // gates data(), which backs $request['field']. The core package writes that header
        // without a charset parameter for an unrelated reason - XenForo compares it with
        // === when parsing a PUT, PATCH or DELETE body - and the two rules coincide. An
        // HTTP library that appended "; charset=utf-8" would leave every body assertion in
        // every consumer's suite comparing against an empty array.
        Http::fake([
            'forum.example.com/*' => Http::response(['user' => ['user_id' => 8, 'username' => 'grace']]),
        ]);

        XenForo::forum()->connection()->post('users/', [
            'username' => 'grace',
            'email' => 'grace@example.com',
        ]);

        Http::assertSent(fn (Request $request): bool => $request->isForm()
            && $request['username'] === 'grace'
            && $request['email'] === 'grace@example.com');
    }

    #[Test]
    public function faking_after_the_client_was_resolved_still_intercepts(): void
    {
        // The ordering a cached transport gets wrong. Factory::fake() REPLACES the
        // factory's stub collection, and createPendingRequest() copies whatever is there
        // when it is called - so a Guzzle client built at resolution time holds a snapshot
        // taken before these stubs existed, and the request would go to the real forum.
        // PendingRequestClient rebuilds per send, so it does not.
        $client = XenForo::forum();

        Http::preventStrayRequests();
        Http::fake([
            'forum.example.com/*' => Http::response(['user' => ['user_id' => 9, 'username' => 'linus']]),
        ]);

        $this->assertSame('linus', $client->users()->get(9)->username);
    }

    #[Test]
    public function a_stray_request_is_reported_as_laravel_reports_it(): void
    {
        // Not disguised as the package's RequestException. Connection::dispatch() catches
        // ClientExceptionInterface, and StrayRequestException is a plain RuntimeException,
        // so it arrives with Laravel's own message and the URL still in it.
        Http::preventStrayRequests();
        Http::fake(['forum.example.com/*' => Http::response(['user' => ['user_id' => 1]])]);

        $this->expectException(StrayRequestException::class);
        $this->expectExceptionMessage('https://other.example.com/api/v1/users/1/');

        XenForo::forum('second')->users()->get(1);
    }

    #[Test]
    public function an_error_status_still_maps_to_the_packages_exception_hierarchy(): void
    {
        // The layer supplies the transport and nothing else. A 404 has to arrive as
        // NotFoundException, not as an unsuccessful Response - telling a missing record
        // from an unusable key is the whole reason the core package exists.
        Http::fake([
            'forum.example.com/*' => Http::response(
                ['errors' => [['code' => 'requested_page_not_found', 'message' => 'The requested page could not be found.']]],
                404
            ),
        ]);

        $this->expectException(NotFoundException::class);

        XenForo::users()->get(404);
    }

    #[Test]
    public function a_derived_client_sends_through_the_same_faked_transport(): void
    {
        // actingAs() builds a new Client over the connection's existing transport. If it
        // did not, a super-user application's tests would fake the first call and miss
        // every one made on behalf of a user.
        Http::fake([
            'other.example.com/*' => Http::response(['user' => ['user_id' => 3, 'username' => 'edsger']]),
        ]);

        $user = XenForo::forum('second')->actingAs(3)->users()->get(3);

        $this->assertSame('edsger', $user->username);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('XF-Api-User', '3'));
    }

    #[Test]
    public function a_built_client_sends_through_the_faked_transport(): void
    {
        // What build() gives a config-less application over constructing a Client itself:
        // the package's transport, so the same fakes, recorder and stray-request guard reach
        // a forum that was never in config.
        Http::preventStrayRequests();
        Http::fake([
            'elsewhere.example.com/*' => Http::response(['user' => ['user_id' => 5, 'username' => 'barbara']]),
        ]);

        $client = XenForo::build(['url' => 'https://elsewhere.example.com', 'key' => 'built-key']);

        $this->assertSame('barbara', $client->users()->get(5)->username);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('XF-Api-Key', 'built-key'));
    }
}
