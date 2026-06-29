<?php declare(strict_types=1);
/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Alessandro Cosentino <cosenal@gmail.com>
 * @author Bernhard Posselt <dev@bernhard-posselt.com>
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Alessandro Cosentino 2012
 * @copyright Bernhard Posselt 2012, 2014
 * @copyright Pauli Järvinen 2020 - 2026
 */

namespace OCA\Music\Tests\Utility;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Response;

/**
 * Simple utility class for testing controllers
 */
abstract class ControllerTestUtility extends \PHPUnit\Framework\TestCase {

	/**
	 * Checks if a controller method has the expected attributes
	 * @param Controller|string $controller name or instance of the controller
	 * @param string[] $expected an array containing the expected attributes
	 * @param string[] $valid if you define your own attributes, pass them here
	 */
	protected function assertAttributes(Controller|string $controller, string $method, array $expected, array $valid=[]) {
		$standard = [
			\OCP\AppFramework\Http\Attribute\PublicPage::class,
			\OCP\AppFramework\Http\Attribute\NoAdminRequired::class,
			\OCP\AppFramework\Http\Attribute\NoCSRFRequired::class,
			\OCP\AppFramework\Http\Attribute\CORS::class
		];

		$possible = \array_merge($standard, $valid);

		// check if expected annotations are valid
		foreach ($expected as $attrName) {
			$this->assertTrue(\in_array($attrName, $possible));
		}

		$reflection = new \ReflectionMethod($controller, $method);
		foreach ($expected as $attrName) {
			$this->assertNotEmpty($reflection->getAttributes($attrName), "attribute $attrName on method $method");
		}
	}

	/**
	 * Shortcut for testing expected headers of a response
	 * @param array $expected an array with the expected headers
	 * @param Response $response the response which we want to test for headers
	 */
	protected function assertHeaders(array $expected, Response $response) {
		$headers = $response->getHeaders();
		foreach ($expected as $header) {
			$this->assertTrue(\in_array($header, $headers));
		}
	}

	/**
	 * Instead of using positional parameters this function instantiates
	 * a request by using a hashmap so its easier to only set specific params
	 * @param array $params a hashmap with the parameters for request
	 * @return \PHPUnit\Framework\MockObject\MockObject acting as \OCP\IRequest instance
	 */
	protected function getRequest(array $params=[]) : \PHPUnit\Framework\MockObject\MockObject {
		$mock = $this->getMockBuilder('\OCP\IRequest')->getMock();

		$merged = [];

		foreach ($params as $key => $value) {
			$merged = \array_merge($value, $merged);
		}

		$mock->expects($this->any())
			->method('getParam')
			->will($this->returnCallback(function ($index, $default) use ($merged) {
				if (\array_key_exists($index, $merged)) {
					return $merged[$index];
				} else {
					return $default;
				}
			}));

		// attribute access
		if (\array_key_exists('server', $params)) {
			$mock->server = $params['server'];
		}

		return $mock;
	}
}
