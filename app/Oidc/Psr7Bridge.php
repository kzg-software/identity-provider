<?php

namespace App\Oidc;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface as Psr7Response;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

/** Converts between Laravel's Symfony-based HTTP objects and the PSR-7 objects league/oauth2-server expects. */
class Psr7Bridge
{
    public static function toPsr7Request(Request $request): \Psr\Http\Message\ServerRequestInterface
    {
        $psr17Factory = new Psr17Factory();
        $factory = new PsrHttpFactory($psr17Factory, $psr17Factory, $psr17Factory, $psr17Factory);

        $psrRequest = $factory->createRequest($request);

        foreach ($request->all() as $key => $value) {
            $psrRequest = $psrRequest->withParsedBody(array_merge((array) $psrRequest->getParsedBody(), [$key => $value]));
        }

        return $psrRequest;
    }

    public static function toPsr7Response(): Psr7Response
    {
        return (new Psr17Factory())->createResponse();
    }

    public static function toLaravelResponse(Psr7Response $psrResponse): Response
    {
        $foundationFactory = new HttpFoundationFactory();
        $symfonyResponse = $foundationFactory->createResponse($psrResponse);

        $response = new Response($symfonyResponse->getContent(), $symfonyResponse->getStatusCode());
        $response->headers = $symfonyResponse->headers;

        return $response;
    }
}
