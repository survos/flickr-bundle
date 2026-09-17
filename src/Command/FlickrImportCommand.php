<?php

declare(strict_types=1);

namespace Survos\FlickrBundle\Command;

use Survos\FlickrBundle\Event\FlickrPhotoEvent;
use Survos\FlickrBundle\Services\FlickrService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[AsCommand('survos:flickr:import', 'Import photos from a Flickr album with event dispatching')]
class FlickrImportCommand
{
    public function __construct(
        private FlickrService $flickrService,
        private EventDispatcherInterface $eventDispatcher,
        private ?CacheInterface $cache = null,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Flickr album ID or URL')]
        string $albumUrl = 'https://www.flickr.com/photos/202304062@N02/albums/72177720328661598/',
        #[Option('Photos per page for pagination')]
        int $perPage = 10,
        #[Option('Level of photo information to fetch: basic, detailed, full')]
        string $infoLevel = 'detailed',
        #[Option('Show what would be processed without dispatching events')]
        bool $dryRun = false,
        #[Option('Stop after processing this many photos (for testing)')]
        ?int $limit = null,
        #[Option('Cache TTL in seconds (0 to disable cache)')]
        int $cacheTtl = 3600,
        #[Option('Clear cache before processing')]
        bool $clearCache = false
    ): int
    {
        $albumId = $this->extractAlbumId($albumUrl);
        $userId = $this->extractUserIdFromUrl($albumUrl);

        if (!$albumId) {
            $io->error('Invalid album ID or URL provided');
            return Command::FAILURE;
        }

        if ($perPage < 1 || $perPage > 500 || ($limit !== null && $limit < 1)) {
            $io->error('Per page must be 1–500 and limit must be positive.');
            return Command::INVALID;
        }
        $userId ??= ''; // Flickr accepts an album ID without an owner filter.

        $io->title('Importing Flickr Album: ' . $albumId);
        $io->writeln("User ID: {$userId}");
        $io->writeln("Info Level: {$infoLevel}");
        $io->writeln("Cache TTL: " . ($cacheTtl > 0 ? "{$cacheTtl}s" : "disabled"));

        if (!in_array($infoLevel, ['basic', 'detailed', 'full'])) {
            $io->error('Info level must be one of: basic, detailed, full');
            return Command::FAILURE;
        }

        // Clear cache if requested
        if ($clearCache && $this->cache) {
            $io->warning('Shared cache is not cleared. Bypassing it for this run.');
            $cacheTtl = 0;
        }

        try {
            // Get album info with caching
            $albumInfo = $this->getCachedAlbumInfo($albumId, $userId, $cacheTtl);
            $userId = $albumInfo['owner'] ?? $userId;
            $io->section('Album Information');
            $io->table(['Property', 'Value'], [
                ['Title', $this->textValue($albumInfo['title'] ?? '')],
                ['Description', $this->textValue($albumInfo['description'] ?? '')],
                ['Total Photos', $albumInfo['photos']],
                ['Owner', $albumInfo['owner']]
            ]);

            // Process photos with event dispatching
            $stats = $this->processPhotosWithEvents($albumId, $userId, $perPage, $infoLevel, $dryRun, $limit, $cacheTtl, $io, $albumInfo);

            $io->success(sprintf(
                'Successfully processed %d photos from album "%s". Events dispatched: %d',
                $stats['processed'],
                $this->textValue($albumInfo['title'] ?? ''),
                $stats['events_dispatched']
            ));

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Error importing album: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function getCachedAlbumInfo(string $albumId, string $userId, int $cacheTtl): array
    {
        if (!$this->cache || $cacheTtl <= 0) {
            return $this->requireResponse($this->flickrService->photosets()->getInfo($albumId, $userId));
        }

        $cacheKey = $this->cacheKey('getInfo', albumId: $albumId, userId: $userId);

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($albumId, $userId, $cacheTtl) {
            $item->expiresAfter($cacheTtl);
            return $this->requireResponse($this->flickrService->photosets()->getInfo($albumId, $userId));
        });
    }

    private function getCachedPhotoInfo(string $photoId, string $userId, int $cacheTtl): array
    {
        if (!$this->cache || $cacheTtl <= 0) {
            return $this->requireResponse($this->flickrService->photos()->getInfo($photoId));
        }

        $cacheKey = $this->cacheKey('photo_info',
            photoId: $photoId,userId: $userId);

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($photoId, $cacheTtl) {
            $item->expiresAfter($cacheTtl);
            return $this->requireResponse($this->flickrService->photos()->getInfo($photoId));
        });
    }

    private function cacheKey(string $type,
                              ?string $photoId = null,
                              ?string $userId = null,
                              ?string $albumId = null,
    ?array $params = null,
    ): string
    {
        return hash('xxh3',
            serialize([$this->cacheAccount(), $type, $photoId, $userId, $albumId, $params]));
    }

    private function processPhotosWithEvents(
        string $albumId,
        string $userId,
        int $perPage,
        string $infoLevel,
        bool $dryRun,
        ?int $limit,
        int $cacheTtl,
        SymfonyStyle $io,
        array $albumInfo
    ): array {
        $eventsDispatched = 0;
        $page = 1;
        $totalPages = 1;
        $cacheHits = 0;
        $cacheMisses = 0;

        $io->section('Processing Photos');
        $io->progressStart();
        $page      = 1;
        $processed = 0;

        do {
            $params = [
                'page'     => $page,
                'per_page' => $perPage,
                'extras'   => $this->getExtrasForInfoLevel($infoLevel),
            ];

            $response = $this->getCachedPhotos($albumId, $userId, $params, $cacheTtl);

            $totalPages  = isset($response['pages']) ? (int)$response['pages'] : 1;
            $totalPhotos = isset($response['total']) ? (int)$response['total'] : 0;
            $photos      = $response['photo'] ?? [];

            if (!$photos) {
                // Defensive: if the API/state says there are pages but this one is empty, stop to avoid an infinite loop.
                $io->writeln(sprintf('No photos returned for page %d; stopping.', $page));
                break;
            }

            foreach ($photos as $photo) {
                $processed++;

                $io->writeln(sprintf(
                    'Processing photo %d/%d (Page %d): %s',
                    $processed,
                    $totalPhotos,
                    $page,
                    $photo['title'] ?? 'Untitled'
                ));

                $photoData = $this->enrichPhotoData($photo, $userId, $infoLevel, $cacheTtl);

                $event = new FlickrPhotoEvent(
                    albumId: $albumId,
                    userId: $userId,
                    photoData: $photoData,
                    albumInfo: $albumInfo,
                    processingContext: [
                        'page'          => $page,
                        'photo_number'  => $processed,
                        'total_photos'  => $totalPhotos,
                        'info_level'    => $infoLevel,
                    ]
                );

                if (!$dryRun) {
                    $this->eventDispatcher->dispatch($event, FlickrPhotoEvent::class);
                    $eventsDispatched++;
                    if ($event->shouldStopProcessing()) {
                        $io->writeln('Processing stopped by event listener');
                        break 2;
                    }
                } else {
                    $io->writeln('  → [DRY RUN] Would dispatch FlickrPhotoEvent');
                }

                if ($io->isVerbose()) {
                    $this->showPhotoDetails($photoData, $io);
                }

                if ($limit && $processed >= $limit) {
                    $io->writeln(sprintf('Reached limit of %d photos', $limit));
                    break 2;
                }

                $io->progressAdvance(1);
            }

            $page++;
        } while ($page <= $totalPages);

        $io->progressFinish();

        // Show cache statistics
        if ($this->cache && $cacheTtl > 0) {
//            $io->writeln(sprintf(
//                'Cache performance: %d hits, %d misses (%.1f%% hit rate)',
//                $cacheHits,
//                $cacheMisses,
//                $cacheMisses > 0 ? ($cacheHits / ($cacheHits + $cacheMisses)) * 100 : 100
//            ));
        }

        return [
            'processed' => $processed,
            'events_dispatched' => $eventsDispatched,
            'cache_hits' => $cacheHits,
            'cache_misses' => $cacheMisses
        ];
    }

    private function enrichPhotoData(array $photo, string $userId, string $infoLevel, int $cacheTtl): array
    {
        $photoData = $photo;

        if ($infoLevel === 'basic') {
            // Just return what we have from the photoset list
            return $photoData;
        }

        // Get detailed photo info for 'detailed' and 'full' levels
        $detailedInfo = $this->getCachedPhotoInfo($photo['id'], $userId, $cacheTtl);
        $photoData = array_merge($photoData, $detailedInfo);
        foreach (['title', 'description'] as $field) {
            $photoData[$field] = $this->textValue($photoData[$field] ?? '');
        }

        if ($infoLevel === 'full') {
            // Add current image URLs for different sizes
            $photoData['direct_urls'] = $this->buildDirectUrls($photoData);

            // Could add more data like EXIF, comments, etc.
            // $photoData['exif'] = $this->getCachedPhotoExif($photo['id'], $cacheTtl);
        }

        return $photoData;
    }

    private function buildDirectUrls(array $photoData): array
    {
        $urls = [];
        foreach (['thumbnail' => 't', 'small' => 'm', 'medium' => 'z', 'large' => 'b', 'original' => 'o'] as $name => $size) {
            if ($url = $this->flickrService->flickrThumbnailUrl($photoData, $size)) {
                $urls[$name] = $url;
            }
        }
        return $urls;
    }

    private function getExtrasForInfoLevel(string $infoLevel): string
    {
        return match($infoLevel) {
            'basic' => 'description,tags',
            'detailed' => 'description,url_m,url_l,url_o,tags,machine_tags,date_taken,owner_name',
            'full' => 'description,url_m,url_l,url_o,url_h,url_k,tags,machine_tags,date_taken,owner_name,geo,path_alias,views',
            default => throw new \InvalidArgumentException("Invalid info level: {$infoLevel}")
        };
    }

    private function showPhotoDetails(array $photoData, SymfonyStyle $io): void
    {
        $io->writeln("  → Photo ID: {$photoData['id']}");
        $io->writeln('  → Title: '.$this->textValue($photoData['title'] ?? ''));

        $description = $this->textValue($photoData['description'] ?? '');
        if ($description) {
            $io->writeln("  → Description: " . substr($description, 0, 100) . (strlen($description) > 100 ? '...' : ''));
        }

        if (isset($photoData['direct_urls'])) {
            $io->writeln("  → Direct URLs available: " . implode(', ', array_keys($photoData['direct_urls'])));
        }
    }

    private function extractAlbumId(string $input): ?string
    {
        if (preg_match('/^\d+$/', $input)) {
            return $input;
        }

        if (preg_match('/albums\/(\d+)/', $input, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function extractUserIdFromUrl(string $input): ?string
    {
        if (preg_match('/\/photos\/([^\/]+)\//', $input, $matches)) {
            return $matches[1];
        }

        return null;
    }
    private function normalizeParams(array $params): array
    {
        // ensure deterministic order for nested arrays like 'extras'
        array_walk_recursive($params, static function (&$v) {
            // leave values as-is
        });
        ksort($params);
        if (isset($params['extras']) && is_array($params['extras'])) {
            sort($params['extras']); // order-insensitive
        }
        return $params;
    }

    private function buildCacheKey(string $prefix, string $albumId, string $userId, array $params): string
    {
        $params = $this->normalizeParams($params);
        return hash('sha256', $this->cacheAccount().sprintf(
            '%s:%s:%s:%s',
            $prefix,
            $albumId,
            $userId,
            json_encode($params, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))
        );
    }

    private function getCachedPhotos(
        string $albumId,
        string $userId,
        array $params,
        int $cacheTtl
    ): array {
        if (!$this->cache || $cacheTtl <= 0) {
            return $this->requireResponse($this->flickrService->photosets()->getPhotos($albumId, $userId, explode(',', $params['extras']),
                perPage: $params['per_page'],
                page: $params['page']
            ));
        }

        $cacheKey = $this->buildCacheKey('flickr_photos', $albumId, $userId, $params);

        $items = $this->cache->get($cacheKey, function (ItemInterface $item) use ($albumId, $userId, $params, $cacheTtl) {
            $item->expiresAfter($cacheTtl);
            return $this->requireResponse($this->flickrService->photosets()->getPhotos($albumId, $userId,
                extras: explode(',', $params['extras']),
                perPage: $params['per_page'],
                page: $params['page']
            ));

        });
        return $items;
    }

    private function requireResponse(array|false $response): array
    {
        if ($response === false) {
            throw new \Samwilson\PhpFlickr\FlickrException('Flickr returned no usable response. Check authentication, album ID and API availability.');
        }
        return $response;
    }

    private function textValue(mixed $value): string
    {
        return (string) (is_array($value) ? ($value['_content'] ?? '') : $value);
    }

    private function cacheAccount(): string
    {
        return hash('sha256', serialize($this->flickrService->getOauthTokenStorage()->retrieveAccessToken('Flickr')->getAccessToken()));
    }

}
