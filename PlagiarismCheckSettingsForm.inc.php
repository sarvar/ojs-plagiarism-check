<?php

/**
 * @file plugins/generic/plagiarismCheck/PlagiarismCheckSettingsForm.inc.php
 *
 * Distributed under the GNU GPL v3.
 *
 * @class PlagiarismCheckSettingsForm
 * @ingroup plugins_generic_plagiarismCheck
 *
 * @brief Per-journal settings for the plagiarism check.
 */

import('lib.pkp.classes.form.Form');
import('plugins.generic.plagiarismCheck.classes.TextExtractor');

class PlagiarismCheckSettingsForm extends Form {

	/** @var PlagiarismCheckPlugin */
	protected $_plugin;

	/** @var int */
	protected $_contextId;

	/** Checkbox settings */
	const BOOLEANS = ['autoCheckSubmissions', 'checkPublishedGalleys', 'excludeBibliography', 'excludeQuotes'];

	/** Text and number settings */
	const VALUES = ['minMatchWords', 'commonDocs', 'warningThreshold', 'pdftotextPath', 'externalProvider', 'externalApiUrl', 'externalApiKey'];

	/**
	 * @param PlagiarismCheckPlugin $plugin
	 * @param int $contextId
	 */
	public function __construct($plugin, $contextId) {
		$this->_plugin = $plugin;
		$this->_contextId = $contextId;
		parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));

		$this->addCheck(new FormValidatorCustom($this, 'minMatchWords', 'required', 'plugins.generic.plagiarismCheck.settings.minMatchWords.invalid', function ($value) {
			return ctype_digit((string) $value) && $value >= 3 && $value <= 100;
		}));
		$this->addCheck(new FormValidatorCustom($this, 'commonDocs', 'required', 'plugins.generic.plagiarismCheck.settings.commonDocs.invalid', function ($value) {
			return ctype_digit((string) $value) && ($value == 0 || $value >= 2);
		}));
		$this->addCheck(new FormValidatorCustom($this, 'warningThreshold', 'required', 'plugins.generic.plagiarismCheck.settings.warningThreshold.invalid', function ($value) {
			return is_numeric($value) && $value > 0 && $value <= 100;
		}));
		$this->addCheck(new FormValidatorPost($this));
		$this->addCheck(new FormValidatorCSRF($this));
	}

	/**
	 * @copydoc Form::initData()
	 */
	public function initData() {
		$this->_data = $this->_plugin->getService()->getSettings($this->_contextId);
	}

	/**
	 * @copydoc Form::readInputData()
	 */
	public function readInputData() {
		$this->readUserVars(array_merge(self::BOOLEANS, self::VALUES));
	}

	/**
	 * @copydoc Form::fetch()
	 */
	public function fetch($request, $template = null, $display = false) {
		$extractor = new TextExtractor($this->getData('pdftotextPath') ?: null);
		$providers = ['' => __('plugins.generic.plagiarismCheck.settings.externalProvider.none')];
		foreach ($this->_plugin->getExternalProviders() as $id => $provider) {
			$providers[$id] = $provider->getName();
		}
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign([
			'pluginName' => $this->_plugin->getName(),
			'pdftotextAvailable' => $extractor->canUsePdftotext(),
			'externalProviders' => $providers,
			'statusCounts' => $this->_plugin->getService()->getDAO()->getStatusCounts($this->_contextId),
		]);
		return parent::fetch($request, $template, $display);
	}

	/**
	 * @copydoc Form::execute()
	 */
	public function execute(...$functionArgs) {
		foreach (self::BOOLEANS as $name) {
			$this->_plugin->updateSetting($this->_contextId, $name, (bool) $this->getData($name), 'bool');
		}
		foreach (['minMatchWords', 'commonDocs'] as $name) {
			$this->_plugin->updateSetting($this->_contextId, $name, (int) $this->getData($name), 'int');
		}
		$this->_plugin->updateSetting($this->_contextId, 'warningThreshold', (float) $this->getData('warningThreshold'), 'string');
		foreach (['pdftotextPath', 'externalProvider', 'externalApiUrl', 'externalApiKey'] as $name) {
			$this->_plugin->updateSetting($this->_contextId, $name, trim((string) $this->getData($name)), 'string');
		}
		parent::execute(...$functionArgs);
	}
}
