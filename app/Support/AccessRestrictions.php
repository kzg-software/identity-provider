<?php

namespace App\Support;

use App\Models\Application;
use Carbon\CarbonInterface;
use Symfony\Component\HttpFoundation\IpUtils;

class AccessRestrictions
{
    private const DAYS = ['mo' => 1, 'di' => 2, 'mi' => 3, 'do' => 4, 'fr' => 5, 'sa' => 6, 'so' => 7];

    /**
     * @return array<int, string>
     */
    public static function parseIpRanges(?string $value): array
    {
        $entries = preg_split('/[\s,;]+/', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique($entries));
    }

    public static function isValidIpRange(string $entry): bool
    {
        [$ip, $prefix] = array_pad(explode('/', $entry, 2), 2, null);

        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        if ($prefix === null) {
            return true;
        }

        $max = str_contains($ip, ':') ? 128 : 32;

        return ctype_digit($prefix) && (int) $prefix <= $max;
    }

    public static function ipAllowed(?string $ip, ?string $ranges): bool
    {
        $entries = self::parseIpRanges($ranges);

        if ($entries === []) {
            return true;
        }

        return $ip !== null && IpUtils::checkIp($ip, $entries);
    }

    /**
     * Zeilenformat: "Mo-Fr 08:00-18:00", "Sa 09:00-12:00", "Mo,Mi,Fr 07:00-19:30".
     *
     * @return array<int, array{days: array<int, int>, from: int, to: int}>|null null bei ungültiger Syntax
     */
    public static function parseTimeWindows(?string $value): ?array
    {
        $windows = [];

        foreach (preg_split('/\R/', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
            $line = trim($line);

            if (! preg_match('/^([A-Za-z,\-\s]+?)\s+(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/', $line, $m)) {
                return null;
            }

            $days = self::parseDays($m[1]);
            $from = (int) $m[2] * 60 + (int) $m[3];
            $to = (int) $m[4] * 60 + (int) $m[5];

            if ($days === null || $m[2] > 24 || $m[4] > 24 || $m[3] > 59 || $m[5] > 59 || $from > 1440 || $to > 1440) {
                return null;
            }

            $windows[] = ['days' => $days, 'from' => $from, 'to' => $to];
        }

        return $windows;
    }

    /**
     * @return array<int, int>|null
     */
    private static function parseDays(string $spec): ?array
    {
        $days = [];

        foreach (explode(',', str_replace(' ', '', strtolower($spec))) as $part) {
            if ($part === '') {
                return null;
            }

            if (str_contains($part, '-')) {
                [$a, $b] = array_pad(explode('-', $part, 2), 2, '');
                $start = self::DAYS[substr($a, 0, 2)] ?? null;
                $end = self::DAYS[substr($b, 0, 2)] ?? null;

                if ($start === null || $end === null) {
                    return null;
                }

                for ($d = $start; ; $d = $d % 7 + 1) {
                    $days[] = $d;
                    if ($d === $end) {
                        break;
                    }
                }
            } else {
                $day = self::DAYS[substr($part, 0, 2)] ?? null;

                if ($day === null) {
                    return null;
                }

                $days[] = $day;
            }
        }

        return array_values(array_unique($days));
    }

    public static function timeAllowed(CarbonInterface $now, ?string $windows): bool
    {
        if (trim((string) $windows) === '') {
            return true;
        }

        $parsed = self::parseTimeWindows($windows);

        if ($parsed === null) {
            return false;
        }

        $day = $now->dayOfWeekIso;
        $previousDay = $day === 1 ? 7 : $day - 1;
        $minutes = $now->hour * 60 + $now->minute;

        foreach ($parsed as $window) {
            if ($window['from'] < $window['to']) {
                if (in_array($day, $window['days'], true) && $minutes >= $window['from'] && $minutes < $window['to']) {
                    return true;
                }

                continue;
            }

            if (in_array($day, $window['days'], true) && $minutes >= $window['from']) {
                return true;
            }

            if (in_array($previousDay, $window['days'], true) && $minutes < $window['to']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string|null 'ip' oder 'time', wenn eine Einschränkung verletzt wird
     */
    public static function violation(Application $application, ?string $ip, CarbonInterface $now): ?string
    {
        if (! self::ipAllowed($ip, $application->allowed_ip_ranges)) {
            return 'ip';
        }

        if (! self::timeAllowed($now, $application->access_time_windows)) {
            return 'time';
        }

        return null;
    }

    public static function message(string $violation): string
    {
        return $violation === 'ip'
            ? 'Diese Anwendung ist von Ihrem aktuellen Netzwerk aus nicht erreichbar.'
            : 'Diese Anwendung ist zu dieser Uhrzeit nicht freigegeben.';
    }
}
