<?php

declare(strict_types=1);

namespace Drupal\XbDemo;

use Composer\Composer;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\InstalledVersions;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Semver\VersionParser;
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
final class Plugin implements PluginInterface, EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PackageEvents::PRE_PACKAGE_UPDATE => 'onPackageUpdate',
    ];
  }

  /**
   * Uninstalls Experience Builder before it is updated.
   *
   * @param \Composer\Installer\PackageEvent $event
   *   The event object.
   */
  public function onPackageUpdate(PackageEvent $event): void {
    $operation = $event->getOperation();

    // We only need to uninstall XB if we're updating it from any version less
    // than 1.0.0-beta1.
    if ($operation instanceof UpdateOperation) {
      $from = $operation->getInitialPackage();

      if ($from->getName() === 'drupal/experience_builder' && version_compare($from->getVersion(), '1.0.0-beta1', '<')) {
        $drush = $event->getComposer()->getConfig()->get('bin-dir') . '/drush';
        assert(is_executable($drush));

        $io = $event->getIO();
        $io->write('Uninstalling Experience Builder because it is being updated from an unstable version.');
        $this->uninstallXb(function (array $command) use ($drush): Process {
          $process = new Process([$drush, ...$command]);
          return $process->mustRun();
        });

        $io->write('Successfully uninstalled Experience Builder. You can reinstall the demo by running the following command:');
        $io->write("$drush recipe " . InstalledVersions::getInstallPath('drupal/drupal_cms_xb_demo'));
      }
    }
  }

  /**
   * Deletes all of Experience Builder's data and uninstalls it.
   *
   * @param callable $drush
   *   A callable that runs Drush. Accepts an array of command-line arguments
   *   and options, and returns a Process object that has run successfully.
   */
  private function uninstallXb(callable $drush): void {
    // Ensure that Drupal is installed and has a database connection; otherwise,
    // there's nothing to do.
    $output = $drush(['core:status', '--field=db-status'])->getOutput();
    $output = trim($output);
    if (empty($output)) {
      return;
    }

    // Ask Drush if Experience Builder is installed.
    $output = $drush([
      'pm:list',
      '--type=module',
      '--field=status',
      '--filter=experience_builder',
    ])->getOutput();

    if (trim($output) === 'Enabled') {
      // Delete all xb_page entities and uninstall XB.
      $drush(['entity:delete', 'xb_page']);
      $drush(['pm:uninstall', 'experience_builder', '--yes']);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function activate(Composer $composer, IOInterface $io): void {
  }

  /**
   * {@inheritdoc}
   */
  public function deactivate(Composer $composer, IOInterface $io): void {
  }

  /**
   * {@inheritdoc}
   */
  public function uninstall(Composer $composer, IOInterface $io): void {
  }

}
