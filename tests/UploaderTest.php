<?php

declare(strict_types=1);

namespace Survos\FlickrBundle\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Samwilson\PhpFlickr\FlickrException;
use Survos\FlickrBundle\Exception\UploadException;
use Survos\FlickrBundle\Services\FlickrService;
use Survos\FlickrBundle\Services\Uploader;

final class UploaderTest extends TestCase
{
    private string $file;
    private RecordingUploader $uploader;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'flickr-test-');
        file_put_contents($this->file, 'fixture bytes');
        $this->uploader = new RecordingUploader((new FlickrService('app', 'secret'))->authenticate('token', 'token-secret'));
    }

    protected function tearDown(): void
    {
        unlink($this->file);
    }

    public function testUploadSignsExactlyThePostedValues(): void
    {
        $result = $this->uploader->upload($this->file, title: 'Ad & headline', isPublic: false, isFriend: false, safetyLevel: 1);
        self::assertSame('12345678901234567890', $result['photoid']);
        self::assertSame('https://up.flickr.com/services/upload/', $this->uploader->endpoint);
        self::assertSame('0', $this->uploader->args['is_public']);
        self::assertSame('1', $this->uploader->args['safety_level']);
        self::assertArrayNotHasKey('description', $this->uploader->args);
        self::assertInstanceOf(\CURLFile::class, $this->uploader->args['photo']);
        $args = $this->uploader->args;
        $signature = $args['oauth_signature'];
        unset($args['photo'], $args['oauth_signature']);
        ksort($args);
        $base = 'POST&'.rawurlencode($this->uploader->endpoint).'&'.rawurlencode(http_build_query($args, '', '&', PHP_QUERY_RFC3986));
        self::assertSame(base64_encode(hash_hmac('sha1', $base, 'secret&token-secret', true)), $signature);
    }

    public function testReplacementUsesReplacementEndpointAndPreservesSecrets(): void
    {
        $this->uploader->body = '<rsp stat="ok"><photoid secret="s" originalsecret="o">123</photoid></rsp>';
        self::assertSame(['stat' => 'ok', 'photoid' => '123', 'secret' => 's', 'originalsecret' => 'o'], $this->uploader->replace($this->file, '123'));
        self::assertSame('https://up.flickr.com/services/replace/', $this->uploader->endpoint);
        self::assertSame('123', $this->uploader->args['photo_id']);
        self::assertArrayNotHasKey('async', $this->uploader->args);
    }

    public function testAsyncTicketAndApiFailureContract(): void
    {
        $this->uploader->body = '<rsp stat="ok"><ticketid>ticket-1</ticketid></rsp>';
        self::assertSame('ticket-1', $this->uploader->upload($this->file, async: true)['ticketid']);
        self::assertSame('1', $this->uploader->args['async']);
        $this->uploader->body = '<rsp stat="fail"><err code="98" msg="Invalid token"/></rsp>';
        self::assertSame(['stat' => 'fail', 'code' => 98, 'message' => 'Invalid token'], $this->uploader->upload($this->file));
    }

    #[DataProvider('invalidResponses')]
    public function testMalformedResponsesThrow(string $body): void
    {
        $this->uploader->body = $body;
        $this->expectException(FlickrException::class);
        $this->uploader->upload($this->file);
    }

    public static function invalidResponses(): iterable
    {
        yield [''];
        yield ['<html>Service unavailable</html>'];
        yield ['<rsp stat="ok"/>'];
        yield ['<rsp stat="fail"/>'];
        yield ['<!DOCTYPE rsp [<!ENTITY x SYSTEM "file:///etc/hosts">]><rsp stat="ok"><photoid>&x;</photoid></rsp>'];
    }

    public function testRateLimitIsExposedWithoutRetry(): void
    {
        $this->uploader->status = 429;
        $this->uploader->retryAfter = '120';
        try {
            $this->uploader->upload($this->file);
            self::fail('Expected rate limit exception');
        } catch (UploadException $e) {
            self::assertSame(429, $e->httpStatus);
            self::assertSame('120', $e->retryAfter);
            self::assertSame(1, $this->uploader->calls);
        }
    }

    public function testOAuthError(): void
    {
        $this->uploader->body = 'oauth_problem=token_rejected';
        $this->expectException(\OAuth\Common\Exception\Exception::class);
        $this->uploader->upload($this->file);
    }

    public function testEmptyFileRejectedBeforeSending(): void
    {
        file_put_contents($this->file, '');
        $this->expectException(FlickrException::class);
        $this->uploader->upload($this->file);
    }

    public function testMissingAuthenticationFailsBeforeSending(): void
    {
        $this->expectException(FlickrException::class);
        $this->expectExceptionMessage('OAuth access token');
        (new Uploader(new FlickrService('app', 'secret')))->upload($this->file);
    }

    public function testCurlTransportErrorsBecomeUploadExceptions(): void
    {
        $uploader = new class(new FlickrService('app', 'secret')) extends Uploader {
            public function testTransport(): void { $this->postFile('unsupported-protocol://upload', []); }
        };
        $this->expectException(UploadException::class);
        $this->expectExceptionMessage('outcome may be unknown');
        $uploader->testTransport();
    }

    public function testInvalidSafetyLevelRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->uploader->upload($this->file, safetyLevel: 0);
    }
}

class RecordingUploader extends Uploader
{
    public string $endpoint;
    public array $args;
    public int $calls = 0;
    public string $body = '<rsp stat="ok"><photoid>12345678901234567890</photoid></rsp>';
    public int $status = 200;
    public ?string $retryAfter = null;

    protected function postFile(string $endpoint, array $args): array
    {
        $this->endpoint = $endpoint;
        $this->args = $args;
        $this->calls++;
        return [$this->body, $this->status, $this->retryAfter];
    }
}
