# XenForo API for Laravel

[![Tests](https://github.com/hampel/xenforo-api-laravel/actions/workflows/tests.yml/badge.svg)](https://github.com/hampel/xenforo-api-laravel/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/hampel/xenforo-api-laravel.svg?style=flat-square)](https://packagist.org/packages/hampel/xenforo-api-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/hampel/xenforo-api-laravel.svg?style=flat-square)](https://packagist.org/packages/hampel/xenforo-api-laravel)
[![Open Issues](https://img.shields.io/github/issues-raw/hampel/xenforo-api-laravel.svg?style=flat-square)](https://github.com/hampel/xenforo-api-laravel/issues)
[![License](https://img.shields.io/packagist/l/hampel/xenforo-api-laravel.svg?style=flat-square)](https://packagist.org/packages/hampel/xenforo-api-laravel)

By [Simon Hampel](mailto:simon@hampelgroup.com)

Laravel integration for [`hampel/xenforo-api`][core] — a service provider, a manager for
named forums, and a facade.

Two things it adds that an application would otherwise write for itself:

- **`Http::fake()` sees the API client's traffic.** The core package carries its own
  PSR-18 client, so by default Laravel's HTTP fakes know nothing about it and an
  application has to fake at the transport library instead. Here every request goes
  through Laravel's own handler stack, so `Http::fake()`, `Http::assertSent()` and
  `Http::preventStrayRequests()` all work.
- **Named forums.** A URL and a credential per forum, a default, and
  `XenForo::forum('name')` to reach one — the shape Laravel's own database and mail
  managers take.

It supplies the transport and nothing else. The core package's request building, status
mapping and exception hierarchy are untouched, which is the point: a 401 and a 404 stay
different exceptions rather than both becoming an unsuccessful response.

## Requirements

PHP 8.3 or later, and Laravel 12 or 13.

Laravel Zero is supported too, with two extra steps covered under Installation. It needs the
HTTP component, which an application opts into with `php <app> app:install http` (that command
runs `composer require illuminate/http` and nothing else).

## Installation

```bash
composer require hampel/xenforo-api-laravel
```

In a Laravel application the provider and the `XenForo` alias are discovered automatically.
Publish the config file if you want to edit it:

```bash
php artisan vendor:publish --tag=xenforo-config
```

### Laravel Zero

**Laravel Zero switches package discovery off, so register the provider yourself** in
`config/app.php`:

```php
'providers' => [
    App\Providers\AppServiceProvider::class,
    Hampel\XenForo\Api\Laravel\XenForoServiceProvider::class,
],
```

The `XenForo` alias comes from the same discovery, so it is not registered either — import
`Hampel\XenForo\Api\Laravel\Facades\XenForo` by its class name. To edit the config, copy
`vendor/hampel/xenforo-api-laravel/config/xenforo.php` into the application's `config/`.

Registering the provider also closes a gap in `Http::fake()`. Laravel binds
`Illuminate\Http\Client\Factory` as a singleton in `FoundationServiceProvider`, which a
Laravel Zero application does not register, and the HTTP component installs the classes
without binding anything. Unbound, the container builds a fresh factory on every resolution,
so the one this package holds is not the one `Http::fake()` configures, and the fake would
miss: the request would go to the real forum. The provider binds a singleton when nothing else
has. Without the provider nothing is bound at all, and resolving a client fails with a
`BindingResolutionException` rather than reaching the network.

## Configuration

A forum needs a URL. Everything else is optional.

```dotenv
XENFORO_URL=https://forum.example.com
XENFORO_API_KEY=your-api-key
```

The shipped `config/xenforo.php` defines one forum called `main`. Add more by naming them:

```php
'default' => 'community',

'forums' => [
    'community' => [
        'url' => env('XENFORO_URL'),
        'key' => env('XENFORO_API_KEY'),
    ],

    'support' => [
        'url' => env('SUPPORT_FORUM_URL'),
        'key' => env('SUPPORT_FORUM_KEY'),
        'user' => env('SUPPORT_FORUM_USER'),   // a super-user key acting as this user_id
        'version' => 1,                        // pin to /api/v1/...
    ],
],
```

Either the board URL or the `/api` URL is accepted; both are normalised.

### Credentials

XenForo accepts three quite different credentials on the same endpoints, and which one you
hold changes what you may ask for:

| configuration | credential | what it does |
|---|---|---|
| `key` | `ApiKey` | a guest or user key. The key itself decides which user the request acts as. |
| `key` + `user` | `SuperUserKey` | a super-user key acting as that `user_id`. XenForo answers 403 `user_id_not_allowed` if the key is not a super-user key. |
| `bearer` | `BearerToken` | an OAuth2 access token. Takes precedence over `key`. |
| none | `Guest` | XenForo treats a missing key as a guest, so unauthenticated endpoints answer normally and everything else answers 400 `no_api_key_in_request`. |

Two combinations are refused when the client is built, because both otherwise fail
somewhere far less obvious:

- a forum with no `url` — which would reach the core package as an empty base URI;
- `user` with no `key` — which would be sent as a guest and *answered*, so the call
  succeeds and quietly returns less than it should.

Both raise `Hampel\XenForo\Api\Laravel\Exception\InvalidConfiguration`, which extends the
core package's `XenForoException`, so an application already catching that catches
misconfiguration too.

`bypassPermissions` is deliberately not configurable. It belongs to a super-user key and to
no other credential, and it is per-call by nature:

```php
use Hampel\XenForo\Api\Authentication\SuperUserKey;

$client = XenForo::forum();
$credential = $client->authentication();

if ($credential instanceof SuperUserKey) {
    $client = $client->withCredential($credential->withBypassPermissions());
}

$client->users()->get(1);
```

### Transport

```php
'timeout' => 10,
'connect_timeout' => 5,
```

Applied to every request, alongside any `Http::globalOptions()` and
`Http::globalRequestMiddleware()` the application has configured.

Redirects are not followed — Guzzle's PSR-18 entry point does not follow them, so a 3xx is
handed back whole. That is what the two attachment thumbnail endpoints need, since the 301
they answer with *is* their documented output.

## Usage

The facade reaches the default forum directly:

```php
use Hampel\XenForo\Api\Laravel\Facades\XenForo;

$user    = XenForo::users()->get(1);
$thread  = XenForo::threads()->get(1234);
$threads = XenForo::forums()->threads(2);
```

Name a forum to reach another:

```php
$user = XenForo::forum('support')->users()->get(1);
```

Everything past that point is the core package — see [its documentation][core] for the
endpoints, the entities, pagination, uploads, OAuth2 and how to call an endpoint an add-on
added that neither package has heard of.

Inject the manager where a facade is not wanted:

```php
use Hampel\XenForo\Api\Laravel\XenForoManager;

public function __construct(private readonly XenForoManager $forums) {}

$this->forums->forum('support')->users()->get(1);
```

### Forums that are not in configuration

When the forum list lives somewhere other than `config/xenforo.php` — a database table,
per-tenant settings, a file read on demand to keep API keys out of the config repository —
build a client from an array instead:

```php
$client = XenForo::build([
    'url' => $forum->url,
    'key' => $forum->api_key,
]);

$user = $client->users()->get(1);
```

The array takes the same keys as an entry under `xenforo.forums`, and gets the same validation
and choice of credential. The client sends through the same transport, so `Http::fake()` and
`Http::preventStrayRequests()` reach it. Each call builds a new client and nothing is memoised,
so keep the one you get.

The transport is bound as `Psr\Http\Client\ClientInterface`, with PSR-17 factories beside it,
for constructing a `Hampel\XenForo\Api\Client` directly:

```php
use Hampel\XenForo\Api\Client;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

$client = new Client(
    $config,
    $credential,
    app(ClientInterface::class),
    app(RequestFactoryInterface::class),
    app(StreamFactoryInterface::class),
);
```

### Returning an entity

Entities implement `\JsonSerializable` and serialise to the forum's own payload, so handing
one to a JSON response gives you what the API answered rather than the entity's internals:

```php
return response()->json(XenForo::users()->get(1));
```

Fields the credential is not allowed to see are *absent* from that payload rather than null,
which is the distinction XenForo itself draws — a permission failure and a genuinely empty
field are not the same thing, and `ApiResponse::has()` is how to tell them apart. Add-on
fields the specification does not describe survive too.

### Errors

The core package's exceptions arrive untouched. Telling them apart is the reason to use it
rather than `Http::` directly — a client that reports every unsuccessful status the same
way cannot tell a rejected credential from a user who is not a member:

```php
use Hampel\XenForo\Api\Exception\NotAuthenticatedException;
use Hampel\XenForo\Api\Exception\NotFoundException;

try {
    $user = XenForo::users()->get($id);
} catch (NotFoundException $e) {
    // no such user - or the acting user may not see them, which is the same 404
} catch (NotAuthenticatedException $e) {
    // the key is not usable. A configuration error, not an empty result.
}
```

## Testing

Fake the forum with the vocabulary the rest of your suite already uses:

```php
use Illuminate\Support\Facades\Http;

Http::preventStrayRequests();

Http::fake([
    'forum.example.com/*' => Http::response(['user' => ['user_id' => 1, 'username' => 'ada']]),
]);

$user = XenForo::users()->get(1);

Http::assertSent(fn ($request) => $request->hasHeader('XF-Api-Key'));
```

The package's real code path runs; only the socket is replaced. So a faked 404 still
arrives as `NotFoundException`, and a faked 200 whose body is not JSON still arrives as
`MalformedResponseException`.

Three things worth knowing:

- **`Http::fake()` with no arguments gives every request an empty 200**, which the core
  package treats as a malformed response rather than an empty result — a 2xx that does not
  decode is a maintenance page, a WAF challenge or a truncated body, and reading it as
  "no such record" is the failure the package exists to prevent. Always give a body.
- **Order does not matter.** Faking after the client has been resolved works, because the
  transport resolves Laravel's HTTP factory at the moment of sending rather than when it
  was built.
- **Request bodies are assertable** — `$request['username']` works, because the core
  package writes form-encoded bodies with an exact `Content-Type`.

Replace the transport entirely by binding `Psr\Http\Client\ClientInterface`, which is how
an application with its own outbound HTTP policy — a proxy-aware or SSRF-guarded client
that everything is required to go through — makes this package use it.

### What is not visible

Laravel raises `ResponseReceived` and `ConnectionFailed` from a layer above the handler stack,
and this package sends through the stack directly, so neither fires — anything listening for
them, including Telescope's HTTP client watcher, will not show this traffic. The core package
logs every request through PSR-3 instead, which reaches the application log.

`RequestSending` does fire, because Laravel raises it from inside the stack. A listener that
pairs it with either of the other two will see requests announced and never concluded.

`Http::assertSent()` cannot inspect uploaded files either: `Illuminate\Http\Client\Request::hasFile()`
reads data the framework records when it builds a multipart body itself. Assert on the
request's `Content-Type` and body instead.

## What this package will not do

Grow retries or backoff. `TooManyRequestsException` is typed so an application can retry;
which requests are safe to retry is the application's knowledge, not this package's.

## License

MIT. See [LICENSE.md](LICENSE.md).

[core]: https://github.com/hampel/xenforo-api
