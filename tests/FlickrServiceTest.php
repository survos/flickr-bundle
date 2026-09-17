<?php

declare(strict_types=1);

namespace Survos\FlickrBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\FlickrBundle\Services\FlickrService;

final class FlickrServiceTest extends TestCase
{
    public function testAuthenticationCanChangeAfterOAuthServiceCreationAndIsIsolated(): void
    {
        $first = new FlickrService('app', 'secret');
        $oauth = $first->getOauthService();
        $first->authenticate('one', 'one-secret');
        self::assertSame($oauth, $first->getOauthService());
        $second = new FlickrService('app', 'secret');
        $second->authenticate('two', 'two-secret');
        self::assertSame('one', $first->getOauthTokenStorage()->retrieveAccessToken('Flickr')->getAccessToken());
        self::assertSame('two', $second->getOauthTokenStorage()->retrieveAccessToken('Flickr')->getAccessToken());
        $first->authenticate('three', 'three-secret');
        self::assertSame('three', $first->getOauthTokenStorage()->retrieveAccessToken('Flickr')->getAccessToken());
        $first->authenticate();
        self::assertEmpty($first->getOauthTokenStorage()->retrieveAccessToken('Flickr')->getAccessToken());
    }

    public function testIncompleteCredentialsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new FlickrService('app', 'secret'))->authenticate('token');
    }

    public function testConfiguredCredentialsAreAvailableToWorkers(): void
    {
        $flickr = new FlickrService('app', 'secret', accessToken: 'worker', accessTokenSecret: 'worker-secret');
        self::assertSame('worker', $flickr->getOauthTokenStorage()->retrieveAccessToken('Flickr')->getAccessToken());
    }

    public function testOriginalAndLargeImageSecrets(): void
    {
        $flickr = new FlickrService('app', 'secret');
        $photo = ['server' => 's', 'id' => '123', 'secret' => 'small'];
        self::assertNull($flickr->flickrThumbnailUrl($photo, 'o'));
        self::assertNull($flickr->flickrThumbnailUrl($photo, 'h'));
        self::assertSame('https://live.staticflickr.com/s/123_small.jpg', $flickr->flickrThumbnailUrl($photo, ''));
        $photo += ['originalsecret' => 'original', 'originalformat' => 'png'];
        self::assertSame('https://live.staticflickr.com/s/123_original_o.png', $flickr->flickrThumbnailUrl($photo, 'o'));
    }

    public function testNewspaperTagsAndLicenses(): void
    {
        $flickr = new FlickrService('app', 'secret');
        self::assertSame('news:publication="Daily News" news:page=5 "newspaper advertising"', $flickr->tagHashToString([
            'news:publication' => 'Daily News', 'news:page' => 5, '_' => ['newspaper advertising'],
        ]));
        self::assertSame(5, $flickr->getLicenseId('CC-BY-SA'));
        self::assertSame(1, $flickr->getLicenseId('CC BY-NC-SA'));
    }

    public function testAmbiguousTagQuotesFailWithoutTerminatingProcess(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new FlickrService('app', 'secret'))->quoteValue('A "quoted" advertiser');
    }
}
