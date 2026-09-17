<?php

declare(strict_types=1);

namespace Survos\FlickrBundle\Services;

use OAuth\Common\Exception\Exception as OauthException;
use OAuth\Common\Exception\Exception;
use Samwilson\PhpFlickr\FlickrException;
class Uploader extends \Samwilson\PhpFlickr\Uploader
{

    protected $response;
    /** @var string */
    protected $uploadEndpoint = 'https://up.flickr.com/services/upload/';

    /** @var string */
    protected $replaceEndpoint = 'https://up.flickr.com/services/replace/';

    public function __construct(FlickrService $flickr, private int $connectTimeout = 15, private int $timeout = 300)
    {
        parent::__construct($flickr);
        if ($connectTimeout < 1 || $timeout < 1) {
            throw new \InvalidArgumentException('Upload timeouts must be positive.');
        }
    }

    /**
     * Upload a photo.
     * @link https://www.flickr.com/services/api/upload.api.html
     * @param string $photoFilename Full filesystem path to the photo to upload.
     * @param string|null $title The title of the photo.
     * @param string|null $description A description of the photo. May contain some limited HTML.
     * @param string|null $tags A space-seperated list of tags to apply to the photo.
     * @param bool|null $isPublic Specifies who can view the photo. If omitted permissions will
     * be set to user's default.
     * @param bool|null $isFriend Specifies who can view the photo. If omitted permissions will
     * be set to user's default.
     * @param bool|null $isFamily Specifies who can view the photo. If omitted permissions will
     * be set to user's default.
     * @param int|null $contentType Set to 1 for Photo, 2 for Screenshot, or 3 for Other. If
     * omitted , will be set to user's default.
     * @param int|null $hidden Set to 1 to keep the photo in global search results, 2 to hide from
     * public searches. If omitted, will be set based to user's default.
     * @param bool $async Whether to upload the file asynchronously (in which case a 'ticketid'
     * will be returned).
     * @param int|null $safetyLevel 1 for Safe, 2 for Moderate, 3 for Restricted.
     * @return array<string, int|string>
     */
    public function upload(
        $photoFilename,
        $title = null,
        $description = null,
        $tags = null,
        $isPublic = null,
        $isFriend = null,
        $isFamily = null,
        $contentType = null,
        $hidden = null,
        $async = false,
        $safetyLevel = null
    ) {
        $params = [
            'title' => $title,
            'description' => $description,
            'tags' => $tags,
            'is_public' => $isPublic,
            'is_friend' => $isFriend,
            'is_family' => $isFamily,
            'content_type' => $contentType,
            'hidden' => $hidden,
            'safety_level' => $safetyLevel,
        ];
        if ($async) {
            $params['async'] = 1;
        }
        return $this->sendFile($photoFilename, $params);
    }

    /**
     * @link https://www.flickr.com/services/api/replace.api.html
     * @param string $photoFilename Full filesystem path to the file to upload.
     * @param int|string $photoId The ID of the photo to replace.
     * @param bool $async Photos may be replaced in async mode, for applications that don't want to
     * wait around for an upload to complete, leaving a socket connection open the whole time.
     * Processing photos asynchronously is recommended.
     * @return array<string, int|string>
     */
    public function replace($photoFilename, $photoId, $async = null)
    {
        if (!preg_match('/^[0-9]+$/', (string) $photoId)) {
            throw new \InvalidArgumentException('A numeric Flickr photo ID is required.');
        }
        return $this->sendFile($photoFilename, ['photo_id' => $photoId, 'async' => $async]);
    }

    /**
     * @param string $filename
     * @param array $params
     * @return array
     * @throws Exception If an OAuth error occurs.
     * @throws FlickrException If the file can't be read.
     */
    public function sendFile($filename, $params)
    {
        if (!is_file($filename) || !is_readable($filename) || filesize($filename) === 0) {
            throw new FlickrException("File must be a readable, nonempty local file: $filename");
        }
        $params = array_filter($params, static fn ($value) => $value !== null);
        foreach (['content_type' => [1, 2, 3], 'safety_level' => [1, 2, 3], 'hidden' => [1, 2],
            'is_public' => [0, 1, false, true], 'is_friend' => [0, 1, false, true],
            'is_family' => [0, 1, false, true], 'async' => [0, 1, false, true]] as $name => $values) {
            if (isset($params[$name]) && !in_array($params[$name], $values, true)) {
                throw new \InvalidArgumentException("Invalid Flickr upload parameter: $name");
            }
        }
        // Sign exactly the scalar values that cURL will send, including false => "0".
        $params = array_map(static fn ($value) => is_bool($value) ? (string) (int) $value : (string) $value, $params);
        $endpoint = isset($params['photo_id']) ? $this->replaceEndpoint : $this->uploadEndpoint;
        if (!$this->flickr->getOauthTokenStorage()->retrieveAccessToken('Flickr')->getAccessToken()) {
            throw new FlickrException('Flickr uploads require an OAuth access token with write permission.');
        }
        $args = $this->flickr->getOauthService()
            ->getAuthorizationForPostingToAlternateUrl($params, $endpoint);
        $args['photo'] = new \CURLFile(realpath($filename));
        [$response, $status, $retryAfter] = $this->postFile($endpoint, $args);
        $this->response = $response;
        if ($status < 200 || $status >= 300) {
            throw new \Survos\FlickrBundle\Exception\UploadException(
                "Flickr upload returned HTTP $status. Reconcile the upload before retrying.", $status, $retryAfter);
        }
        return $this->parseResponse($response);
    }

    /** @return array{string, int, ?string} */
    protected function postFile(string $endpoint, array $args): array
    {
        $retryAfter = null;
        $curl = curl_init($endpoint);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $args,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => $this->flickr->getUserAgent(),
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$retryAfter): int {
                if (stripos($header, 'Retry-After:') === 0) {
                    $retryAfter = trim(substr($header, 12));
                }
                return strlen($header);
            },
        ]);
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if ($response === false) {
            throw new \Survos\FlickrBundle\Exception\UploadException(
                'Flickr upload transport failed (cURL '.curl_errno($curl).'). The outcome may be unknown; reconcile before retrying.');
        }
        // PHP 8.5 releases CurlHandle automatically; curl_close() is deprecated.
        return [(string) $response, $status, $retryAfter];
    }

    /** @return array<string, int|string> */
    protected function parseResponse(string $response): array
    {
        if (preg_match('/(?:^|&)oauth_problem=([^&\s]+)/', $response, $matches)) {
            throw new OauthException(rawurldecode($matches[1]));
        }
        $previous = libxml_use_internal_errors(true);
        try {
            if (stripos($response, '<!DOCTYPE') !== false) {
                throw new FlickrException('Unexpected Flickr XML document type.');
            }
            $xml = simplexml_load_string($response, \SimpleXMLElement::class, LIBXML_NONET);
            if ($xml === false || $xml->getName() !== 'rsp' || !in_array((string) $xml['stat'], ['ok', 'fail'], true)) {
                throw new FlickrException('Invalid Flickr upload response; reconcile before retrying.');
            }
            $result = ['stat' => (string) $xml['stat']];
            if ($result['stat'] === 'fail') {
                if (!isset($xml->err)) {
                    throw new FlickrException('Flickr failure response is missing its error details.');
                }
                // Preserve the existing API error-array contract for callers.
                $result['code'] = (int) $xml->err['code'];
                $result['message'] = (string) $xml->err['msg'];
                return $result;
            }
            if (isset($xml->photoid) && preg_match('/^[0-9]+$/', (string) $xml->photoid)) {
                $result['photoid'] = (string) $xml->photoid;
                foreach (['secret', 'originalsecret'] as $key) {
                    if (isset($xml->photoid[$key])) {
                        $result[$key] = (string) $xml->photoid[$key];
                    }
                }
            } elseif (isset($xml->ticketid) && (string) $xml->ticketid !== '') {
                $result['ticketid'] = (string) $xml->ticketid;
            } else {
                throw new FlickrException('Flickr upload response is missing a photo ID or ticket ID.');
            }
            return $result;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
