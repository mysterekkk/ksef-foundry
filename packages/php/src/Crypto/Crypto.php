<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Crypto;

use LuroWeb\KsefFoundry\Exception\EncryptionException;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\RSA\PrivateKey;
use phpseclib3\Crypt\RSA\PublicKey as RsaPublicKey;

/**
 * KSeF cryptography: RSA-OAEP (SHA-256, MGF1-SHA-256, empty label) via phpseclib (ext-openssl OAEP is SHA-1
 * only), AES-256-CBC/PKCS#7 via ext-openssl, SHA-256 base64.
 */
final class Crypto
{
    public function rsaOaepSha256Encrypt(#[\SensitiveParameter] string $plain, PublicKey|string $key): string
    {
        $pem = $key instanceof PublicKey ? $key->pem : $key;
        try {
            $loaded = PublicKeyLoader::load($pem);
            if (!$loaded instanceof RsaPublicKey) {
                throw new EncryptionException('KSeF public key is not an RSA key.');
            }
            $padded = $loaded->withPadding(RSA::ENCRYPTION_OAEP);
            if (!$padded instanceof RsaPublicKey) {
                throw new EncryptionException('Unexpected phpseclib key type.');
            }
            $hashed = $padded->withHash('sha256');
            if (!$hashed instanceof RsaPublicKey) {
                throw new EncryptionException('Unexpected phpseclib key type.');
            }
            $mgf = $hashed->withMGFHash('sha256');
            if (!$mgf instanceof RsaPublicKey) {
                throw new EncryptionException('Unexpected phpseclib key type.');
            }
            $out = $mgf->encrypt($plain);
            if (!is_string($out)) {
                throw new EncryptionException('RSA-OAEP encryption returned no data.');
            }
        } catch (EncryptionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Never chain the phpseclib exception: its trace arguments contain the key material.
            throw new EncryptionException('RSA-OAEP encryption failed: ' . $e->getMessage());
        }

        return $out;
    }

    /** Inverse of rsaOaepSha256Encrypt; used by round-trip tests and diagnostics, never by production flows. */
    public function rsaOaepSha256Decrypt(#[\SensitiveParameter] string $cipher, #[\SensitiveParameter] string $privateKeyPem): string
    {
        try {
            $loaded = PublicKeyLoader::load($privateKeyPem);
            if (!$loaded instanceof PrivateKey) {
                throw new EncryptionException('Not an RSA private key.');
            }
            $padded = $loaded->withPadding(RSA::ENCRYPTION_OAEP);
            if (!$padded instanceof PrivateKey) {
                throw new EncryptionException('Unexpected phpseclib key type.');
            }
            $hashed = $padded->withHash('sha256');
            if (!$hashed instanceof PrivateKey) {
                throw new EncryptionException('Unexpected phpseclib key type.');
            }
            $mgf = $hashed->withMGFHash('sha256');
            if (!$mgf instanceof PrivateKey) {
                throw new EncryptionException('Unexpected phpseclib key type.');
            }
            $out = $mgf->decrypt($cipher);
            if (!is_string($out)) {
                throw new EncryptionException('RSA-OAEP decryption returned no data.');
            }
        } catch (EncryptionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new EncryptionException('RSA-OAEP decryption failed.');
        }

        return $out;
    }

    public function aes256CbcEncrypt(#[\SensitiveParameter] string $plain, #[\SensitiveParameter] SessionKey $key): string
    {
        $out = openssl_encrypt($plain, 'aes-256-cbc', $key->key(), OPENSSL_RAW_DATA, $key->iv());
        if ($out === false) {
            throw new EncryptionException('AES-256-CBC encryption failed.');
        }

        return $out;
    }

    public function aes256CbcDecrypt(#[\SensitiveParameter] string $cipher, #[\SensitiveParameter] SessionKey $key): string
    {
        $out = openssl_decrypt($cipher, 'aes-256-cbc', $key->key(), OPENSSL_RAW_DATA, $key->iv());
        if ($out === false) {
            throw new EncryptionException('AES-256-CBC decryption failed.');
        }

        return $out;
    }

    public function sha256Base64(string $bytes): string
    {
        return base64_encode(hash('sha256', $bytes, true));
    }

    public function encryptInvoice(#[\SensitiveParameter] string $plain, #[\SensitiveParameter] SessionKey $key): EncryptedInvoice
    {
        if ($plain === '') {
            throw new EncryptionException('Invoice content must not be empty.');
        }
        $cipher = $this->aes256CbcEncrypt($plain, $key);

        return new EncryptedInvoice(
            $this->sha256Base64($plain),
            strlen($plain),
            $this->sha256Base64($cipher),
            strlen($cipher),
            base64_encode($cipher),
        );
    }

    /**
     * Value for POST /auth/ksef-token: base64(RSA-OAEP-SHA256("{token}|{timestampMs}")).
     */
    public function encryptKsefToken(#[\SensitiveParameter] string $token, int $timestampMs, PublicKey $key): string
    {
        return base64_encode($this->rsaOaepSha256Encrypt($token . '|' . $timestampMs, $key));
    }

    /** base64(RSA-OAEP-SHA256(session key)) for OpenOnlineSessionRequest.encryption.encryptedSymmetricKey. */
    public function encryptSessionKey(#[\SensitiveParameter] SessionKey $key, PublicKey $publicKey): string
    {
        return base64_encode($this->rsaOaepSha256Encrypt($key->key(), $publicKey));
    }
}
