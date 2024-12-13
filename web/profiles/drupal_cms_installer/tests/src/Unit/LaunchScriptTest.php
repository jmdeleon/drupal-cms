<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_cms_installer\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

class LaunchScriptTest extends TestCase {

  private readonly string $dir;

  private readonly Filesystem $fileSystem;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->dir = uniqid(sys_get_temp_dir() . '/');

    $this->fileSystem = new Filesystem();
    $this->fileSystem->mkdir($this->dir);
    $this->fileSystem->mirror(dirname(__DIR__, 6), $this->dir);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->fileSystem->remove($this->dir);
    $this->assertDirectoryDoesNotExist($this->dir);
    parent::tearDown();
  }

  private function launch(array $env = []): Process {
    $script = $this->dir . '/launch-drupal-cms.sh';
    $this->assertFileExists($script);
    $this->assertTrue(is_executable($script), 'The launcher is not executable.');

    $env += [
      'IS_DDEV_PROJECT' => '',
      'PATH' => $this->dir . ':' . getenv('PATH'),
    ];
    return new Process([$script], env: $env);
  }

  public function testLauncherExitsInDdev(): void {
    $process = $this->launch(['IS_DDEV_PROJECT' => 'true'])->mustRun();
    $this->assertStringContainsString('Drupal CMS is already running.', $process->getOutput());
  }

  public function testLauncherChecksForMarkerFile(): void {
    $this->fileSystem->remove($this->dir . '/.drupal-cms');
    $process = $this->launch();
    $this->assertSame(2, $process->run());
    $this->assertStringContainsString('FATAL: We do not appear to be in a Drupal CMS project.', $process->getOutput());
  }

  public function testLauncherChecksForDdev(): void {
    $process = $this->launch();
    $this->assertSame(1, $process->run());
    $this->assertStringContainsString('DDEV needs to be installed.', $process->getOutput());
  }

  /**
   * @testWith [{"COMPOSER_CREATE": "foo/bar"}]
   *   [{}]
   */
  public function testSuccess(array $env): void {
    $this->mockDdev(<<<'END'
if [ $1 = "config" ]; then
  mkdir .ddev && touch .ddev/config.yaml
elif [ $1 = "composer" ]; then
  mkdir web && touch web/index.php
fi

echo "$@" >> ddev.log
END
);
    $this->launch($env)->mustRun();

    // The only things in the project should be the `.ddev` directory and the
    // web root.
    $finder = Finder::create()
      ->in($this->dir)
      ->ignoreDotFiles(FALSE)
      ->depth(0)
      ->notName(['ddev', 'ddev.log']);
    $items = iterator_to_array($finder);
    $items = array_map('basename', array_keys($items));
    sort($items);
    $this->assertSame(['.ddev', 'web'], $items);
    // There should be no directories below the top level (i.e., the backup
    // directory should be gone).
    $this->assertCount(0, $finder->directories()->depth('> 0'));

    if (array_key_exists('COMPOSER_CREATE', $env)) {
      $log = file_get_contents($this->dir . '/ddev.log');
      $this->assertSame(1, substr_count($log, 'composer create ' . $env['COMPOSER_CREATE']));
    }
  }

  public function testDdevConfigFailure(): void {
    // Simulate that DDEV will fail at whatever it tries to do.
    $this->mockDdev('exit 1');
    $this->assertSame(3, $this->launch()->run());
    $this->assertDirectoryDoesNotExist($this->dir . '/.ddev');

    $finder = Finder::create()->in($this->dir);
    $this->assertGreaterThan(0, count($finder));
  }

  public function testDdevComposerCreateFailure(): void {
    // Simulate a DDEV project that is fully configured but not built, and that
    // DDEV will fail at whatever it tries to do.
    $this->touch('.ddev/config.yaml');
    $this->mockDdev('exit 1');
    $this->assertGreaterThan(0, $this->launch()->run());

    // The only thing that should be in the `.ddev` directory is `config.yaml`.
    $finder = Finder::create()->in($this->dir . '/.ddev');
    $items = iterator_to_array($finder);
    $items = array_map('basename', array_keys($items));
    $this->assertSame(['config.yaml'], $items);

    // The original files should be restored, including the marker file.
    $finder->in($this->dir)->depth(0);
    $this->assertGreaterThan(1, count($finder));
    $this->assertFileExists($this->dir . '/.drupal-cms');
  }

  public function testDdevComposerFailure(): void {
    // Simulate a DDEV project that is fully configured but not built, and that
    // DDEV will succeed at whatever it tries to do.
    $this->touch('.ddev/config.yaml');
    $this->mockDdev('exit 0');

    $process = $this->launch();
    $this->assertSame(3, $process->run());
    $this->assertStringContainsString('This project does not appear to have been set up correctly with Composer.', $process->getOutput());
  }

  public function testExtantProjectIsStarted(): void {
    // Simulate a DDEV project that is fully configured and built.
    $this->touch('.ddev/config.yaml');
    $this->touch('web/index.php');

    // Simulate a failing container health check.
    $this->mockDdev(<<<'END'
if [ "$1" = "php" ]; then
  exit 1
else
  echo "$1" >> ddev.log
  exit 0
fi
END
);
    $this->launch()->mustRun();

    // Confirm that `ddev start` was called and the project was launched.
    $this->assertSame("start\nlaunch\n", file_get_contents($this->dir . '/ddev.log'));
  }

  private function touch(string $file): void {
    $file = $this->dir . '/' . $file;

    $this->fileSystem->mkdir(dirname($file));
    $this->fileSystem->touch($file);
  }

  private function mockDdev(string $script): void {
    $file = $this->dir . '/ddev';
    file_put_contents($file, "#!/usr/bin/env sh\n$script");
    $this->fileSystem->chmod($file, 0755);
    $this->assertTrue(is_executable($file), "The mocked DDEV script is not executable.");
  }

}
