<?php

use App\Http\Controllers\Admin\ApplicationController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BackupController;
use App\Http\Controllers\Admin\DirectoryController;
use App\Http\Controllers\Admin\GroupRoleMappingController;
use App\Http\Controllers\Admin\ImpersonateController;
use App\Http\Controllers\Admin\MailQueueController;
use App\Http\Controllers\Admin\OidcKeyController;
use App\Http\Controllers\Admin\ProviderController;
use App\Http\Controllers\Admin\SamlCertificateController;
use App\Http\Controllers\Admin\SessionController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\SystemStatusController;
use App\Http\Controllers\Admin\SystemUpdateController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\NegotiateController;
use App\Http\Controllers\Auth\PasskeyLoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Oidc\AuthorizationController;
use App\Http\Controllers\Oidc\DiscoveryController;
use App\Http\Controllers\Oidc\JwksController;
use App\Http\Controllers\Oidc\LogoutController;
use App\Http\Controllers\Oidc\TokenController;
use App\Http\Controllers\Oidc\UserInfoController;
use App\Http\Controllers\Profile\AccountController;
use App\Http\Controllers\Profile\ConnectedAppController;
use App\Http\Controllers\Profile\SecurityController;
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

    // Passwort vergessen (nur lokale Konten; Meldung bleibt immer gleich)
    Route::get('forgot-password', [PasswordResetController::class, 'showForgot'])->name('password.request');
    Route::post('forgot-password', [PasswordResetController::class, 'sendResetLink'])
        ->middleware('throttle:6,1')->name('password.email');
    Route::get('reset-password/{token}', [PasswordResetController::class, 'showReset'])->name('password.reset');
    Route::post('reset-password', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:6,1')->name('password.update');

    // Passwortlose Anmeldung mit einem Passkey
    Route::post('login/passkey/options', [PasskeyLoginController::class, 'options'])
        ->middleware('throttle:30,1')->name('login.passkey.options');
    Route::post('login/passkey', [PasskeyLoginController::class, 'verify'])
        ->middleware('throttle:30,1')->name('login.passkey.verify');

    // Zwei-Faktor-Challenge nach bestandener Passwortprüfung
    Route::get('two-factor-challenge', [TwoFactorChallengeController::class, 'show'])->name('two-factor.challenge');
    Route::post('two-factor-challenge/cancel', [TwoFactorChallengeController::class, 'cancel'])->name('two-factor.cancel');
    Route::post('two-factor-challenge/webauthn/options', [TwoFactorChallengeController::class, 'webauthnOptions'])
        ->middleware('throttle:30,1')->name('two-factor.webauthn.options');
    Route::post('two-factor-challenge/webauthn', [TwoFactorChallengeController::class, 'verifyWebauthn'])
        ->middleware('throttle:30,1')->name('two-factor.webauthn');
    Route::post('two-factor-challenge/totp', [TwoFactorChallengeController::class, 'verifyTotp'])
        ->middleware('throttle:30,1')->name('two-factor.totp');
    Route::post('two-factor-challenge/recovery', [TwoFactorChallengeController::class, 'verifyRecovery'])
        ->middleware('throttle:30,1')->name('two-factor.recovery');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [LoginController::class, 'logout'])->name('logout');

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('dashboard', [DashboardController::class, 'index']);

    Route::post('impersonate/stop', [ImpersonateController::class, 'stop'])->name('impersonate.stop');

    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/{notification}/open', [NotificationController::class, 'open'])->name('notifications.open');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::delete('notifications/{notification}', [NotificationController::class, 'destroy'])->name('notifications.destroy');
    Route::delete('notifications', [NotificationController::class, 'clear'])->name('notifications.clear');

    Route::get('profile/sessions', [ProfileSessionController::class, 'index'])->name('profile.sessions');
    Route::delete('profile/sessions/{userSession}', [ProfileSessionController::class, 'destroy'])->name('profile.sessions.destroy');
    Route::post('profile/sessions/destroy-others', [ProfileSessionController::class, 'destroyOthers'])->name('profile.sessions.destroy-others');

    Route::get('profile', [AccountController::class, 'index'])->name('profile.index');
    Route::get('profile/notifications', [AccountController::class, 'notifications'])->name('profile.notifications');
    Route::post('profile/notifications', [AccountController::class, 'updateNotifications'])->name('profile.notifications.update');
    Route::get('profile/apps', [ConnectedAppController::class, 'index'])->name('profile.apps');
    Route::delete('profile/apps/{client}', [ConnectedAppController::class, 'destroy'])->name('profile.apps.destroy');

    Route::get('profile/security', [SecurityController::class, 'edit'])->name('profile.security');
    Route::post('profile/security/password', [SecurityController::class, 'updatePassword'])->name('profile.security.password');
    Route::post('profile/security/passkeys/options', [SecurityController::class, 'passkeyOptions'])->name('profile.security.passkeys.options');
    Route::post('profile/security/passkeys', [SecurityController::class, 'storePasskey'])->name('profile.security.passkeys.store');
    Route::delete('profile/security/passkeys/{credential}', [SecurityController::class, 'destroyPasskey'])->name('profile.security.passkeys.destroy');
    Route::get('profile/security/authenticator', [SecurityController::class, 'totpSetup'])->name('profile.security.totp.setup');
    Route::post('profile/security/authenticator', [SecurityController::class, 'storeTotp'])->name('profile.security.totp.store');
    Route::delete('profile/security/authenticator', [SecurityController::class, 'destroyTotp'])->name('profile.security.totp.destroy');
    Route::post('profile/security/recovery-codes', [SecurityController::class, 'regenerateRecoveryCodes'])->name('profile.security.recovery-codes');
    Route::delete('profile/security/trusted-devices', [SecurityController::class, 'destroyTrustedDevices'])->name('profile.security.trusted-devices.destroy-all');
    Route::delete('profile/security/trusted-devices/{trustedDevice}', [SecurityController::class, 'destroyTrustedDevice'])->name('profile.security.trusted-devices.destroy');

    Route::prefix('admin')->name('admin.')->middleware('admin')->group(function () {
        Route::get('/', [App\Http\Controllers\Admin\DashboardController::class, 'index'])->name('dashboard');

        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::get('users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::get('users/export', [UserController::class, 'export'])->name('users.export');
        Route::get('users/import', [UserController::class, 'importForm'])->name('users.import');
        Route::post('users/import', [UserController::class, 'import'])->name('users.import.run');
        Route::post('users/bulk', [UserController::class, 'bulk'])->name('users.bulk');
        Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');
        Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
        Route::post('users/{user}/two-factor/reset', [UserController::class, 'resetTwoFactor'])->name('users.two-factor.reset');
        Route::delete('users/{user}/webauthn/{credential}', [UserController::class, 'removeWebauthn'])->name('users.webauthn.destroy');
        Route::post('users/{user}/toggle-admin', [UserController::class, 'toggleAdmin'])->name('users.toggle-admin');
        Route::post('users/{user}/impersonate', [ImpersonateController::class, 'start'])->name('users.impersonate');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

        Route::get('settings', [SettingController::class, 'edit'])->name('settings.edit');
        Route::put('settings', [SettingController::class, 'update'])->name('settings.update');
        Route::post('settings/test-mail', [SettingController::class, 'sendTestMail'])->name('settings.test-mail');
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

        Route::get('mail-queue', [MailQueueController::class, 'index'])->name('mail-queue.index');
        Route::post('mail-queue/process', [MailQueueController::class, 'process'])->name('mail-queue.process');
        Route::delete('mail-queue/pending', [MailQueueController::class, 'cancelAll'])->name('mail-queue.cancel-all');
        Route::delete('mail-queue/pending/{job}', [MailQueueController::class, 'cancel'])->name('mail-queue.cancel');
        Route::post('mail-queue/retry-all', [MailQueueController::class, 'retryAll'])->name('mail-queue.retry-all');
        Route::post('mail-queue/{uuid}/retry', [MailQueueController::class, 'retry'])->name('mail-queue.retry');
        Route::delete('mail-queue/failed', [MailQueueController::class, 'flush'])->name('mail-queue.flush');
        Route::delete('mail-queue/{uuid}', [MailQueueController::class, 'forget'])->name('mail-queue.forget');

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
        Route::post('applications/{application}/provider', [ApplicationController::class, 'attachProvider'])->name('applications.provider.attach');
        Route::delete('applications/{application}/provider', [ApplicationController::class, 'detachProvider'])->name('applications.provider.detach');
        Route::post('applications/{application}/policies', [ApplicationController::class, 'storePolicy'])->name('applications.policies.store');
        Route::delete('applications/{application}/policies/{policy}', [ApplicationController::class, 'destroyPolicy'])->name('applications.policies.destroy');

        Route::get('providers', [ProviderController::class, 'index'])->name('providers.index');
        Route::get('providers/create', [ProviderController::class, 'create'])->name('providers.create');
        Route::post('providers', [ProviderController::class, 'store'])->name('providers.store');
        Route::get('providers/{provider}', [ProviderController::class, 'show'])->name('providers.show');
        Route::put('providers/{provider}', [ProviderController::class, 'update'])->name('providers.update');
        Route::delete('providers/{provider}', [ProviderController::class, 'destroy'])->name('providers.destroy');
        Route::post('providers/{provider}/regenerate-secret', [ProviderController::class, 'regenerateSecret'])->name('providers.regenerate-secret');
        Route::post('providers/{provider}/mappings', [ProviderController::class, 'storeMapping'])->name('providers.mappings.store');
        Route::delete('providers/{provider}/mappings/{mapping}', [ProviderController::class, 'destroyMapping'])->name('providers.mappings.destroy');

        Route::permanentRedirect('saml-service-providers', 'admin/providers');

        Route::get('oidc-keys', [OidcKeyController::class, 'index'])->name('oidc-keys.index');
        Route::post('oidc-keys/rotate', [OidcKeyController::class, 'rotate'])->name('oidc-keys.rotate');

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
