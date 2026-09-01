<?php

namespace App\Http\Controllers\Oidc;

use App\Http\Controllers\Controller;
use App\Oidc\OidcKeyService;
use Illuminate\Http\JsonResponse;

class JwksController extends Controller
{
    public function __construct(private readonly OidcKeyService $keys)
    {
    }

    /** GET /.well-known/jwks.json */
    public function __invoke(): JsonResponse
    {
        return response()->json($this->keys->jwks());
    }
}
