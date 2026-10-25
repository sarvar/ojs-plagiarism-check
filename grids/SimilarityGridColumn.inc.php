<?php

/**
 * @file plugins/generic/plagiarismCheck/grids/SimilarityGridColumn.inc.php
 *
 * Distributed under the GNU GPL v3.
 *
 * @class SimilarityGridColumn
 * @ingroup plugins_generic_plagiarismCheck
 *
 * @brief File grid column showing the similarity score and check actions.
 */

import('lib.pkp.classes.controllers.grid.GridColumn');
import('lib.pkp.classes.controllers.grid.ColumnBasedGridCellProvider');
import('lib.pkp.classes.linkAction.request.RemoteActionConfirmationModal');

class SimilarityGridColumn extends GridColumn {

	const COLUMN_ID = 'plagiarismCheck';

	/** @var PlagiarismCheckPlugin */
	protected $_plugin;

	/** @var array Documents already loaded for this request, keyed by submission file id */
	protected $_documents = [];

	/**
	 * @param PlagiarismCheckPlugin $plugin
	 */
	public function __construct($plugin) {
		$this->_plugin = $plugin;
		parent::__construct(
			self::COLUMN_ID,
			'plugins.generic.plagiarismCheck.column.title',
			null,
			null,
			new ColumnBasedGridCellProvider(),
			['width' => 20, 'alignment' => COLUMN_ALIGNMENT_LEFT, 'anyhtml' => true]
		);
	}

	/**
	 * @param GridRow $row
	 * @return SubmissionFile
	 */
	protected function _getSubmissionFile($row) {
		$data = $row->getData();
		return $data['submissionFile'];
	}

	/**
	 * @param SubmissionFile $submissionFile
	 * @return stdClass|null
	 */
	protected function _getDocument($submissionFile) {
		$id = $submissionFile->getId();
		if (!array_key_exists($id, $this->_documents)) {
			$this->_documents[$id] = $this->_plugin->getService()->getDAO()->getBySubmissionFileId($id);
		}
		return $this->_documents[$id];
	}

	/**
	 * Method expected by ColumnBasedGridCellProvider to render a cell in this column.
	 *
	 * @copydoc ColumnBasedGridCellProvider::getTemplateVarsFromRowColumn()
	 */
	public function getTemplateVarsFromRow($row) {
		$submissionFile = $this->_getSubmissionFile($row);
		$document = $this->_getDocument($submissionFile);
		if (!$document) {
			return ['label' => ''];
		}

		$request = Application::get()->getRequest();
		$service = $this->_plugin->getService();
		$settings = $service->getSettings($document->context_id);
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign([
			'document' => $document,
			'warningThreshold' => (float) $settings['warningThreshold'],
			'outdated' => (int) $document->file_id !== (int) $submissionFile->getData('fileId'),
			'reportUrl' => $this->_plugin->getHandlerUrl($request, 'report', ['documentId' => $document->document_id]),
		]);
		return ['label' => $templateMgr->fetch($this->_plugin->getTemplateResource('scoreCell.tpl'))];
	}

	/**
	 * @copydoc GridColumn::getCellActions()
	 */
	public function getCellActions($request, $row, $position = GRID_ACTION_POSITION_DEFAULT) {
		$actions = parent::getCellActions($request, $row, $position);
		$submissionFile = $this->_getSubmissionFile($row);
		$service = $this->_plugin->getService();
		if (!$service->isSupported($submissionFile)) {
			return $actions;
		}

		$params = [
			'submissionId' => $submissionFile->getData('submissionId'),
			'submissionFileId' => $submissionFile->getId(),
			'stageId' => $request->getUserVar('stageId'),
		];
		$document = $this->_getDocument($submissionFile);
		$label = !$document ? 'check' : ($document->status === PlagiarismCheckDAO::STATUS_FAILED ? 'retry' : 'recheck');

		$actions[] = new LinkAction(
			'plagiarismCheck-' . $submissionFile->getId(),
			new RemoteActionConfirmationModal(
				$request->getSession(),
				__('plugins.generic.plagiarismCheck.action.check.confirm'),
				__('plugins.generic.plagiarismCheck.action.' . $label),
				$this->_plugin->getHandlerUrl($request, 'check', $params),
				'modal_information'
			),
			__('plugins.generic.plagiarismCheck.action.' . $label)
		);

		if ($document && $document->status === PlagiarismCheckDAO::STATUS_DONE && $document->external_status !== 'pending'
				&& ($provider = $service->getExternalProvider($document->context_id))) {
			$actions[] = new LinkAction(
				'plagiarismCheckExternal-' . $submissionFile->getId(),
				new RemoteActionConfirmationModal(
					$request->getSession(),
					__('plugins.generic.plagiarismCheck.action.external.confirm', ['provider' => $provider->getName()]),
					__('plugins.generic.plagiarismCheck.action.external', ['provider' => $provider->getName()]),
					$this->_plugin->getHandlerUrl($request, 'sendExternal', $params),
					'modal_information'
				),
				__('plugins.generic.plagiarismCheck.action.external', ['provider' => $provider->getName()])
			);
		}

		return $actions;
	}
}
