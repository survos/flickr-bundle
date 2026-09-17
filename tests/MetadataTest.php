<?php

declare(strict_types=1);

namespace Survos\FlickrBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\FlickrBundle\Metadata\FlickrUserInterface;
use Survos\FlickrBundle\Metadata\FlickrUserTrait;
use Survos\FlickrBundle\Event\FlickrPhotoEvent;

final class MetadataTest extends TestCase
{
    public function testUserTraitAndNullableAlbum(): void
    {
        $user = new class implements FlickrUserInterface { use FlickrUserTrait; };
        self::assertNull($user->getFlickrKey());
        self::assertSame($user, $user->setFlickrKey('key')->setFlickrSecret('secret'));
        self::assertSame('key', $user->getFlickrKey());
        $event = new FlickrPhotoEvent(null, 'owner', ['id' => 123, 'title' => ['_content' => 'Ad'], 'description' => ['_content' => 'Archive']]);
        self::assertNull($event->getAlbumId());
        self::assertSame('123', $event->getPhotoId());
        self::assertSame('Ad', $event->getPhotoTitle());
        self::assertSame('Archive', $event->getPhotoDescription());
    }
}
