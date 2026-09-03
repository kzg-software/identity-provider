<?php

namespace App\Services\Backup;

use App\Models\SystemSetting;
use App\Support\Secret;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Das Ziel für automatische Sicherungen: ein lokales Verzeichnis, ein
 * S3-kompatibler Bucket, FTP oder SFTP. Die Konfiguration kommt aus den
 * Systemeinstellungen (Präfix "auto_backup_"); daraus wird zur Laufzeit ein
 * Flysystem-Datenträger gebaut.
 */
class BackupDestination
{
    public const TARGETS = ['local', 's3', 'ftp', 'sftp'];

    public function target(): string
    {
        $t = (string) SystemSetting::get('auto_backup_target', 'local');

        return in_array($t, self::TARGETS, true) ? $t : 'local';
    }

    public function directory(): string
    {
        return trim((string) SystemSetting::get('auto_backup_dir', ''), '/');
    }

    public function disk(): Filesystem
    {
        return Storage::build($this->diskConfig());
    }

    /**
     * @return array<string, mixed>
     */
    public function diskConfig(): array
    {
        $get = fn (string $key, $default = null) => SystemSetting::get('auto_backup_'.$key, $default);

        return match ($this->target()) {
            's3' => [
                'driver' => 's3',
                'key' => (string) $get('s3_key'),
                'secret' => Secret::decrypt($get('s3_secret')),
                'region' => (string) $get('s3_region', 'us-east-1'),
                'bucket' => (string) $get('s3_bucket'),
                'endpoint' => $get('s3_endpoint') ?: null,
                'use_path_style_endpoint' => (bool) $get('s3_path_style', false),
                'throw' => true,
            ],
            'ftp' => [
                'driver' => 'ftp',
                'host' => (string) $get('host'),
                'port' => (int) ($get('port') ?: 21),
                'username' => (string) $get('username'),
                'password' => Secret::decrypt($get('remote_password')),
                'root' => $this->directory() ?: '/',
                'ssl' => (bool) $get('ftp_ssl', false),
                'timeout' => 30,
                'throw' => true,
            ],
            'sftp' => [
                'driver' => 'sftp',
                'host' => (string) $get('host'),
                'port' => (int) ($get('port') ?: 22),
                'username' => (string) $get('username'),
                'password' => Secret::decrypt($get('remote_password')),
                'root' => $this->directory() ?: '/',
                'timeout' => 30,
                'throw' => true,
            ],
            default => [
                'driver' => 'local',
                'root' => $this->localRoot(),
                'throw' => true,
            ],
        };
    }

    public function localRoot(): string
    {
        $dir = $this->directory();

        if ($dir === '') {
            return storage_path('app/private/backups');
        }

        // Absoluter Pfad wird direkt genutzt, relativer unter storage/app/.
        return preg_match('#^([a-zA-Z]:[\\\\/]|/)#', $dir) === 1 ? $dir : storage_path('app/'.$dir);
    }

    /**
     * Legt für die S3-, FTP- und SFTP-Ziele ausschliesslich den Dateinamen ab,
     * fuer local ebenso (root ist bereits das Zielverzeichnis). Bei S3 sitzt
     * das Praefix im Key.
     */
    public function path(string $filename): string
    {
        if ($this->target() === 's3' && $this->directory() !== '') {
            return $this->directory().'/'.$filename;
        }

        return $filename;
    }

    /**
     * @return list<array{name: string, last_modified: int|null, size: int|null}>
     */
    public function existingBackups(): array
    {
        $disk = $this->disk();
        $prefix = $this->target() === 's3' ? $this->directory() : '';

        $files = collect($disk->files($prefix))
            ->filter(fn ($f) => str_ends_with($f, '.authbak'))
            ->map(fn ($f) => [
                'name' => basename($f),
                'path' => $f,
                'last_modified' => rescue(fn () => $disk->lastModified($f), null, false),
                'size' => rescue(fn () => $disk->size($f), null, false),
            ])
            ->sortByDesc('name')
            ->values()
            ->all();

        return $files;
    }
}
