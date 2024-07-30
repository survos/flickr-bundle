<?php

declare(strict_types=1);

namespace Survos\FlickrBundle\Services;

use App\Entity\User;
use OAuth\Common\Storage\Memory;
use OAuth\Common\Storage\Session;
use OAuth\OAuth1\Token\StdOAuth1Token;
use Samwilson\PhpFlickr\PhpFlickr;
use Survos\FlickrBundle\Metadata\FlickrUserInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

class FlickrService extends PhpFlickr
{
    protected PhpFlickr $flickr;
    public function __construct(
        string $apiKey,
        string $secret,
        private ?Security $security,
        int|\DateInterval|null $cacheExpiration = null,
    ) {
        parent::__construct($apiKey, $secret);


        if ($cacheExpiration) {
            $this->setCacheDefaultExpiry($cacheExpiration);
        }



//        $this->flickr = new \Samwilson\PhpFlickr\PhpFlickr($apiKey, $apiSecret);
//        $storage = new Memory();
//// Create the access token from the strings you acquired before.
//        $token = new StdOAuth1Token();
//// Add the token to the storage.
//        $storage->storeAccessToken('Flickr', $token);
    }

    public function authenticate(?string $key = null, ?string $secret = null): self
    {
        static $initialized = false;
        if ($initialized) {
            return $this;
        }
        /** @var FlickrUserInterface $user */
        if ($this->security && ($user = $this->security->getUser()) && method_exists($user, 'getFlickrKey') && $user->getFlickrKey()) {
            $key = $user->getFlickrKey();
            $secret = $user->getFlickrSecret();
        }
        if ($key) {
            $token = new StdOAuth1Token();
            $token->setAccessToken($key);
            $token->setAccessTokenSecret($secret);
            $storage = new Session();
            $storage->storeAccessToken('Flickr', $token);
            $this->setOauthStorage($storage);
        }
        $initialized = true;

        return $this;
    }

    public function flickrThumbnailUrl(array|object $record, string $size='m', string $format = 'jpg'): string
    {
        if (is_object($record)) {
            $record = (array)$record;
        }
//        https://live.staticflickr.com/{server-id}/{id}_{o-secret}_o.{o-format}
//        https://www.flickr.com/services/api/misc.urls.html
//        You can also use s,q,t for cropped squares,
// m=240,n=320,w=400 for small, z=620,c=800 for medium, and b=1024 for large.
        return sprintf('https://live.staticflickr.com/%s/%s_%s_%s.%s',
            $record['server'],
            $record['id'],
            $record['secret'],
            $size,
            $format
        );
    }

    public function flickrPageUrl(array|object|int|string $record)
    {
        if (is_object($record)) {
            $record = (array)$record;
        }
        return sprintf('https://www.flickr.com/photo.gne?id=%s', is_array($record) ? $record['id']: $record);
    }
    public function flickrAlbumUrl(array $album)
    {
        return sprintf('https://www.flickr.com/photos/%s/albums/%s', $album['username'], $album['id']);
    }



    /**
     * Returns a license based on string from common license agreements
     *
     * @param string $license
     * @return int
     */
    public function getLicenseId(string $license): int
    {
        // https://gitea.armuli.eu/museum-digital/MDAllowedValueSets/src/branch/master/src/MDLicensesSet.php
        // https://mus.wip/flickr-licenses.json
        return match(strtoupper($license)) {
            'CC0' => 9,
            'CC-BY-SA',
            'CC BY-NC-SA' => 1, // "https://creativecommons.org/licenses/by-nc-sa/2.0/"
            default => assert(false, "Missing $license")
        };

    }

    public function uploader(): Uploader
    {
        return new Uploader($this);
    }

    public function tagString($tagName, $tagValue): ?string
    {
        if (is_array($tagValue)) {
            return null;
        }
        if (($tagValue != '')) {
            // escape or remove
            if (str_contains($tagValue, '"')) {
                dd($tagValue);
            }
            if (str_contains($tagValue, ' ')) {
                $tagValue = sprintf('"%s"', $tagValue);
            }
            return sprintf('%s=%s', $tagName, $tagValue);
        } else {
            return null;
        }

    }

    public function tagHashToString(array $tags): string
    {
        $parts = [];
        foreach ($tags as $key=>$value) {
            $parts[] = is_array($value) ? join(' ', $value) : $this->tagString($key, $value);
        }
        return join(' ', $parts);

    }

    public function getTagsAsHash(string $tagString)
    {
        $machineTags = $regularTags = [];
        foreach (explode(' ', $tagString) as $tagPart) {
            // check if machineTag.
            // @todo: handle array
            if (str_contains($tagPart, '=')) {
                [$tag, $value] = explode('=', $tagPart, 2);
                $machineTags[$tag] = $value;
            } else {
                $regularTags[] = $tagPart;
            }
        }

        $machineTags['_'] = join(' ', $regularTags);
        return $machineTags;
    }



}
