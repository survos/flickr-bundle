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
        private RequestStack $requestStack,
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
            'CC BY-NC-SA' => 1, // "https://creativecommons.org/licenses/by-nc-sa/2.0/"
            default => assert(false, "Missing $license")
        };

    }
}
