#!/usr/bin/env bash

###
# Launches Drupal CMS using DDEV.
#
# This requires that DDEV be installed and available in the PATH, and
# only works in Unix-like environments (Linux, macOS, or the Windows
# Subsystem for Linux). This will initialize a new DDEV project based
# on Drupal CMS, start the containers, install dependencies, and open
# Drupal's interactive installer in the browser.
###

# Abort this entire script if any one command fails.
set -e

# If debugging, output every line of this script as interpreted by the shell.
if [ -n "$DEBUG" ]; then
  set -x
fi

BACKUP_DIR=.ddev/drupal-cms-backup
# The list of files to back up is a constant so that we don't accidentally
# move files we shouldn't.
BACKUP_FILES="composer.json .drupal-cms launch-drupal-cms.sh README.md web"
# To test with an alternate COMPOSER_CREATE
# export COMPOSER_CREATE='drupal/cms --stability=dev --repository={"type":"vcs","url":"https://github.com/phenaproxima/test-ddev-cms.git"}'
COMPOSER_CREATE=${COMPOSER_CREATE:-drupal/cms --stability="RC"}
# The document root is hard-coded to `web` to match what's in `composer.json`.
DOCROOT=web

PROJECT_ROOT=$(cd "$(dirname "$0")" && pwd -P)
# This should never happen, but guard against it anyway.
cd "$PROJECT_ROOT" || (echo "FATAL: Cannot determine where the project root is." && exit 2)

restore_backup () {
  set +e
  if [ -d "$BACKUP_DIR" ]; then
    cp -R "$BACKUP_DIR"/* .
  fi
  echo "The project could not be set up. Sorry about that! Please file a bug report at https://www.drupal.org/node/add/project-issue/drupal_cms with as much detail as you can provide."
}

# If we're running inside a DDEV container, there's nothing to do.
if [ -n "$IS_DDEV_PROJECT" ]; then
  echo "Drupal CMS is already running. Run 'ddev launch' to open it in a browser."
  exit 0
fi

# Ensure we are running in a Drupal CMS project by checking for our hidden
# marker file.
if [ ! -f ".drupal-cms" ]; then
  echo "FATAL: We do not appear to be in a Drupal CMS project."
  exit 2
fi

if ! command -v ddev >/dev/null; then
  echo "DDEV needs to be installed. Visit https://ddev.com/get-started for instructions."
  exit 1
fi

# If it hasn't already happened, configure DDEV and build the code base.
if [ ! -f ".ddev/config.yaml" ]; then
  ddev config --project-type=drupal11 --docroot="$DOCROOT" || exit 3

  # Clear out the project root so DDEV can spin up the code base cleanly.
  mkdir -p $BACKUP_DIR
  for file in $BACKUP_FILES; do
    mv -f "$file" $BACKUP_DIR;
  done

  # Restore the backed up files if DDEV fails to create the project.
  trap 'restore_backup' ERR
  ddev composer create $COMPOSER_CREATE
  # We don't need the backed up files anymore.
  trap - ERR
  rm -r -f $BACKUP_DIR
fi

# Make sure we actually have a "real" setup, with index.php having been laid down.
if [ ! -f "$DOCROOT/index.php" ]; then
  echo "This project does not appear to have been set up correctly with Composer."
  exit 3
fi

# If we skipped the `composer create` because we found a project already here,
# check if it's running by doing `ddev php --version`, and if it's not running,
# start it.
(set +e; ddev php --version >/dev/null 2>&1) || ddev start

# All done...let's get to Drupalin'.
ddev launch
