# hampel/xenforo-api-laravel

Laravel integration for `hampel/xenforo-api`. A service provider, a manager for named
forums, and a facade — and one adapter that is the reason the package exists.

## Commands

```bash
composer check          # lint, analyse, test - CI's commands, against your composer.lock
composer test           # phpunit
composer analyse        # phpstan, level 10 with larastan, PHP 8.3-8.5 in one pass
composer format         # pint
```

**A green `composer check` is not a green CI run.** CI's newest-corner jobs run `composer update`,
so they resolve whatever PHPStan, Larastan and Pint are current that day, and the lockfile is not
committed. A tool release can fail CI with nothing changed here. Before tagging, run
`composer update` first, so the check runs against what CI will resolve. On 2026-09-14 PHPStan
2.2.14 and Larastan 3.12.1 appeared between the local check and the push, and the 1.2.0 release
went red on both Laravel 13 jobs while `composer check` was clean.

## Layout

| path | what it is |
|---|---|
| `src/Http/PendingRequestClient.php` | the PSR-18 adapter over Laravel's HTTP client |
| `src/XenForoManager.php` | one client per configured forum, memoised; `build()` for one that is not |
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

Five decisions in `PendingRequestClient` are load-bearing and each has a way of looking
like clutter to be tidied away:

- **The pending request is rebuilt on every send.** `Factory::fake()` *replaces* the
  factory's stub collection, and `createPendingRequest()` copies whatever is there when it
  is called — so a client built once and kept holds a snapshot, and a fake registered after
  it was built never applies. `HttpFakeTest::faking_after_the_client_was_resolved_still_intercepts`
  is the test.
- **The factory is resolved on every send, too.** The constructor takes a resolver, not a
  `Factory`, because `Http::swap()` binds a *new* factory — the usual way for a suite to start
  from clean fakes, since `fake()` merges. A client holding the factory it was built with sends
  past the new fakes and past the new `preventStrayRequests()`: a real request carrying the
  configured key. A `Factory` passed directly is still accepted, for a client built by hand, and
  held as given. The swap tests in `HttpFakeTest` point at `forum.invalid`, so a regression fails
  to resolve rather than reaching a forum.
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
- **Transport options are passed by hand, through an allowlist.** `PendingRequest` merges its
  options — the configured timeouts and everything from `Http::globalOptions()` — only inside
  `sendRequest()`, which the adapter does not call, so `transportOptions()` hands the transport
  half to `send()` itself. **Never wholesale:** a global `headers`, `query` or `form_params`
  entry would overwrite the core package's API key, query string or body. Each key is copied
  only when its value has the type Guzzle declares, and **one key at a time rather than in a
  loop** — Guzzle 8 declares `send()`'s options as an array shape, and PHPStan 2.1.22, which the
  `--prefer-lowest` corner resolves, widens a loop-built array and rejects the return.

  `TransportTest` has a test per direction: configured timeouts arrive, a global CA bundle and
  proxy arrive, and a global header, query and body do not. Probed both ways — passing nothing
  fails the first two, passing everything fails the third.

  **Both defects were silent from the first release until 2026-09-14,** found in a sibling
  wrapper whose adapter was the same file. Measured here before fixing: a configured `timeout`
  reached Guzzle as `null` and a global CA bundle as Guzzle's default `true`, and a client
  resolved before `Http::swap()` sent a real request that failed only because its host was
  `.invalid`. The README had said `Http::globalOptions()` applied.

## Facts worth not rediscovering

- **The transport is bound under `xenforo.http_client`, never under
  `Psr\Http\Client\ClientInterface`, and the manager never falls back to the shared key.** The
  shared key is claimed by every package that binds it. When each Laravel API wrapper bound its
  adapter there, `singleton()` on the bound key meant the last provider registered supplied its
  adapter and timeouts to every manager, and took the others' bindings over. A fallback would bring
  that back: an older sibling or an unrelated library can still bind the shared key, and picking
  it up silently would also put the traffic outside `Http::fake()`.

  **It is a convention shared by the four Laravel API wrappers**, so an application meets one
  override style: each binds `<config key>.http_client`, **with `singletonIf()`, not
  `singleton()`.** A full Laravel application registers discovered package providers before its
  own (`Application::registerConfiguredProviders()` splices them in ahead), so an override there
  always comes later. Laravel Zero runs no discovery and registers in `config/app.php` order,
  where an `AppServiceProvider` commonly sits above the package, and `singleton()` would silently
  replace its override. `SharedBindingTest::an_application_override_of_the_packages_key_is_kept`
  fails in the before-order with `singleton()`. The PSR-17 `bindIf()` calls and the
  `singletonIf()` for Laravel's HTTP factory stay as they are — stateless or deliberately shared.

  Found on 2026-09-14 in an application with three wrappers installed, where every manager sent
  through the last-registered wrapper's adapter. Two of them had been configured with a 2s
  timeout and were still waiting when killed at 15s. No package's own suite could see it, since
  each installs one package. `SharedBindingTest` registers a stub provider binding the shared key
  before and after this one. The manager must keep its own adapter and timeout, and the stub's
  binding must survive. Both orders failed against the old provider.

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
- **Of Laravel's three HTTP client events, only `RequestSending` fires.** `ResponseReceived`
  and `ConnectionFailed` are dispatched from `PendingRequest::send()`, a layer above the
  handler stack, so Telescope's HTTP client watcher will not show this traffic; the core
  package's PSR-3 logging is what does. `RequestSending` comes from a callback
  `PendingRequest`'s constructor registers, which `buildBeforeSendingHandler()` runs *inside*
  the stack the adapter drives.

  This documentation said neither fires until 2026-09-13, when a sibling wrapper's test
  counted a `RequestSending` it did not expect. The Telescope conclusion was right and the
  mechanism given for it was half wrong — plausible enough to be copied into the next
  wrapper. `TransportTest::request_sending_fires_and_response_received_does_not` and
  `a_connection_failure_raises_request_sending_and_nothing_to_match_it` now measure all three,
  and pass on Laravel 12.61.1 and 13.31.0.
- **`failOnDeprecation` is inert in a Testbench package without help, and the provider needs
  three probes, not two.** Laravel's `HandleExceptions` replaces PHPUnit's error handler when
  the application boots. `withoutDeprecationHandling()` in `setUp()` restores it for
  test-executed paths — but Testbench boots the application inside `parent::setUp()`, before
  that line runs, so the provider's `register()` **and** `boot()` have both already run under
  the swallowing handler. Each is covered separately, against an application built in the test
  body: `registering_the_provider_binds_the_manager_and_merges_the_config` for `register()`,
  `booting_the_provider_registers_the_config_to_publish` for `boot()`.

  **Covering one does not cover the other.** `register()` holds the bindings, but `boot()`
  still calls framework API — `runningInConsole()`, `publishes()`, `configPath()` — on a
  package that claims two Laravel majors. On 2026-09-13 a deprecation planted in `boot()`
  exited 0 and printed `OK (56 tests)`, after an audit had recorded the flag as verified: it
  probed `register()` and a test path, which is what the checklist then asked for. Probed
  since, all three exit 2.

  The `boot()` test touches static state. `ServiceProvider::$publishes` and `$publishGroups`
  are static and Testbench has already filled them, so the test snapshots both and restores
  them in a `finally` — without that, it overwrites the destination another test asserts on
  and the pair fails or passes by execution order. It also empties them before booting: the
  entry Testbench registered names the same source path, so against the filled statics the
  assertion cannot tell whose registration it sees.
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

- **Laravel Zero ignores package discovery, so the fix above only runs if the consumer
  registers the provider by hand.** `LaravelZero\Framework\Application::registerBaseBindings()`
  empties the `PackageManifest` — "Ignores auto-discovery" — before
  `registerConfiguredProviders()` reads providers from it. So `extra.laravel` never reaches a
  Zero application: neither the provider nor the `XenForo` alias. The README tells Zero
  consumers to list the provider in `config/app.php`.

  **Reading only `registerConfiguredProviders()` gives the opposite answer**, which is how this
  package's documentation got it wrong: that method visibly splices the manifest's providers in,
  and the line that emptied the manifest is in a different method. The README claimed discovery
  worked in Zero from release until 2026-09-13, when the first Laravel Zero consumer measured
  `ClientInterface` unbound with the package installed and not listed.

  **The suite cannot test this, and the reason is resolution, not effort.**
  `LaravelZeroTest` builds an `Illuminate\Foundation\Application` and calls `register()`
  directly, which models the missing `FoundationServiceProvider` and skips provider loading
  entirely. Going through Zero's own loading would need `laravel-zero/framework` in
  `require-dev`, which requires `illuminate/*` `^13.24` — unresolvable on the Laravel 12 CI
  corner — and ships a fork of `Illuminate\Foundation` that would collide with the one
  Testbench installs.

- **`composer-require-checker` carries the undeclared-dependency check here, not the
  dev-free PHPStan job.** `laravel/framework` `replace`s every `illuminate/*` component, so
  the framework supplies every `Illuminate` symbol whether its component was declared or
  not. The five whitelisted symbols are that same `replace` — there is no
  `vendor/illuminate/` for the checker to attribute them to. A *new* `Illuminate` symbol
  appearing there is a prompt to check `require`, not to extend the list.

  **It is not in `composer check`, so it does not run with the rest.** The tool lives
  outside the package by design, which means nothing local re-runs it when an import
  changes and only CI notices. Adding `Illuminate\Contracts\Events\Dispatcher` to the
  provider is what caught this out: the checker had been run clean before that import
  existed and was not run again, so the first red build was the release push. **Run it by
  hand after adding or changing any `use` in `src/`** — CI gets the binary from `setup-php`'s
  `tools:` input, which has no equivalent on a workstation, so the local recipe installs it
  into a scratch directory instead:

  ```bash
  mkdir -p /tmp/crc && composer -d /tmp/crc require maglnet/composer-require-checker
  /tmp/crc/vendor/bin/composer-require-checker check \
      --config-file=.github/composer-require-checker.json composer.json
  ```

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
