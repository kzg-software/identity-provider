@extends('layouts.install')

@section('install-content')
<h2 class="text-base font-semibold text-gray-900 mb-1">Active Directory (optional)</h2>
<p class="text-sm text-gray-500 mb-4">
    Verbindung zu einem Active Directory oder LDAP-Verzeichnis, damit sich Benutzer mit ihrem Windows-Konto anmelden können.
    Dieser Schritt ist freiwillig. Sie können ihn überspringen und später unter <em>Administration, Verzeichnisse</em> nachholen.
    Das
    <span class="inline-flex h-4 w-4 items-center justify-center rounded-full border border-gray-300 text-[10px] font-semibold text-gray-500">i</span>
    neben jedem Feld erklärt es und nennt ein Beispiel.
</p>

<form method="POST" action="{{ route('install.directory.store') }}" class="space-y-4">
    @csrf

    <div>
        <div class="flex items-center gap-1.5 mb-1">
            <span class="text-sm font-medium text-gray-700">Name</span>
            <x-field-info required="required" example="Primäres Active Directory">
                Anzeigename dieses Verzeichnisses in der Verwaltung. Frei wählbar, hat keine technische Bedeutung.
            </x-field-info>
        </div>
        <x-input type="text" name="name" value="{{ old('name', 'Primäres Active Directory') }}" />
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <div class="flex items-center gap-1.5 mb-1">
                <span class="text-sm font-medium text-gray-700">Domain</span>
                <x-field-info example="firma.local">
                    DNS-Name der AD-Domain. Ordnet angemeldete Benutzer diesem Verzeichnis zu.
                </x-field-info>
            </div>
            <x-input type="text" name="domain" value="{{ old('domain') }}" placeholder="firma.local" />
        </div>
        <div>
            <div class="flex items-center gap-1.5 mb-1">
                <span class="text-sm font-medium text-gray-700">Realm</span>
                <x-field-info example="FIRMA.LOCAL">
                    Kerberos-Realm, üblicherweise die Domain in Großbuchstaben. Nur für Windows-SSO relevant.
                </x-field-info>
            </div>
            <x-input type="text" name="realm" value="{{ old('realm') }}" placeholder="FIRMA.LOCAL" />
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <div class="flex items-center gap-1.5 mb-1">
                <span class="text-sm font-medium text-gray-700">Domain Controller</span>
                <x-field-info example="dc01.firma.local">
                    Hostname eines Domänencontrollers. Kann leer bleiben, wenn unten ein LDAP-Server steht.
                </x-field-info>
            </div>
            <x-input type="text" name="domain_controller" value="{{ old('domain_controller') }}" placeholder="dc01.firma.local" />
        </div>
        <div>
            <div class="flex items-center gap-1.5 mb-1">
                <span class="text-sm font-medium text-gray-700">NetBIOS-Domain</span>
                <x-field-info example="FIRMA">
                    Kurzer Domänenname, der Teil vor dem Backslash bei <code class="bg-gray-100 px-1 rounded">FIRMA\benutzer</code>.
                    Für die Zuordnung von Windows-SSO-Anmeldungen.
                </x-field-info>
            </div>
            <x-input type="text" name="netbios_domain" value="{{ old('netbios_domain') }}" placeholder="FIRMA" />
        </div>
    </div>

    <div class="grid grid-cols-6 gap-4 items-end">
        <div class="col-span-3">
            <div class="flex items-center gap-1.5 mb-1">
                <span class="text-sm font-medium text-gray-700">LDAP-Server</span>
                <x-field-info required="connection" example="dc01.firma.local oder 192.168.10.5">
                    Host, zu dem die LDAP-Verbindung aufgebaut wird. IP oder DNS-Name. Ohne LDAP-Server oder
                    Domain Controller ist keine Verbindung möglich.
                </x-field-info>
            </div>
            <x-input type="text" name="ldap_server" value="{{ old('ldap_server') }}" placeholder="dc01.firma.local" />
        </div>
        <div class="col-span-2">
            <div class="flex items-center gap-1.5 mb-1">
                <span class="text-sm font-medium text-gray-700">LDAP-Port</span>
                <x-field-info example="389">
                    TCP-Port des LDAP-Diensts. 389 ohne Verschlüsselung, 636 mit LDAPS.
                </x-field-info>
            </div>
            <x-input type="number" name="ldap_port" value="{{ old('ldap_port', 389) }}" />
        </div>
        <label class="flex items-center gap-2 pb-2 text-sm text-gray-700">
            <x-checkbox name="use_ldaps" value="1" @checked(old('use_ldaps')) />
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
        <x-input type="text" name="base_dn" value="{{ old('base_dn') }}" placeholder="DC=firma,DC=local" />
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <div class="flex items-center gap-1.5 mb-1">
                <span class="text-sm font-medium text-gray-700">User DN</span>
                <x-field-info example="OU=Benutzer,DC=firma,DC=local">
                    Teilbaum, in dem nach Benutzern gesucht wird. Leer lassen, dann gilt die Base DN.
                </x-field-info>
            </div>
            <x-input type="text" name="user_dn" value="{{ old('user_dn') }}" placeholder="OU=Benutzer,DC=firma,DC=local" />
        </div>
        <div>
            <div class="flex items-center gap-1.5 mb-1">
                <span class="text-sm font-medium text-gray-700">Group DN</span>
                <x-field-info example="OU=Gruppen,DC=firma,DC=local">
                    Teilbaum, in dem nach <em>Gruppenobjekten</em> gesucht wird. Das ist <strong>kein</strong>
                    Mitgliedschaftsfilter, dafür ist das Feld unten gedacht. Leer lassen, dann gilt die Base DN.
                </x-field-info>
            </div>
            <x-input type="text" name="group_dn" value="{{ old('group_dn') }}" placeholder="OU=Gruppen,DC=firma,DC=local" />
        </div>
    </div>

    <div>
        <div class="flex items-center gap-1.5 mb-1">
            <span class="text-sm font-medium text-gray-700">Anmeldung auf Gruppen beschränken</span>
            <x-field-info example="CN=IDP-Login,OU=Gruppen,DC=firma,DC=local">
                Eine Gruppe pro Zeile (voller DN oder nur der CN). Ist hier etwas eingetragen, werden nur
                Benutzer synchronisiert und angemeldet, die Mitglied mindestens einer dieser Gruppen sind,
                auch über verschachtelte Gruppen. Bleibt das Feld leer, gelten alle Benutzer im User DN.
            </x-field-info>
        </div>
        <x-textarea name="login_group_filter" rows="2" class="font-mono text-xs"
                    placeholder="CN=IDP-Login,OU=Gruppen,DC=firma,DC=local">{{ old('login_group_filter') }}</x-textarea>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <div class="flex items-center gap-1.5 mb-1">
                <span class="text-sm font-medium text-gray-700">Bind User</span>
                <x-field-info required="connection" example="svc-ldap@firma.local oder FIRMA\svc-ldap">
                    Konto, mit dem sich die Anwendung am Verzeichnis anmeldet, um zu suchen. Ein einfaches
                    Konto mit Leserechten genügt, kein Domänen-Admin.
                </x-field-info>
            </div>
            <x-input type="text" name="bind_user" value="{{ old('bind_user') }}" placeholder="svc-ldap@firma.local" />
        </div>
        <div>
            <div class="flex items-center gap-1.5 mb-1">
                <span class="text-sm font-medium text-gray-700">Bind Password</span>
                <x-field-info required="connection">
                    Passwort des Bind-Kontos. Wird verschlüsselt gespeichert und nie im Klartext angezeigt.
                </x-field-info>
            </div>
            <x-input type="password" name="bind_password" value="{{ old('bind_password') }}" />
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
            <x-input type="text" name="upn_suffix" value="{{ old('upn_suffix') }}" placeholder="firma.de" />
        </div>
        <div>
            <div class="flex items-center gap-1.5 mb-1">
                <span class="text-sm font-medium text-gray-700">Kerberos Realm</span>
                <x-field-info example="FIRMA.LOCAL">
                    Realm für die Prüfung von Kerberos-Tickets bei Windows-SSO. Meist identisch mit dem Feld Realm.
                </x-field-info>
            </div>
            <x-input type="text" name="kerberos_realm" value="{{ old('kerberos_realm') }}" placeholder="FIRMA.LOCAL" />
        </div>
    </div>

    <div class="flex flex-wrap gap-3 pt-2 items-center">
        <x-button type="submit" variant="secondary" formaction="{{ route('install.directory.test') }}">Verbindung testen</x-button>
        <x-button type="submit">Speichern &amp; weiter</x-button>
        <x-button type="submit" variant="link" name="skip" value="1">Überspringen</x-button>
    </div>
</form>
@endsection
