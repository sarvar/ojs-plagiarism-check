<?php

/**
 * @file plugins/generic/plagiarismCheck/classes/PlagiarismCheckService.inc.php
 *
 * Distributed under the GNU GPL v3.
 *
 * @class PlagiarismCheckService
 * @ingroup plugins_generic_plagiarismCheck
 *
 * @brief Queue files, extract their text, index them and compare them with
 *  everything already indexed.
 */

import('plugins.generic.plagiarismCheck.classes.TextFingerprinter');
import('plugins.generic.plagiarismCheck.classes.SimilarityEngine');
import('plugins.generic.plagiarismCheck.classes.TextExtractor');
import('plugins.generic.plagiarismCheck.classes.PlagiarismCheckDAO');
import('lib.pkp.classes.submission.SubmissionFile'); // SUBMISSION_FILE_* constants

class PlagiarismCheckService {

	/** Normalized characters per shingle (about five words) */
	const SHINGLE_SIZE = 35;

	/** Keep 1 in this many shingle hashes in the index */
	const INDEX_SAMPLING = 16;

	/** Candidate sources compared in detail for each check */
	const MAX_CANDIDATES = 50;

	/** Documents with fewer words are treated as having no extractable text */
	const MIN_WORDS = 50;

	/** Version of the stored match format, for future changes */
	const RESULT_VERSION = 1;

	/** @var PlagiarismCheckPlugin */
	protected $_plugin;

	/** @var PlagiarismCheckDAO */
	protected $_dao;

	/**
	 * @param PlagiarismCheckPlugin $plugin
	 */
	public function __construct($plugin) {
		$this->_plugin = $plugin;
		$this->_dao = new PlagiarismCheckDAO();
	}

	/**
	 * @return PlagiarismCheckDAO
	 */
	public function getDAO() {
		return $this->_dao;
	}

	/**
	 * Per-journal settings with defaults applied.
	 *
	 * @param int $contextId
	 * @return array
	 */
	public function getSettings($contextId) {
		$defaults = [
			'autoCheckSubmissions' => true,
			'checkPublishedGalleys' => true,
			'excludeBibliography' => true,
			'excludeQuotes' => false,
			'minMatchWords' => 8,
			'commonDocs' => 5,
			'warningThreshold' => 20,
			'pdftotextPath' => 'pdftotext',
			'externalProvider' => '',
			'externalApiUrl' => '',
			'externalApiKey' => '',
		];
		$settings = [];
		foreach ($defaults as $name => $default) {
			$value = $this->_plugin->getSetting($contextId, $name);
			$settings[$name] = $value === null ? $default : $value;
		}
		return $settings;
	}

	/**
	 * Whether a submission file's format can be checked.
	 *
	 * @param SubmissionFile $submissionFile
	 * @return bool
	 */
	public function isSupported($submissionFile) {
		$file = Services::get('file')->get($submissionFile->getData('fileId'));
		return $file && TextExtractor::getFormat($file->mimetype, $file->path) !== null;
	}

	/**
	 * Queue a submission file.
	 *
	 * @param SubmissionFile $submissionFile
	 * @param string $sourceType PlagiarismCheckDAO::SOURCE_*
	 * @param bool $force Queue even if this exact file revision was already checked
	 * @return int|null Document id, or null when the format is not supported
	 */
	public function enqueueSubmissionFile($submissionFile, $sourceType = PlagiarismCheckDAO::SOURCE_SUBMISSION, $force = true) {
		if (!$this->isSupported($submissionFile)) {
			return null;
		}
		$existing = $this->_dao->getBySubmissionFileId($submissionFile->getId());
		if (!$force && $existing && $existing->status === PlagiarismCheckDAO::STATUS_DONE
				&& (int) $existing->file_id === (int) $submissionFile->getData('fileId')) {
			return (int) $existing->document_id;
		}

		$submission = Services::get('submission')->get($submissionFile->getData('submissionId'));
		return $this->_dao->enqueue([
			'context_id' => $submission->getData('contextId'),
			'submission_id' => $submission->getId(),
			'submission_file_id' => $submissionFile->getId(),
			'file_id' => $submissionFile->getData('fileId'),
			'source_type' => $sourceType,
			'title' => $submission->getLocalizedTitle(),
		]);
	}

	/**
	 * Queue the manuscript files of a newly completed submission.
	 *
	 * @param Submission $submission
	 * @return int Number of files queued
	 */
	public function enqueueSubmission($submission) {
		$submissionFiles = Services::get('submissionFile')->getMany([
			'submissionIds' => [$submission->getId()],
			'fileStages' => [SUBMISSION_FILE_SUBMISSION],
		]);
		$count = 0;
		foreach ($submissionFiles as $submissionFile) {
			if ($this->enqueueSubmissionFile($submissionFile)) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Queue the galley files of a publication.
	 *
	 * @param Publication $publication
	 * @param bool $force
	 * @return int Number of files queued
	 */
	public function enqueuePublicationGalleys($publication, $force = false) {
		$count = 0;
		foreach ((array) $publication->getData('galleys') as $galley) {
			$submissionFileId = $galley->getData('submissionFileId');
			if (!$submissionFileId) {
				continue; // remote galley
			}
			$submissionFile = Services::get('submissionFile')->get($submissionFileId);
			if ($submissionFile && $this->enqueueSubmissionFile($submissionFile, PlagiarismCheckDAO::SOURCE_GALLEY, $force)) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Process queued documents until the limits are reached.
	 *
	 * Runs in two passes: all queued files are indexed first and compared
	 * afterwards. When a whole issue is published at once, every article is
	 * then compared with all of its issue-mates, so text they all share (issue
	 * front matter) is recognized as boilerplate from the first article on.
	 *
	 * @param int $maxDocuments
	 * @param int $maxSeconds
	 * @param callable|null $progress Called with ($documentId, $success) after each document
	 * @return int Number of documents processed
	 */
	public function processQueue($maxDocuments = 20, $maxSeconds = 240, $progress = null) {
		$start = time();
		$this->_dao->failStale(__('plugins.generic.plagiarismCheck.error.interrupted'));
		$documentIds = $this->_dao->getQueuedIds($maxDocuments);

		$indexed = [];
		foreach ($documentIds as $documentId) {
			if (time() - $start >= $maxSeconds / 2) {
				break;
			}
			if ($this->processDocument($documentId, true, true)) {
				$indexed[] = $documentId;
			} elseif ($progress) {
				call_user_func($progress, $documentId, false);
			}
		}

		$processed = count($documentIds) - count($indexed);
		foreach ($indexed as $documentId) {
			if (time() - $start >= $maxSeconds) {
				break;
			}
			$success = $this->processDocument($documentId, false, true);
			$processed++;
			if ($progress) {
				call_user_func($progress, $documentId, $success);
			}
		}
		return $processed;
	}

	/**
	 * Extract, index and check one document.
	 *
	 * @param int $documentId
	 * @param bool $indexOnly Only extract and index the text. The document stays
	 *  queued until it is compared.
	 * @param bool $reuseText Use the stored text when the file has not changed
	 *  instead of extracting it again
	 * @return bool Success
	 */
	public function processDocument($documentId, $indexOnly = false, $reuseText = false) {
		if (!$this->_dao->claim($documentId)) {
			return false;
		}
		@set_time_limit(300);
		self::_raiseMemoryLimit('256M');
		$document = $this->_dao->getById($documentId);
		$settings = $this->getSettings($document->context_id);

		try {
			$submissionFile = Services::get('submissionFile')->get($document->submission_file_id);
			if (!$submissionFile) {
				throw new Exception(__('plugins.generic.plagiarismCheck.error.fileMissing'));
			}
			$file = Services::get('file')->get($submissionFile->getData('fileId'));
			$path = rtrim(Config::getVar('files', 'files_dir'), '/') . '/' . $file->path;
			$format = TextExtractor::getFormat($file->mimetype, $file->path);
			if (!$format) {
				throw new Exception(__('plugins.generic.plagiarismCheck.error.unsupported'));
			}

			$text = null;
			if ($reuseText && (int) $document->file_id === (int) $submissionFile->getData('fileId')) {
				$text = $this->_dao->getText($documentId);
			}
			if (!$text) {
				$extractor = new TextExtractor($settings['pdftotextPath'] ?: null);
				$text = TextFingerprinter::cleanText($extractor->extract($path, $format));
				unset($extractor);
				// The PDF parser leaves reference cycles behind; free them before the next file
				gc_collect_cycles();
			}

			$options = [
				'excludeBibliography' => (bool) $settings['excludeBibliography'],
				'excludeQuotes' => (bool) $settings['excludeQuotes'],
			];
			$fingerprinter = new TextFingerprinter(self::SHINGLE_SIZE);
			$tokens = $fingerprinter->tokenize($text, $options);
			if (count($tokens['words']) < self::MIN_WORDS) {
				throw new Exception(__('plugins.generic.plagiarismCheck.error.noText'));
			}

			$hashes = $fingerprinter->shingleHashes($tokens['words']);
			$sampled = TextFingerprinter::sampleHashes($hashes, self::INDEX_SAMPLING);
			unset($hashes);

			$values = [
				'file_id' => $submissionFile->getData('fileId'),
				'text' => $text,
				'token_count' => count($tokens['words']),
				'error' => null,
				'date_checked' => Core::getCurrentDate(),
			];

			if (!$indexOnly) {
				$result = $this->_compare($document, $tokens['words'], $sampled, $fingerprinter, $options, $settings);
				$values['score'] = $result['score'];
				$values['matches'] = json_encode($result);
			}

			$this->_dao->replaceFingerprints($documentId, $sampled);
			$values['status'] = $indexOnly ? PlagiarismCheckDAO::STATUS_QUEUED : PlagiarismCheckDAO::STATUS_DONE;
			$this->_dao->update($documentId, $values);
			return true;
		} catch (Throwable $e) {
			error_log('plagiarismCheck: document ' . $documentId . ' failed: ' . $e->getMessage());
			$this->_dao->markFailed($documentId, $e->getMessage());
			return false;
		}
	}

	/**
	 * Raise PHP's memory limit for large PDFs, if the host allows it.
	 *
	 * @param string $limit e.g. "256M"
	 */
	protected static function _raiseMemoryLimit($limit) {
		$toBytes = function ($value) {
			$value = trim((string) $value);
			if ($value === '' || $value === '-1') {
				return PHP_INT_MAX;
			}
			$units = ['g' => 1073741824, 'm' => 1048576, 'k' => 1024];
			$unit = strtolower(substr($value, -1));
			return isset($units[$unit]) ? (int) $value * $units[$unit] : (int) $value;
		};
		if ($toBytes(ini_get('memory_limit')) < $toBytes($limit)) {
			@ini_set('memory_limit', $limit);
		}
	}

	/**
	 * Compare a document with the most likely sources in the index.
	 *
	 * @param stdClass $document
	 * @param string[] $words
	 * @param int[] $sampledHashes
	 * @param TextFingerprinter $fingerprinter
	 * @param array $options Tokenizer options
	 * @param array $settings
	 * @return array Stored as the document's matches
	 */
	protected function _compare($document, $words, $sampledHashes, $fingerprinter, $options, $settings) {
		$commonDocs = (int) $settings['commonDocs'];
		list($candidateIds, $commonHashes) = $this->_dao->findCandidates(
			$sampledHashes,
			$document->submission_id,
			self::MAX_CANDIDATES,
			$commonDocs
		);

		$dao = $this->_dao;
		$loadCandidates = function () use ($candidateIds, $dao, $fingerprinter, $options) {
			foreach ($candidateIds as $candidateId) {
				$text = $dao->getText($candidateId);
				if ($text) {
					yield $candidateId => $fingerprinter->tokenize($text, $options)['words'];
				}
			}
		};

		$engine = new SimilarityEngine($fingerprinter, (int) $settings['minMatchWords'], $commonDocs);
		$result = $engine->compare($words, $loadCandidates(), $commonHashes);

		$summaries = $this->_dao->getSummaries(array_column($result['sources'], 'key'));
		$sources = [];
		foreach ($result['sources'] as $source) {
			$summary = isset($summaries[$source['key']]) ? $summaries[$source['key']] : null;
			$sources[] = [
				'documentId' => (int) $source['key'],
				'submissionId' => $summary ? (int) $summary->submission_id : null,
				'sourceType' => $summary ? $summary->source_type : null,
				'title' => $summary ? $summary->title : '',
				'words' => $source['words'],
				'percent' => $source['percent'],
				'runs' => $source['runs'],
			];
		}

		return [
			'version' => self::RESULT_VERSION,
			'options' => $options + [
				'shingleSize' => self::SHINGLE_SIZE,
				'minMatchWords' => (int) $settings['minMatchWords'],
				'commonDocs' => $commonDocs,
			],
			'candidates' => count($candidateIds),
			'tokenCount' => $result['tokenCount'],
			'matchedWords' => $result['matchedWords'],
			'score' => $result['score'],
			'sources' => $sources,
		];
	}

	/**
	 * Build the data shown in a document's similarity report.
	 *
	 * @param int $documentId
	 * @return array|null [document, result, segments] where segments is a list
	 *  of ['text' => string, 'source' => int|null index into result sources, 'excluded' => bool]
	 */
	public function getReport($documentId) {
		$document = $this->_dao->getById($documentId, true);
		if (!$document || !$document->matches) {
			return null;
		}
		$result = json_decode($document->matches, true);
		$fingerprinter = new TextFingerprinter($result['options']['shingleSize']);
		$text = TextFingerprinter::stripControlChars((string) $document->text);
		$tokens = $fingerprinter->tokenize($text, $result['options']);

		// Which source each word belongs to (the most similar source wins)
		$wordSource = [];
		foreach ($result['sources'] as $index => $source) {
			foreach ($source['runs'] as $run) {
				for ($i = $run[0]; $i <= $run[1]; $i++) {
					if (!isset($wordSource[$i])) {
						$wordSource[$i] = $index;
					}
				}
			}
		}

		// Split the original text into highlighted and plain segments
		$segments = [];
		$cursor = 0;
		$current = null;
		$segmentStart = 0;
		foreach ($tokens['offsets'] as $i => $offset) {
			$source = isset($wordSource[$i]) ? $wordSource[$i] : null;
			if ($source !== $current) {
				if ($offset[0] > $segmentStart) {
					$segments[] = ['text' => substr($text, $segmentStart, $offset[0] - $segmentStart), 'source' => $current, 'excluded' => false];
				}
				$segmentStart = $offset[0];
				$current = $source;
			}
			$cursor = $offset[0] + $offset[1];
		}
		$end = $tokens['excludedFrom'] !== null ? $tokens['excludedFrom'] : strlen($text);
		if ($current !== null) {
			$segments[] = ['text' => substr($text, $segmentStart, $cursor - $segmentStart), 'source' => $current, 'excluded' => false];
			$segmentStart = $cursor;
		}
		if ($end > $segmentStart) {
			$segments[] = ['text' => substr($text, $segmentStart, $end - $segmentStart), 'source' => null, 'excluded' => false];
		}
		if ($tokens['excludedFrom'] !== null) {
			$segments[] = ['text' => substr($text, $tokens['excludedFrom']), 'source' => null, 'excluded' => true];
		}

		return ['document' => $document, 'result' => $result, 'segments' => $segments];
	}

	//
	// External provider
	//

	/**
	 * The configured external provider for a journal, if any.
	 *
	 * @param int $contextId
	 * @return ExternalProvider|null
	 */
	public function getExternalProvider($contextId) {
		$settings = $this->getSettings($contextId);
		$providers = $this->_plugin->getExternalProviders();
		if (!$settings['externalProvider'] || !isset($providers[$settings['externalProvider']]) || !$settings['externalApiKey']) {
			return null;
		}
		$provider = $providers[$settings['externalProvider']];
		$provider->setCredentials($settings['externalApiUrl'], $settings['externalApiKey']);
		return $provider;
	}

	/**
	 * Send a document's file to the external provider.
	 *
	 * @param int $documentId
	 * @throws Exception
	 */
	public function sendToExternal($documentId) {
		$document = $this->_dao->getById($documentId);
		$provider = $document ? $this->getExternalProvider($document->context_id) : null;
		if (!$provider) {
			throw new Exception(__('plugins.generic.plagiarismCheck.external.notConfigured'));
		}
		$submissionFile = Services::get('submissionFile')->get($document->submission_file_id);
		$file = Services::get('file')->get($submissionFile->getData('fileId'));
		$externalId = $provider->submit(
			rtrim(Config::getVar('files', 'files_dir'), '/') . '/' . $file->path,
			$submissionFile->getLocalizedData('name'),
			['title' => $document->title, 'submissionId' => $document->submission_id]
		);
		$this->_dao->update($documentId, [
			'external_provider' => $provider->getId(),
			'external_id' => $externalId,
			'external_status' => 'pending',
			'external_score' => null,
			'external_report_url' => null,
		]);
	}

	/**
	 * Fetch results for documents waiting on the external provider.
	 *
	 * @return int Number of results received
	 */
	public function pollExternalResults() {
		$received = 0;
		foreach ($this->_dao->getPendingExternal() as $document) {
			$provider = $this->getExternalProvider($document->context_id);
			if (!$provider || $provider->getId() !== $document->external_provider) {
				continue;
			}
			try {
				$result = $provider->fetchResult($document->external_id);
			} catch (Throwable $e) {
				error_log('plagiarismCheck: external result for document ' . $document->document_id . ' failed: ' . $e->getMessage());
				$this->_dao->update($document->document_id, ['external_status' => 'failed']);
				continue;
			}
			if ($result !== null) {
				$this->_dao->update($document->document_id, [
					'external_status' => 'done',
					'external_score' => $result['score'],
					'external_report_url' => $result['reportUrl'],
				]);
				$received++;
			}
		}
		return $received;
	}
}
