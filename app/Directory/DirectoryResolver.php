<?php

namespace App\Directory;

use App\Models\Directory as DirectoryModel;
use Illuminate\Support\Collection;

/**
 * Shared "which Directory does this domain hint belong to" logic, used by
 * every passive identity-trust login path (Kerberos/SPNEGO REMOTE_USER,
 * in-app NTLM negotiation) so the matching rules stay in exactly one place.
 */
class DirectoryResolver
{
    /**
     * @return array{0: ?string, 1: string} [domainOrUpnSuffixOrNull, samAccountName]
     */
    public static function parseDomainQualifiedUsername(string $username): array
    {
        if (str_contains($username, '\\')) {
            [$domain, $sam] = explode('\\', $username, 2);

            return [$domain, $sam];
        }

        if (str_contains($username, '@')) {
            [$sam, $suffix] = explode('@', $username, 2);

            return [$suffix, $sam];
        }

        return [null, $username];
    }

    public static function resolveSingle(?string $domainOrSuffix): ?DirectoryModel
    {
        $query = DirectoryModel::query()->where('is_active', true)->orderByDesc('priority');

        if ($domainOrSuffix) {
            $query->where(function ($q) use ($domainOrSuffix) {
                $q->where('netbios_domain', $domainOrSuffix)
                    ->orWhere('upn_suffix', $domainOrSuffix)
                    ->orWhere('domain', $domainOrSuffix)
                    ->orWhere('realm', $domainOrSuffix);
            });
        }

        return $query->first() ?? DirectoryModel::query()->where('is_active', true)->orderByDesc('priority')->first();
    }

    /**
     * @return Collection<int, DirectoryModel>
     */
    public static function candidates(?string $domainOrSuffix): Collection
    {
        $query = DirectoryModel::query()->where('is_active', true)->orderByDesc('priority');

        if ($domainOrSuffix) {
            $matching = (clone $query)->where(function ($q) use ($domainOrSuffix) {
                $q->where('netbios_domain', $domainOrSuffix)
                    ->orWhere('upn_suffix', $domainOrSuffix)
                    ->orWhere('domain', $domainOrSuffix)
                    ->orWhere('realm', $domainOrSuffix);
            })->get();

            if ($matching->isNotEmpty()) {
                return $matching;
            }
        }

        return $query->get();
    }
}
