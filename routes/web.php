<?php

use App\Http\Controllers\Admin\ApplicationController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BackupController;
use App\Http\Controllers\Admin\DirectoryController;
use App\Http\Controllers\Admin\GroupRoleMappingController;
use App\Http\Controllers\Admin\ImpersonateController;
use App\Http\Controllers\Admin\OidcKeyController;
use App\Http\Controllers\Admin\SamlCertificateController;
use App\Http\Controllers\Admin\SamlServiceProviderController;
use App\Http\Controllers\Admin\SessionController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\SystemStatusController;
use App\Http\Controllers\Admin\SystemUpdateController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\NegotiateController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\Oidc\AuthorizationController;
use App\Http\Controllers\Oidc\DiscoveryController;
use App\Http\Controllers\Oidc\JwksController;
use App\Http\Controllers\Oidc\LogoutController;
use App\Http\Controllers\Oidc\TokenController;
use App\Http\Controllers\Oidc\UserInfoController;
use App\Http\Controllers\Profile\SessionController as ProfileSessionController;
use App\Http\Controllers\Saml\MetadataController;
use App\Http\Controllers\Saml\SloController;
use App\Http\Controllers\Saml\SsoController;
use Illuminate\Support\Facades\Route;

Route::get('.well-known/openid-configuration', DiscoveryController::class)->name('oidc.discovery');
Route::get('.well-known/jwks.json', JwksController::class)->name('oidc.jwks');

Route::get('saml/metadata', [MetadataController::class, 'global'])->name('saml.metadata');
Route::get('saml/{application}/metadata', [MetadataController::class, 'forApplication'])->name('saml.metadata.application');
Route::match(['get', 'post'], 'saml/sso', [SsoController::class, 'handle'])->middleware('throttle:30,1')->name('saml.sso');
Route::get('saml/sso/resume', [SsoController::class, 'resume'])->middleware('auth')->name('saml.sso.resume');
Route::match(['get', 'post'], 'saml/slo', [SloController::class, 'handle'])->middleware('throttle:30,1')->name('saml.slo');

Route::prefix('oauth')->name('oauth.')->group(function () {
    Route::get('authorize', [AuthorizationController::class, 'authorize'])->name('authorize');
    Route::post('authorize/decision', [AuthorizationController::class, 'decision'])->middleware('auth')->name('authorize.decision');
    Route::post('token', [TokenController::class, 'issue'])->middleware('throttle:30,1')->name('token');
    Route::post('revoke', [TokenController::class, 'revoke'])->middleware('throttle:30,1')->name('revoke');
    Route::match(['get', 'post'], 'userinfo', UserInfoController::class)->middleware('oauth_token')->name('userinfo');
    Route::match(['get', 'post'], 'logout', LogoutController::class)->name('logout');
});

Route::prefix('install')->name('install.')->group(function () {
    Route::get('/', [InstallController::class, 'welcome'])->name('index');

    Route::get('restore', [InstallController::class, 'restore'])->name('restore');
    Route::post('restore', [InstallController::class, 'restoreStore'])->name('restore.store');

    Route::get('requirements', [InstallController::class, 'requirements'])->name('requirements');
    Route::post('requirements', [InstallController::class, 'requirementsContinue'])->name('requirements.continue');

    Route::get('database', [InstallController::class, 'database'])->name('database');
    Route::post('database/test', [InstallController::class, 'databaseTest'])->name('database.test');
    Route::post('database', [InstallController::class, 'databaseStore'])->name('database.store');

    Route::get('system', [InstallController::class, 'system'])->name('system');
    Route::post('system', [InstallController::class, 'systemStore'])->name('system.store');

    Route::get('admin', [InstallController::class, 'admin'])->name('admin');
    Route::post('admin', [InstallController::class, 'adminStore'])->name('admin.store');

    Route::get('directory', [InstallController::class, 'directory'])->name('directory');
    Route::post('directory/test', [InstallController::class, 'directoryTest'])->name('directory.test');
    Route::post('directory', [InstallController::class, 'directoryStore'])->name('directory.store');

    Route::get('windows-sso', [InstallController::class, 'windowsSso'])->name('windows-sso');
    Route::post('windows-sso', [InstallController::class, 'windowsSsoStore'])->name('windows-sso.store');

    Route::get('finish', [InstallController::class, 'finish'])->name('finish');
    Route::post('finish', [InstallController::class, 'complete'])->name('complete');
});

Route::get('auth/negotiate', NegotiateController::class)->name('auth.negotiate');

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'login'])->name('login.attempt');
    Route::post('login/directory', [LoginController::class, 'loginDirectory'])->name('login.directory');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [LoginController::class, 'logout'])->name('logout');

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('dashboard', [DashboardController::class, 'index']);

    Route::post('impersonate/stop', [ImpersonateController::class, 'stop'])->name('impersonate.stop');

    Route::get('profile/sessions', [ProfileSessionController::class, 'index'])->name('profile.sessions');
    Route::delete('profile/sessions/{userSession}', [ProfileSessionController::class, 'destroy'])->name('profile.sessions.destroy');
    Route::post('profile/sessions/destroy-others', [ProfileSessionController::class, 'destroyOthers'])->name('profile.sessions.destroy-others');

    Route::prefix('admin')->name('admin.')->middleware('admin')->group(function () {
        Route::get('/', [App\Http\Controllers\Admin\DashboardController::class, 'index'])->name('dashboard');

        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::get('users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');
        Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
        Route::post('users/{user}/toggle-admin', [UserController::class, 'toggleAdmin'])->name('users.toggle-admin');
        Route::post('users/{user}/impersonate', [ImpersonateController::class, 'start'])->name('users.impersonate');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

        Route::get('settings', [SettingController::class, 'edit'])->name('settings.edit');
        Route::put('settings', [SettingController::class, 'update'])->name('settings.update');
        Route::post('settings/logo', [SettingController::class, 'uploadLogo'])->name('settings.logo.upload');
        Route::delete('settings/logo', [SettingController::class, 'deleteLogo'])->name('settings.logo.delete');
        Route::post('settings/favicon', [SettingController::class, 'uploadFavicon'])->name('settings.favicon.upload');
        Route::delete('settings/favicon', [SettingController::class, 'deleteFavicon'])->name('settings.favicon.delete');
        Route::post('settings/login-background', [SettingController::class, 'uploadLoginBackground'])->name('settings.login-background.upload');
        Route::delete('settings/login-background', [SettingController::class, 'deleteLoginBackground'])->name('settings.login-background.delete');

        Route::get('directories', [DirectoryController::class, 'index'])->name('directories.index');
        Route::get('directories/create', [DirectoryController::class, 'create'])->name('directories.create');
        Route::post('directories', [DirectoryController::class, 'store'])->name('directories.store');
        Route::get('directories/{directory}', [DirectoryController::class, 'show'])->name('directories.show');
        Route::get('directories/{directory}/edit', [DirectoryController::class, 'edit'])->name('directories.edit');
        Route::put('directories/{directory}', [DirectoryController::class, 'update'])->name('directories.update');
        Route::delete('directories/{directory}', [DirectoryController::class, 'destroy'])->name('directories.destroy');
        Route::post('directories/{directory}/activate', [DirectoryController::class, 'activate'])->name('directories.activate');
        Route::post('directories/{directory}/deactivate', [DirectoryController::class, 'deactivate'])->name('directories.deactivate');
        Route::post('directories/{directory}/test-connection', [DirectoryController::class, 'testConnection'])->name('directories.test-connection');
        Route::post('directories/{directory}/search-user', [DirectoryController::class, 'searchUser'])->name('directories.search-user');
        Route::post('directories/{directory}/search-group', [DirectoryController::class, 'searchGroup'])->name('directories.search-group');
        Route::post('directories/{directory}/test-authenticate', [DirectoryController::class, 'testAuthenticate'])->name('directories.test-authenticate');
        Route::post('directories/{directory}/raw-query', [DirectoryController::class, 'rawQuery'])->name('directories.raw-query');
        Route::post('directories/{directory}/sync', [DirectoryController::class, 'sync'])->name('directories.sync');

        Route::get('status', [SystemStatusController::class, 'index'])->name('status.index');

        Route::get('updates', [SystemUpdateController::class, 'index'])->name('updates.index');
        Route::post('updates/check', [SystemUpdateController::class, 'check'])->name('updates.check');

        Route::get('group-role-mappings', [GroupRoleMappingController::class, 'index'])->name('group-role-mappings.index');
        Route::post('group-role-mappings', [GroupRoleMappingController::class, 'store'])->name('group-role-mappings.store');
        Route::delete('group-role-mappings/{groupRoleMapping}', [GroupRoleMappingController::class, 'destroy'])->name('group-role-mappings.destroy');

        Route::get('applications', [ApplicationController::class, 'index'])->name('applications.index');
        Route::get('applications/create', [ApplicationController::class, 'create'])->name('applications.create');
        Route::post('applications', [ApplicationController::class, 'store'])->name('applications.store');
        Route::get('applications/{application}', [ApplicationController::class, 'show'])->name('applications.show');
        Route::put('applications/{application}', [ApplicationController::class, 'update'])->name('applications.update');
        Route::delete('applications/{application}', [ApplicationController::class, 'destroy'])->name('applications.destroy');
        Route::put('applications/{application}/clients/{client}', [ApplicationController::class, 'updateClient'])->name('applications.clients.update');
        Route::post('applications/{application}/clients/{client}/regenerate-secret', [ApplicationController::class, 'regenerateSecret'])->name('applications.clients.regenerate-secret');
        Route::post('applications/{application}/policies', [ApplicationController::class, 'storePolicy'])->name('applications.policies.store');
        Route::delete('applications/{application}/policies/{policy}', [ApplicationController::class, 'destroyPolicy'])->name('applications.policies.destroy');

        Route::get('oidc-keys', [OidcKeyController::class, 'index'])->name('oidc-keys.index');
        Route::post('oidc-keys/rotate', [OidcKeyController::class, 'rotate'])->name('oidc-keys.rotate');

        Route::get('saml-service-providers', [SamlServiceProviderController::class, 'index'])->name('saml-service-providers.index');
        Route::get('saml-service-providers/create', [SamlServiceProviderController::class, 'create'])->name('saml-service-providers.create');
        Route::post('saml-service-providers', [SamlServiceProviderController::class, 'store'])->name('saml-service-providers.store');
        Route::get('saml-service-providers/{samlServiceProvider}', [SamlServiceProviderController::class, 'show'])->name('saml-service-providers.show');
        Route::put('saml-service-providers/{samlServiceProvider}', [SamlServiceProviderController::class, 'update'])->name('saml-service-providers.update');
        Route::delete('saml-service-providers/{samlServiceProvider}', [SamlServiceProviderController::class, 'destroy'])->name('saml-service-providers.destroy');
        Route::post('saml-service-providers/{samlServiceProvider}/mappings', [SamlServiceProviderController::class, 'storeMapping'])->name('saml-service-providers.mappings.store');
        Route::delete('saml-service-providers/{samlServiceProvider}/mappings/{mapping}', [SamlServiceProviderController::class, 'destroyMapping'])->name('saml-service-providers.mappings.destroy');
        Route::post('saml-service-providers/{samlServiceProvider}/policies', [SamlServiceProviderController::class, 'storePolicy'])->name('saml-service-providers.policies.store');
        Route::delete('saml-service-providers/{samlServiceProvider}/policies/{policy}', [SamlServiceProviderController::class, 'destroyPolicy'])->name('saml-service-providers.policies.destroy');

        Route::get('saml-certificates', [SamlCertificateController::class, 'index'])->name('saml-certificates.index');
        Route::post('saml-certificates/rotate', [SamlCertificateController::class, 'rotate'])->name('saml-certificates.rotate');

        Route::get('backups', [BackupController::class, 'index'])->name('backups.index');
        Route::post('backups/download', [BackupController::class, 'download'])->name('backups.download');
        Route::post('backups/restore', [BackupController::class, 'restore'])->name('backups.restore');
        Route::put('backups/auto', [BackupController::class, 'updateAuto'])->name('backups.auto.update');
        Route::post('backups/auto/run', [BackupController::class, 'runAuto'])->name('backups.auto.run');
        Route::post('backups/auto/test', [BackupController::class, 'testDestination'])->name('backups.auto.test');

        Route::get('audit-log', [AuditLogController::class, 'index'])->name('audit-log.index');
        Route::get('audit-log/export', [AuditLogController::class, 'export'])->name('audit-log.export');

        Route::get('sessions', [SessionController::class, 'index'])->name('sessions.index');
        Route::delete('sessions/{userSession}', [SessionController::class, 'destroy'])->name('sessions.destroy');
    });
});
