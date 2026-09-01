<?php

namespace App\Http\Controllers\Oidc;

use App\Http\Controllers\Controller;
use App\Models\OauthScope;
use Illuminate\Http\JsonResponse;

class DiscoveryController extends Controller
{
    /** GET /.well-known/openid-configuration */
    public function __invoke(): JsonResponse
    {
        $issuer = rtrim(config('app.url'), '/');
        $scopes = OauthScope::query()->pluck('key')->all();

        if (empty($scopes)) {
            $scopes = ['openid', 'profile', 'email', 'groups'];
        }

        return response()->json([
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer.'/oauth/authorize',
            'token_endpoint' => $issuer.'/oauth/token',
            'userinfo_endpoint' => $issuer.'/oauth/userinfo',
            'jwks_uri' => $issuer.'/.well-known/jwks.json',
            'revocation_endpoint' => $issuer.'/oauth/revoke',
            'end_session_endpoint' => $issuer.'/oauth/logout',
            'scopes_supported' => $scopes,
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token', 'client_credentials'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic', 'none'],
            'code_challenge_methods_supported' => ['S256', 'plain'],
            'claims_supported' => [
                'sub', 'name', 'given_name', 'family_name', 'preferred_username',
                'email', 'email_verified', 'groups', 'department', 'company', 'roles',
            ],
        ]);
    }
}
