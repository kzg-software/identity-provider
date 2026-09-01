<?php

namespace App\Http\Controllers\Oidc;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Oidc\IdTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserInfoController extends Controller
{
    public function __construct(private readonly IdTokenService $idTokens)
    {
    }

    /** GET|POST /oauth/userinfo — requires a valid access token (see ValidateOAuthAccessToken middleware). */
    public function __invoke(Request $request): JsonResponse
    {
        $user = User::find($request->attributes->get('oauth_user_id'));

        if (! $user) {
            return response()->json(['error' => 'invalid_token'], 401);
        }

        $scopes = $request->attributes->get('oauth_scopes', []);

        return response()->json(array_merge(
            ['sub' => (string) $user->id],
            $this->idTokens->claimsForScopes($user, $scopes)
        ));
    }
}
