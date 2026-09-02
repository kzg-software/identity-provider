<?php

namespace App\Services\Backup;

/**
 * Verschlüsselt und entschlüsselt eine Sicherungsdatei mit einem Passwort.
 *
 * Aufbau der Datei:
 *   "AUTHBK01"                            8 Byte  Kennung
 *   Salt                                 16 Byte  für die Schlüsselableitung
 *   secretstream-Header                  24 Byte
 *   danach beliebig viele Blöcke, je:
 *     Länge (uint32, big endian)          4 Byte
 *     verschlüsselter Block               n Byte
 *
 * Der Schlüssel wird mit Argon2id aus dem Passwort abgeleitet, die Nutzdaten
 * mit XChaCha20-Poly1305 (libsodium secretstream) blockweise verschlüsselt.
 * Ein falsches Passwort oder eine veränderte Datei fällt beim Entschlüsseln
 * sofort auf.
 */
class ArchiveCipher
{
    private const MAGIC = 'AUTHBK01';

    private const CHUNK_SIZE = 1024 * 1024;

    public static function encryptFile(string $sourcePath, string $targetPath, string $password): void
    {
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $key = self::deriveKey($password, $salt);

        $in = @fopen($sourcePath, 'rb');
        $out = @fopen($targetPath, 'wb');

        if ($in === false || $out === false) {
            throw new BackupException('Die Sicherungsdatei konnte nicht geschrieben werden.');
        }

        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);

            fwrite($out, self::MAGIC);
            fwrite($out, $salt);
            fwrite($out, $header);

            while (! feof($in)) {
                $chunk = fread($in, self::CHUNK_SIZE);

                if ($chunk === false) {
                    throw new BackupException('Die Sicherung konnte nicht vollständig gelesen werden.');
                }

                if ($chunk === '') {
                    continue;
                }

                $tag = feof($in)
                    ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                    : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;

                $encrypted = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag);

                fwrite($out, pack('N', strlen($encrypted)));
                fwrite($out, $encrypted);
            }
        } finally {
            fclose($in);
            fclose($out);
            sodium_memzero($key);
        }
    }

    public static function decryptFile(string $sourcePath, string $targetPath, string $password): void
    {
        $in = @fopen($sourcePath, 'rb');
        $out = @fopen($targetPath, 'wb');

        if ($in === false || $out === false) {
            throw new BackupException('Die Sicherungsdatei konnte nicht geöffnet werden.');
        }

        try {
            if (fread($in, strlen(self::MAGIC)) !== self::MAGIC) {
                throw new BackupException('Das ist keine gültige Sicherungsdatei dieses Systems.');
            }

            $salt = fread($in, SODIUM_CRYPTO_PWHASH_SALTBYTES);
            $header = fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);

            if (strlen($salt) !== SODIUM_CRYPTO_PWHASH_SALTBYTES
                || strlen($header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
                throw new BackupException('Die Sicherungsdatei ist unvollständig oder beschädigt.');
            }

            $key = self::deriveKey($password, $salt);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);

            if ($state === false) {
                throw new BackupException('Die Sicherungsdatei konnte nicht gelesen werden.');
            }

            $sawFinal = false;

            while (! feof($in)) {
                $lengthRaw = fread($in, 4);

                if ($lengthRaw === '' || $lengthRaw === false) {
                    break;
                }

                if (strlen($lengthRaw) !== 4) {
                    throw new BackupException('Die Sicherungsdatei ist unvollständig oder beschädigt.');
                }

                $length = unpack('N', $lengthRaw)[1];
                $block = fread($in, $length);

                if ($block === false || strlen($block) !== $length) {
                    throw new BackupException('Die Sicherungsdatei ist unvollständig oder beschädigt.');
                }

                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $block);

                if ($result === false) {
                    throw new BackupException('Falsches Passwort oder beschädigte Sicherungsdatei.');
                }

                [$decrypted, $tag] = $result;
                fwrite($out, $decrypted);

                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    $sawFinal = true;
                    break;
                }
            }

            if (! $sawFinal) {
                throw new BackupException('Die Sicherungsdatei ist unvollständig oder beschädigt.');
            }
        } finally {
            fclose($in);
            fclose($out);

            if (isset($key)) {
                sodium_memzero($key);
            }
        }
    }

    private static function deriveKey(string $password, string $salt): string
    {
        return sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
            $password,
            $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );
    }
}
