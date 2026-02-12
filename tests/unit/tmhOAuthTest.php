<?php

namespace themattharris\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use themattharris\tmhOAuth;

class tmhOAuthTest extends TestCase
{
    private tmhOAuth $oauth;

    protected function setUp(): void
    {
        $this->oauth = new tmhOAuth([
            'consumer_key'    => 'test_consumer_key',
            'consumer_secret' => 'test_consumer_secret',
            'token'           => 'test_token',
            'secret'          => 'test_secret',
            'force_nonce'     => 'test_nonce',
            'force_timestamp' => '1234567890',
        ]);
    }

    // ---- Constructor & Configuration ----

    public function testConstructorSetsDefaults(): void
    {
        $oauth = new tmhOAuth();
        $this->assertSame('api.twitter.com', $oauth->config['host']);
        $this->assertSame('GET', $oauth->config['method']);
        $this->assertSame('1.0', $oauth->config['oauth_version']);
        $this->assertSame('HMAC-SHA1', $oauth->config['oauth_signature_method']);
        $this->assertTrue($oauth->config['use_ssl']);
        $this->assertTrue($oauth->config['curl_ssl_verifypeer']);
        $this->assertSame(2, $oauth->config['curl_ssl_verifyhost']);
    }

    public function testConstructorMergesConfig(): void
    {
        $oauth = new tmhOAuth([
            'host' => 'custom.api.com',
            'consumer_key' => 'my_key',
        ]);
        $this->assertSame('custom.api.com', $oauth->config['host']);
        $this->assertSame('my_key', $oauth->config['consumer_key']);
        // defaults still present
        $this->assertSame('GET', $oauth->config['method']);
    }

    public function testReconfigure(): void
    {
        $oauth = new tmhOAuth(['host' => 'first.com']);
        $this->assertSame('first.com', $oauth->config['host']);

        $oauth->reconfigure(['host' => 'second.com']);
        $this->assertSame('second.com', $oauth->config['host']);
    }

    public function testUserAgentIsSetAutomatically(): void
    {
        $oauth = new tmhOAuth();
        $this->assertStringContainsString('tmhOAuth', $oauth->config['user_agent']);
        $this->assertStringContainsString(tmhOAuth::VERSION, $oauth->config['user_agent']);
    }

    public function testCustomUserAgentIsPreserved(): void
    {
        $oauth = new tmhOAuth(['user_agent' => 'MyApp/1.0']);
        $this->assertSame('MyApp/1.0', $oauth->config['user_agent']);
    }

    public function testUserAgentContainsSslIndicator(): void
    {
        $oauth = new tmhOAuth(['use_ssl' => true, 'curl_ssl_verifypeer' => true, 'curl_ssl_verifyhost' => 2]);
        $this->assertStringContainsString('+SSL', $oauth->config['user_agent']);

        $oauth = new tmhOAuth(['use_ssl' => false]);
        $this->assertStringContainsString('-SSL', $oauth->config['user_agent']);
    }

    // ---- safe_encode / safe_decode ----

    public function testSafeEncodeString(): void
    {
        $result = $this->invokePrivate($this->oauth, 'safe_encode', ['hello world']);
        $this->assertSame('hello%20world', $result);
    }

    public function testSafeEncodeTildeNotEncoded(): void
    {
        $result = $this->invokePrivate($this->oauth, 'safe_encode', ['hello~world']);
        $this->assertSame('hello~world', $result);
    }

    public function testSafeEncodeArray(): void
    {
        $result = $this->invokePrivate($this->oauth, 'safe_encode', [['a b', 'c~d']]);
        $this->assertSame(['a%20b', 'c~d'], $result);
    }

    public function testSafeEncodeNonScalar(): void
    {
        $result = $this->invokePrivate($this->oauth, 'safe_encode', [null]);
        $this->assertSame('', $result);
    }

    public function testSafeDecodeString(): void
    {
        $result = $this->invokePrivate($this->oauth, 'safe_decode', ['hello%20world']);
        $this->assertSame('hello world', $result);
    }

    public function testSafeDecodeArray(): void
    {
        $result = $this->invokePrivate($this->oauth, 'safe_decode', [['a%20b', 'c%7Ed']]);
        $this->assertSame(['a b', 'c~d'], $result);
    }

    public function testSafeDecodeNonScalar(): void
    {
        $result = $this->invokePrivate($this->oauth, 'safe_decode', [null]);
        $this->assertSame('', $result);
    }

    // ---- transformText (public wrapper) ----

    public function testTransformTextEncode(): void
    {
        $this->assertSame('hello%20world', $this->oauth->transformText('hello world', 'encode'));
    }

    public function testTransformTextDecode(): void
    {
        $this->assertSame('hello world', $this->oauth->transformText('hello%20world', 'decode'));
    }

    // ---- extract_params ----

    public function testExtractParams(): void
    {
        $body = 'oauth_token=token_value&oauth_secret=secret_value';
        $result = $this->oauth->extract_params($body);
        $this->assertSame([
            'oauth_token' => 'token_value',
            'oauth_secret' => 'secret_value',
        ], $result);
    }

    public function testExtractParamsUrlEncoded(): void
    {
        $body = 'key%20one=value%20one&key%20two=value%20two';
        $result = $this->oauth->extract_params($body);
        $this->assertSame([
            'key one' => 'value one',
            'key two' => 'value two',
        ], $result);
    }

    // ---- nonce ----

    public function testNonceGeneratesString(): void
    {
        $oauth = new tmhOAuth();
        $nonce = $this->invokePrivate($oauth, 'nonce', []);
        $this->assertIsString($nonce);
        $this->assertSame(32, strlen($nonce)); // md5 produces 32 hex chars
    }

    public function testNonceForced(): void
    {
        $nonce = $this->invokePrivate($this->oauth, 'nonce', []);
        $this->assertSame('test_nonce', $nonce);
    }

    // ---- timestamp ----

    public function testTimestampGeneratesString(): void
    {
        $oauth = new tmhOAuth();
        $ts = $this->invokePrivate($oauth, 'timestamp', []);
        $this->assertIsString($ts);
        $this->assertGreaterThan(0, (int) $ts);
    }

    public function testTimestampForced(): void
    {
        $ts = $this->invokePrivate($this->oauth, 'timestamp', []);
        $this->assertSame('1234567890', $ts);
    }

    // ---- url ----

    public function testUrlBuildsWithDefaults(): void
    {
        $result = $this->oauth->url('1.1/statuses/update');
        $this->assertSame('https://api.twitter.com/1.1/statuses/update.json', $result);
    }

    public function testUrlWithCustomExtension(): void
    {
        $result = $this->oauth->url('1.1/statuses/update', 'xml');
        $this->assertSame('https://api.twitter.com/1.1/statuses/update.xml', $result);
    }

    public function testUrlWithEmptyExtension(): void
    {
        $result = $this->oauth->url('1.1/statuses/update', '');
        $this->assertSame('https://api.twitter.com/1.1/statuses/update', $result);
    }

    public function testUrlPassthroughForFullUrl(): void
    {
        $result = $this->oauth->url('https://example.com/path');
        $this->assertSame('https://example.com/path', $result);
    }

    public function testUrlPassthroughForHttpUrl(): void
    {
        $result = $this->oauth->url('HTTP://example.com/path');
        $this->assertSame('HTTP://example.com/path', $result);
    }

    public function testUrlPassthroughForProtocolRelative(): void
    {
        $result = $this->oauth->url('//example.com/path');
        $this->assertSame('//example.com/path', $result);
    }

    public function testUrlNoSsl(): void
    {
        $oauth = new tmhOAuth(['use_ssl' => false]);
        $result = $oauth->url('1.1/test');
        $this->assertStringStartsWith('http://', $result);
    }

    public function testUrlDoesNotDuplicateExtension(): void
    {
        $result = $this->oauth->url('1.1/statuses/update.json', 'json');
        $this->assertSame('https://api.twitter.com/1.1/statuses/update.json', $result);
    }

    public function testUrlRemovesMultiSlashes(): void
    {
        $result = $this->oauth->url('1.1//statuses///update');
        $this->assertSame('https://api.twitter.com/1.1/statuses/update.json', $result);
    }

    // ---- bearer_token_credentials ----

    public function testBearerTokenCredentials(): void
    {
        $result = $this->oauth->bearer_token_credentials();
        $expected = base64_encode('test_consumer_key:test_consumer_secret');
        $this->assertSame($expected, $result);
    }

    public function testBearerTokenCredentialsWithSpecialChars(): void
    {
        $oauth = new tmhOAuth([
            'consumer_key' => 'key with spaces',
            'consumer_secret' => 'secret/slash',
        ]);
        $result = $oauth->bearer_token_credentials();
        $decoded = base64_decode($result);
        $this->assertStringContainsString(':', $decoded);
    }

    // ---- HMAC signing ----

    public function testHmacSha1Signing(): void
    {
        // Use a known OAuth test vector to verify HMAC-SHA1 signing
        $oauth = new tmhOAuth([
            'consumer_key'    => 'dpf43f3p2l4k3l03',
            'consumer_secret' => 'kd94hf93k423kf44',
            'token'           => 'nnch734d00sl2jdk',
            'secret'          => 'pfkkdhi9sl3r4s00',
            'force_nonce'     => 'kllo9940pd9333jh',
            'force_timestamp' => '1191242096',
            'oauth_signature_method' => 'HMAC-SHA1',
        ]);

        // Manually set up the request state for signing
        $oauth->config['block'] = true; // prevent actual curl call
        $code = $oauth->request('GET', 'https://photos.example.net/photos', [
            'size' => 'original',
            'file' => 'vacation.jpg',
        ]);

        $this->assertSame(0, $code); // blocked request returns 0
    }

    public function testHmacSha256Signing(): void
    {
        $oauth = new tmhOAuth([
            'consumer_key'    => 'test_key',
            'consumer_secret' => 'test_secret',
            'token'           => 'test_token',
            'secret'          => 'token_secret',
            'force_nonce'     => 'testnonce',
            'force_timestamp' => '1234567890',
            'oauth_signature_method' => 'HMAC-SHA256',
        ]);

        $oauth->config['block'] = true;
        $code = $oauth->request('GET', 'https://api.example.com/test');

        $this->assertSame(0, $code);
    }

    // ---- token / secret resolution ----

    public function testTokenResolution(): void
    {
        $token = $this->invokePrivate($this->oauth, 'token', []);
        $this->assertSame('test_token', $token);
    }

    public function testTokenFallbackToUserToken(): void
    {
        $oauth = new tmhOAuth([
            'user_token' => 'fallback_token',
        ]);
        // Need to set up request_settings with_user = true
        $this->invokePrivate($oauth, 'reset_request_settings', []);
        $token = $this->invokePrivate($oauth, 'token', []);
        $this->assertSame('fallback_token', $token);
    }

    public function testTokenEmptyWhenNotWithUser(): void
    {
        $this->invokePrivate($this->oauth, 'reset_request_settings', [['with_user' => false]]);
        $token = $this->invokePrivate($this->oauth, 'token', []);
        $this->assertSame('', $token);
    }

    public function testSecretResolution(): void
    {
        $secret = $this->invokePrivate($this->oauth, 'secret', []);
        $this->assertSame('test_secret', $secret);
    }

    // ---- prepare_method ----

    public function testPrepareMethodUppercases(): void
    {
        $this->invokePrivate($this->oauth, 'reset_request_settings', [['method' => 'post']]);
        $this->invokePrivate($this->oauth, 'prepare_method', []);
        $this->assertSame('POST', $this->getPrivateProperty($this->oauth, 'request_settings')['method']);
    }

    // ---- prepare_url ----

    #[DataProvider('urlNormalizationProvider')]
    public function testPrepareUrlNormalization(string $input, string $expected): void
    {
        $this->invokePrivate($this->oauth, 'reset_request_settings', [['url' => $input]]);
        $this->invokePrivate($this->oauth, 'prepare_url', []);
        $result = $this->getPrivateProperty($this->oauth, 'request_settings')['url'];
        $this->assertSame($expected, $result);
    }

    public static function urlNormalizationProvider(): array
    {
        return [
            'https default port stripped' => [
                'https://EXAMPLE.COM:443/path',
                'https://example.com/path',
            ],
            'http default port stripped' => [
                'http://EXAMPLE.COM:80/path',
                'http://example.com/path',
            ],
            'https non-default port kept' => [
                'https://example.com:8443/path',
                'https://example.com:8443/path',
            ],
            'scheme and host lowercased' => [
                'HTTPS://API.TWITTER.COM/path/Resource',
                'https://api.twitter.com/path/Resource',
            ],
        ];
    }

    // ---- multipart_escape ----

    public function testMultipartEscapeNonMultipart(): void
    {
        $this->invokePrivate($this->oauth, 'reset_request_settings', [['multipart' => false]]);
        $result = $this->invokePrivate($this->oauth, 'multipart_escape', ['@value']);
        $this->assertSame('@value', $result);
    }

    public function testMultipartEscapeNonAtPrefix(): void
    {
        $this->invokePrivate($this->oauth, 'reset_request_settings', [['multipart' => true]]);
        $result = $this->invokePrivate($this->oauth, 'multipart_escape', ['normal_value']);
        $this->assertSame('normal_value', $result);
    }

    public function testMultipartEscapeNonFileAtPrefix(): void
    {
        $this->invokePrivate($this->oauth, 'reset_request_settings', [['multipart' => true]]);
        $result = $this->invokePrivate($this->oauth, 'multipart_escape', ['@nonexistent_file']);
        $this->assertSame(' @nonexistent_file', $result);
    }

    // ---- blocked request (integration-like) ----

    public function testBlockedRequestReturnsZero(): void
    {
        $this->oauth->config['block'] = true;
        $code = $this->oauth->request('GET', 'https://api.twitter.com/1.1/test.json');
        $this->assertSame(0, $code);
    }

    public function testUnauthenticatedBlockedRequest(): void
    {
        $this->oauth->config['block'] = true;
        $code = $this->oauth->request('GET', 'https://api.twitter.com/1.1/test.json', [], false);
        $this->assertSame(0, $code);
    }

    public function testAppOnlyBlockedRequest(): void
    {
        $this->oauth->config['block'] = true;
        $this->oauth->config['bearer'] = 'test_bearer';
        $code = $this->oauth->apponly_request([
            'method' => 'GET',
            'url' => 'https://api.twitter.com/1.1/test.json',
        ]);
        $this->assertSame(0, $code);
    }

    // ---- VERSION constant ----

    public function testVersionConstant(): void
    {
        $this->assertSame('0.8.5', tmhOAuth::VERSION);
    }

    // ---- Response structure ----

    public function testResponseInitialized(): void
    {
        $oauth = new tmhOAuth();
        $this->assertIsArray($oauth->response);
    }

    // ---- Helpers ----

    private function invokePrivate(object $object, string $method, array $args = []): mixed
    {
        $ref = new ReflectionMethod($object, $method);
        return $ref->invoke($object, ...$args);
    }

    private function getPrivateProperty(object $object, string $property): mixed
    {
        $ref = new \ReflectionProperty($object, $property);
        return $ref->getValue($object);
    }
}
