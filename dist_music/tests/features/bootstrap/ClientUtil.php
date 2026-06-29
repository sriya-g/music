<?php

/**
 * Nextcloud Music app
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Pauli Järvinen <pauli.jarvinen@gmail.com>
 * @copyright Pauli Järvinen 2021 - 2026
 */

/**
 * Common utilities for AmpacheClient and SubsonicClient
 */
class ClientUtil {

	/**
	 * Get XML from HTTP response. There used to be method $response->xml() in Guzzle 5.x but not
	 * anymore in 6.x. The logic below is a modified copy from the old xml() method.
	 */
	public static function getXml($response, array $config = []) {
		$internalErrors = \libxml_use_internal_errors(true);
		try {
			// Allow XML to be retrieved even if there is no response body
			$xml = new \SimpleXMLElement(
				(string) $response->getBody() ?: '<root />',
				isset($config['libxml_options']) ? $config['libxml_options'] : LIBXML_NONET,
				false,
				isset($config['ns']) ? $config['ns'] : '',
				isset($config['ns_is_prefix']) ? $config['ns_is_prefix'] : false
			);
			\libxml_use_internal_errors($internalErrors);
		} catch (\Exception $e) {
			\libxml_use_internal_errors($internalErrors);
			throw new Exception(
					'Unable to parse response body into XML: ' . $e->getMessage() .
					'; libxml error: ' . \libxml_get_last_error()->message
			);
		}
		return $xml;
	}

}
