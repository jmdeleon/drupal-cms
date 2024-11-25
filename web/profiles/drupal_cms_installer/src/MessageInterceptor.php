<?php

declare(strict_types=1);

namespace Drupal\drupal_cms_installer;

use Drupal\Core\Messenger\MessengerInterface;

/**
 * Decorates the messenger to suppress or alter certain install-time messages.
 */
final class MessageInterceptor implements MessengerInterface {

  public function __construct(
    private readonly MessengerInterface $decorated,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function addMessage($message, $type = self::TYPE_STATUS, $repeat = FALSE): static {
    if (strval($message) !== 'Congratulations, you installed Drupal CMS!') {
      $this->decorated->addMessage($message, $type, $repeat);
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function addStatus($message, $repeat = FALSE): static {
    return $this->addMessage($message, self::TYPE_STATUS, $repeat);
  }

  /**
   * {@inheritdoc}
   */
  public function addError($message, $repeat = FALSE): static {
    return $this->addMessage($message, self::TYPE_ERROR, $repeat);
  }

  /**
   * {@inheritdoc}
   */
  public function addWarning($message, $repeat = FALSE): static {
    return $this->addMessage($message, self::TYPE_WARNING, $repeat);
  }

  /**
   * {@inheritdoc}
   */
  public function all(): array {
    return $this->decorated->all();
  }

  /**
   * {@inheritdoc}
   */
  public function messagesByType($type): array {
    return $this->decorated->messagesByType($type);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAll(): array {
    return $this->decorated->deleteAll();
  }

  /**
   * {@inheritdoc}
   */
  public function deleteByType($type): array {
    return $this->decorated->deleteByType($type);
  }

}
