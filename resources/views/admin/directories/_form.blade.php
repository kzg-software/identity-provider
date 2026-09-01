@php($d = $directory ?? null)

<div class="space-y-4">
    <div>
        <x-input-label value="Name" />
        <x-input type="text" name="name" value="{{ old('name', $d?->name) }}" required />
    </div>

    <div class="grid grid-cols-3 gap-4 items-end">
        <div>
            <x-input-label value="Typ" />
            <x-select name="type">
                <option value="active_directory" @selected(old('type', $d?->type) === 'active_directory')>Active Directory</option>
                <option value="ldap" @selected(old('type', $d?->type) === 'ldap')>LDAP</option>
            </x-select>
        </div>
        <div>
            <x-input-label value="Priorität" />
            <x-input type="number" name="priority" value="{{ old('priority', $d?->priority ?? 0) }}" />
        </div>
        <label class="flex items-center gap-2 pb-2 text-sm text-gray-700">
            <x-checkbox name="is_active" value="1" :checked="old('is_active', $d?->is_active)" />
            Aktiviert
        </label>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <x-input-label value="Domain" />
            <x-input type="text" name="domain" value="{{ old('domain', $d?->domain) }}" placeholder="example.local" />
        </div>
        <div>
            <x-input-label value="Realm" />
            <x-input type="text" name="realm" value="{{ old('realm', $d?->realm) }}" placeholder="EXAMPLE.LOCAL" />
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <x-input-label value="Domain Controller" />
            <x-input type="text" name="domain_controller" value="{{ old('domain_controller', $d?->domain_controller) }}" />
        </div>
        <div>
            <x-input-label value="NetBIOS-Domain" />
            <x-input type="text" name="netbios_domain" value="{{ old('netbios_domain', $d?->netbios_domain) }}" placeholder="RL" />
        </div>
    </div>

    <div class="grid grid-cols-6 gap-4 items-end">
        <div class="col-span-3">
            <x-input-label value="LDAP-Server (Host, mehrere via Komma)" />
            <x-input type="text" name="ldap_server" value="{{ old('ldap_server', $d?->ldap_server) }}" />
        </div>
        <div class="col-span-2">
            <x-input-label value="LDAP-Port" />
            <x-input type="number" name="ldap_port" value="{{ old('ldap_port', $d?->ldap_port ?? 389) }}" />
        </div>
        <label class="flex items-center gap-2 pb-2 text-sm text-gray-700">
            <x-checkbox name="use_ldaps" value="1" :checked="old('use_ldaps', $d?->use_ldaps)" />
            LDAPS
        </label>
    </div>

    <div>
        <x-input-label value="Base DN" />
        <x-input type="text" name="base_dn" value="{{ old('base_dn', $d?->base_dn) }}" placeholder="DC=example,DC=local" />
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <x-input-label value="User DN" />
            <x-input type="text" name="user_dn" value="{{ old('user_dn', $d?->user_dn) }}" />
        </div>
        <div>
            <x-input-label value="Group DN" />
            <x-input type="text" name="group_dn" value="{{ old('group_dn', $d?->group_dn) }}" />
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <x-input-label value="Bind User" />
            <x-input type="text" name="bind_user" value="{{ old('bind_user', $d?->bind_user) }}" />
        </div>
        <div>
            <x-input-label>Bind Password @if($d) <span class="text-gray-400 font-normal">(leer lassen = unverändert)</span> @endif</x-input-label>
            <x-input type="password" name="bind_password" autocomplete="new-password" />
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <x-input-label value="UPN-Suffix" />
            <x-input type="text" name="upn_suffix" value="{{ old('upn_suffix', $d?->upn_suffix) }}" />
        </div>
        <div>
            <x-input-label value="Kerberos Realm" />
            <x-input type="text" name="kerberos_realm" value="{{ old('kerberos_realm', $d?->kerberos_realm) }}" />
        </div>
    </div>
</div>
