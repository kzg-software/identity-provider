@extends('layouts.install')

@section('install-content')
<h2 class="text-base font-semibold text-gray-900 mb-4">Schritt 6: Windows SSO (Integrated Windows Authentication)</h2>

<p class="text-sm text-gray-500 mb-4">
    Der Kerberos/SPNEGO-Handshake selbst findet auf Webserver-Ebene statt. Der Webserver validiert das vom Browser
    gesendete Kerberos-Ticket und reicht die authentifizierte Windows-Identität als Server-Variable
    <code class="bg-gray-100 px-1 rounded">REMOTE_USER</code> (z.B. <code class="bg-gray-100 px-1 rounded">DOMAIN\jkinzig</code>) an die Anwendung durch. Laravel übernimmt ab dort:
    Identität parsen, Benutzer im passenden Verzeichnis (siehe Schritt 5) suchen bzw. synchronisieren und anmelden.
    Ohne gesetztes <code class="bg-gray-100 px-1 rounded">REMOTE_USER</code> erfolgt <strong>kein</strong> automatischer Login — es erscheint die normale Login-Seite.
</p>

<div x-data="{ open: 'iis' }" class="border border-gray-200 rounded-md divide-y divide-gray-200 mb-4">
    <div>
        <button type="button" @click="open = (open === 'iis' ? null : 'iis')" class="w-full flex justify-between items-center px-4 py-3 text-sm font-medium text-gray-800 hover:bg-gray-50">
            IIS Windows Authentication
            <svg class="h-4 w-4 transition-transform" :class="open === 'iis' && 'rotate-180'" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
        </button>
        <div x-show="open === 'iis'" x-cloak class="px-4 pb-4 text-sm text-gray-600">
            <ol class="list-decimal pl-5 space-y-1">
                <li>Feature "Windows Authentication" in der IIS-Serverrolle aktivieren (Server-Manager → Rollen und Features).</li>
                <li>In den Site-Authentifizierungseinstellungen: Anonyme Authentifizierung deaktivieren, Windows-Authentifizierung aktivieren.</li>
                <li>Provider-Reihenfolge: <code class="bg-gray-100 px-1 rounded">Negotiate</code> vor <code class="bg-gray-100 px-1 rounded">NTLM</code> stellen (Kerberos wird bevorzugt).</li>
                <li>SPN für den Anwendungspool-Dienstkonto registrieren: <code class="bg-gray-100 px-1 rounded">setspn -S HTTP/auth.domain.local DOMAIN\svc-auth</code></li>
                <li>PHP läuft z.B. via FastCGI hinter IIS — <code class="bg-gray-100 px-1 rounded">REMOTE_USER</code> wird von IIS automatisch gesetzt und muss lediglich an FastCGI durchgereicht werden (Standardverhalten).</li>
            </ol>
        </div>
    </div>
    <div>
        <button type="button" @click="open = (open === 'apache' ? null : 'apache')" class="w-full flex justify-between items-center px-4 py-3 text-sm font-medium text-gray-800 hover:bg-gray-50">
            Apache mit mod_auth_gssapi
            <svg class="h-4 w-4 transition-transform" :class="open === 'apache' && 'rotate-180'" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
        </button>
        <div x-show="open === 'apache'" x-cloak class="px-4 pb-4 text-sm text-gray-600 space-y-2">
            <p>Keytab für den HTTP-Dienst erzeugen (auf dem Domain Controller oder via <code class="bg-gray-100 px-1 rounded">ktpass</code>/<code class="bg-gray-100 px-1 rounded">msktutil</code>):</p>
            <pre class="bg-gray-100 p-2 rounded text-xs overflow-x-auto">ktpass -princ HTTP/auth.domain.local@DOMAIN.LOCAL -mapuser svc-auth -crypto AES256-SHA1 -ptype KRB5_NT_PRINCIPAL -out auth.keytab</pre>
            <p>Apache-VHost-Konfiguration:</p>
            <pre class="bg-gray-100 p-2 rounded text-xs overflow-x-auto">&lt;Location /&gt;
    AuthType GSSAPI
    AuthName "Windows SSO"
    GssapiCredStore keytab:/etc/apache2/auth.keytab
    GssapiLocalName On
    Require valid-user
&lt;/Location&gt;</pre>
            <p>Apache setzt bei Erfolg <code class="bg-gray-100 px-1 rounded">REMOTE_USER</code>, das über PHP-FPM/mod_php an Laravel weitergereicht wird.</p>
        </div>
    </div>
    <div>
        <button type="button" @click="open = (open === 'nginx' ? null : 'nginx')" class="w-full flex justify-between items-center px-4 py-3 text-sm font-medium text-gray-800 hover:bg-gray-50">
            Nginx mit SPNEGO/Kerberos
            <svg class="h-4 w-4 transition-transform" :class="open === 'nginx' && 'rotate-180'" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
        </button>
        <div x-show="open === 'nginx'" x-cloak class="px-4 pb-4 text-sm text-gray-600 space-y-2">
            <p>Nginx hat kein natives SPNEGO-Modul. Zwei gängige Ansätze:</p>
            <ul class="list-disc pl-5 space-y-1">
                <li>Reverse-Proxy auf einen vorgelagerten Apache (mod_auth_gssapi) oder IIS, der <code class="bg-gray-100 px-1 rounded">REMOTE_USER</code> als
                    Header (z.B. <code class="bg-gray-100 px-1 rounded">X-Remote-User</code>) an Nginx/PHP-FPM weiterreicht.</li>
                <li>Drittanbieter-Modul <code class="bg-gray-100 px-1 rounded">nginx-http-auth-spnego</code> (separater Build) einsetzen, das ebenfalls
                    <code class="bg-gray-100 px-1 rounded">REMOTE_USER</code>/<code class="bg-gray-100 px-1 rounded">fastcgi_param</code> setzt.</li>
            </ul>
        </div>
    </div>
</div>

<p class="text-sm text-gray-500 mb-4">
    Benötigte Werte, unabhängig vom Webserver: <strong>Service Principal Name (SPN)</strong> (z.B. <code class="bg-gray-100 px-1 rounded">HTTP/auth.domain.local</code>),
    <strong>Kerberos-Realm</strong> (z.B. <code class="bg-gray-100 px-1 rounded">DOMAIN.LOCAL</code>), <strong>Hostname</strong> des Auth-Servers,
    <strong>Keytab-Datei</strong> (Apache/Linux) bzw. registrierter SPN auf dem Dienstkonto (IIS), sowie der
    <strong>HTTP Principal</strong>, unter dem der Dienst im AD registriert ist.
</p>

<form method="POST" action="{{ route('install.windows-sso.store') }}" class="space-y-4">
    @csrf

    <div>
        <x-input-label value="Service Principal Name (SPN)" />
        <x-input type="text" name="spn" placeholder="HTTP/auth.example.local" />
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <x-input-label value="Realm" />
            <x-input type="text" name="realm" />
        </div>
        <div>
            <x-input-label value="Hostname" />
            <x-input type="text" name="hostname" value="{{ request()->getHost() }}" />
        </div>
    </div>

    <div>
        <x-input-label value="HTTP Principal" />
        <x-input type="text" name="http_principal" />
    </div>

    <x-button type="submit">Weiter</x-button>
</form>
@endsection
