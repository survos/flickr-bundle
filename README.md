# survos/flickr-bundle

Symfony integration for [samwilson/phpflickr](https://github.com/samwilson/phpflickr), with OAuth authentication, uploads, album import events and Twig image links.

## Compatibility and installation

Requires PHP **8.5**, Symfony **8.1**, cURL and SimpleXML. These are the existing package constraints; this review does not downgrade or broaden the supported stack. The bundle uses the Survos kit base and requires `survos/kit-bundle:^2.28`. Doctrine is optional and only needed for `FlickrUserTrait` persistence; SecurityBundle is optional for logged-in-user authentication.

```bash
composer require survos/flickr-bundle
```

Ensure `Survos\FlickrBundle\SurvosFlickrBundle::class => ['all' => true]` is present in `config/bundles.php` (Flex normally adds it). No bundle HTTP routes are provided; existing imports of `@SurvosFlickrBundle/config/routes.yaml` remain valid but empty.

```yaml
# config/packages/survos_flickr.yaml
survos_flickr:
  api_key: '%env(FLICKR_API_KEY)%'
  secret: '%env(FLICKR_SECRET)%'
  cache_expiration: 3600
  # Optional for public reads; REQUIRED for unattended account uploads:
  access_token: '%env(FLICKR_ACCESS_TOKEN)%'
  access_token_secret: '%env(FLICKR_ACCESS_TOKEN_SECRET)%'
```

Keep these values in Symfony secrets or `.env.local`, never in source control. The API key/secret identify the application; the access token/secret identify the authorized Flickr account. An API key alone cannot upload.

## OAuth

Use Flickr's [OAuth 1.0a flow](https://www.flickr.com/services/api/auth.oauth.html) with **write** permission. The inherited `getAuthUrl('write')` and `retrieveAccessToken($verifier)` support an out-of-band authorization flow when the same client and token storage are retained through both steps. For browser callbacks, implement the callback in the host application and retain request-token storage between requests; the bundle does not supply a login controller. Do not use legacy frob/auth-token authentication.

For a single account, save the resulting access token and token secret in the configuration above. Alternatively:

```php
$flickr->authenticate($accessToken, $accessTokenSecret);
```

For per-user authentication, implement `FlickrUserInterface` on the user (the optional Doctrine `FlickrUserTrait` supplies fields/accessors), and call `authenticate()` after the user is available. Explicit credentials take precedence. Token storage is in memory, so console uploads do not start a PHP session. Re-authenticate for each account/job in long-running workers. Token fields in the trait are ordinary database strings: the host application owns encryption/access control and must exclude them from serialization and logs.

## Uploading newspaper advertising

Use JPEG or PNG exports of the advertising image and retain archival masters and source identifiers separately. Choose content and safety settings appropriate to the actual image; the command defaults to Other (`3`), Safe (`1`), and private visibility.

```bash
bin/console survos:flickr:upload-file /path/to/advertisement.jpg \
  --title='Daily News — 1924-05-18 — page 5' \
  --description='Advertisement. Source: archive record URL; issue date 1924-05-18, page 5.' \
  --tags='"newspaper advertising" news:publication="Daily News" news:issue=1924-05-18 news:page=5 news:record=ad-0001' \
  --dry-run
```

Remove `--dry-run` to send the image. Add `--public` only when public visibility is intended. A dry run validates the local file and option ranges; it does not validate credentials, quotas, image decoding, or Flickr acceptance. Exit status is nonzero for rejected inputs, API failures or transport failures.

The old `survos:flickr:upload` command was an unfinished moderation placeholder. It now returns failure with a pointer to `upload-file`, rather than silently reporting zero processed images as success. Its old options remain accepted for a clear migration error.

Programmatic uploads preserve the existing positional argument order; `safetyLevel` is appended:

```php
$tags = $flickr->tagHashToString([
    'news:publication' => 'Daily News',
    'news:issue' => '1924-05-18',
    'news:page' => 5,
    'news:record' => 'ad-0001',
    '_' => ['newspaper advertising', 'retail'],
]);
$result = $flickr->uploader()->upload(
    $filename,
    title: $title,
    description: $sourceCitation,
    tags: $tags,
    isPublic: false,
    isFriend: false,
    isFamily: false,
    contentType: 3,
    hidden: 2,
    safetyLevel: 1,
);
if ($result['stat'] !== 'ok') {
    throw new \RuntimeException(sprintf('Flickr %s: %s', $result['code'], $result['message']));
}
$photoId = $result['photoid']; // String: store this with the source record and file hash.
```

The service retains Flickr's account defaults when optional privacy/content arguments are omitted. Tags containing whitespace are quoted; embedded double quotes are rejected explicitly rather than terminating the process. Machine tags use `namespace:predicate=value`; plain `key=value` is not a namespaced machine tag. `getTagsAsHash()` only handles Flickr's compressed search tag string; use `photos()->getInfo()` for full structured tags and raw values.

Upload accepts title, description and tags. Licensing, dates and album assignment are separate API operations: use `photosLicenses()->getInfo()` / `setLicense()`, `photos()->setDates()` and `photosets()->addPhoto()` after recording the returned photo ID. Verify their results independently; a metadata failure does not undo the upload. `getLicenseId()` maps unversioned CC names to Flickr's legacy CC 2.0 IDs (and CC0 to 9); use the live license list for version-specific choices. In particular CC-BY-SA is 5, not 1 (CC-BY-NC-SA). The helper does not determine what rights a newspaper advertisement has.

## Replacement, failures and batch operation

`uploader()->replace($filename, $photoId)` signs and posts to the separate [replace endpoint](https://www.flickr.com/services/api/replace.api.html). Flickr requires a Pro account for replacement. Async upload/replacement returns `ticketid`; poll `photosUpload()->checkTickets()` and persist the resulting photo ID before continuing metadata work.

Flickr API rejection XML continues to return `['stat' => 'fail', 'code' => ..., 'message' => ...]`. Malformed XML, missing result IDs, unreadable/empty files and missing authentication throw exceptions. Photo IDs now remain **strings** instead of being cast to integers; update callers that depend on an integer return type.

Network/HTTP failures throw `Survos\FlickrBundle\Exception\UploadException` (a `FlickrException`) with `httpStatus` and optional raw `retryAfter` (seconds or HTTP date). Connection and total timeouts default to 15 and 300 seconds; construct `new Uploader($flickr, connectTimeout: 15, timeout: 600)` for another positive bound. There are no automatic write retries: a timeout can occur after Flickr accepted the image. Reconcile against the account and your source-record/photo-ID ledger before retrying, to avoid duplicates.

Before a batch, check `people()->getUploadStatus()` for the account's limits and complete a private upload smoke test. Flickr's [developer guide](https://www.flickr.com/services/developer/api/) describes an aggregate budget of 3,600 API queries/hour per key, with additional abuse controls. Coordinate workers across the key, honor `Retry-After`, and use bounded backoff for retryable read requests. This bundle does not provide a distributed limiter, durable queue, automatic reconciliation, or retry policy for inherited REST calls. Keep API caches within Flickr's published 24-hour guidance and isolate caches by account if processing private data.

## Album imports and Twig

```bash
bin/console survos:flickr:import 'https://www.flickr.com/photos/OWNER/albums/ALBUM/' --per-page=100 --limit=10
```

A numeric album ID is also accepted. Imports dispatch `FlickrPhotoEvent::class`; subscribe to that class name. Use `--info-level=basic` to avoid a detailed API call per photo. `--cache-ttl=0` bypasses caching; `--clear-cache` now bypasses the cache for that run instead of clearing the application's shared cache pool. Import dry runs still fetch metadata but do not dispatch events.

```twig
<a href="{{ flickrPageUrl(photo) }}">
  <img src="{{ flickrThumbnailUrl(photo, 'm') }}" alt="{{ photo.title }}">
</a>
```

`flickrAlbumUrl(album)` accepts `username` and `id`. Image URLs use the current `live.staticflickr.com` scheme. Originals require `originalsecret` and `originalformat`, or a returned `url_o`; larger sizes require their API-provided URLs. The helper returns null when that information is absent. See [Flickr image URLs](https://www.flickr.com/services/api/misc.urls.html); old farm-style URLs are deprecated.

## Development

```bash
composer install
composer validate --strict
composer test
composer analyse
composer audit
composer check-platform-reqs
```

Tests run offline with fake credentials: they cover OAuth signing, token changes, upload/replacement endpoints, privacy parameters, API/XML errors, rate-limit responses, metadata helpers, pagination/cache behavior and Symfony command/container integration. They do not upload images to Flickr.
