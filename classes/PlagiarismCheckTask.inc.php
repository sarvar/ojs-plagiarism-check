<?php

/**
 * @file plugins/generic/plagiarismCheck/classes/PlagiarismCheckTask.inc.php
 *
 * Distributed under the GNU GPL v3.
 *
 * @class PlagiarismCheckTask
 * @ingroup plugins_generic_plagiarismCheck
 *
 * @brief Scheduled task that runs queued plagiarism checks and polls the
 *  external provider for pending results.
 */

import('lib.pkp.classes.scheduledTask.ScheduledTask');

class PlagiarismCheckTask extends ScheduledTask {

	/**
	 * @copydoc ScheduledTask::getName()
	 */
	public function getName() {
		return __('plugins.generic.plagiarismCheck.task.name');
	}

	/**
	 * @copydoc ScheduledTask::executeActions()
	 */
	protected function executeActions() {
		$plugin = PluginRegistry::getPlugin('generic', 'plagiarismcheckplugin');
		if (!$plugin) {
			PluginRegistry::loadCategory('generic');
			$plugin = PluginRegistry::getPlugin('generic', 'plagiarismcheckplugin');
		}
		if (!$plugin) {
			$this->addExecutionLogEntry('Plagiarism check plugin is not available.', SCHEDULED_TASK_MESSAGE_TYPE_ERROR);
			return false;
		}

		$service = $plugin->getService();
		$processed = $service->processQueue(20, 240);
		$polled = $service->pollExternalResults();
		$this->addExecutionLogEntry("Processed $processed queued document(s), updated $polled external result(s).", SCHEDULED_TASK_MESSAGE_TYPE_COMPLETED);
		return true;
	}
}
