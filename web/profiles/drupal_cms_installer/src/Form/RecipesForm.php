<?php

namespace Drupal\drupal_cms_installer\Form;

use Composer\InstalledVersions;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element\Checkboxes;

/**
 * Provides a form to choose the site template and optional add-on recipes.
 *
 * @todo Present this as a mini project browser once
 *   https://www.drupal.org/i/3450629 is fixed.
 */
final class RecipesForm extends InstallerFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'drupal_cms_installer_recipes_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#title'] = $this->t('What are your top goals?');

    $form['help'] = [
      '#prefix' => '<p class="cms-installer__subhead">',
      '#markup' => $this->t('You can change your mind later.'),
      '#suffix' => '</p>',
    ];

    $form['add_ons'] = [
      '#prefix' => '<div class="cms-installer__form-group">',
      '#suffix' => '</div>',
      '#type' => 'checkboxes',
      '#value_callback' => static::class . '::valueCallback',
    ];

    // @todo Remove this try-catch wrapper when all our components are published
    //   on Packagist.
    try {
      $base_recipe_path = InstalledVersions::getInstallPath('drupal/drupal_cms_starter');
    }
    catch (\OutOfBoundsException) {
      ['install_path' => $base_recipe_path] = InstalledVersions::getRootPackage();
      $base_recipe_path .= '/recipes/drupal_cms_starter';
    }
    $cookbook_path = dirname($base_recipe_path);

    // Read the list of optional recipes from the base recipe's `composer.json`.
    $composer = file_get_contents($base_recipe_path . '/composer.json');
    $composer = json_decode($composer, TRUE, flags: JSON_THROW_ON_ERROR);

    $optional_recipes = array_keys($composer['suggest'] ?? []);
    foreach ($optional_recipes as $name) {
      $recipe = $cookbook_path . '/' . basename($name) . '/recipe.yml';
      if (file_exists($recipe)) {
        $recipe = file_get_contents($recipe);
        $recipe = Yaml::decode($recipe);
        $key = basename($name);
        $form['add_ons']['#options'][$key] = $recipe['name'];
      }
    }

    $form['actions'] = [
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Next'),
        '#button_type' => 'primary',
        '#op' => 'submit',
      ],
      'skip' => [
        '#type' => 'submit',
        '#value' => $this->t('Skip this step'),
        '#op' => 'skip',
      ],
      '#type' => 'actions',
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    global $install_state;
    $install_state['parameters']['recipes'] = ['drupal_cms_starter'];

    $pressed_button = $form_state->getTriggeringElement();
    // Only choose add-ons if the Next button was pressed, or if the form was
    // submitted programmatically (i.e., by `drush site:install`).
    if (($pressed_button && $pressed_button['#op'] === 'submit') || $form_state->isProgrammed()) {
      $add_ons = $form_state->getValue('add_ons', []);
      $add_ons = array_filter($add_ons);
      array_push($install_state['parameters']['recipes'], ...array_values($add_ons));
    }
  }

  public static function valueCallback(&$element, $input, FormStateInterface $form_state): array {
    // If the input was a comma-separated string or `*`, transform it -- this is
    // for compatibility with `drush site:install`.
    if (is_string($input)) {
      $selections = $input === '*'
        ? array_keys($element['#options'])
        : array_map('trim', explode(',', $input));

      $input = array_combine($selections, $selections);
    }
    return Checkboxes::valueCallback($element, $input, $form_state);
  }

}
