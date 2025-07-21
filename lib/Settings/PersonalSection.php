<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class PersonalSection implements IIconSection {

        /** @var IL10N */
        private $l;

        /** @var IURLGenerator */
        private $urlGenerator;

        public function __construct(IL10N $l, IURLGenerator $urlGenerator) {
                $this->l = $l;
                $this->urlGenerator = $urlGenerator;
        }

        /**
         * Returns the ID of the section. It is supposed to be a lowercase string
         *
         * @returns string
         */
        public function getID() {
                return 'oidc_provider_personal'; //or a generic id if feasible
        }

        /**
         * Returns the translated name as it should be displayed, e.g. 'LDAP / AD
         * integration'. Use the L10N service to translate it.
         *
         * @return string
         */
        public function getName() {
                return $this->l->t('OpenID Connect Provider');
        }

        /**
         * Whether the form should be rather on the top or bottom of
         * the settings navigation. The sections are arranged in ascending order of
         * the priority values. It is required to return a value between 0 and 99.
         *
         * @return int
         */
        public function getPriority() {
                return 90;
        }

        /**
         * The relative path to an icon describing the section
         *
         * @return string
         */
        public function getIcon() {
                return $this->urlGenerator->imagePath('oidc', 'openid-dark.svg');
        }

}
