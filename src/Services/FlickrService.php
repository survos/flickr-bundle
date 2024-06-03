<?php

declare(strict_types=1);

namespace Survos\FlickrBundle\Services;

use OAuth\Common\Storage\Memory;
use OAuth\OAuth1\Token\StdOAuth1Token;
use Samwilson\PhpFlickr\PhpFlickr;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class FlickrService extends PhpFlickr
{
    protected PhpFlickr $flickr;
    public function __construct(
        string $apiKey,
        string $secret,
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

}
