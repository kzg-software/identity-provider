<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\SystemSetting;
use Monolog\Handler\SocketHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Level;
use Monolog\Logger;
use Throwable;

/**
 * Schiebt jeden Audit-Log-Eintrag zusätzlich an einen externen Syslog-/SIEM-
 * Empfänger (RFC 5424 über UDP oder TCP), sofern das in den Systemeinstellungen
 * aktiviert ist. Die Zustellung ist "best effort": schlägt sie fehl, wird das
 * nur lokal protokolliert und die auslösende Aktion läuft normal weiter.
 */
class AuditForwarder
{
    public static function enabled(): bool
    {
        return SystemSetting::bool('audit_forward_enabled', false)
            && filled(SystemSetting::get('audit_forward_host'));
    }

    public static function forward(AuditLog $log): void
    {
        if (! self::enabled()) {
            return;
        }

        try {
            $log->loadMissing(['user:id,username', 'application:id,name']);

            $payload = json_encode([
                'ts' => ($log->created_at ?? now())->toIso8601String(),
                'event' => $log->event,
                'user' => $log->user?->username ?? ($log->metadata['username'] ?? null),
                'user_id' => $log->user_id,
                'ip' => $log->ip_address,
                'application' => $log->application?->name,
                'application_id' => $log->application_id,
                'metadata' => $log->metadata ?: null,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $logger = new Logger('audit');
            $logger->pushHandler(self::handler());
            $logger->info($payload ?: $log->event);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private static function handler()
    {
        $host = (string) SystemSetting::get('audit_forward_host');
        $port = (int) (SystemSetting::get('audit_forward_port') ?: 514);
        $protocol = SystemSetting::get('audit_forward_protocol') === 'tcp' ? 'tcp' : 'udp';

        if ($protocol === 'tcp') {
            $handler = new SocketHandler("tcp://{$host}:{$port}", Level::Info);
            $handler->setConnectionTimeout(2.0);
            $handler->setTimeout(2.0);
            $handler->setPersistent(false);

            return $handler;
        }

        return new SyslogUdpHandler($host, $port, LOG_USER, Level::Info);
    }
}
