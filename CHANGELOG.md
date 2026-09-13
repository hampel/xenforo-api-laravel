CHANGELOG
=========

Unreleased
----------

* A client resolved before `Http::swap()` sends through the swapped-in factory, so its fakes and
  `preventStrayRequests()` apply
* `xenforo.timeout`, `xenforo.connect_timeout` and the transport options in `Http::globalOptions()`
  reach the request: timeouts, TLS verification and client certificates, proxy, protocol
  version, `force_ip_resolve`, `decode_content` and `curl`. Global `headers`, `query` and body
  options are not applied
* `PendingRequestClient`'s constructor accepts a `Closure` returning the `Factory`, as well as a
  `Factory`

1.1.0 (2026-09-14)
------------------

* `XenForoManager::build()` makes a client from an array of settings, for a forum that is not
  in `config/xenforo.php`. It takes the same keys as an entry under `xenforo.forums`, applies
  the same validation and credential selection, sends through the same transport, and is not
  memoised
* `InvalidConfiguration::missingUrl()` and `actingUserWithoutKey()` accept `null` for settings
  passed to `build()`
* README: Laravel Zero does not discover packages, so the provider must be listed in
  `config/app.php` and the facade imported by class name
* README and `PendingRequestClient` docblock: `RequestSending` fires for requests the API
  client makes; `ResponseReceived` and `ConnectionFailed` do not

1.0.0 (2026-09-09)
------------------

* Initial release
* `XenForoServiceProvider` binds `XenForoManager`, merges `config/xenforo.php` and
  publishes it under the `xenforo-config` tag
* `XenForoManager` builds one `Hampel\XenForo\Api\Client` per configured forum, memoised by
  name; a call naming no forum is forwarded to the default
* `XenForo` facade, with a `@method` annotation for each accessor on `Client`
* `Http::fake()`, `Http::assertSent()` and `Http::preventStrayRequests()` apply to requests
  the API client makes. Request building, status mapping and the exception hierarchy are
  the core package's throughout, and its exceptions reach the caller unchanged
* `Illuminate\Http\Client\Factory` is bound as a singleton when the application has not
  bound one, as a Laravel Zero application does not
* `Psr\Http\Client\ClientInterface` is bound separately: rebind it to route the package's
  requests through an application's own HTTP client
* Credentials are read from configuration as `ApiKey`, `SuperUserKey`, `BearerToken` or
  `Guest`
* `UnknownForum` and `InvalidConfiguration` extend the core package's `XenForoException`.
  A forum with no `url`, and an acting user with no key beside it, are refused when the
  client is built
* Requires `hampel/xenforo-api` `^1.1`, PHP 8.3, and Laravel 12 or 13
