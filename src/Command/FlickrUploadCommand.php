<?php

declare(strict_types=1);

namespace Survos\FlickrBundle\Command;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('survos:flickr:upload', 'Legacy placeholder; use survos:flickr:upload-file')]
class FlickrUploadCommand
{
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
        $io->error('This legacy moderation command has no upload implementation. Use survos:flickr:upload-file to upload an image.');
        return Command::FAILURE;
    }

}
