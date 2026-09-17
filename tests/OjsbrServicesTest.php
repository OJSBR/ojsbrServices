<?php

/**
 * @file plugins/generic/ojsbrServices/tests/OjsbrServicesTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class OjsbrServicesTest
 *
 * @brief What a signed request from the connector has to carry to be accepted,
 *        the key material the pin is read from, and the requests the plugin
 *        makes — through the application's HTTP client, never a curl handle.
 */

namespace APP\plugins\generic\ojsbrServices\tests;

use APP\plugins\generic\ojsbrServices\classes\OjsbrHttp;
use APP\plugins\generic\ojsbrServices\classes\OjsbrSignature;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\core\Registry;
use PKP\tests\PKPTestCase;

#[CoversClass(OjsbrSignature::class)]
#[CoversClass(OjsbrHttp::class)]
class OjsbrServicesTest extends PKPTestCase
{
    /** An Ed25519 pair of this run: the private half never leaves the test. */
    private string $publicKey;
    private string $privateKey;

    /**
     * An Ed25519 pair for the checks that need one. The plugin requires
     * ext-sodium; where it is missing there is nothing to verify signatures
     * with, and only these checks are skipped.
     */
    protected function requireKeys(): void
    {
        if (!extension_loaded('sodium')) {
            $this->markTestSkipped('ext-sodium is not loaded');
        }
        $pair = sodium_crypto_sign_keypair();
        $this->publicKey = sodium_crypto_sign_publickey($pair);
        $this->privateKey = sodium_crypto_sign_secretkey($pair);
    }

    /**
     * The signature the connector sends: base64 of Ed25519 over
     * timestamp + "\n" + sha256 of the body.
     */
    protected function sign(string $timestamp, string $body, ?string $privateKey = null): string
    {
        return base64_encode(sodium_crypto_sign_detached(
            $timestamp . "\n" . hash('sha256', $body),
            $privateKey ?? $this->privateKey
        ));
    }

    /** The requests the mocked client was asked to make, in order. */
    private array $requests = [];

    /**
     * Answers the plugin's requests with the given responses and records them.
     *
     * @param Response[] $responses
     */
    protected function mockClient(array $responses): void
    {
        $this->requests = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(\GuzzleHttp\Middleware::history($this->requests));
        $client = new Client(['handler' => $stack]);
        // The second argument is taken by reference: it has to be a variable.
        Registry::set(PKPTestCase::MOCKED_GUZZLE_CLIENT_NAME, $client);
    }

    public function testASignedRequestIsAcceptedAndAnythingElseIsNot(): void
    {
        $this->requireKeys();
        $body = '{"ordemId":"os-1","status":"done"}';
        $timestamp = (string) time();
        $keys = [base64_encode($this->publicKey)];

        $this->assertTrue(OjsbrSignature::verify($timestamp, $this->sign($timestamp, $body), $body, $keys));

        // A body that changed on the way.
        $this->assertFalse(OjsbrSignature::verify($timestamp, $this->sign($timestamp, $body), $body . ' ', $keys));
        // Another key's signature.
        $otherPair = sodium_crypto_sign_keypair();
        $this->assertFalse(OjsbrSignature::verify($timestamp, $this->sign($timestamp, $body, sodium_crypto_sign_secretkey($otherPair)), $body, $keys));
        // Nothing at all, or something that is not a signature.
        $this->assertFalse(OjsbrSignature::verify($timestamp, null, $body, $keys));
        $this->assertFalse(OjsbrSignature::verify($timestamp, '', $body, $keys));
        $this->assertFalse(OjsbrSignature::verify($timestamp, 'not base64 at all!!', $body, $keys));
        // No key to check against.
        $this->assertFalse(OjsbrSignature::verify($timestamp, $this->sign($timestamp, $body), $body, []));
    }

    public function testAnOldOrImpossibleTimestampIsRefused(): void
    {
        $this->requireKeys();
        $body = '{}';
        $keys = [base64_encode($this->publicKey)];

        $old = (string) (time() - OjsbrSignature::SKEW_SECONDS - 60);
        $this->assertFalse(OjsbrSignature::verify($old, $this->sign($old, $body), $body, $keys));

        $ahead = (string) (time() + OjsbrSignature::SKEW_SECONDS + 60);
        $this->assertFalse(OjsbrSignature::verify($ahead, $this->sign($ahead, $body), $body, $keys));

        // Inside the window, on both sides.
        $recent = (string) (time() - OjsbrSignature::SKEW_SECONDS + 30);
        $this->assertTrue(OjsbrSignature::verify($recent, $this->sign($recent, $body), $body, $keys));

        $this->assertFalse(OjsbrSignature::verify('yesterday', $this->sign('yesterday', $body), $body, $keys));
        $this->assertFalse(OjsbrSignature::verify(null, $this->sign('1', $body), $body, $keys));
    }

    public function testThePinIsReadFromPemBase64HexOrRawAndNeverFromThePlaceholder(): void
    {
        $this->requireKeys();
        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode(hex2bin('302a300506032b6570032100') . $this->publicKey), 64, "\n")
            . "-----END PUBLIC KEY-----\n";

        $this->assertSame($this->publicKey, OjsbrSignature::extractPublicKey($pem));
        $this->assertSame($this->publicKey, OjsbrSignature::extractPublicKey(base64_encode($this->publicKey)));
        $this->assertSame($this->publicKey, OjsbrSignature::extractPublicKey(bin2hex($this->publicKey)));
        $this->assertSame($this->publicKey, OjsbrSignature::extractPublicKey($this->publicKey));

        $this->assertNull(OjsbrSignature::extractPublicKey(''));
        $this->assertNull(OjsbrSignature::extractPublicKey('   '));
        $this->assertNull(OjsbrSignature::extractPublicKey('too short'));
        // The key shipped in the repository is a placeholder, never a key.
        $this->assertNull(OjsbrSignature::extractPublicKey('PIN-PLACEHOLDER'));
        $shipped = (string) file_get_contents(dirname(__DIR__) . '/keys/ojsbr.pub');
        $this->assertNotSame('', trim($shipped), 'the repository ships a pin file');
    }

    /**
     * A raw key whose first or last byte is one that trim() takes away — a space,
     * a tab, a line break or a NUL — is still that key. About one key in forty
     * begins or ends with one of them, and a pin refused for that reason would
     * look like a wrong signature and nothing else.
     */
    public function testARawKeyThatBeginsOrEndsWithAByteTrimWouldEatIsStillRead(): void
    {
        $this->requireKeys();
        $middle = substr($this->publicKey, 1, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES - 2);

        foreach ([' ', "\t", "\n", "\r", "\x0B", "\0"] as $byte) {
            $key = $byte . $middle . $byte;
            $this->assertSame(
                SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES,
                strlen($key),
                'the key of the test is of the right length'
            );
            $this->assertSame(
                $key,
                OjsbrSignature::extractPublicKey($key),
                'a raw key around the byte ' . bin2hex($byte) . ' was not read'
            );
        }
    }

    public function testTheProofOfTokenIsTheHmacOfTheNonce(): void
    {
        $this->assertSame(
            base64_encode(hash_hmac('sha256', 'nonce-1', 'token-1', true)),
            OjsbrSignature::hmacToken('token-1', 'nonce-1')
        );
        $this->assertNotSame(
            OjsbrSignature::hmacToken('token-1', 'nonce-1'),
            OjsbrSignature::hmacToken('token-2', 'nonce-1')
        );
    }

    public function testJsonGoesOutWithTheTokenAndTheAnswerComesBackWhole(): void
    {
        $this->mockClient([
            new Response(201, ['X-OJSBR-Timestamp' => '123'], '{"ordemId":"os-9"}'),
        ]);

        $result = OjsbrHttp::postJson('https://connector.example.org/os', ['submissionId' => 7], 'token-1');

        $this->assertSame(201, $result['status']);
        $this->assertSame('{"ordemId":"os-9"}', $result['body']);
        $this->assertSame('123', $result['headers']['X-OJSBR-Timestamp']);

        $this->assertCount(1, $this->requests);
        $request = $this->requests[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://connector.example.org/os', (string) $request->getUri());
        $this->assertSame('Bearer token-1', $request->getHeaderLine('Authorization'));
        $this->assertSame('token-1', $request->getHeaderLine('X-OJSBR-Token'));
        $this->assertSame('{"submissionId":7}', (string) $request->getBody());
    }

    public function testFilesGoOutAsOneMultipartRequestWithTheirRoles(): void
    {
        $this->mockClient([new Response(200, [], '{"ok":true}')]);

        OjsbrHttp::postFiles('https://connector.example.org/files', 'token-2', [
            ['role' => 'manuscript', 'fileName' => "paper\"\r\n.docx", 'contents' => 'DOCX', 'locale' => 'pt_BR'],
            ['role' => 'galley', 'fileName' => 'paper.pdf', 'contents' => 'PDF', 'galleyId' => '12'],
        ]);

        $this->assertCount(1, $this->requests);
        $request = $this->requests[0]['request'];
        $this->assertStringStartsWith('multipart/form-data', $request->getHeaderLine('Content-Type'));
        $body = (string) $request->getBody();
        $this->assertStringContainsString('name="role"', $body);
        $this->assertStringContainsString('manuscript', $body);
        $this->assertStringContainsString('name="galleyId"', $body);
        $this->assertStringContainsString('name="locale"', $body);
        $this->assertStringContainsString('DOCX', $body);
        $this->assertStringContainsString('PDF', $body);
        // A quote or a line break in the name would break the part header.
        $this->assertStringContainsString('filename="paper.docx"', $body);
    }

    public function testAConnectorThatCannotBeReachedIsAnAnswerOfItsOwn(): void
    {
        $this->mockClient([new \GuzzleHttp\Exception\ConnectException(
            'Connection refused',
            new \GuzzleHttp\Psr7\Request('GET', 'https://connector.example.org/status')
        )]);

        $result = OjsbrHttp::get('https://connector.example.org/status', 'token-3');

        $this->assertSame(0, $result['status']);
        $this->assertSame('', $result['body']);
        $this->assertSame([], $result['headers']);
    }

    public function testTheRequestsGoThroughTheApplicationClient(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/classes/OjsbrHttp.php');
        $this->assertStringContainsString('Application::get()->getHttpClient()', $source);
        $this->assertStringNotContainsString('curl_', $source);
        // An error status is the caller's business, and a redirect is never followed.
        $this->assertStringContainsString("'http_errors' => false", $source);
        $this->assertStringContainsString("'allow_redirects' => false", $source);
    }
}
