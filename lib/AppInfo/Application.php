<?php

namespace OCA\OIDCIdentityProvider\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Services\IAppConfig;

class Application extends App {
	public const APP_ID = 'oidc';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

}