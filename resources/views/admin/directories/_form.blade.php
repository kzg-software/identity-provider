@php($d = $directory ?? null)

<div class="space-y-4">
    <div>
        <div class="flex items-center gap-1.5 mb-1">
            <span class="text-sm font-medium text-gray-700">Name</span>
            <x-field-info required="required" example="Primäres Active Directory">
                Anzeigename dieses Verzeichnisses in der Verwaltung. Frei wählbar, ohne technische Bedeutung.
            </x-field-info>
        </div>
        <x-input type="text" name="name" value="{{ old('name', $d?->name) }}" required />
    </div>

    <div class="grid grid-cols-3 gap-4 items-end">
        <div>
            <div class="flex items-center gap-1.5 mb-1">
                <span class="text-sm font-medium text-gray-700">Typ</span>
                <x-field-info required="required">
                    Active Directory für Windows-Domänen, LDAP für generische Verzeichnisse wie OpenLDAP.
                </x-field-info>
            </div>
            <x-select name="type">
                <option value="active_directory" @selected(old('type', $d?->type) === 'active_directory')>Active Directory</option>
                <option value="ldap" @selected(old('type', $d?->type) === 'ldap')>LDAP</option>
            </x-select>
        </div>
        <div>
            <div class="flex items-center gap-1.5 mb-1">
                <span class="text-sm font-medium text-gray-700">Priorität</span>
                <x-field-info example="0">
                    Reihenfolge bei mehreren Verzeichnissen. Die niedrigere Zahl wird zuerst durchsucht.
                </x-field-info>
            </div>
            <x-input type="number" name="priority" value="{{ old('priority', $d?->priority ?? 0) }}" />
        </div>
        <label class="flex items-center gap-2 pb-2 text-sm text-gray-700">
            <x-checkbox name="is_active" value="1" :checked="old('is_active', $d?->is_active)" />
            Aktiviert
        </label>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <div class="flex items-center gap-1.5 mb-1">
                <span class="text-sm font-medium text-gray-700">Domain</span>
                <x-field-info example="firma.local">
                    DNS-Name der Domain. Ordnet angemeldete Benutzer diesem Verzeichnis zu.
                </x-field-info>
            </div>
            <x-input type="text" name="domain" value="{{ old('domain', $d?->domain) }}" placeholder="firma.local" />
        </div>
        <div>
            <div class="flex items-center gap-1.5 mb-1">
                <span class="text-sm font-medium text-gray-700">Realm</span>
                <x-field-info example="FIRMA.LOCAL">
                    Kerberos-Realm, üblicherweise die Domain in Großbuchstaben. Nur für Windows-SSO relevant.
                </x-field-info>
            </div>
            <x-input type="text" name="realm" value="{{ old('realm', $d?->realm) }}" placeholder="FIRMA.LOCAL" />
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <div class="flex items-center gap-1.5 mb-1">
                <span class="text-sm font-medium text-gray-700">Domain Controller</span>
                <x-field-info example="dc01.firma.local">
                    Hostname eines Domänencontrollers. Kann leer bleiben, wenn ein LDAP-Server gesetzt ist.
                </x-field-info>
            </div>
            <x-input type="text" name="domain_controller" value="{{ old('domain_controller', $d?->domain_controller) }}" placeholder="dc01.firma.local" />
        </div>
        <div>
            <div class="flex items-center gap-1.5 mb-1">
                <span class="text-sm font-medium text-gray-700">NetBIOS-Domain</span>
                <x-field-info example="FIRMA">
                    Kurzer Domänenname, der Teil vor dem Backslash bei <code class="bg-gray-100 px-1 rounded">FIRMA\benutzer</code>.
                </x-field-info>
            </div>
            <x-input type="text" name="netbios_domain" value="{{ old('netbios_domain', $d?->netbios_domain) }}" placeholder="FIRMA" />
        </div>
    </div>

    <div class="grid grid-cols-6 gap-4 items-end">
        <div class="col-span-3">
            <div class="flex items-center gap-1.5 mb-1">
                <span class="text-sm font-medium text-gray-700">LDAP-Server</span>
                <x-field-info required="connection" example="dc01.firma.local, dc02.firma.local">
                    Host für die LDAP-Verbindung, mehrere durch Komma getrennt. IP oder DNS-Name. Ohne LDAP-Server
                    oder Domain Controller ist keine Verbindung möglich.
                </x-field-info>
            </div>
            <x-input type="text" name="ldap_server" value="{{ old('ldap_server', $d?->ldap_server) }}" placeholder="dc01.firma.local" />
        </div>
        <div class="col-span-2">
            <div class="flex items-center gap-1.5 mb-1">
                <span class="text-sm font-medium text-gray-700">LDAP-Port</span>
                <x-field-info example="389">
                    TCP-Port des LDAP-Diensts. 389 ohne Verschlüsselung, 636 mit LDAPS.
                </x-field-info>
            </div>
            <x-input type="number" name="ldap_port" value="{{ old('ldap_port', $d?->ldap_port ?? 389) }}" />
        </div>
        <label class="flex items-center gap-2 pb-2 text-sm text-gray-700">
            <x-checkbox name="use_ldaps" value="1" :checked="old('use_ldaps', $d?->use_ldaps)" />
            LDAPS
        </label>
    </div>

    <div>
        <div class="flex items-center gap-1.5 mb-1">
            <span class="text-sm font-medium text-gray-700">Base DN</span>
            <x-field-info required="connection" example="DC=firma,DC=local">
                Ausgangspunkt im Verzeichnisbaum für alle Suchen nach Benutzern und Gruppen. Üblicherweise die
                Domäne in DN-Schreibweise.
            </x-field-info>
        </div>
        <x-input type="text" name="base_dn" value="{{ old('base_dn', $d?->base_dn) }}" placeholder="DC=firma,DC=local" />
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <div class="flex items-center gap-1.5 mb-1">
                <span class="text-sm font-medium text-gray-700">User DN</span>
                <x-field-info example="OU=Benutzer,DC=firma,DC=local">
                    Teilbaum, in dem nach Benutzern gesucht wird. Leer lassen, dann gilt die Base DN.
                </x-field-info>
            </div>
            <x-input type="text" name="user_dn" value="{{ old('user_dn', $d?->user_dn) }}" placeholder="OU=Benutzer,DC=firma,DC=local" />
        </div>
        <div>
            <div class="flex items-center gap-1.5 mb-1">
                <span class="text-sm font-medium text-gray-700">Group DN</span>
                <x-field-info example="OU=Gruppen,DC=firma,DC=local">
                    Teilbaum, in dem nach Gruppen gesucht wird. Leer lassen, dann gilt die Base DN.
                </x-field-info>
            </div>
            <x-input type="text" name="group_dn" value="{{ old('group_dn', $d?->group_dn) }}" placeholder="OU=Gruppen,DC=firma,DC=local" />
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <div class="flex items-center gap-1.5 mb-1">
                <span class="text-sm font-medium text-gray-700">Bind User</span>
                <x-field-info required="connection" example="svc-ldap@firma.local oder FIRMA\svc-ldap">
                    Konto, mit dem sich die Anwendung am Verzeichnis anmeldet, um zu suchen. Ein Konto mit
                    Leserechten genügt, kein Domänen-Admin.
                </x-field-info>
            </div>
            <x-input type="text" name="bind_user" value="{{ old('bind_user', $d?->bind_user) }}" placeholder="svc-ldap@firma.local" />
        </div>
        <div>
            <div class="flex items-center gap-1.5 mb-1">
                <span class="text-sm font-medium text-gray-700">Bind Password</span>
                <x-field-info :required="$d ? 'optional' : 'connection'">
                    Passwort des Bind-Kontos. Wird verschlüsselt gespeichert.
                    @if($d) Leer lassen behält das gespeicherte Passwort. @endif
                </x-field-info>
            </div>
            <x-input type="password" name="bind_password" autocomplete="new-password" placeholder="{{ $d ? '••••••• (unverändert)' : '' }}" />
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <div class="flex items-center gap-1.5 mb-1">
                <span class="text-sm font-medium text-gray-700">UPN-Suffix</span>
                <x-field-info example="firma.de">
                    User-Principal-Name-Suffix, falls er vom Domainnamen abweicht. Erlaubt die Anmeldung per
                    <code class="bg-gray-100 px-1 rounded">benutzer@suffix</code>.
                </x-field-info>
            </div>
            <x-input type="text" name="upn_suffix" value="{{ old('upn_suffix', $d?->upn_suffix) }}" placeholder="firma.de" />
        </div>
        <div>
            <div class="flex items-center gap-1.5 mb-1">
                <span class="text-sm font-medium text-gray-700">Kerberos Realm</span>
                <x-field-info example="FIRMA.LOCAL">
                    Realm für die Prüfung von Kerberos-Tickets bei Windows-SSO. Meist identisch mit dem Feld Realm.
                </x-field-info>
            </div>
            <x-input type="text" name="kerberos_realm" value="{{ old('kerberos_realm', $d?->kerberos_realm) }}" placeholder="FIRMA.LOCAL" />
        </div>
    </div>
</div>
