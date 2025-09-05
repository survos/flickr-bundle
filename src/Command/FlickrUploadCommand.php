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
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Psr\Log\LoggerInterface;

#[AsCommand('survos:flickr:upload', 'Process Flickr photos and moderate content')]
class FlickrUploadCommand
{
    public function __construct(
        private FlickrService $flickrService,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger,
        private ?CacheInterface $cache = null,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Pixie code or search criteria, only used for callback context')]
        ?string $pixieCode = null,
        #[Option('Limit the number of photos to process')]
        int $limit = 0,
        #[Option('Photos per page')]
        int $perPage = 500,
        #[Option('Starting page number')]
        int $page = 1,
        #[Option('Safety level filter (0=safe, 1=moderate, 2=restricted)')]
        int $safety = 0,
        #[Option('Cache TTL in seconds (0 to disable cache)')]
        int $cacheTtl = 3600,
        #[Option('Clear cache before processing')]
        bool $clearCache = false,
        #[Option('Show what would be processed without dispatching events')]
        bool $dryRun = false
    ): int
    {
        $io->title('Flickr Photo Upload/Moderation');
        $io->writeln("Pixie Code: {$pixieCode}");
        $io->writeln("Safety Level: {$safety}");
        $io->writeln("Per Page: {$perPage}");
        $io->writeln("Cache TTL: " . ($cacheTtl > 0 ? "{$cacheTtl}s" : "disabled"));

        if ($clearCache && $this->cache) {
            $io->writeln('Clearing Flickr cache...');
            $this->cache->clear();
        }

        try {
            $stats = $this->processPhotos($pixieCode, $limit, $perPage, $page, $safety, $cacheTtl, $dryRun, $io);

            $io->success(sprintf(
                'Successfully processed %d photos across %d pages. Events dispatched: %d',
                $stats['processed'],
                $stats['pages'],
                $stats['events_dispatched']
            ));

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Error processing photos: ' . $e->getMessage());
            $this->logger->error('Flickr upload command failed', [
                'error' => $e->getMessage(),
                'pixie_code' => $pixieCode
            ]);
            return Command::FAILURE;
        }
    }

    private function processPhotos(
        string $pixieCode,
        int $limit,
        int $perPage,
        int $startPage,
        int $safety,
        int $cacheTtl,
        bool $dryRun,
        SymfonyStyle $io
    ): array {
        $processedCount = 0;
        $eventsDispatched = 0;
        $page = $startPage;
        $totalPages = '?';

        $io->section('Processing Photos');
        $io->progressStart();

        while (true) {
            $io->writeln("Checking page {$page} of {$totalPages}, {$perPage} items");

            // Get search results with caching
            $results = $this->getCachedSearchResults($page, $perPage, $safety, $pixieCode, $cacheTtl);
            $totalPages = $results['pages'];

            if (empty($results['photo'])) {
                $io->writeln('No more photos to process');
                break;
            }

            foreach ($results['photo'] as $idx => $photoData) {
                $processedCount++;
                $flickrId = $photoData['id'];

                $io->writeln(sprintf(
                    'Processing photo %d: %s (ID: %s)',
                    $processedCount,
                    $photoData['title'] ?? 'Untitled',
                    $flickrId
                ));

                try {
                    // Get detailed photo info with caching
                    $photoInfo = $this->getCachedPhotoInfo($flickrId, $photoData['secret'] ?? '', $cacheTtl);
                    $enrichedPhotoData = array_merge($photoData, $photoInfo);

                    // Create and dispatch event
                    $event = new FlickrPhotoEvent(
                        albumId: null, // No specific album for search results
                        userId: $photoData['owner'] ?? '',
                        photoData: $enrichedPhotoData,
                        albumInfo: [],
                        processingContext: [
                            'page' => $page,
                            'photo_number' => $processedCount,
                            'total_photos' => $results['total'] ?? 0,
                            'pixie_code' => $pixieCode,
                            'safety_level' => $safety,
                            'search_context' => true
                        ]
                    );

                    if (!$dryRun) {
                        $this->eventDispatcher->dispatch($event, FlickrPhotoEvent::NAME);
                        $eventsDispatched++;

                        if ($event->shouldStopProcessing()) {
                            $io->writeln('Processing stopped by event listener');
                            break 2;
                        }
                    } else {
                        $io->writeln('  → [DRY RUN] Would dispatch FlickrPhotoEvent');
                    }

                } catch (\Exception $e) {
                    $io->error("Error processing photo {$flickrId}: " . $e->getMessage());
                    $this->logger->error('Photo processing failed', [
                        'flickr_id' => $flickrId,
                        'error' => $e->getMessage()
                    ]);
                }

                // Check limit
                if ($limit && $processedCount >= $limit) {
                    $io->writeln(sprintf('Reached limit of %d photos', $limit));
                    break 2;
                }

                $io->progressAdvance(1);
            }

            $page++;
            if ($page > $totalPages) {
                break;
            }
        }

        $io->progressFinish();

        return [
            'processed' => $processedCount,
            'events_dispatched' => $eventsDispatched,
            'pages' => $page - $startPage
        ];
    }

    private function getCachedSearchResults(int $page, int $perPage, int $safety, string $pixieCode, int $cacheTtl): array
    {
        if (!$this->cache || $cacheTtl <= 0) {
            return $this->performSearch($page, $perPage, $safety, $pixieCode);
        }

        $cacheKey = "flickr_search_{$page}_{$perPage}_{$safety}_{$pixieCode}";
        
        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($page, $perPage, $safety, $pixieCode, $cacheTtl) {
            $item->expiresAfter($cacheTtl);
            return $this->performSearch($page, $perPage, $safety, $pixieCode);
        });
    }

    private function getCachedPhotoInfo(string $flickrId, string $secret, int $cacheTtl): array
    {
        if (!$this->cache || $cacheTtl <= 0) {
            return $this->flickrService->photos()->getInfo($flickrId, $secret);
        }

        $cacheKey = "flickr_photo_info_{$flickrId}";
        
        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($flickrId, $secret, $cacheTtl) {
            $item->expiresAfter($cacheTtl);
            return $this->flickrService->photos()->getInfo($flickrId, $secret);
        });
    }

    private function performSearch(int $page, int $perPage, int $safety, string $pixieCode): array
    {
        // This would need to be adapted based on your ImageService implementation
        // For now, using a placeholder structure that matches your original code
        
        $params = [
            'page' => $page,
            'per_page' => $perPage,
            'safe_search' => $safety,
            'extras' => 'description,machine_tags,safety_level,owner_name,date_taken'
        ];

        if ($pixieCode) {
            $params['tags'] = "museado:pixie={$pixieCode}";
        }

        // This would call your actual search method
        // return $this->imageService->search($params);
        
        // Placeholder implementation - replace with actual search logic
        return [
            'photo' => [],
            'pages' => 1,
            'total' => 0
        ];
    }
}
