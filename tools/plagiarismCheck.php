<?php

/**
 * @file plugins/generic/plagiarismCheck/tools/plagiarismCheck.php
 *
 * Distributed under the GNU GPL v3.
 *
 * @class PlagiarismCheckTool
 * @ingroup plugins_generic_plagiarismCheck
 *
 * @brief Command line tool: index the back catalogue, run the queue, export results.
 */

// Run from the OJS root. Fall back to the current directory when the plugin
// folder is a symlink and __FILE__ points outside the OJS installation.
$bootstrap = dirname(dirname(dirname(dirname(dirname(__FILE__))))) . '/tools/bootstrap.inc.php';
require(file_exists($bootstrap) ? $bootstrap : getcwd() . '/tools/bootstrap.inc.php');

import('classes.submission.Submission'); // STATUS_PUBLISHED

class PlagiarismCheckTool extends CommandLineTool {

	/** @var string */
	protected $_command;

	/** @var string|null */
	protected $_contextPath;

	/** @var string|null */
	protected $_file;

	/** @var PlagiarismCheckPlugin */
	protected $_plugin;

	/**
	 * @param array $argv
	 */
	public function __construct($argv = []) {
		parent::__construct($argv);
		$this->_command = isset($this->argv[0]) ? $this->argv[0] : null;
		foreach (array_slice($this->argv, 1) as $arg) {
			if (strpos($arg, '--context=') === 0) {
				$this->_contextPath = substr($arg, 10);
			} else {
				$this->_file = $arg;
			}
		}
	}

	/**
	 * Print command usage information.
	 */
	public function usage() {
		echo "Local plagiarism check.\n\n"
			. "Usage: php {$this->scriptName} <command> [--context=journalPath] [file]\n\n"
			. "Commands:\n"
			. "  backfill   Index all published articles, then check each one against all others\n"
			. "  process    Run every queued check now (what the cron job does)\n"
			. "  recheck    Re-run the comparison for all checked documents (e.g. after changing settings)\n"
			. "  status     Show how many documents are queued, done and failed\n"
			. "  export     Write all results to a CSV file (default: plagiarism-results.csv)\n";
	}

	/**
	 * Run the requested command.
	 */
	public function execute() {
		PluginRegistry::loadCategory('generic');
		$this->_plugin = PluginRegistry::getPlugin('generic', 'plagiarismcheckplugin');
		if (!$this->_plugin) {
			echo "The plagiarismCheck plugin could not be loaded.\n";
			exit(1);
		}
		// Make sure the tables exist even if the plugin was copied in manually
		$this->_plugin->getInstallMigration()->up();
		$service = $this->_plugin->getService();

		switch ($this->_command) {
			case 'backfill':
				$this->_backfill($service);
				break;
			case 'process':
				$count = $service->processQueue(PHP_INT_MAX, PHP_INT_MAX, [$this, 'printProgress']);
				echo "Processed $count document(s).\n";
				break;
			case 'recheck':
				$this->_recheck($service);
				break;
			case 'status':
				foreach ($this->_getContexts() as $context) {
					echo $context->getPath() . ': ' . json_encode($service->getDAO()->getStatusCounts($context->getId())) . "\n";
				}
				break;
			case 'export':
				$this->_export($service);
				break;
			default:
				$this->usage();
				exit(1);
		}
	}

	/**
	 * @param int $documentId
	 * @param bool $success
	 */
	public function printProgress($documentId, $success) {
		$document = $this->_plugin->getService()->getDAO()->getById($documentId);
		printf("  #%-6d %-7s %6s  %s\n", $documentId, $success ? 'ok' : 'FAILED', $success && $document->score !== null ? $document->score . '%' : '', $success ? mb_substr((string) $document->title, 0, 70) : $document->error);
	}

	/**
	 * @return Context[]
	 */
	protected function _getContexts() {
		$contextDao = Application::getContextDAO();
		if ($this->_contextPath) {
			$context = $contextDao->getByPath($this->_contextPath);
			if (!$context) {
				echo "No journal with path {$this->_contextPath}\n";
				exit(1);
			}
			return [$context];
		}
		return $contextDao->getAll()->toArray();
	}

	/**
	 * Index every published galley first so each article is compared with
	 * the whole back catalogue, then compare them.
	 *
	 * @param PlagiarismCheckService $service
	 */
	protected function _backfill($service) {
		$dao = $service->getDAO();
		$documentIds = [];
		foreach ($this->_getContexts() as $context) {
			$submissions = Services::get('submission')->getMany(['contextId' => $context->getId(), 'status' => STATUS_PUBLISHED]);
			foreach ($submissions as $submission) {
				foreach ((array) $submission->getData('publications') as $publication) {
					if ($publication->getData('status') == STATUS_PUBLISHED) {
						$service->enqueuePublicationGalleys($publication, true);
					}
				}
			}
			$documentIds = array_merge($documentIds, array_map(function ($document) {
				return $document->document_id;
			}, array_filter($dao->getByContextId($context->getId(), PHP_INT_MAX), function ($document) {
				return $document->source_type === PlagiarismCheckDAO::SOURCE_GALLEY;
			})));
		}
		sort($documentIds);

		echo "Step 1/2: extracting and indexing " . count($documentIds) . " published file(s)\n";
		foreach ($documentIds as $documentId) {
			$success = $service->processDocument($documentId, true);
			$document = $dao->getById($documentId);
			printf("  #%-6d %-7s %s\n", $documentId, $success ? 'indexed' : 'FAILED', $success ? mb_substr((string) $document->title, 0, 70) : $document->error);
		}

		echo "Step 2/2: comparing each article with all others\n";
		foreach ($documentIds as $documentId) {
			$document = $dao->getById($documentId);
			if ($document->status !== PlagiarismCheckDAO::STATUS_QUEUED) {
				continue; // extraction failed in step 1
			}
			$this->printProgress($documentId, $service->processDocument($documentId, false, true));
		}
		echo "Done. Run `php {$this->scriptName} export` for a CSV, or open the results page from the plugin list.\n";
	}

	/**
	 * @param PlagiarismCheckService $service
	 */
	protected function _recheck($service) {
		$dao = $service->getDAO();
		foreach ($this->_getContexts() as $context) {
			foreach ($dao->getByContextId($context->getId(), PHP_INT_MAX) as $document) {
				if ($document->status === PlagiarismCheckDAO::STATUS_DONE) {
					$this->printProgress($document->document_id, $service->processDocument($document->document_id, false, true));
				}
			}
		}
	}

	/**
	 * @param PlagiarismCheckService $service
	 */
	protected function _export($service) {
		$file = $this->_file ?: 'plagiarism-results.csv';
		$handle = fopen($file, 'w');
		fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows Cyrillic correctly
		fputcsv($handle, ['journal', 'document_id', 'submission_id', 'type', 'title', 'status', 'score', 'words', 'top_source_submission_id', 'top_source_title', 'top_source_percent', 'error']);
		$dao = $service->getDAO();
		foreach ($this->_getContexts() as $context) {
			foreach ($dao->getByContextId($context->getId(), PHP_INT_MAX) as $summary) {
				$document = $dao->getById($summary->document_id, true);
				$matches = $document->matches ? json_decode($document->matches, true) : null;
				$top = $matches && $matches['sources'] ? $matches['sources'][0] : null;
				fputcsv($handle, [
					$context->getPath(), $document->document_id, $document->submission_id, $document->source_type,
					$document->title, $document->status, $document->score, $document->token_count,
					$top ? $top['submissionId'] : '', $top ? $top['title'] : '', $top ? $top['percent'] : '', $document->error,
				]);
			}
		}
		fclose($handle);
		echo "Wrote $file\n";
	}
}

$tool = new PlagiarismCheckTool(isset($argv) ? $argv : []);
$tool->execute();
