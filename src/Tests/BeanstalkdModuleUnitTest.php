<?php

/**
 * @file
 * Contains BeanstalkdModuleUnitTest.
 */

namespace Drupal\beanstalkd\Tests;

use Drupal\Tests\UnitTestCase;

/**
 * Class BeanstalkModuleUnitTest.
 *
 * @group Beanstalkd
 */
class BeanstalkdModuleUnitTest extends UnitTestCase {

  /**
   * Test invalid and install phases.
   *
   * Equivalent happy test is in BeanstalkdModuleKernelTest.
   */
  public function testRequirementsSad() {
    require_once __DIR__ . '/../../beanstalkd.install.php';

    foreach (['invalid', 'install'] as $phase) {
      $actual = beanstalkd_requirements($phase);
      $expected = [];
      $this->assertEquals($expected, $actual,
        strtr('Not requirements reported for @phase phase', [
          '@phase' => $phase,
        ]));
    }
  }

}
