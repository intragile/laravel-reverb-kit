<?php

declare(strict_types=1);

namespace IntrAgile\ReverbKit\Publishing;

use Illuminate\Contracts\Config\Repository;

/**
 * Measures a publish request before it is sent, so an oversized broadcast fails where it is
 * made instead of as a 413 from Reverb.
 *
 * Reverb compares the whole HTTP buffer (request line, query string, headers and body) with
 * max_request_size, so a fixed allowance is added to the measured body for everything but the body.
 *
 * Bodies for private-encrypted-* channels are measured as encrypted: everything but the escaped
 * slashes in the base64 output is exact, and those are covered by a bound the real count exceeds
 * with negligible probability (they depend on the random nonce, so no exact count exists up front).
 */
final class PayloadGuard
{
    private const DEFAULT_MAX_BYTES = 10000;

    private const DEFAULT_HEADER_ALLOWANCE = 1024;

    private const ENCRYPTED_PREFIX = 'private-encrypted-';

    /** SODIUM_CRYPTO_SECRETBOX_NONCEBYTES; a literal so the guard works without ext-sodium. */
    private const NONCE_BYTES = 24;

    /** SODIUM_CRYPTO_SECRETBOX_MACBYTES: the Poly1305 tag secretbox appends to the plaintext. */
    private const MAC_BYTES = 16;

    /** Standard deviations above the expected slash count reserved for encrypted bodies. */
    private const SLASH_SIGMAS = 8;

    public function __construct(
        private readonly int $maxBytes,
        private readonly int $headerAllowance,
    ) {}

    /**
     * Reads reverb-kit.publish.max_bytes, falling back to reverb.servers.{reverb-kit.server}.max_request_size
     * and then to Reverb's own default of 10000 bytes.
     */
    public static function fromConfig(Repository $config): self
    {
        $server = $config->get('reverb-kit.server');
        $server = is_string($server) && $server !== '' ? $server : 'reverb';

        $maxBytes = self::int($config->get('reverb-kit.publish.max_bytes'))
            ?? self::int($config->get("reverb.servers.{$server}.max_request_size"))
            ?? self::DEFAULT_MAX_BYTES;

        $headerAllowance = self::int($config->get('reverb-kit.publish.header_allowance'))
            ?? self::DEFAULT_HEADER_ALLOWANCE;

        return new self($maxBytes, $headerAllowance);
    }

    /**
     * The byte length of the body pusher-php-server's trigger() sends for these arguments.
     *
     * Mirrors Pusher::make_event() and make_trigger_body() (pusher-php-server 7.x): the payload is
     * JSON-encoded into the `data` string, a single channel goes under `channel` and several under
     * `channels`, and the socket id is merged in last. Both encodes use no flags other than
     * JSON_THROW_ON_ERROR, so non-ASCII characters and slashes are escaped exactly as on the wire.
     *
     * A single private-encrypted-* channel gets the `data` PusherCrypto::encrypt_payload() produces,
     * {"nonce":base64(nonce),"ciphertext":base64(plaintext + MAC)}, plus the slash allowance.
     *
     * @param  array<array-key, string>  $channels
     * @param  array<array-key, mixed>  $payload
     */
    public function measure(array $channels, string $event, array $payload, ?string $socketId = null): int
    {
        $channels = array_values($channels);

        $data = json_encode($payload, JSON_THROW_ON_ERROR);
        $slashAllowance = 0;

        if (count($channels) === 1 && str_starts_with($channels[0], self::ENCRYPTED_PREFIX)) {
            [$data, $slashAllowance] = $this->encryptedData($data);
        }

        $body = ['name' => $event, 'data' => $data];

        if (count($channels) === 1) {
            $body['channel'] = $channels[0];
        } else {
            $body['channels'] = $channels;
        }

        if ($socketId !== null) {
            $body = array_merge($body, ['socket_id' => $socketId]);
        }

        return strlen(json_encode($body, JSON_THROW_ON_ERROR)) + $slashAllowance;
    }

    /**
     * A stand-in for the encrypted `data` string with the exact length of the real one minus its
     * escaped slashes, and the bytes reserved for those slashes in the final body.
     *
     * Base64 of zero bytes is all 'A' with the real padding, so the stand-in holds no slash. Each
     * '/' in the real base64 is escaped twice, once when the message is encoded ('\/') and once
     * when it is embedded in the body ('\\\/'), so it adds 3 bytes. Encrypted output is uniformly
     * random, so each base64 character is a slash with probability 1/64; the allowance is that
     * binomial mean plus SLASH_SIGMAS standard deviations.
     *
     * @return array{string, int}
     */
    private function encryptedData(string $plaintext): array
    {
        $nonce = base64_encode(str_repeat("\0", self::NONCE_BYTES));
        $ciphertext = base64_encode(str_repeat("\0", strlen($plaintext) + self::MAC_BYTES));

        $data = json_encode(['nonce' => $nonce, 'ciphertext' => $ciphertext], JSON_THROW_ON_ERROR);

        $chars = strlen(rtrim($nonce, '=')) + strlen(rtrim($ciphertext, '='));
        $p = 1 / 64;
        $slashes = min($chars, (int) ceil($chars * $p + self::SLASH_SIGMAS * sqrt($chars * $p * (1 - $p))) + 1);

        return [$data, 3 * $slashes];
    }

    /**
     * @param  array<array-key, string>  $channels
     * @param  array<array-key, mixed>  $payload
     *
     * @throws PayloadTooLarge when the measured body plus the header allowance exceeds the limit
     */
    public function assertFits(array $channels, string $event, array $payload, ?string $socketId = null): void
    {
        $bytes = $this->measure($channels, $event, $payload, $socketId) + $this->headerAllowance;

        if ($bytes > $this->maxBytes) {
            throw new PayloadTooLarge($bytes, $this->maxBytes, $event, array_values($channels));
        }
    }

    private static function int(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && is_numeric($value)) ? (int) $value : null;
    }
}
