<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022-2023 Thorsten Jagel <dev@jagel.net>
 *
 * @author Thorsten Jagel <dev@jagel.net>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace OCA\OIDCIdentityProvider\BackgroundJob;

use OCA\OIDCIdentityProvider\Db\GroupMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;

class CleanupGroups extends TimedJob {

    /** @var GroupMapper */
	private $groupMapper;
	/** @var IConfig */
	private $settings;

	/**
	 * @param ITimeFactory $time
	 * @param GroupMapper $groupMapper
	 */
	public function __construct(ITimeFactory $time,
								GroupMapper $groupMapper,
								IConfig $settings) {
		parent::__construct($time);
		$this->groupMapper = $groupMapper;
		$this->settings = $settings;

		// Run once a day
		$this->setInterval(24 * 60 * 60);
		$this->setTimeSensitivity(\OCP\BackgroundJob\IJob::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		// Don't run CleanUpJob when backgroundjobs_mode is ajax or webcron
		// if ($this->settings->getAppValue('core', 'backgroundjobs_mode') !== 'cron') return;
        $this->groupMapper->cleanUp();
	}
}
