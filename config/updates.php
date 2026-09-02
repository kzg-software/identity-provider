<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Update-Prüfung
    |--------------------------------------------------------------------------
    |
    | Das System prüft regelmäßig gegen das öffentliche GitHub-Repository, ob
    | ein neueres Release veröffentlicht wurde, und meldet dies in der
    | Administration (inkl. Changelog und Update-Anleitung). Die Prüfung ist
    | fester Bestandteil und lässt sich nicht abschalten – nur das Ziel-Repo
    | und ein optionales Token sind konfigurierbar.
    |
    */

    // GitHub-Repository "owner/repo", gegen das geprüft wird.
    'repository' => env('UPDATE_REPOSITORY', 'kzg-software/identity-provider'),

    // Öffentliche Projekt-/Repo-URL – Footer-Link und "Auf GitHub ansehen".
    'repository_url' => env('UPDATE_REPOSITORY_URL', 'https://github.com/kzg-software/identity-provider'),

    // Optionales GitHub-Token (nur bei privatem Repo oder gegen Rate-Limits
    // nötig). Ein feingranulares Token mit "Contents: read" genügt.
    'token' => env('UPDATE_GITHUB_TOKEN', env('GITHUB_TOKEN')),

    // Wie lange ein Prüfergebnis als "frisch" gilt (Stunden). Der Scheduler
    // prüft alle 2 Stunden; dazwischen frischt ein Seitenaufruf ein älteres
    // Ergebnis im Hintergrund auf.
    'ttl_hours' => (int) env('UPDATE_CHECK_TTL_HOURS', 2),

];
