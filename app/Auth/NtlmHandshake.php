<?php

namespace App\Auth;

/**
 * NTLM Type 1/2/3 wire-format helpers, ported byte-for-byte from the
 * reference implementation in auth-old/server/utils/ntlm.ts.
 *
 * IMPORTANT — this only PARSES the username/domain out of a Type 3 message.
 * It does NOT cryptographically verify the NTLM challenge/response against
 * the Domain Controller (that would require a Netlogon callback which this
 * lightweight, dependency-free implementation does not perform). A forged
 * Type 3 message with an arbitrary username will parse "successfully" here.
 * This is therefore a "trust the Windows username the browser/OS reports"
 * mechanism, not full NTLM authentication — acceptable only on a trusted
 * internal network, which is why NegotiateController additionally restricts
 * this endpoint to private/internal source IPs.
 */
class NtlmHandshake
{
    private const SIGNATURE = "NTLMSSP\x00";

    public static function generateChallenge(): string
    {
        return random_bytes(8);
    }

    public static function isNtlmOrNegotiateToken(string $authHeader): bool
    {
        return str_starts_with($authHeader, 'NTLM ') || str_starts_with($authHeader, 'Negotiate ');
    }

    public static function extractToken(string $authHeader): ?string
    {
        $b64 = match (true) {
            str_starts_with($authHeader, 'NTLM ') => substr($authHeader, 5),
            str_starts_with($authHeader, 'Negotiate ') => substr($authHeader, 10),
            default => null,
        };

        if ($b64 === null) {
            return null;
        }

        $decoded = base64_decode(trim($b64), true);

        return $decoded === false ? null : $decoded;
    }

    public static function getMessageType(string $token): ?int
    {
        if (strlen($token) < 12) {
            return null;
        }

        if (substr($token, 0, 8) !== self::SIGNATURE) {
            return null;
        }

        return self::readUInt32LE($token, 8);
    }

    public static function buildType2Challenge(string $challenge, string $targetName = 'AUTH'): string
    {
        $targetNameBytes = mb_convert_encoding($targetName, 'UTF-16LE', 'UTF-8');
        $targetNameLen = strlen($targetNameBytes);

        // NTLMSSP_NEGOTIATE_UNICODE | NTLMSSP_REQUEST_TARGET |
        // NTLMSSP_NEGOTIATE_NTLM | NTLMSSP_NEGOTIATE_TARGET_INFO
        $flags = 0x00018201;

        $msgLen = 56 + $targetNameLen;
        $buf = str_repeat("\x00", $msgLen);

        $buf = self::writeString($buf, self::SIGNATURE, 0);
        $buf = self::writeUInt32LE($buf, 2, 8);
        $buf = self::writeUInt16LE($buf, $targetNameLen, 12);
        $buf = self::writeUInt16LE($buf, $targetNameLen, 14);
        $buf = self::writeUInt32LE($buf, 56, 16);
        $buf = self::writeUInt32LE($buf, $flags, 20);
        $buf = self::writeString($buf, $challenge, 24); // 8 bytes ServerChallenge
        // bytes 32-39 reserved, 40-47 empty TargetInfo
        $buf = self::writeString($buf, $targetNameBytes, 56);

        return base64_encode($buf);
    }

    /**
     * @return array{username: string, domain: string}|null
     */
    public static function parseType3(string $token): ?array
    {
        try {
            if (strlen($token) < 12 || substr($token, 0, 8) !== self::SIGNATURE) {
                return null;
            }

            if (self::readUInt32LE($token, 8) !== 3) {
                return null;
            }

            $domainLen = self::readUInt16LE($token, 28);
            $domainOffset = self::readUInt32LE($token, 32);
            $userLen = self::readUInt16LE($token, 36);
            $userOffset = self::readUInt32LE($token, 40);

            if ($userLen === 0 || strlen($token) < $userOffset + $userLen) {
                return null;
            }

            $username = self::utf16leToUtf8(substr($token, $userOffset, $userLen));

            $domain = '';
            if ($domainLen > 0 && strlen($token) >= $domainOffset + $domainLen) {
                $domain = self::utf16leToUtf8(substr($token, $domainOffset, $domainLen));
            }

            return ['username' => $username, 'domain' => $domain];
        } catch (\Throwable) {
            return null;
        }
    }

    private static function utf16leToUtf8(string $bytes): string
    {
        $result = mb_convert_encoding($bytes, 'UTF-8', 'UTF-16LE');

        return $result === false ? '' : $result;
    }

    private static function readUInt16LE(string $data, int $offset): int
    {
        return unpack('v', substr($data, $offset, 2))[1];
    }

    private static function readUInt32LE(string $data, int $offset): int
    {
        return unpack('V', substr($data, $offset, 4))[1];
    }

    private static function writeUInt16LE(string $buf, int $value, int $offset): string
    {
        return substr_replace($buf, pack('v', $value), $offset, 2);
    }

    private static function writeUInt32LE(string $buf, int $value, int $offset): string
    {
        return substr_replace($buf, pack('V', $value), $offset, 4);
    }

    private static function writeString(string $buf, string $value, int $offset): string
    {
        return substr_replace($buf, $value, $offset, strlen($value));
    }
}
