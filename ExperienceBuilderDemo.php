<?php

declare(strict_types=1);

use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\Installer\PackageEvent;
use Symfony\Component\Process\Process;

/**
 * Uninstalls Experience Builder whenever Composer attempts to update it.
 *
 * Experience Builder currently has no update path, which means anyone who has
 * it installed in demo mode could theoretically, suddenly break their site if
 * Experience Builder ships a breaking change. To prevent that scenario, this
 * plugin will uninstall Experience Builder and delete all of its data before
 * the Composer package is updated.
 */
final class ExperienceBuilderDemo {

  /**
   * Uninstalls Experience Builder before it is updated.
   *
   * @param \Composer\Installer\PackageEvent $event
   *   The event object.
   */
  public static function onPackageUpdate(PackageEvent $event): void {
    $operation = $event->getOperation();

    // We only need to uninstall XB if we're updating it from any version less
    // than 1.0.0-beta1.
    if ($operation instanceof UpdateOperation) {
      $from = $operation->getInitialPackage();

      if ($from->getName() === 'drupal/experience_builder' && version_compare($from->getVersion(), '1.0.0-beta1', '<')) {
        $config = $event->getComposer()->getConfig();
        $drush = $config->get('bin-dir') . '/drush';
        assert(is_executable($drush));

        $run_drush = function(array $command) use ($drush): Process {
          $process = new Process([$drush, ...$command]);
          return $process->mustRun();
        };

        // Ensure that Drupal is installed and has a database connection; otherwise,
        // there's nothing to do.
        $output = $run_drush(['core:status', '--field=db-status'])
          ->getOutput();
        $output = trim($output);
        if (empty($output)) {
          return;
        }
        $io = $event->getIO();
        $io->write('<comment>Uninstalling Experience Builder because it is being updated from an unstable version.</>');

        // Ask Drush if Experience Builder is installed. If it isn't, there's
        // nothing we need to do.
        $output = $run_drush(['pm:list', '--type=module', '--field=status', '--filter=experience_builder'])
          ->getOutput();
        if (trim($output) === 'Disabled') {
          return;
        }
        // Delete all xb_page entities and uninstall XB.
        $run_drush(['entity:delete', 'xb_page']);
        $run_drush(['pm:uninstall', 'experience_builder', '--yes']);

        $project_root = dirname($config->getConfigSource()->getName());
        $io->write("<bg=blue;fg=white>Successfully uninstalled Experience Builder. You can reinstall the demo by running the following command:\n$drush recipe $project_root/recipes/xb_demo</>");
      }
    }
  }

}
