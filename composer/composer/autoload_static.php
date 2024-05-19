<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInitOIDCIdentityProvider
{
    public static $prefixLengthsPsr4 = array (
        'O' =>
        array (
            'OCA\\OIDCIdentityProvider\\' => 11,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'OCA\\OIDCIdentityProvider\\' =>
        array (
            0 => __DIR__ . '/..' . '/../lib',
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
		'OCA\\OIDCIdentityProvider\\AppInfo\\Application' => __DIR__ . '/..' . '/../lib/AppInfo/Application.php',
        'OCA\\OIDCIdentityProvider\\Controller\\LoginRedirectorController' => __DIR__ . '/..' . '/../lib/Controller/LoginRedirectorController.php',
        'OCA\\OIDCIdentityProvider\\Controller\\OIDCApiController' => __DIR__ . '/..' . '/../lib/Controller/OIDCApiController.php',
        'OCA\\OIDCIdentityProvider\\Controller\\UserInfoController' => __DIR__ . '/..' . '/../lib/Controller/UserInfoController.php',
        'OCA\\OIDCIdentityProvider\\Controller\\DiscoveryController' => __DIR__ . '/..' . '/../lib/Controller/DiscoveryController.php',
        'OCA\\OIDCIdentityProvider\\Controller\\DynamicRegistrationController' => __DIR__ . '/..' . '/../lib/Controller/DynamicRegistrationController.php',
        'OCA\\OIDCIdentityProvider\\Controller\\JwksController' => __DIR__ . '/..' . '/../lib/Controller/JwksController.php',
        'OCA\\OIDCIdentityProvider\\Controller\\LogoutController' => __DIR__ . '/..' . '/../lib/Controller/LogoutController.php',
        'OCA\\OIDCIdentityProvider\\Controller\\PageController' => __DIR__ . '/..' . '/../lib/Controller/PageController.php',
		'OCA\\OIDCIdentityProvider\\Controller\\CorsController' => __DIR__ . '/..' . '/../lib/Controller/CorsController.php',
		'OCA\\OIDCIdentityProvider\\Util\\JwtGenerator' => __DIR__ . '/..' . '/../lib/Util/JwtGenerator.php',
        'OCA\\OIDCIdentityProvider\\Controller\\SettingsController' => __DIR__ . '/..' . '/../lib/Controller/SettingsController.php',
        'OCA\\OIDCIdentityProvider\\Http\\WellKnown\\JsonResponseMapper' => __DIR__ . '/..' . '/../lib/Http/WellKnown/JsonResponseMapper.php',
		'OCA\\OIDCIdentityProvider\\Http\\WellKnown\\WebFingerHandler' => __DIR__ . '/..' . '/../lib/Http/WellKnown/WebFingerHandler.php',
		'OCA\\OIDCIdentityProvider\\Util\\DiscoveryGenerator' => __DIR__ . '/..' . '/../lib/Util/DiscoveryGenerator.php',
        'OCA\\OIDCIdentityProvider\\Http\\WellKnown\\OIDCDiscoveryHandler' => __DIR__ . '/..' . '/../lib/Http/WellKnown/OIDCDiscoveryHandler.php',
		'OCA\\OIDCIdentityProvider\\BackgroundJob\\CleanupExpiredTokens' => __DIR__ . '/..' . '/../lib/BackgroundJob/CleanupExpiredTokens.php',
        'OCA\\OIDCIdentityProvider\\BackgroundJob\\CleanupExpiredClients' => __DIR__ . '/..' . '/../lib/BackgroundJob/CleanupExpiredClients.php',
		'OCA\\OIDCIdentityProvider\\BackgroundJob\\CleanupGroups' => __DIR__ . '/..' . '/../lib/BackgroundJob/CleanupGroups.php',
		'OCA\\OIDCIdentityProvider\\Db\\AccessToken' => __DIR__ . '/..' . '/../lib/Db/AccessToken.php',
        'OCA\\OIDCIdentityProvider\\Db\\AccessTokenMapper' => __DIR__ . '/..' . '/../lib/Db/AccessTokenMapper.php',
        'OCA\\OIDCIdentityProvider\\Db\\Client' => __DIR__ . '/..' . '/../lib/Db/Client.php',
        'OCA\\OIDCIdentityProvider\\Db\\ClientMapper' => __DIR__ . '/..' . '/../lib/Db/ClientMapper.php',
		'OCA\\OIDCIdentityProvider\\Db\\Group' => __DIR__ . '/..' . '/../lib/Db/Group.php',
        'OCA\\OIDCIdentityProvider\\Db\\GroupMapper' => __DIR__ . '/..' . '/../lib/Db/GroupMapper.php',
        'OCA\\OIDCIdentityProvider\\Db\\RedirectUri' => __DIR__ . '/..' . '/../lib/Db/RedirectUri.php',
        'OCA\\OIDCIdentityProvider\\Db\\RedirectUriMapper' => __DIR__ . '/..' . '/../lib/Db/RedirectUriMapper.php',
		'OCA\\OIDCIdentityProvider\\Db\\LogoutRedirectUri' => __DIR__ . '/..' . '/../lib/Db/LogoutRedirectUri.php',
        'OCA\\OIDCIdentityProvider\\Db\\LogoutRedirectUriMapper' => __DIR__ . '/..' . '/../lib/Db/LogoutRedirectUriMapper.php',
        'OCA\\OIDCIdentityProvider\\Exceptions\\AccessTokenNotFoundException' => __DIR__ . '/..' . '/../lib/Exceptions/AccessTokenNotFoundException.php',
        'OCA\\OIDCIdentityProvider\\Exceptions\\ClientNotFoundException' => __DIR__ . '/..' . '/../lib/Exceptions/ClientNotFoundException.php',
        'OCA\\OIDCIdentityProvider\\Exceptions\\RedirectUriNotFoundException' => __DIR__ . '/..' . '/../lib/Exceptions/RedirectUriNotFoundException.php',
		'OCA\\OIDCIdentityProvider\\Migration\\CreateKeys' => __DIR__ . '/..' . '/../lib/Migration/CreateKeys.php',
        'OCA\\OIDCIdentityProvider\\Migration\\Version0001Date20220209222100' => __DIR__ . '/..' . '/../lib/Migration/Version0001Date20220209222100.php',
        'OCA\\OIDCIdentityProvider\\Migration\\Version0002Date20220301210900' => __DIR__ . '/..' . '/../lib/Migration/Version0002Date20220301210900.php',
        'OCA\\OIDCIdentityProvider\\Migration\\Version0003Date20220927082100' => __DIR__ . '/..' . '/../lib/Migration/Version0003Date20220927082100.php',
		'OCA\\OIDCIdentityProvider\\Migration\\Version0004Date20220928082100' => __DIR__ . '/..' . '/../lib/Migration/Version0004Date20220928082100.php',
		'OCA\\OIDCIdentityProvider\\Migration\\Version0005Date20221009082100' => __DIR__ . '/..' . '/../lib/Migration/Version0005Date20221009082100.php',
		'OCA\\OIDCIdentityProvider\\Migration\\Version0006Date20221011082100' => __DIR__ . '/..' . '/../lib/Migration/Version0006Date20221011082100.php',
		'OCA\\OIDCIdentityProvider\\Migration\\Version0007Date20230121172100' => __DIR__ . '/..' . '/../lib/Migration/Version0007Date20230121172100.php',
		'OCA\\OIDCIdentityProvider\\Migration\\Version0008Date20230204190000' => __DIR__ . '/..' . '/../lib/Migration/Version0008Date20230204190000.php',
		'OCA\\OIDCIdentityProvider\\Migration\\Version0009Date20230401232100' => __DIR__ . '/..' . '/../lib/Migration/Version0009Date20230401232100.php',
		'OCA\\OIDCIdentityProvider\\Migration\\Version0010Date20230411232100' => __DIR__ . '/..' . '/../lib/Migration/Version0010Date20230411232100.php',
		'OCA\\OIDCIdentityProvider\\Migration\\Version0011Date20240430171900' => __DIR__ . '/..' . '/../lib/Migration/Version0011Date20240430171900.php',
		'OCA\\OIDCIdentityProvider\\Settings\\Admin' => __DIR__ . '/..' . '/../lib/Settings/Admin.php',
		'OCA\\OIDCIdentityProvider\\BasicAuthBackend' => __DIR__ . '/..' . '/../lib/BasicAuthBackend.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInitOIDCIdentityProvider::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInitOIDCIdentityProvider::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInitOIDCIdentityProvider::$classMap;

        }, null, ClassLoader::class);
    }
}
