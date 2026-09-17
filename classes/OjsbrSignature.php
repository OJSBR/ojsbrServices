<?php

/**
 * @file plugins/generic/ojsbrServices/classes/OjsbrSignature.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @brief Verifies X-OJSBR-Timestamp and X-OJSBR-Signature (Ed25519).
 *
 * The plugin never signs anything with the OJSBR private key: that key does not
 * exist here. The message signed is timestamp + "\n" + sha256 of the body in
 * hexadecimal, and the signature travels as base64.
 */

namespace APP\plugins\generic\ojsbrServices\classes;

use APP\core\Request;

class OjsbrSignature
{
    public const HEADER_TIMESTAMP = 'X-OJSBR-Timestamp';
    public const HEADER_SIGNATURE = 'X-OJSBR-Signature';
    public const SKEW_SECONDS = 300;

    /**
     * Reads the timestamp and the signature of a request made to the plugin.
     *
     * @return array{timestamp:?string,signature:?string}
     */
    public static function fromRequest(Request $request): array
    {
        return [
            'timestamp' => self::header('HTTP_X_OJSBR_TIMESTAMP'),
            'signature' => self::header('HTTP_X_OJSBR_SIGNATURE'),
        ];
    }

    /**
     * One header of the request being answered. The core reads its own headers
     * the same way (PKPRequest::getUserAgent), and a header is the only place
     * these are taken from: a parameter is not a header.
     */
    private static function header(string $key): ?string
    {
        $value = trim((string) ($_SERVER[$key] ?? ''));
        return $value === '' ? null : $value;
    }

    /**
     * Reads the timestamp and the signature of an answer from the connector.
     *
     * @param array<string,string|string[]> $headers
     * @return array{timestamp:?string,signature:?string}
     */
    public static function fromHeaders(array $headers): array
    {
        return [
            'timestamp' => self::headerValue($headers, self::HEADER_TIMESTAMP),
            'signature' => self::headerValue($headers, self::HEADER_SIGNATURE),
        ];
    }

    /**
     * @param string[] $publicPems  PEMs ou material Ed25519 (32 bytes / base64 / hex)
     */
    public static function verify(?string $timestamp, ?string $signatureB64, string $body, array $publicPems): bool
    {
        // Without ext-sodium nothing can be verified, so nothing is accepted:
        // the installation refuses every signed request instead of failing with
        // an undefined constant in the middle of the answer.
        if (!extension_loaded('sodium')) {
            error_log('OJSBR Services: ext-sodium is not loaded; signed requests cannot be verified.');
            return false;
        }
        if ($timestamp === null || $timestamp === '' || $signatureB64 === null || $signatureB64 === '') {
            return false;
        }
        if (!ctype_digit((string) $timestamp)) {
            return false;
        }
        if (abs(time() - (int) $timestamp) > self::SKEW_SECONDS) {
            return false;
        }

        $signature = base64_decode($signatureB64, true);
        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        $message = $timestamp . "\n" . hash('sha256', $body);

        foreach ($publicPems as $pem) {
            $publicKey = self::extractPublicKey((string) $pem);
            if ($publicKey === null) {
                continue;
            }
            try {
                if (sodium_crypto_sign_verify_detached($signature, $message, $publicKey)) {
                    return true;
                }
            } catch (\SodiumException) {
                continue;
            }
        }

        return false;
    }

    /**
     * HMAC-SHA256 of the nonce with the plugin's token, in base64: the proof
     * that this installation holds the token. Answers to the heartbeat and to a
     * key rotation carry it; no Ed25519 is involved.
     */
    public static function hmacToken(string $pluginToken, string $nonce): string
    {
        return base64_encode(hash_hmac('sha256', $nonce, $pluginToken, true));
    }

    /**
     * The 32 bytes of an Ed25519 public key, from SPKI PEM, base64, hexadecimal
     * or raw material.
     */
    public static function extractPublicKey(string $material): ?string
    {
        if (!extension_loaded('sodium')) {
            return null;
        }

        // The key may come as text (PEM, base64, hex) or as the 32 raw bytes.
        // Only the text forms may be trimmed: a raw key whose first or last byte
        // is 0x20, 0x09, 0x0a, 0x0d, 0x0b or 0x00 would lose it and be refused,
        // which is one key in about forty and impossible to see from outside.
        $raw = $material;
        $material = trim($material);
        if ($material === '' || str_contains($material, 'PIN-PLACEHOLDER')) {
            return null;
        }

        if (preg_match('/-----BEGIN PUBLIC KEY-----(.*)-----END PUBLIC KEY-----/s', $material, $m)) {
            $der = base64_decode(preg_replace('/\s+/', '', $m[1]) ?? '', true);
            if ($der === false || strlen($der) < SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                return null;
            }
            return substr($der, -SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
        }

        $decoded = base64_decode($material, true);
        if ($decoded !== false && strlen($decoded) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return $decoded;
        }

        if (ctype_xdigit($material) && strlen($material) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES * 2) {
            $hex = hex2bin($material);
            return $hex === false ? null : $hex;
        }

        if (strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return $raw;
        }

        return null;
    }

    /**
     * @param array<string,string|string[]> $headers
     */
    public static function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) !== 0) {
                continue;
            }
            if (is_array($value)) {
                $value = $value[0] ?? '';
            }
            $value = trim((string) $value);
            return $value === '' ? null : $value;
        }
        return null;
    }

}
