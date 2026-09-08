<?php

namespace Tests;

use App\Models\Application;
use App\Models\Provider;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Legt einen Provider für ein Detail-Model (OauthClient|SamlServiceProvider) an
     * und verknüpft ihn mit der Anwendung. Ersetzt die frühere direkte
     * *.application_id-Zuordnung.
     */
    protected function linkProvider(Application $application, string $type, string $name = 'Test-Provider'): Provider
    {
        $provider = Provider::create(['name' => $name, 'type' => $type, 'is_active' => true]);
        $application->update(['provider_id' => $provider->id]);

        return $provider;
    }
}
