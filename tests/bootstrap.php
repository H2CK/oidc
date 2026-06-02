<?php

if (!defined('PHPUNIT_RUN')) {
    define('PHPUNIT_RUN', 1);
}

require_once __DIR__.'/../../../lib/base.php';

// Add Test\ namespace for Nextcloud integration tests
\OC::$composerAutoloader->addPsr4('Test\\', \OC::$SERVERROOT . '/tests/lib/', true);

\OC::$server->get(\OC\App\AppManager::class)->loadApp('oidc');
