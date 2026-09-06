/*
 * Kleiner WebAuthn-Helfer ohne Abhaengigkeiten.
 * Uebersetzt zwischen base64url (Server) und ArrayBuffer (Browser-API) und
 * kapselt Registrierung und Anmeldung.
 *
 * window.Webauthn.supported()
 * window.Webauthn.register(optionsUrl, submitUrl, csrf, extraBody?)  -> Promise<Response>
 * window.Webauthn.authenticate(optionsUrl, submitUrl, csrf, extraBody?, mediation?) -> Promise<Response>
 */
(function () {
    'use strict';

    function b64urlToBuf(value) {
        var s = String(value).replace(/-/g, '+').replace(/_/g, '/');
        while (s.length % 4) { s += '='; }
        var bin = atob(s);
        var bytes = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) { bytes[i] = bin.charCodeAt(i); }
        return bytes.buffer;
    }

    function bufToB64url(buf) {
        var bytes = new Uint8Array(buf);
        var bin = '';
        for (var i = 0; i < bytes.length; i++) { bin += String.fromCharCode(bytes[i]); }
        return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    function mapCredList(list) {
        return (list || []).map(function (item) {
            var out = { type: item.type, id: b64urlToBuf(item.id) };
            if (item.transports) { out.transports = item.transports; }
            return out;
        });
    }

    function prepareCreate(publicKey) {
        publicKey.challenge = b64urlToBuf(publicKey.challenge);
        publicKey.user.id = b64urlToBuf(publicKey.user.id);
        if (publicKey.excludeCredentials) {
            publicKey.excludeCredentials = mapCredList(publicKey.excludeCredentials);
        }
        return publicKey;
    }

    function prepareGet(publicKey) {
        publicKey.challenge = b64urlToBuf(publicKey.challenge);
        if (publicKey.allowCredentials) {
            publicKey.allowCredentials = mapCredList(publicKey.allowCredentials);
        }
        return publicKey;
    }

    function serializeAttestation(cred) {
        return {
            id: cred.id,
            rawId: bufToB64url(cred.rawId),
            type: cred.type,
            clientExtensionResults: cred.getClientExtensionResults ? cred.getClientExtensionResults() : {},
            response: {
                clientDataJSON: bufToB64url(cred.response.clientDataJSON),
                attestationObject: bufToB64url(cred.response.attestationObject),
                transports: cred.response.getTransports ? cred.response.getTransports() : []
            }
        };
    }

    function serializeAssertion(cred) {
        return {
            id: cred.id,
            rawId: bufToB64url(cred.rawId),
            type: cred.type,
            clientExtensionResults: cred.getClientExtensionResults ? cred.getClientExtensionResults() : {},
            response: {
                clientDataJSON: bufToB64url(cred.response.clientDataJSON),
                authenticatorData: bufToB64url(cred.response.authenticatorData),
                signature: bufToB64url(cred.response.signature),
                userHandle: cred.response.userHandle ? bufToB64url(cred.response.userHandle) : null
            }
        };
    }

    function postJson(url, csrf, body) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(body || {})
        });
    }

    function getJson(url, csrf) {
        return postJson(url, csrf, {}).then(function (res) {
            if (!res.ok) { throw new Error('options_failed'); }
            return res.json();
        });
    }

    var Webauthn = {
        supported: function () {
            return typeof window.PublicKeyCredential !== 'undefined'
                && typeof navigator.credentials !== 'undefined';
        },

        register: function (optionsUrl, submitUrl, csrf, extraBody) {
            return getJson(optionsUrl, csrf).then(function (options) {
                return navigator.credentials.create({ publicKey: prepareCreate(options.publicKey) });
            }).then(function (cred) {
                var body = serializeAttestation(cred);
                if (extraBody) { Object.keys(extraBody).forEach(function (k) { body[k] = extraBody[k]; }); }
                return postJson(submitUrl, csrf, body);
            });
        },

        authenticate: function (optionsUrl, submitUrl, csrf, extraBody, mediation) {
            return getJson(optionsUrl, csrf).then(function (options) {
                var request = { publicKey: prepareGet(options.publicKey) };
                if (mediation) { request.mediation = mediation; }
                return navigator.credentials.get(request);
            }).then(function (cred) {
                if (!cred) { throw new Error('no_credential'); }
                var body = serializeAssertion(cred);
                if (extraBody) { Object.keys(extraBody).forEach(function (k) { body[k] = extraBody[k]; }); }
                return postJson(submitUrl, csrf, body);
            });
        }
    };

    window.Webauthn = Webauthn;
})();
