<?php

declare(strict_types=1);

namespace Survos\FlickrBundle\Exception;

use Samwilson\PhpFlickr\FlickrException;

final class UploadException extends FlickrException
{
    public function __construct(string $message, public readonly int $httpStatus = 0, public readonly ?string $retryAfter = null)
    {
        parent::__construct($message, $httpStatus);
    }
}
