<?php

namespace Survos\FlickrBundle\Metadata;

use Doctrine\ORM\Mapping as ORM;

trait FlickrUserTrait
{

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $flickrUserId = null; // e.g. "26016159@N00"

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $flickrUsername = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $flickrKey = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $flickrSecret = null;

    public function getFlickrSecret(): ?string
    {
        return $this->flickrSecret;
    }


    public function getFlickrKey(): ?string
    {
        return $this->flickrKey;
    }

    public function setFlickrKey(?string $flickrKey): static
    {
        $this->flickrKey = $flickrKey;

        return $this;
    }

    public function setFlickrSecret(?string $flickrSecret): static
    {
        $this->flickrSecret = $flickrSecret;

        return $this;
    }

    public function getFlickrUserId(): ?string
    {
        return $this->flickrUserId;
    }

    public function setFlickrUserId(?string $flickrUserId): static
    {
        $this->flickrUserId = $flickrUserId;
        return $this;
    }


    public function getFlickrUsername(): ?string
    {
        return $this->flickrUsername;
    }

    public function setFlickrUsername(?string $flickrUsername): static
    {
        $this->flickrUsername = $flickrUsername;
        return $this;
    }



}
