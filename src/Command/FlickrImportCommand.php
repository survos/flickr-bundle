<?php

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
        int $perPage = 100,
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

        if (!$userId) {
            $io->error('Could not extract user ID from URL');
            return Command::FAILURE;
        }

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
            $io->writeln('Clearing Flickr cache...');
            $this->cache->clear();
        }

        try {
            // Get album info with caching
            $albumInfo = $this->getCachedAlbumInfo($albumId, $userId, $cacheTtl);
            $io->section('Album Information');
            $io->table(['Property', 'Value'], [
                ['Title', $albumInfo['title']],
                ['Description', $albumInfo['description']],
                ['Total Photos', $albumInfo['photos']],
                ['Owner', $albumInfo['owner']]
            ]);

            // Process photos with event dispatching
            $stats = $this->processPhotosWithEvents($albumId, $userId, $perPage, $infoLevel, $dryRun, $limit, $cacheTtl, $io);

            $io->success(sprintf(
                'Successfully processed %d photos from album "%s". Events dispatched: %d',
                $stats['processed'],
                $albumInfo['title'],
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
            return $this->flickrService->photosets()->getInfo($albumId, $userId);
        }

        $cacheKey = $this->cacheKey('getInfo', albumId: $albumId, userId: $userId);

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($albumId, $userId, $cacheTtl) {
            $item->expiresAfter($cacheTtl);
            return $this->flickrService->photosets()->getInfo($albumId, $userId);
        });
    }

    private function getCachedPhotos(
        string $albumId, string $userId, array $params, int $cacheTtl): array
    {
        if (!$this->cache || $cacheTtl <= 0) {
            return $this->flickrService->photosets()->getPhotos($albumId, $userId, $params);
        }
        // Track cache performance
        $cacheKeyExists = false;
        if ($this->cache && $cacheTtl > 0) {
            $cacheKey = $this->cacheKey("flickr_photos",
                albumId: $albumId,
                userId: $userId,
                params: $params
            );
            $cacheKeyExists = $this->cache->hasItem($cacheKey);
        }

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($albumId, $userId, $params, $cacheTtl) {
            $item->expiresAfter($cacheTtl);
            return $this->flickrService->photosets()->getPhotos($albumId, $userId, $params);
        });
    }

    private function getCachedPhotoInfo(string $photoId, string $userId, int $cacheTtl): array
    {
        if (!$this->cache || $cacheTtl <= 0) {
            return $this->flickrService->photos()->getInfo($photoId, $userId);
        }

        $cacheKey = $this->cacheKey('photo_info',
            photoId: $photoId,userId: $userId);

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($photoId, $userId, $cacheTtl) {
            $item->expiresAfter($cacheTtl);
            return $this->flickrService->photos()->getInfo($photoId, $userId);
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
            $type . $photoId . $userId . $albumId . serialize($params));
    }

    private function processPhotosWithEvents(
        string $albumId,
        string $userId,
        int $perPage,
        string $infoLevel,
        bool $dryRun,
        ?int $limit,
        int $cacheTtl,
        SymfonyStyle $io
    ): array {
        $processedCount = 0;
        $eventsDispatched = 0;
        $page = 1;
        $totalPages = 1;
        $cacheHits = 0;
        $cacheMisses = 0;

        $io->section('Processing Photos');
        $io->progressStart();

        do {
            $params = [
                'page' => $page,
                'per_page' => $perPage,
                'extras' => $this->getExtrasForInfoLevel($infoLevel)
            ];

            $response = $this->getCachedPhotos($albumId, $userId, $params, $cacheTtl);
            

            $totalPages = $response['pages'];
            $totalPhotos = $response['total'];

            foreach ($response['photo'] as $photo) {
                $processedCount++;

                $io->writeln(sprintf(
                    'Processing photo %d/%d (Page %d): %s',
                    $processedCount,
                    $totalPhotos,
                    $page,
                    $photo['title'] ?? 'Untitled'
                ));

                // Get additional photo info based on level
                $photoData = $this->enrichPhotoData($photo, $userId, $infoLevel, $cacheTtl);

                // Create and dispatch event
                $event = new FlickrPhotoEvent(
                    albumId: $albumId,
                    userId: $userId,
                    photoData: $photoData,
                    albumInfo: $response['photoset'] ?? [],
                    processingContext: [
                        'page' => $page,
                        'photo_number' => $processedCount,
                        'total_photos' => $totalPhotos,
                        'info_level' => $infoLevel
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

                // Show photo details in verbose mode
                if ($io->isVerbose()) {
                    $this->showPhotoDetails($photoData, $io);
                }

                // Check limit
                if ($limit && $processedCount >= $limit) {
                    $io->writeln(sprintf('Reached limit of %d photos', $limit));
                    break 2;
                }

                $io->progressAdvance(1);
            }

            $io->writeln(sprintf(
                " Page %d/%d complete (%d photos processed)",
                $page,
                $totalPages,
                count($response['photo'])
            ), SymfonyStyle::VERBOSITY_VERBOSE);

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
            'processed' => $processedCount,
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

        if ($infoLevel === 'full') {
            // Add direct farm URLs for different sizes
            $photoData['direct_urls'] = $this->buildDirectUrls($photoData);

            // Could add more data like EXIF, comments, etc.
            // $photoData['exif'] = $this->getCachedPhotoExif($photo['id'], $cacheTtl);
        }

        return $photoData;
    }

    private function buildDirectUrls(array $photoData): array
    {
        if (!isset($photoData['farm'], $photoData['server'], $photoData['id'], $photoData['secret'])) {
            return [];
        }

        $farm = $photoData['farm'];
        $server = $photoData['server'];
        $photoId = $photoData['id'];
        $secret = $photoData['secret'];

        return [
            'thumbnail' => "https://farm{$farm}.staticflickr.com/{$server}/{$photoId}_{$secret}_t.jpg", // 100px
            'small' => "https://farm{$farm}.staticflickr.com/{$server}/{$photoId}_{$secret}_m.jpg",     // 240px
            'medium' => "https://farm{$farm}.staticflickr.com/{$server}/{$photoId}_{$secret}_z.jpg",    // 640px
            'large' => "https://farm{$farm}.staticflickr.com/{$server}/{$photoId}_{$secret}_b.jpg",     // 1024px
            'original' => "https://farm{$farm}.staticflickr.com/{$server}/{$photoId}_{$secret}_o.jpg"   // original
        ];
    }

    private function getExtrasForInfoLevel(string $infoLevel): string
    {
        return match($infoLevel) {
            'basic' => 'description,tags',
            'detailed' => 'description,url_m,url_l,url_o,tags,machine_tags,date_taken,owner_name',
            'full' => 'description,url_m,url_l,url_o,url_h,url_k,tags,machine_tags,date_taken,owner_name,geo,path_alias,views'
        };
    }

    private function showPhotoDetails(array $photoData, SymfonyStyle $io): void
    {
        $io->writeln("  → Photo ID: {$photoData['id']}");
        $io->writeln("  → Title: {$photoData['title']}");

        $description = $photoData['description'] ?? '';
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
}
