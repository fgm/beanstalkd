<?php

/**
 * @file
 * Contains BeanstalkdModuleUnitTest.
 */

namespace Drupal\beanstalkd\Tests;

/**
 * Class BeanstalkModuleUnitTest.
 *
 * @group Beanstalkd
 */
class BeanstalkdModuleKernelTest extends BeanstalkdTestBase {

  public static $modules = ['beanstalkd'];

  /**
   * Test invalid and install phases.
   *
   * Equivalent sad test is in BeanstalkdModuleUnitTest.
   */
  public function testRequirementsHappy() {
    require_once __DIR__ . '/../../beanstalkd.install.php';

    $requirements = beanstalkd_requirements('runtime');
    $this->assertTrue(is_array($requirements), 'hook_requirements() returns an array.');
    $this->assertTrue(isset($requirements['pheanstalk']), 'hook_requirements() returns a "pheanstalk" key in array');

    $actual = array_keys($requirements);
    sort($actual);

    $servers = $this->serverFactory->getServerDefinitions();

    $initial = 0;
    $expected = array_reduce($servers, function ($carry) use($initial) {
      $carry[] = 'beanstalkd-' . $initial;
      $initial++;
      return $carry;
    }, ['pheanstalk']);
    sort($expected);
    $this->assertEquals($expected, $actual, 'hook_requirements() contains the expected beanstalk-* keys');
  }

}
