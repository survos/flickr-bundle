<?php

declare(strict_types=1);

namespace Survos\FlickrBundle\Services;

use Survos\FlickrBundle\Util\FlickrUrl;


use OAuth\OAuth1\Token\StdOAuth1Token;
use Samwilson\PhpFlickr\PhpFlickr;
use Survos\FlickrBundle\Metadata\FlickrUserInterface;
use Symfony\Bundle\SecurityBundle\Security;

class FlickrService extends PhpFlickr
{

    public function __construct(
        string                 $apiKey,
        string                 $secret,
        private ?Security      $security = null,
        int|\DateInterval|null $cacheExpiration = null,
        private ?string $accessToken = null,
        private ?string $accessTokenSecret = null,
    )
    {
        parent::__construct($apiKey, $secret);


        if ($cacheExpiration !== null) {
            $this->setCacheDefaultExpiry($cacheExpiration);
        }
        $this->authenticate($accessToken, $accessTokenSecret);
    }

    public function authenticate(?string $key = null, ?string $secret = null): self
    {
        if ($key === null && $secret === null) {
            $user = $this->security?->getUser();
            if ($user instanceof FlickrUserInterface) {
                $key = $user->getFlickrKey();
                $secret = $user->getFlickrSecret();
            } else {
                $key = $this->accessToken;
                $secret = $this->accessTokenSecret;
            }
        }
        if ((bool) $key !== (bool) $secret) {
            throw new \InvalidArgumentException('Both Flickr access token and token secret are required.');
        }
        $token = new StdOAuth1Token();
        if ($key && $secret) {
            $token->setAccessToken($key);
            $token->setAccessTokenSecret($secret);
        }
        // Keep the storage instance: an existing OAuth service holds a reference to it.
        $this->getOauthTokenStorage()->storeAccessToken('Flickr', $token);

        return $this;
    }

    public function flickrThumbnailUrl(array|object $record, string $size = 'm', string $format = 'jpg'): ?string
    {
        $record = (array) $record;
        if (isset($record['url_'.$size])) {
            return $record['url_'.$size];
        }
        if ($size !== 'o') { // Larger sizes (h, k, ...) require their own secret; request url_h/url_k/etc.
            return FlickrUrl::image($record['server'] ?? '', $record['id'] ?? '', (string) ($record['secret'] ?? ''), $size, $format);
        }
        $secret = $record['originalsecret'] ?? null;
        $format = $record['originalformat'] ?? null;
        if (empty($record['server']) || empty($record['id']) || !$secret || !$format) {
            return null;
        }
        return sprintf('https://live.staticflickr.com/%s/%s_%s_o.%s', $record['server'], $record['id'], $secret, $format);
    }

    public function flickrPageUrl(array|object|int|string $record): ?string
    {
        if (is_object($record)) {
            $record = (array)$record;
        }
        $id = is_array($record) ? ($record['id']??null) : $record;
        return $id ? sprintf('https://www.flickr.com/photo.gne?id=%s', $id) : null;
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
        $licenseId = match (strtoupper($license)) {
            'CC0' => 9,

            'CC-BY' , 'CC BY' => 4,
            'CC-BY-SA', 'CC BY-SA' => 5,
            'CC-BY-ND', 'CC BY-ND' => 6,
            'CC-BY-NC', 'CC BY-NC' => 2,
            'CC-BY-NC-ND', 'CC BY-NC-ND' => 3,
            'CC-BY-NC-SA', 'CC BY-NC-SA' => 1, // "https://creativecommons.org/licenses/by-nc-sa/2.0/"
            default => 0, // assert(false, "Missing $license")
        };
        assert($license, "Missing $license");
        return $licenseId;
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
            return sprintf('%s=%s', $tagName, $this->quoteValue((string) $tagValue));
        } else {
            return null;
        }
    }

    public function quoteValue(string $tagValue): string
    {
        if (str_contains($tagValue, '"')) {
            throw new \InvalidArgumentException('Flickr tag values cannot contain double quotes.');
        }
        if (preg_match('/\\s/u', $tagValue)) {
            $tagValue = sprintf('"%s"', $tagValue);
        }
        return $tagValue;

    }

    public function tagHashToString(array $tags): string
    {
        $parts = [];
        foreach ($tags as $key => $value) {
            $parts[] = is_array($value) ? join(' ', array_map($this->quoteValue(...), $value)) : $this->tagString($key, $value);
        }
        return join(' ', $parts);

    }

    // this is ONLY used with a search result, which returns a compressed tag string.  photos()->getInfo() returns tags properly
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
