<?php

/**
 * @file plugins/generic/plagiarismCheck/controllers/PlagiarismCheckHandler.inc.php
 *
 * Distributed under the GNU GPL v3.
 *
 * @class PlagiarismCheckHandler
 * @ingroup plugins_generic_plagiarismCheck
 *
 * @brief Run checks from the file grid and show similarity reports. Only
 *  journal managers, section editors and site admins have access.
 */

import('classes.handler.Handler');
import('lib.pkp.classes.core.JSONMessage');

class PlagiarismCheckHandler extends Handler {

	/** @var PlagiarismCheckPlugin */
	protected static $_plugin;

	/** Operations that act on one submission file */
	const FILE_OPS = ['check', 'sendExternal'];

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
		$this->addRoleAssignment(
			[ROLE_ID_SITE_ADMIN, ROLE_ID_MANAGER, ROLE_ID_SUB_EDITOR],
			['check', 'sendExternal', 'report']
		);
	}

	/**
	 * @copydoc PKPHandler::authorize()
	 */
	public function authorize($request, &$args, $roleAssignments) {
		import('lib.pkp.classes.security.authorization.ContextAccessPolicy');
		$this->addPolicy(new ContextAccessPolicy($request, $roleAssignments));

		if (in_array($request->getRequestedOp(), self::FILE_OPS)) {
			import('lib.pkp.classes.security.authorization.SubmissionFileAccessPolicy');
			$this->addPolicy(new SubmissionFileAccessPolicy($request, $args, $roleAssignments, SUBMISSION_FILE_ACCESS_READ, (int) $request->getUserVar('submissionFileId')));
		}
		return parent::authorize($request, $args, $roleAssignments);
	}

	/**
	 * Check (or re-check) a file right away.
	 *
	 * @param array $args
	 * @param PKPRequest $request
	 * @return JSONMessage
	 */
	public function check($args, $request) {
		if (!$request->checkCSRF()) {
			return new JSONMessage(false);
		}
		$submissionFile = $this->getAuthorizedContextObject(ASSOC_TYPE_SUBMISSION_FILE);
		$service = self::$_plugin->getService();
		$sourceType = $submissionFile->getData('fileStage') == SUBMISSION_FILE_PROOF
			? PlagiarismCheckDAO::SOURCE_GALLEY
			: PlagiarismCheckDAO::SOURCE_SUBMISSION;

		$documentId = $service->enqueueSubmissionFile($submissionFile, $sourceType, true);
		if (!$documentId) {
			return new JSONMessage(false, __('plugins.generic.plagiarismCheck.error.unsupported'));
		}
		$service->processDocument($documentId);
		return DAO::getDataChangedEvent($submissionFile->getId());
	}

	/**
	 * Send a checked file to the configured external provider.
	 *
	 * @param array $args
	 * @param PKPRequest $request
	 * @return JSONMessage
	 */
	public function sendExternal($args, $request) {
		if (!$request->checkCSRF()) {
			return new JSONMessage(false);
		}
		$submissionFile = $this->getAuthorizedContextObject(ASSOC_TYPE_SUBMISSION_FILE);
		$service = self::$_plugin->getService();
		$document = $service->getDAO()->getBySubmissionFileId($submissionFile->getId());
		if (!$document) {
			return new JSONMessage(false);
		}
		try {
			$service->sendToExternal($document->document_id);
		} catch (Throwable $e) {
			return new JSONMessage(false, $e->getMessage());
		}
		return DAO::getDataChangedEvent($submissionFile->getId());
	}

	/**
	 * Full similarity report of one document.
	 *
	 * @param array $args
	 * @param PKPRequest $request
	 * @return string
	 */
	public function report($args, $request) {
		$context = $request->getContext();
		$service = self::$_plugin->getService();
		$document = $service->getDAO()->getById((int) $request->getUserVar('documentId'));
		if (!$document || (int) $document->context_id !== (int) $context->getId()) {
			$request->getDispatcher()->handle404();
		}

		$report = $service->getReport($document->document_id);
		$sourceLinks = [];
		if ($report) {
			foreach ($report['result']['sources'] as $index => $source) {
				$sourceLinks[$index] = $source['submissionId']
					? $request->getDispatcher()->url($request, ROUTE_PAGE, null, 'workflow', 'access', [$source['submissionId']])
					: null;
			}
		}

		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign([
			'stylesTemplate' => self::$_plugin->getTemplateResource('styles.tpl'),
			'document' => $document,
			'report' => $report,
			'sourceLinks' => $sourceLinks,
			'workflowUrl' => $request->getDispatcher()->url($request, ROUTE_PAGE, null, 'workflow', 'access', [$document->submission_id]),
			'warningThreshold' => (float) $service->getSettings($context->getId())['warningThreshold'],
		]);
		return $templateMgr->fetch(self::$_plugin->getTemplateResource('report.tpl'));
	}
}
