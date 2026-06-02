<?php

if (!defined('PHPUNIT_RUN')) {
    define('PHPUNIT_RUN', 1);
}

require_once __DIR__.'/../../../lib/base.php';

// Add Test\ namespace for Nextcloud integration tests
$serverRoot = \OC::$SERVERROOT;
$testCasePath = null;

// Try to find the TestCase.php file
if ($serverRoot && is_file($serverRoot . '/tests/lib/TestCase.php')) {
    $testCasePath = $serverRoot . '/tests/lib/TestCase.php';
} else {
    // Fallback: calculate from app location
    $appRoot = dirname(__DIR__, 3);
    if (is_file($appRoot . '/tests/lib/TestCase.php')) {
        $testCasePath = $appRoot . '/tests/lib/TestCase.php';
        $serverRoot = $appRoot;
    }
}

// Register PSR-4 namespace for Test classes
if ($serverRoot && is_dir($serverRoot . '/tests/lib/')) {
    \OC::$composerAutoloader->addPsr4('Test\\', $serverRoot . '/tests/lib/', true);
}

// Load TestCase class if found (for integration tests)
if ($testCasePath) {
    require_once $testCasePath;
}

\OC::$server->get(\OC\App\AppManager::class)->loadApp('oidc');
