<?php

if (!defined('PHPUNIT_RUN')) {
    define('PHPUNIT_RUN', 1);
}

require_once __DIR__.'/../../../lib/base.php';

// Add Test\ namespace for Nextcloud integration tests
$serverRoot = \OC::$SERVERROOT;
if (!$serverRoot || !is_dir($serverRoot . '/tests/lib/')) {
    // Fallback: calculate from app location if SERVERROOT is not correct
    $appRoot = dirname(__DIR__, 3);
    if (is_dir($appRoot . '/tests/lib/')) {
        $serverRoot = $appRoot;
    }
}

\OC::$composerAutoloader->addPsr4('Test\\', $serverRoot . '/tests/lib/', true);

// Explicitly load the TestCase class to ensure it's available
require_once $serverRoot . '/tests/lib/TestCase.php';

\OC::$server->get(\OC\App\AppManager::class)->loadApp('oidc');
