<?php

declare(strict_types=1);

namespace Hampel\XenForo\Api\Laravel\Tests;

use Hampel\XenForo\Api\Authentication\ApiKey;
use Hampel\XenForo\Api\Authentication\SuperUserKey;
use Hampel\XenForo\Api\Client;
use Hampel\XenForo\Api\Config;
use Hampel\XenForo\Api\Exception\MalformedResponseException;
use Hampel\XenForo\Api\Generated\Schema\Node;
use Hampel\XenForo\Api\Generated\Schema\Thread;
use Hampel\XenForo\Api\Generated\Schema\User;
use Hampel\XenForo\Api\Laravel\Facades\XenForo;
use Hampel\XenForo\Api\Laravel\XenForoManager;
use Illuminate\Http\Client\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The README's examples, run.
 *
 * A pasted snippet gets hand-edited when the code around it changes and quietly stops
 * matching what the package does. These are the ones with a signature in them, so a
 * renamed accessor or a changed return type fails here rather than being discovered by
 * somebody following the documentation.
 */
final class ReadmeExamplesTest extends TestCase
{
    #[Test]
    public function the_usage_examples_return_what_the_readme_says_they_do(): void
    {
        Http::fake([
            'forum.example.com/api/users/*' => Http::response(['user' => ['user_id' => 1, 'username' => 'ada']]),
            'forum.example.com/api/threads/*' => Http::response(['thread' => ['thread_id' => 1234, 'title' => 'Hello']]),
            'forum.example.com/api/forums/*' => Http::response([
                'threads' => [['thread_id' => 1, 'title' => 'One']],
                'pagination' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 1],
            ]),
        ]);

        $user = XenForo::users()->get(1);
        $thread = XenForo::threads()->get(1234);
        $threads = XenForo::forums()->threads(2);

        $this->assertInstanceOf(User::class, $user);
        $this->assertInstanceOf(Thread::class, $thread);

        // forums()->threads() answers with a page of threads and the sticky ones beside
        // it, rather than one flat list - the shape the README's variable name has to
        // survive, and the reason it is not called $page.
        $this->assertSame(['threads', 'sticky'], array_keys($threads));
        $this->assertCount(1, $threads['threads']);
        $this->assertSame(1, $threads['threads']->total);
        $this->assertSame([], $threads['sticky']);
    }

    #[Test]
    public function a_named_forum_is_reached_the_way_the_readme_says(): void
    {
        Http::fake(['other.example.com/*' => Http::response(['user' => ['user_id' => 1, 'username' => 'ada']])]);

        $this->assertSame('ada', XenForo::forum('second')->users()->get(1)->username);
    }

    #[Test]
    public function the_manager_can_be_injected_instead_of_using_the_facade(): void
    {
        Http::fake(['other.example.com/*' => Http::response(['user' => ['user_id' => 1, 'username' => 'ada']])]);

        $forums = $this->container()->make(XenForoManager::class);

        $this->assertSame('ada', $forums->forum('second')->users()->get(1)->username);
    }

    #[Test]
    public function the_bypass_permissions_example_compiles_and_applies_the_parameter(): void
    {
        Http::fake(['other.example.com/*' => Http::response(['user' => ['user_id' => 1, 'username' => 'ada']])]);

        $client = XenForo::forum('second');
        $credential = $client->authentication();

        $this->assertInstanceOf(SuperUserKey::class, $credential);

        $client = $client->withCredential($credential->withBypassPermissions());

        $client->users()->get(1);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'api_bypass_permissions=1'));
    }

    #[Test]
    public function an_entity_handed_to_a_json_response_serialises_to_the_forums_payload(): void
    {
        // The README's `return response()->json(XenForo::users()->get(1));`. This asserts
        // Laravel's JSON response path honours the entity's \JsonSerializable, not that the
        // core package serialises correctly - that is covered in the core package's own
        // suite and duplicating it would be this package testing that one.
        Http::fake([
            'forum.example.com/*' => Http::response(['user' => [
                'user_id' => 7,
                'username' => 'ada',
                'custom_addon_field' => 'value the specification has never heard of',
            ]]),
        ]);

        $response = new JsonResponse(XenForo::users()->get(7));
        $decoded = json_decode((string) $response->getContent(), true);

        $this->assertSame([
            'user_id' => 7,
            'username' => 'ada',
            'custom_addon_field' => 'value the specification has never heard of',
        ], $decoded);

        // Absent rather than null: the credential may not see it, which is not the same
        // thing as the forum answering null, and the README says so. Named explicitly
        // because that is the property, even though the assertion above covers it.
        $this->assertArrayNotHasKey('email', $decoded);
    }

    #[Test]
    public function faking_with_no_arguments_gives_an_empty_200_which_is_a_malformed_response(): void
    {
        // The README warns about this because the failure reads as success. A 2xx that does
        // not decode is somebody else's answer - a maintenance page, a WAF challenge, a
        // truncated body - and returning it as an empty result would reach every caller as
        // "no such record".
        Http::fake();

        $this->expectException(MalformedResponseException::class);

        XenForo::users()->get(1);
    }

    #[Test]
    public function a_node_comes_back_from_the_forums_endpoint(): void
    {
        Http::fake(['forum.example.com/*' => Http::response(['forum' => ['node_id' => 2, 'title' => 'General']])]);

        $this->assertInstanceOf(Node::class, XenForo::forums()->get(2));
    }

    #[Test]
    public function a_forum_not_in_configuration_is_built_the_way_the_readme_says(): void
    {
        $forum = (object) ['url' => 'https://elsewhere.example.com', 'api_key' => 'built-key'];

        Http::fake([
            'elsewhere.example.com/*' => Http::response(['user' => ['user_id' => 1, 'username' => 'ada']]),
        ]);

        $client = XenForo::build([
            'url' => $forum->url,
            'key' => $forum->api_key,
        ]);

        $user = $client->users()->get(1);

        $this->assertSame('ada', $user->username);
    }

    #[Test]
    public function a_client_constructed_on_the_bound_transport_is_faked_the_way_the_readme_says(): void
    {
        // The README's direct construction. Resolving the bound interfaces is what makes a
        // hand-built client share the package's transport rather than bypass it.
        Http::preventStrayRequests();
        Http::fake([
            'elsewhere.example.com/*' => Http::response(['user' => ['user_id' => 1, 'username' => 'ada']]),
        ]);

        $config = new Config('https://elsewhere.example.com');
        $credential = new ApiKey('direct-key');

        $client = new Client(
            $config,
            $credential,
            app(ClientInterface::class),
            app(RequestFactoryInterface::class),
            app(StreamFactoryInterface::class),
        );

        $this->assertSame('ada', $client->users()->get(1)->username);
    }
}
