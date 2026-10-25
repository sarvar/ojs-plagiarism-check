<?php

/**
 * @file plugins/generic/plagiarismCheck/classes/providers/ExternalProvider.inc.php
 *
 * Distributed under the GNU GPL v3.
 *
 * @class ExternalProvider
 * @ingroup plugins_generic_plagiarismCheck
 *
 * @brief Contract for a paid external plagiarism service (StrikePlagiarism,
 *  Antiplagiat, Copyleaks, iThenticate, ...). The local check works without
 *  any provider. To add one:
 *
 *   1. Create classes/providers/<Name>Provider.inc.php implementing this interface.
 *   2. Add it to PlagiarismCheckPlugin::getExternalProviders() (or register it
 *      from another plugin with the `PlagiarismCheck::externalProviders` hook).
 *   3. Choose it in the plugin settings and enter the API URL and key.
 *
 *  Editors then get a "Send to <provider>" action next to the local score and
 *  the scheduled task polls for the result.
 */

interface ExternalProvider {

	/**
	 * @return string Short machine id stored with results, e.g. "strike"
	 */
	public function getId();

	/**
	 * @return string Name shown to editors
	 */
	public function getName();

	/**
	 * @param string $apiUrl
	 * @param string $apiKey
	 */
	public function setCredentials($apiUrl, $apiKey);

	/**
	 * Upload a file for checking.
	 *
	 * @param string $path Absolute path of the file
	 * @param string $filename Original file name
	 * @param array $metadata title, authors, submissionId, locale
	 * @return string The provider's id for this check
	 * @throws Exception On API errors
	 */
	public function submit($path, $filename, $metadata);

	/**
	 * Ask for the result of a previous submit().
	 *
	 * @param string $externalId
	 * @return array|null null while still processing, otherwise
	 *  ['score' => float percent, 'reportUrl' => string]
	 * @throws Exception On API errors
	 */
	public function fetchResult($externalId);
}
