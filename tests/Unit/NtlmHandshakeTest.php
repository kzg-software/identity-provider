<?php

namespace Tests\Unit;

use App\Auth\NtlmHandshake;
use Tests\TestCase;

class NtlmHandshakeTest extends TestCase
{
    public function test_token_detection_and_extraction(): void
    {
        $this->assertTrue(NtlmHandshake::isNtlmOrNegotiateToken('NTLM abcd'));
        $this->assertTrue(NtlmHandshake::isNtlmOrNegotiateToken('Negotiate abcd'));
        $this->assertFalse(NtlmHandshake::isNtlmOrNegotiateToken('Basic abcd'));

        $this->assertSame('hi', NtlmHandshake::extractToken('NTLM '.base64_encode('hi')));
        $this->assertNull(NtlmHandshake::extractToken('Basic '.base64_encode('hi')));
    }

    public function test_type2_challenge_has_correct_wire_format(): void
    {
        $challenge = str_repeat("\x11", 8);
        $encoded = NtlmHandshake::buildType2Challenge($challenge, 'AUTH');
        $bytes = base64_decode($encoded);

        $this->assertSame("NTLMSSP\x00", substr($bytes, 0, 8));
        $this->assertSame(2, NtlmHandshake::getMessageType($bytes));
        $this->assertSame($challenge, substr($bytes, 24, 8));
    }

    public function test_parses_synthetic_type3_message(): void
    {
        $token = $this->buildType3Message('jdoe', 'RL');

        $parsed = NtlmHandshake::parseType3($token);

        $this->assertNotNull($parsed);
        $this->assertSame('jdoe', $parsed['username']);
        $this->assertSame('RL', $parsed['domain']);
        $this->assertSame(3, NtlmHandshake::getMessageType($token));
    }

    public function test_rejects_malformed_type3_message(): void
    {
        $this->assertNull(NtlmHandshake::parseType3('not an ntlm message'));
        $this->assertNull(NtlmHandshake::parseType3("NTLMSSP\x00".str_repeat("\x00", 4)));
    }

    /**
     * Builds a minimal, spec-shaped NTLM Type 3 (AUTHENTICATE) message with
     * only the Domain/User security buffers populated (UTF-16LE), matching
     * the offsets NtlmHandshake::parseType3 reads (28/32 domain, 36/40 user).
     */
    private function buildType3Message(string $username, string $domain): string
    {
        $domainBytes = mb_convert_encoding($domain, 'UTF-16LE', 'UTF-8');
        $userBytes = mb_convert_encoding($username, 'UTF-16LE', 'UTF-8');

        $domainOffset = 64;
        $userOffset = $domainOffset + strlen($domainBytes);

        $buf = str_repeat("\x00", $userOffset + strlen($userBytes));
        $buf = substr_replace($buf, "NTLMSSP\x00", 0, 8);
        $buf = substr_replace($buf, pack('V', 3), 8, 4);

        $buf = substr_replace($buf, pack('v', strlen($domainBytes)), 28, 2);
        $buf = substr_replace($buf, pack('v', strlen($domainBytes)), 30, 2);
        $buf = substr_replace($buf, pack('V', $domainOffset), 32, 4);

        $buf = substr_replace($buf, pack('v', strlen($userBytes)), 36, 2);
        $buf = substr_replace($buf, pack('v', strlen($userBytes)), 38, 2);
        $buf = substr_replace($buf, pack('V', $userOffset), 40, 4);

        $buf = substr_replace($buf, $domainBytes, $domainOffset, strlen($domainBytes));
        $buf = substr_replace($buf, $userBytes, $userOffset, strlen($userBytes));

        return $buf;
    }
}
