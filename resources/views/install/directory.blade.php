@extends('layouts.install')

@section('install-content')
<h2 class="text-base font-semibold text-gray-900 mb-1">Schritt 5: Active Directory (optional)</h2>
<p class="text-sm text-gray-500 mb-4">
    Verbindungsdaten für ein Active Directory / LDAP-Verzeichnis. Erweiterte Verwaltung (mehrere Verzeichnisse,
    Benutzer-/Gruppensuche, Synchronisierung, Gruppen-Rollen-Mapping) folgt nach der Installation unter
    <em>Administration → Verzeichnisse</em>.
</p>

<form method="POST" action="{{ route('install.directory.store') }}" class="space-y-4">
    @csrf

    <div>
        <x-input-label value="Name" />
        <x-input type="text" name="name" value="{{ old('name', 'Primäres Active Directory') }}" />
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <x-input-label value="Domain" />
            <x-input type="text" name="domain" placeholder="example.local" />
        </div>
        <div>
            <x-input-label value="Realm" />
            <x-input type="text" name="realm" placeholder="EXAMPLE.LOCAL" />
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <x-input-label value="Domain Controller" />
            <x-input type="text" name="domain_controller" />
        </div>
        <div>
            <x-input-label value="NetBIOS-Domain" />
            <x-input type="text" name="netbios_domain" placeholder="RL" />
        </div>
    </div>

    <div class="grid grid-cols-6 gap-4 items-end">
        <div class="col-span-3">
            <x-input-label value="LDAP-Server" />
            <x-input type="text" name="ldap_server" />
        </div>
        <div class="col-span-2">
            <x-input-label value="LDAP-Port" />
            <x-input type="number" name="ldap_port" value="389" />
        </div>
        <label class="flex items-center gap-2 pb-2 text-sm text-gray-700">
            <x-checkbox name="use_ldaps" value="1" />
            LDAPS
        </label>
    </div>

    <div>
        <x-input-label value="Base DN" />
        <x-input type="text" name="base_dn" placeholder="DC=example,DC=local" />
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <x-input-label value="User DN" />
            <x-input type="text" name="user_dn" />
        </div>
        <div>
            <x-input-label value="Group DN" />
            <x-input type="text" name="group_dn" />
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <x-input-label value="Bind User" />
            <x-input type="text" name="bind_user" />
        </div>
        <div>
            <x-input-label value="Bind Password" />
            <x-input type="password" name="bind_password" />
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <x-input-label value="UPN-Suffix" />
            <x-input type="text" name="upn_suffix" />
        </div>
        <div>
            <x-input-label value="Kerberos Realm" />
            <x-input type="text" name="kerberos_realm" />
        </div>
    </div>

    <div class="flex flex-wrap gap-3 pt-2 items-center">
        <x-button type="submit" variant="secondary" formaction="{{ route('install.directory.test') }}">Verbindung testen</x-button>
        <x-button type="submit">Speichern &amp; weiter</x-button>
        <x-button type="submit" variant="link" name="skip" value="1">Überspringen</x-button>
    </div>
</form>
@endsection
