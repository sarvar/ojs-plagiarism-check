<?php

/**
 * @file plugins/generic/plagiarismCheck/PlagiarismCheckPlugin.inc.php
 *
 * Distributed under the GNU GPL v3.
 *
 * @class PlagiarismCheckPlugin
 * @ingroup plugins_generic_plagiarismCheck
 *
 * @brief Local plagiarism check: compares manuscripts with every article and
 *  submission already in this OJS installation, with an optional external
 *  provider for checks against the wider web.
 */

import('lib.pkp.classes.plugins.GenericPlugin');

class PlagiarismCheckPlugin extends GenericPlugin {

	/** @var PlagiarismCheckService */
	protected $_service;

	/** Component handlers this plugin serves */
	const HANDLER = 'plugins.generic.plagiarismCheck.controllers.PlagiarismCheckHandler';

	/** Back-office page with all results (index.php/<journal>/plagiarismCheck) */
	const PAGE = 'plagiarismCheck';

	/**
	 * @copydoc Plugin::register()
	 */
	public function register($category, $path, $mainContextId = null) {
		$success = parent::register($category, $path, $mainContextId);
		if (!$success || !$this->getEnabled($mainContextId)) {
			return $success;
		}

		HookRegistry::register('submissionsubmitstep4form::execute', [$this, 'callbackSubmissionCompleted']);
		HookRegistry::register('Publication::publish', [$this, 'callbackPublished']);
		HookRegistry::register('SubmissionFile::delete', [$this, 'callbackFileDeleted']);
		HookRegistry::register('Submission::delete', [$this, 'callbackSubmissionDeleted']);
		HookRegistry::register('editorsubmissiondetailsfilesgridhandler::initfeatures', [$this, 'callbackAddGridColumn']);
		HookRegistry::register('editorreviewfilesgridhandler::initfeatures', [$this, 'callbackAddGridColumn']);
		HookRegistry::register('LoadComponentHandler', [$this, 'callbackLoadHandler']);
		HookRegistry::register('AcronPlugin::parseCronTab', [$this, 'callbackParseCronTab']);
		HookRegistry::register('LoadHandler', [$this, 'callbackLoadPage']);
		HookRegistry::register('TemplateManager::setupBackendPage', [$this, 'callbackAddMenuItem']);

		return $success;
	}

	/**
	 * @copydoc Plugin::getDisplayName()
	 */
	public function getDisplayName() {
		return __('plugins.generic.plagiarismCheck.displayName');
	}

	/**
	 * @copydoc Plugin::getDescription()
	 */
	public function getDescription() {
		return __('plugins.generic.plagiarismCheck.description');
	}

	/**
	 * @copydoc Plugin::getInstallMigration()
	 */
	public function getInstallMigration() {
		$this->import('PlagiarismCheckSchemaMigration');
		return new PlagiarismCheckSchemaMigration();
	}

	/**
	 * @copydoc Plugin::setEnabled()
	 *
	 * Create the tables when the plugin is enabled from the plugin gallery or
	 * the plugin list, where OJS does not run install migrations.
	 */
	public function setEnabled($enabled) {
		if ($enabled) {
			$this->getInstallMigration()->up();
		}
		parent::setEnabled($enabled);
	}

	/**
	 * @return PlagiarismCheckService
	 */
	public function getService() {
		if (!$this->_service) {
			$this->import('classes.PlagiarismCheckService');
			$this->_service = new PlagiarismCheckService($this);
		}
		return $this->_service;
	}

	/**
	 * External providers available to choose in the settings, keyed by id.
	 * None are bundled yet; see classes/providers/ExternalProvider.inc.php.
	 *
	 * @return ExternalProvider[]
	 */
	public function getExternalProviders() {
		$this->import('classes.providers.ExternalProvider');
		$providers = [];
		HookRegistry::call('PlagiarismCheck::externalProviders', [&$providers]);
		return $providers;
	}

	/**
	 * Whether the current user may see plagiarism results (editors only).
	 *
	 * @param User|null $user
	 * @param Context|null $context
	 * @return bool
	 */
	public function canViewResults($user, $context) {
		if (!$user || !$context) {
			return false;
		}
		return $user->hasRole([ROLE_ID_MANAGER, ROLE_ID_SUB_EDITOR], $context->getId())
			|| $user->hasRole([ROLE_ID_SITE_ADMIN], CONTEXT_SITE);
	}

	//
	// Hook callbacks
	//

	/**
	 * Queue the manuscript files when an author completes a submission.
	 *
	 * @param string $hookName `submissionsubmitstep4form::execute`
	 * @param array $args [SubmissionSubmitStep4Form]
	 * @return bool
	 */
	public function callbackSubmissionCompleted($hookName, $args) {
		$form = $args[0];
		$submission = $form->submission;
		$settings = $this->getService()->getSettings($submission->getData('contextId'));
		if ($settings['autoCheckSubmissions']) {
			try {
				$this->getService()->enqueueSubmission($submission);
			} catch (Throwable $e) {
				// Never block a submission because of the plagiarism check
				error_log('plagiarismCheck: could not queue submission ' . $submission->getId() . ': ' . $e->getMessage());
			}
		}
		return false;
	}

	/**
	 * Index and check galley files when an article is published, so new
	 * articles become sources for future checks.
	 *
	 * @param string $hookName `Publication::publish`
	 * @param array $args [Publication $newPublication, Publication $publication, Submission $submission]
	 * @return bool
	 */
	public function callbackPublished($hookName, $args) {
		$newPublication = $args[0];
		$submission = $args[2];
		$settings = $this->getService()->getSettings($submission->getData('contextId'));
		if ($settings['checkPublishedGalleys']) {
			try {
				$this->getService()->enqueuePublicationGalleys($newPublication);
			} catch (Throwable $e) {
				error_log('plagiarismCheck: could not queue galleys of submission ' . $submission->getId() . ': ' . $e->getMessage());
			}
		}
		return false;
	}

	/**
	 * Remove a deleted file's text from the index so it is no longer a source.
	 *
	 * @param string $hookName `SubmissionFile::delete`
	 * @param array $args [SubmissionFile]
	 * @return bool
	 */
	public function callbackFileDeleted($hookName, $args) {
		$dao = $this->getService()->getDAO();
		$document = $dao->getBySubmissionFileId($args[0]->getId());
		if ($document) {
			$dao->deleteById($document->document_id);
		}
		return false;
	}

	/**
	 * Remove all documents of a deleted submission.
	 *
	 * @param string $hookName `Submission::delete`
	 * @param array $args [Submission]
	 * @return bool
	 */
	public function callbackSubmissionDeleted($hookName, $args) {
		$this->getService()->getDAO()->deleteBySubmissionId($args[0]->getId());
		return false;
	}

	/**
	 * Add the similarity column to the editor's submission and review file grids.
	 *
	 * @param string $hookName `...gridhandler::initfeatures`
	 * @param array $args [GridHandler, Request, array $args, array &$features]
	 * @return bool
	 */
	public function callbackAddGridColumn($hookName, $args) {
		$request = Application::get()->getRequest();
		if (!$this->canViewResults($request->getUser(), $request->getContext())) {
			return false;
		}
		$grid = $args[0];
		$features =& $args[3];
		$this->import('grids.SimilarityGridColumn');
		$this->import('grids.MoveColumnFeature');
		$grid->addColumn(new SimilarityGridColumn($this));
		$features[] = new MoveColumnFeature($grid);
		return false;
	}

	/**
	 * Route the plugin's component handler.
	 *
	 * @param string $hookName `LoadComponentHandler`
	 * @param array $args [string &$component, ...]
	 * @return bool
	 */
	public function callbackLoadHandler($hookName, $args) {
		$component =& $args[0];
		if ($component !== self::HANDLER) {
			return false;
		}
		$this->import('controllers.PlagiarismCheckHandler');
		PlagiarismCheckHandler::setPlugin($this);
		return true;
	}

	/**
	 * Serve the results page at index.php/<journal>/plagiarismCheck.
	 *
	 * @param string $hookName `LoadHandler`
	 * @param array $args [string &$page, string &$op, string &$sourceFile]
	 * @return bool
	 */
	public function callbackLoadPage($hookName, $args) {
		if ($args[0] !== self::PAGE) {
			return false;
		}
		$this->import('controllers.PlagiarismCheckPageHandler');
		PlagiarismCheckPageHandler::setPlugin($this);
		define('HANDLER_CLASS', 'PlagiarismCheckPageHandler');
		return true;
	}

	/**
	 * Add the results page to the editorial sidebar, under Statistics.
	 *
	 * @param string $hookName `TemplateManager::setupBackendPage`
	 * @param array $args []
	 * @return bool
	 */
	public function callbackAddMenuItem($hookName, $args) {
		$request = Application::get()->getRequest();
		if (!$this->canViewResults($request->getUser(), $request->getContext())) {
			return false;
		}
		$templateMgr = TemplateManager::getManager($request);
		$menu = (array) $templateMgr->getState('menu');
		$item = [
			'name' => __('plugins.generic.plagiarismCheck.menu.title'),
			'url' => $this->getResultsPageUrl($request),
			'isCurrent' => $request->getRequestedPage() === self::PAGE,
		];
		if (isset($menu['statistics']['submenu'])) {
			$menu['statistics']['submenu']['plagiarismCheck'] = $item;
		} else {
			// Site admins without a journal role have no Statistics section
			$menu['plagiarismCheck'] = $item;
		}
		$templateMgr->setState(['menu' => $menu]);
		return false;
	}

	/**
	 * Let the Acron plugin run the queue when no server cron job is set up.
	 *
	 * @param string $hookName `AcronPlugin::parseCronTab`
	 * @param array $args [array &$taskFilesPath]
	 * @return bool
	 */
	public function callbackParseCronTab($hookName, $args) {
		$taskFilesPath =& $args[0];
		$taskFilesPath[] = $this->getPluginPath() . DIRECTORY_SEPARATOR . 'scheduledTasks.xml';
		return false;
	}

	//
	// Settings
	//

	/**
	 * @copydoc Plugin::getActions()
	 */
	public function getActions($request, $actionArgs) {
		$actions = parent::getActions($request, $actionArgs);
		if (!$this->getEnabled()) {
			return $actions;
		}
		$router = $request->getRouter();
		import('lib.pkp.classes.linkAction.request.AjaxModal');
		import('lib.pkp.classes.linkAction.request.RedirectAction');
		array_unshift(
			$actions,
			new LinkAction(
				'settings',
				new AjaxModal(
					$router->url($request, null, null, 'manage', null, ['verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic']),
					$this->getDisplayName()
				),
				__('manager.plugins.settings'),
				null
			),
			new LinkAction(
				'results',
				new RedirectAction($this->getResultsPageUrl($request)),
				__('plugins.generic.plagiarismCheck.results.title'),
				null
			)
		);
		return $actions;
	}

	/**
	 * @copydoc Plugin::manage()
	 */
	public function manage($args, $request) {
		if ($request->getUserVar('verb') !== 'settings') {
			return parent::manage($args, $request);
		}
		$context = $request->getContext();
		AppLocale::requireComponents(LOCALE_COMPONENT_APP_COMMON, LOCALE_COMPONENT_PKP_MANAGER);
		$this->import('PlagiarismCheckSettingsForm');
		$form = new PlagiarismCheckSettingsForm($this, $context->getId());
		if ($request->getUserVar('save')) {
			$form->readInputData();
			if ($form->validate()) {
				$form->execute();
				return new JSONMessage(true);
			}
		} else {
			$form->initData();
		}
		return new JSONMessage(true, $form->fetch($request));
	}

	/**
	 * @param PKPRequest $request
	 * @return string URL of the results page
	 */
	public function getResultsPageUrl($request) {
		return $request->getDispatcher()->url($request, ROUTE_PAGE, null, self::PAGE);
	}

	/**
	 * URL of an operation in the plugin's component handler.
	 *
	 * @param PKPRequest $request
	 * @param string $op
	 * @param array $params
	 * @return string
	 */
	public function getHandlerUrl($request, $op, $params = []) {
		return $request->getDispatcher()->url($request, ROUTE_COMPONENT, null, self::HANDLER, $op, null, $params);
	}
}
