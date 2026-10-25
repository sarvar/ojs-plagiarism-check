<?php

/**
 * @file plugins/generic/plagiarismCheck/controllers/PlagiarismCheckPageHandler.inc.php
 *
 * Distributed under the GNU GPL v3.
 *
 * @class PlagiarismCheckPageHandler
 * @ingroup plugins_generic_plagiarismCheck
 *
 * @brief Back-office page listing all plagiarism check results, linked from
 *  the editorial sidebar. Managers, section editors and site admins only.
 */

import('classes.handler.Handler');

class PlagiarismCheckPageHandler extends Handler {

	/** @copydoc PKPHandler::_isBackendPage */
	var $_isBackendPage = true;

	/** @var PlagiarismCheckPlugin */
	protected static $_plugin;

	/**
	 * @param PlagiarismCheckPlugin $plugin
	 */
	public static function setPlugin($plugin) {
		self::$_plugin = $plugin;
	}

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct();
		$this->addRoleAssignment([ROLE_ID_SITE_ADMIN, ROLE_ID_MANAGER, ROLE_ID_SUB_EDITOR], ['index']);
	}

	/**
	 * @copydoc PKPHandler::authorize()
	 */
	public function authorize($request, &$args, $roleAssignments) {
		import('lib.pkp.classes.security.authorization.ContextAccessPolicy');
		$this->addPolicy(new ContextAccessPolicy($request, $roleAssignments));
		return parent::authorize($request, $args, $roleAssignments);
	}

	/**
	 * List every checked file of the journal, most similar first.
	 *
	 * @param array $args
	 * @param PKPRequest $request
	 */
	public function index($args, $request) {
		$this->setupTemplate($request);
		$context = $request->getContext();
		$service = self::$_plugin->getService();
		$documents = $service->getDAO()->getByContextId($context->getId(), PHP_INT_MAX);
		$dispatcher = $request->getDispatcher();
		$reportUrls = [];
		$workflowUrls = [];
		foreach ($documents as $document) {
			$reportUrls[$document->document_id] = self::$_plugin->getHandlerUrl($request, 'report', ['documentId' => $document->document_id]);
			$workflowUrls[$document->document_id] = $dispatcher->url($request, ROUTE_PAGE, null, 'workflow', 'access', [$document->submission_id]);
		}

		$templateMgr = TemplateManager::getManager($request);
		// Vue strips <style>/<script> from page content, so register them instead
		$pluginPath = self::$_plugin->getPluginPath();
		$templateMgr->addStyleSheet('plagiarismCheckResults', file_get_contents($pluginPath . '/css/resultsPage.css'), ['contexts' => 'backend', 'inline' => true]);
		$templateMgr->addJavaScript('plagiarismCheckResults', file_get_contents($pluginPath . '/js/resultsPage.js'), ['contexts' => 'backend', 'inline' => true]);
		$templateMgr->assign([
			'pageTitle' => __('plugins.generic.plagiarismCheck.results.title'),
			'documents' => $documents,
			'reportUrls' => $reportUrls,
			'workflowUrls' => $workflowUrls,
			'statusCounts' => $service->getDAO()->getStatusCounts($context->getId()),
			'warningThreshold' => (float) $service->getSettings($context->getId())['warningThreshold'],
		]);
		$templateMgr->display(self::$_plugin->getTemplateResource('resultsPage.tpl'));
	}
}
