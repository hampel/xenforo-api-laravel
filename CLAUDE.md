# hampel/xenforo-api-laravel

Laravel integration for `hampel/xenforo-api`. A service provider, a manager for named
forums, and a facade — and one adapter that is the reason the package exists.

## Commands

```bash
composer check          # lint, analyse, test - what CI runs
composer test           # phpunit
composer analyse        # phpstan, level 10 with larastan, PHP 8.3-8.5 in one pass
composer format         # pint
```

## Layout

| path | what it is |
|---|---|
| `src/Http/PendingRequestClient.php` | the PSR-18 adapter over Laravel's HTTP client |
| `src/XenForoManager.php` | one client per configured forum, memoised |
| `src/XenForoServiceProvider.php` | the bindings, the merged config, the publish tag |
| `src/Facades/XenForo.php` | the facade, and the `@method` block that types it |
| `src/Exception/` | configuration failures, in the core package's hierarchy |
| `config/xenforo.php` | the published config |

## What the package is for

The core package holds its own PSR-18 client, so nothing it sends is visible to
`Http::fake()`. `PendingRequestClient` replaces that client with one that sends through
Laravel's handler stack, so the fakes, `Http::assertSent()` and
`Http::preventStrayRequests()` all reach it — while the core package's request building,
status mapping and exception hierarchy stay untouched.

`tests/HttpFakeTest.php` is that claim, asserted. If it does not pass, the package has no
reason to exist.

## The adapter, and why it looks the way it does

Three decisions in `PendingRequestClient` are load-bearing and each has a way of looking
like clutter to be tidied away:

- **The pending request is rebuilt on every send.** `Factory::fake()` *replaces* the
  factory's stub collection, and `createPendingRequest()` copies whatever is there when it
  is called — so a client built once and kept holds a snapshot, and a fake registered after
  it was built never applies. `HttpFakeTest::faking_after_the_client_was_resolved_still_intercepts`
  is the test.
- **One Guzzle handler is shared across those rebuilds.** The handler owns curl's
  connection pool, so keep-alive survives even though the stack around it is new each time.
  Without it, an API client paging through results pays a fresh TLS handshake per page.
- **`send()` rather than `sendRequest()`, with four options set by hand.**
  `laravel_data` and `on_stats` are `PendingRequest::sendRequest()`'s contract with the
  handler stack it builds; anything driving that stack without going through that method
  has to supply them. Laravel 13 reads both defensively, **Laravel 12 does not** — without
  them every request raises `Undefined array key`, which an application with debug error
  handling turns into an `ErrorException`. The other three options reproduce Guzzle's own
  `sendRequest()`, and `http_errors` must stay off or a 404 arrives as a Guzzle exception
  and gets reported as a transport failure instead of mapping to `NotFoundException`.

  Only the Laravel 12 CI job catches a regression here. On 13 the suite passes without any
  of it.

## Facts worth not rediscovering

- **The core package's `Content-Type` exactness is what makes request bodies assertable.**
  `Illuminate\Http\Client\Request::isForm()` matches
  `application/x-www-form-urlencoded` *exactly*, and gates `data()`, which backs
  `$request['field']`. The core package writes that header without a charset parameter for
  an unrelated reason — XenForo compares it with `===` when parsing a PUT, PATCH or DELETE
  body. An HTTP library that appended `; charset=utf-8` would leave every body assertion
  comparing against an empty array.
- **Redirects are never followed, and there is no setting for it.** Guzzle's PSR-18 entry
  point hard-codes `allow_redirects => false`. That is what the two attachment thumbnail
  endpoints need, since the 301 they answer with *is* their output. An earlier
  `follow_redirects` config key was removed once this was measured: it could never have
  done anything.
- **Laravel's `RequestSending` / `ResponseReceived` events do not fire.** They are raised
  in `PendingRequest::send()`, a layer above the handler stack. Telescope's HTTP client
  watcher will not show this traffic; the core package's PSR-3 logging is what does.
- **`failOnDeprecation` is inert in a Testbench package without help.** Laravel's
  `HandleExceptions` replaces PHPUnit's error handler when the application boots.
  `withoutDeprecationHandling()` in `setUp()` fixes it for test-executed paths — but the
  provider's own `register()` has already run inside `parent::setUp()`, so
  `ConfigurationTest::registering_the_provider_binds_the_manager_and_merges_the_config`
  registers it again against an application built in the test body. Both paths were probed
  with `trigger_error(..., E_USER_DEPRECATED)`; both exit non-zero.
- **Laravel Zero does not bind the HTTP client factory, and Testbench cannot see that.**
  Laravel binds `Illuminate\Http\Client\Factory` as a singleton in
  `FoundationServiceProvider`; Laravel Zero's provider set is Build, Cache, Collision,
  CommandRecorder, Composer, Filesystem, GitVersion and NullLogger, and `app:install http`
  only runs `composer require illuminate/http`. Unbound, every `make()` builds a fresh
  factory, so the package holds a different one from the facade and `Http::fake()` does not
  intercept — the request reaches the real forum. `singletonIf` in the provider closes it.

  `Http::fake()` hides the ordering, which is what makes it dangerous: `fake()` calls
  `Facade::swap()`, which binds its instance into the container, so faking *before* the
  client is resolved happens to work and faking after does not. `tests/LaravelZeroTest.php`
  builds the container by hand because Testbench always boots a full application and can
  never reach this.

- **`composer-require-checker` carries the undeclared-dependency check here, not the
  dev-free PHPStan job.** `laravel/framework` `replace`s every `illuminate/*` component, so
  the framework supplies every `Illuminate` symbol whether its component was declared or
  not. The four whitelisted symbols are that same `replace` — there is no
  `vendor/illuminate/` for the checker to attribute them to. A *new* `Illuminate` symbol
  appearing there is a prompt to check `require`, not to extend the list.

## The facade's annotations are the only types it has

`XenForo::users()` goes through the manager's `__call()` and returns `mixed`; the
`@method static` block is what makes `XenForo::users()->get(1)` analysable. So an accessor
added to the core package's `Client` in a later release is a call that works at runtime and
silently loses its type. `tests/FacadeConformanceTest.php` compares the two lists in both
directions and checks every annotated return type resolves.

The manager needs the same coverage and gets it from one `@mixin Client` line, which cannot
drift. The facade cannot use `@mixin` because `__callStatic()` needs `@method static`.

## No harness

Everything worth exercising here is container and configuration wiring, which Testbench
sees. The core package's harness drives the real API calls.
