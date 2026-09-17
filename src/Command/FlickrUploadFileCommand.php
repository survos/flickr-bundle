<?php

declare(strict_types=1);

namespace Survos\FlickrBundle\Command;

use Survos\FlickrBundle\Services\FlickrService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('survos:flickr:upload-file', 'Upload one local image to Flickr (private by default)')]
final class FlickrUploadFileCommand
{
    public function __construct(private readonly FlickrService $flickrService)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Local image filename')] string $filename,
        #[Option('Photo title')] ?string $title = null,
        #[Option('Photo description and source citation')] ?string $description = null,
        #[Option('Space-separated Flickr tags; quote values containing spaces')] ?string $tags = null,
        #[Option('Make the upload publicly visible')] bool $public = false,
        #[Option('Content type: 1 photo, 2 screenshot, 3 other')] int $contentType = 3,
        #[Option('Safety level: 1 safe, 2 moderate, 3 restricted')] int $safetyLevel = 1,
        #[Option('Check local inputs without contacting Flickr')] bool $dryRun = false,
    ): int {
        if (!is_file($filename) || !is_readable($filename) || filesize($filename) === 0) {
            $io->error('Image must be a readable, nonempty local file.');
            return Command::INVALID;
        }
        if (!in_array($contentType, [1, 2, 3], true) || !in_array($safetyLevel, [1, 2, 3], true)) {
            $io->error('Content type and safety level must be 1, 2, or 3.');
            return Command::INVALID;
        }
        if ($dryRun) {
            $io->success('Local inputs are valid. Authentication, account limits and image acceptance have not been checked.');
            return Command::SUCCESS;
        }
        try {
            $this->flickrService->authenticate();
            $result = $this->flickrService->uploader()->upload(
                $filename, $title, $description, $tags,
                $public, false, false, $contentType, $public ? 1 : 2,
                safetyLevel: $safetyLevel,
            );
            if ($result['stat'] !== 'ok') {
                $io->error(sprintf('Flickr error %s: %s', $result['code'], $result['message']));
                return Command::FAILURE;
            }
            $io->success('Uploaded Flickr photo ID: '.$result['photoid']);
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }
}
