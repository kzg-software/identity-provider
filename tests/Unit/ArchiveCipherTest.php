<?php

namespace Tests\Unit;

use App\Services\Backup\ArchiveCipher;
use App\Services\Backup\BackupException;
use PHPUnit\Framework\TestCase;

class ArchiveCipherTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/authbak-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_it_round_trips_a_file(): void
    {
        $plain = $this->dir.'/plain.bin';
        $cipher = $this->dir.'/cipher.authbak';
        $out = $this->dir.'/out.bin';

        file_put_contents($plain, random_bytes(3 * 1024 * 1024 + 17));

        ArchiveCipher::encryptFile($plain, $cipher, 'correct horse battery staple');
        ArchiveCipher::decryptFile($cipher, $out, 'correct horse battery staple');

        $this->assertSame(hash_file('sha256', $plain), hash_file('sha256', $out));
        $this->assertNotSame(file_get_contents($plain), file_get_contents($cipher));
    }

    public function test_wrong_password_is_rejected(): void
    {
        $plain = $this->dir.'/plain.bin';
        $cipher = $this->dir.'/cipher.authbak';

        file_put_contents($plain, 'some secret payload');
        ArchiveCipher::encryptFile($plain, $cipher, 'right-password');

        $this->expectException(BackupException::class);
        ArchiveCipher::decryptFile($cipher, $this->dir.'/out.bin', 'wrong-password');
    }

    public function test_a_foreign_file_is_rejected(): void
    {
        file_put_contents($this->dir.'/foreign.bin', str_repeat('not a backup', 100));

        $this->expectException(BackupException::class);
        ArchiveCipher::decryptFile($this->dir.'/foreign.bin', $this->dir.'/out.bin', 'whatever');
    }
}
