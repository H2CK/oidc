// SPDX-FileCopyrightText: 2026 OIDC Identity Provider contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { recommended } from '@nextcloud/eslint-config'

export default [
	...recommended,
	{ ignores: ['vendor/**', 'node_modules/**', 'js/**', 'build/**'] },
]
