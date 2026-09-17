<?php

declare(strict_types=1);

namespace Survos\FlickrBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\FlickrBundle\Command\FlickrImportCommand;
use Survos\FlickrBundle\Event\FlickrPhotoEvent;
use Survos\FlickrBundle\Services\FlickrService;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class ImportCommandTest extends TestCase
{
    public function testPaginationMetadataAndCache(): void
    {
        $flickr = new class('app', 'secret') extends FlickrService {
            public array $calls = [];
            public function request($command, $args = [], $nocache = false): array
            {
                $this->calls[] = [$command, $args];
                return match ($command) {
                    'flickr.photosets.getInfo' => ['photoset' => ['title' => ['_content' => 'Newspapers'], 'description' => ['_content' => 'Archive'], 'photos' => 2, 'owner' => 'owner']],
                    'flickr.photosets.getPhotos' => ['photoset' => ['pages' => 2, 'total' => 2, 'photo' => [['id' => (string) $args['page'], 'title' => 'Ad']]]],
                    'flickr.photos.getInfo' => ['photo' => ['id' => $args['photo_id'], 'title' => ['_content' => 'Ad headline'], 'description' => ['_content' => 'Source citation']]],
                    default => throw new \LogicException($command),
                };
            }
        };
        $dispatcher = new EventDispatcher();
        $events = [];
        $dispatcher->addListener(FlickrPhotoEvent::class, static function (FlickrPhotoEvent $event) use (&$events): void { $events[] = $event; });
        $command = new FlickrImportCommand($flickr, $dispatcher, new ArrayAdapter());
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);
        self::assertSame(0, $command($io, '123', perPage: 1));
        self::assertCount(2, $events);
        self::assertSame('Source citation', $events[0]->getPhotoDescription());
        self::assertSame('owner', $events[0]->getUserId());
        self::assertSame(2, $events[0]->getAlbumInfo()['photos']);
        self::assertStringContainsString('Successfully processed 2 photos', $output->fetch());
        $requests = count($flickr->calls);
        self::assertSame(0, $command($io, '123', perPage: 1));
        self::assertCount($requests, $flickr->calls);
        $flickr->authenticate('another-user', 'secret');
        self::assertSame(0, $command($io, '123', perPage: 1));
        self::assertGreaterThan($requests, count($flickr->calls));
        foreach ($flickr->calls as [$method, $args]) {
            if ($method === 'flickr.photosets.getPhotos') {
                self::assertStringContainsString('machine_tags', $args['extras']);
                self::assertSame(1, $args['per_page']);
            }
            if ($method === 'flickr.photos.getInfo') {
                self::assertNull($args['secret']);
            }
        }
    }

    public function testMissingApiResponseReturnsFailure(): void
    {
        $flickr = new class('app', 'secret') extends FlickrService {
            public function request($command, $args = [], $nocache = false): array { return []; }
        };
        $command = new FlickrImportCommand($flickr, new EventDispatcher());
        self::assertSame(1, $command(new SymfonyStyle(new ArrayInput([]), new BufferedOutput()), '123'));
    }
}
