<?php

declare(strict_types=1);

namespace Hampel\XenForo\Api\Laravel\Facades;

use Hampel\XenForo\Api\Authentication\Authentication;
use Hampel\XenForo\Api\Client;
use Hampel\XenForo\Api\Config;
use Hampel\XenForo\Api\Connection;
use Hampel\XenForo\Api\Endpoint\Alerts;
use Hampel\XenForo\Api\Endpoint\Attachments;
use Hampel\XenForo\Api\Endpoint\Auth;
use Hampel\XenForo\Api\Endpoint\ConversationMessages;
use Hampel\XenForo\Api\Endpoint\Conversations;
use Hampel\XenForo\Api\Endpoint\Endpoint;
use Hampel\XenForo\Api\Endpoint\Featured;
use Hampel\XenForo\Api\Endpoint\Forums;
use Hampel\XenForo\Api\Endpoint\Index;
use Hampel\XenForo\Api\Endpoint\Me;
use Hampel\XenForo\Api\Endpoint\Media;
use Hampel\XenForo\Api\Endpoint\MediaAlbums;
use Hampel\XenForo\Api\Endpoint\MediaCategories;
use Hampel\XenForo\Api\Endpoint\MediaComments;
use Hampel\XenForo\Api\Endpoint\Nodes;
use Hampel\XenForo\Api\Endpoint\OAuth2;
use Hampel\XenForo\Api\Endpoint\OEmbed;
use Hampel\XenForo\Api\Endpoint\Posts;
use Hampel\XenForo\Api\Endpoint\ProfilePostComments;
use Hampel\XenForo\Api\Endpoint\ProfilePosts;
use Hampel\XenForo\Api\Endpoint\ResourceCategories;
use Hampel\XenForo\Api\Endpoint\ResourceReviews;
use Hampel\XenForo\Api\Endpoint\ResourceUpdates;
use Hampel\XenForo\Api\Endpoint\ResourceVersions;
use Hampel\XenForo\Api\Endpoint\Resources;
use Hampel\XenForo\Api\Endpoint\Search;
use Hampel\XenForo\Api\Endpoint\SearchForums;
use Hampel\XenForo\Api\Endpoint\Stats;
use Hampel\XenForo\Api\Endpoint\Threads;
use Hampel\XenForo\Api\Endpoint\Users;
use Illuminate\Support\Facades\Facade;

/**
 * Facade for the XenForo API manager.
 *
 *     XenForo::users()->get(1);                     // the default forum
 *     XenForo::forum('support')->users()->get(1);   // a named one
 *
 * The operations are one hop further in, so the annotations below cover the hop and the
 * endpoint classes carry the typed signatures from there. Without them every call through
 * the facade is untyped to both the IDE and PHPStan, which is most of what a facade costs
 * you.
 *
 * Everything after forum() mirrors a method on the client, reached through the manager's
 * __call(). FacadeConformanceTest asserts that the two lists stay identical, so a method
 * added to the client in a later release of the core package shows up as a failing test
 * rather than as a call that silently loses its type.
 *
 * endpoint() is the one annotation that gives something up: on the client it is generic,
 * returning the class it was handed, and a @method line cannot express that. Reach for
 * `XenForo::forum()->endpoint(Foo::class)` where the generic return matters.
 *
 * @method static Client forum(?string $name = null)
 * @method static string getDefaultForum()
 * @method static list<string> configuredForums()
 * @method static Config config()
 * @method static Authentication authentication()
 * @method static Client actingAs(?int $userId)
 * @method static Client withCredential(Authentication $authentication)
 * @method static Endpoint endpoint(string $class)
 * @method static Index index()
 * @method static Auth auth()
 * @method static Me me()
 * @method static Users users()
 * @method static Threads threads()
 * @method static Posts posts()
 * @method static Forums forums()
 * @method static Nodes nodes()
 * @method static Conversations conversations()
 * @method static ConversationMessages conversationMessages()
 * @method static Alerts alerts()
 * @method static Attachments attachments()
 * @method static OAuth2 oauth2()
 * @method static ProfilePosts profilePosts()
 * @method static ProfilePostComments profilePostComments()
 * @method static Search search()
 * @method static SearchForums searchForums()
 * @method static Featured featured()
 * @method static Stats stats()
 * @method static OEmbed oembed()
 * @method static Media media()
 * @method static MediaAlbums mediaAlbums()
 * @method static MediaCategories mediaCategories()
 * @method static MediaComments mediaComments()
 * @method static Resources resources()
 * @method static ResourceCategories resourceCategories()
 * @method static ResourceReviews resourceReviews()
 * @method static ResourceUpdates resourceUpdates()
 * @method static ResourceVersions resourceVersions()
 * @method static Connection connection()
 *
 * @see \Hampel\XenForo\Api\Laravel\XenForoManager
 * @see \Hampel\XenForo\Api\Client
 */
final class XenForo extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Hampel\XenForo\Api\Laravel\XenForoManager::class;
    }
}
