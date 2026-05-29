---
name: oidc-release
description: Prepare and verify a new release for the Nextcloud OIDC Identity Provider app. Automatically updates version files, generates changelog entries from merge requests, and verifies the build.
short_description: Prepare OIDC app release
commands:
  - name: prepare-release
    description: Prepare a new OIDC app release
    parameters:
      - name: version
        type: string
        required: false
        description: The new version number (e.g., 1.18.0). If not provided, will prompt for it.
      - name: date
        type: string
        required: false
        description: The release date in YYYY-MM-DD format. Defaults to today.
    example: prepare-release version=1.18.0 date=2026-06-15
tags:
  - nextcloud
  - oidc
  - release
  - automation
---

# OIDC Identity Provider Release Preparation Skill

This skill automates the process of preparing a new release for the Nextcloud OIDC Identity Provider application. It handles version updates across multiple files, generates changelog entries from Git merge requests, and verifies the build process.

## What This Skill Does

When invoked, this skill performs the following steps:

### 1. **Version Input**
- If a version is provided via parameter, use it
- Otherwise, prompt the user for the new version number
- Validates the version follows semantic versioning (X.Y.Z) format
- Shows current version from `appinfo/info.xml` for reference

### 2. **Date Input**
- If a date is provided via parameter, use it
- Otherwise, defaults to today's date in YYYY-MM-DD format

### 3. **Update Version Files**
Updates the version number in:
- `appinfo/info.xml` - Nextcloud app info file
- `package.json` - Node.js package file
- `package-lock.json` - Node.js lock file (both root and package entries)

### 4. **Generate Changelog Entry**
- Extracts the last release date from CHANGELOG.md
- Searches Git history for merge commits and pull request references since the last release
- Groups changes into "Added" and "Changed" categories
- Creates a properly formatted markdown entry with:
  - Version number and release date
  - Pull request references as links
  - Standard entries for dependency updates and translations

### 5. **Build Verification**
- Executes `make build-test` to:
  - Clean previous build artifacts
  - Run unit and integration tests
  - Build production JavaScript bundles
  - Assemble the release package

### 6. **Summary and Next Steps**
- Displays a summary of all changes made
- Shows next steps for the release process:
  - Review changes with `git diff`
  - Commit changes
  - Create Git tag
  - Push to repository

## Usage Examples

### Interactive Mode (prompts for version)
```
use skill oidc-release
prepare-release
```

### With Version Parameter
```
use skill oidc-release
prepare-release version=1.18.0
```

### With Version and Date
```
use skill oidc-release
prepare-release version=1.18.0 date=2026-06-15
```

### As One-Liner
```
use skill oidc-release && prepare-release version=1.18.0
```

## Requirements

### Working Directory
The skill should be invoked from the OIDC app directory:
```
cd /path/to/nextcloud/server/apps/oidc
```

Or it will automatically detect the app directory structure.

### Required Files
- `appinfo/info.xml` - Must exist with current version
- `package.json` - Must exist
- `package-lock.json` - Must exist
- `CHANGELOG.md` - Must exist with previous releases
- `Makefile` - Must have `build-test` target

### Required Tools
- `git` - For accessing commit history and merge requests
- `sed` - For file modifications
- `grep` - For pattern matching
- `awk` - For text processing
- `make` - For build process
- `npm` - For JavaScript dependencies

## Implementation Details

### Version Detection
The current version is automatically detected from `appinfo/info.xml` using:
```bash
grep -oP '<version>\K[^<]+' appinfo/info.xml
```

### Merge Request Discovery
The skill searches for pull request references in commit messages using:
```bash
git log --oneline --since="$last_release_date" --format="%s" | grep -oE '#[0-9]+'
```

This captures PR numbers from commit messages like:
- `Merge pull request #653 from H2CK:feat_extend-access-token`
- `Fixes #123`
- `Related to #456`

### File Updates
All file updates are performed with `sed` in-place edits. The skill:
1. Creates backups automatically (on macOS with `sed -i ''`)
2. Validates changes were applied correctly
3. Reports success/failure for each file

### Changelog Generation
The changelog entry is inserted at the top of the file, below the header, maintaining:
- Consistent markdown formatting
- Proper date format (YYYY-MM-DD)
- Link formatting for PR references
- Categorization of changes (Added/Changed)

## Error Handling

The skill includes comprehensive error handling:
- Validates version format (optional, with user confirmation)
- Checks for required files existence
- Validates Git repository status
- Verifies build process completion
- Provides clear error messages with color coding

## Color Output

The skill uses color-coded output for better visibility:
- **Red** - Errors and failures
- **Green** - Success messages
- **Yellow** - Information and warnings

## Customization

To customize the behavior, you can:

1. **Modify the changelog template** - Edit the skill to change how changelog entries are formatted
2. **Add more categories** - Extend to support "Fixed", "Removed", "Security" sections
3. **Add pre-release checks** - Include additional validation steps
4. **Integrate with CI/CD** - Extend to automatically create Git tags or push changes

## Notes

- The skill is designed for the Nextcloud OIDC Identity Provider app but can be adapted for other Nextcloud apps
- It assumes semantic versioning (MAJOR.MINOR.PATCH)
- The release date format follows ISO 8601 (YYYY-MM-DD)
- Pull request references are formatted as GitHub links

## Troubleshooting

### "Version not found" error
Ensure `appinfo/info.xml` exists and has a `<version>` tag. Run from the correct directory.

### "No merge requests found" warning
This is normal if there are no PRs between releases. The changelog will still be created with standard entries.

### "Build failed" error
Check the output of `make build-test` for specific errors. Ensure all dependencies are installed.

### Permission denied errors
Make sure the script has write permissions for all files:
```bash
chmod +x build/prepare-release.sh
chmod u+w appinfo/info.xml package.json package-lock.json CHANGELOG.md
```

## See Also

- [Nextcloud OIDC App Repository](https://github.com/H2CK/oidc)
- [Semantic Versioning](https://semver.org/)
- [Keep a Changelog](https://keepachangelog.com/)

---

## Workflow Implementation

```
STEP 1: Initialize
├─ Detect current working directory
├─ Verify required files exist
├─ Get current version from info.xml
└─ Load skill configuration

STEP 2: Get Version
├─ Check if version parameter provided
├─ If not, prompt user for version
├─ Validate version format (optional)
└─ Store version for subsequent steps

STEP 3: Get Release Date
├─ Check if date parameter provided
├─ If not, use today's date
└─ Format as YYYY-MM-DD

STEP 4: Discover Changes
├─ Extract last release date from CHANGELOG.md
├─ Search Git log for commits since last release
├─ Extract pull request numbers from commit messages
└─ Format PR references

STEP 5: Update Version Files
├─ Update appinfo/info.xml
│  └─ Replace <version>X.Y.Z</version> with new version
├─ Update package.json
│  └─ Replace "version": "X.Y.Z" with new version
└─ Update package-lock.json
   ├─ Update root package version
   └─ Update top-level version

STEP 6: Update CHANGELOG.md
├─ Create new entry header with version and date
├─ Add "Added" section (if applicable)
├─ Add "Changed" section
│  ├─ List pull request references
│  ├─ Add "Updated dependencies"
│  └─ Add "Updated translations"
└─ Insert entry at top of changelog

STEP 7: Verify Build
├─ Run "make build-test"
├─ Check return code
├─ Capture output for error reporting
└─ Display success/failure

STEP 8: Finalize
├─ Display summary of changes
├─ Show next steps
└─ Return to user control
```
