<?php

namespace Survos\FlickrBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

class FlickrPhotoEvent extends Event
{
    public const NAME = 'survos_flickr.photo_processed';

    private bool $stopProcessing = false;

    public function __construct(
        private(set) ?string $albumId, // unless the event can move it?
        private(set) string $userId,
        private(set) array $photoData,
        private(set) array $albumInfo = [],
        public array $processingContext = []
    ) {
    }

    public function getAlbumId(): string
    {
        return $this->albumId;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getPhotoData(): array
    {
        return $this->photoData;
    }

    public function getPhotoId(): string
    {
        return $this->photoData['id'] ?? '';
    }

    public function getPhotoTitle(): string
    {
        return $this->photoData['title'] ?? '';
    }

    public function getPhotoDescription(): string
    {
        return $this->photoData['description'] ?? '';
    }

    public function getDirectUrls(): array
    {
        return $this->photoData['direct_urls'] ?? [];
    }

    public function getAlbumInfo(): array
    {
        return $this->albumInfo;
    }

    public function getProcessingContext(): array
    {
        return $this->processingContext;
    }

    public function getCurrentPhotoNumber(): int
    {
        return $this->processingContext['photo_number'] ?? 0;
    }

    public function getTotalPhotos(): int
    {
        return $this->processingContext['total_photos'] ?? 0;
    }

    public function getInfoLevel(): string
    {
        return $this->processingContext['info_level'] ?? 'detailed';
    }

    public function stopProcessing(): void
    {
        $this->stopProcessing = true;
    }

    public function shouldStopProcessing(): bool
    {
        return $this->stopProcessing;
    }
}