<?php
/**
 * @file
 * Containts Item.
 */

namespace Drupal\beanstalkd\Server;

use Pheanstalk\Exception\ClientException;

/**
 * Class Item contains job data as passed to Pheanstalk.
 */
class Item {

  protected $isSerialized = FALSE;

  protected $data;

  /**
   * Constructor.
   *
   * @param mixed $data
   *   The data to pass as workload.
   *
   * @throws \Pheanstalk\Exception\ClientException
   *   If the data cannot be serialized.
   */
  public function __construct($data) {
    if (is_scalar($data)) {
      $this->data = $data;
    }
    else {
      try {
        $this->data = serialize($data);
        $this->isSerialized = TRUE;
      }
      catch (\Exception $pokemon) {
        throw new ClientException('Item cannot be serialized, so it cannot be passed to Beanstalkd');
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function __toString() {
    return '' . $this->data;
  }

}
