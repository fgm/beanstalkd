<?php
/**
 * @file
 * Contains Payload.
 */

namespace Drupal\beanstalkd\Server;

use Pheanstalk\Exception\ClientException;

/**
 * Class Payload contains item data as passed to Pheanstalk.
 *
 * It may also contain instructions telling the server to submit the data with
 * specific options, like a delay, priority or time to run.
 *
 * @see Drupal\beanstalkd\Queue\Item
 */
class Payload {

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
        throw new ClientException('Payload cannot be serialized, so it cannot be passed to Beanstalkd');
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
