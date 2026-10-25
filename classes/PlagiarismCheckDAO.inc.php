<?php

/**
 * @file plugins/generic/plagiarismCheck/classes/PlagiarismCheckDAO.inc.php
 *
 * Distributed under the GNU GPL v3.
 *
 * @class PlagiarismCheckDAO
 * @ingroup plugins_generic_plagiarismCheck
 *
 * @brief Read and write plagiarism check documents and the fingerprint index.
 */

use Illuminate\Database\Capsule\Manager as Capsule;

class PlagiarismCheckDAO {

	const STATUS_QUEUED = 'queued';
	const STATUS_PROCESSING = 'processing';
	const STATUS_DONE = 'done';
	const STATUS_FAILED = 'failed';

	const SOURCE_SUBMISSION = 'submission';
	const SOURCE_GALLEY = 'galley';

	/** Columns needed everywhere except the report (the text column can be large) */
	const SUMMARY_COLUMNS = [
		'document_id', 'context_id', 'submission_id', 'submission_file_id', 'file_id', 'source_type',
		'title', 'status', 'token_count', 'score', 'error', 'external_provider', 'external_id',
		'external_status', 'external_score', 'external_report_url', 'date_queued', 'date_checked',
	];

	/**
	 * @param int $documentId
	 * @param bool $withText Include the text and matches columns
	 * @return stdClass|null
	 */
	public function getById($documentId, $withText = false) {
		return Capsule::table('plagiarism_check_documents')
			->where('document_id', (int) $documentId)
			->select($withText ? ['*'] : self::SUMMARY_COLUMNS)
			->first();
	}

	/**
	 * @param int $submissionFileId
	 * @return stdClass|null
	 */
	public function getBySubmissionFileId($submissionFileId) {
		return Capsule::table('plagiarism_check_documents')
			->where('submission_file_id', (int) $submissionFileId)
			->select(self::SUMMARY_COLUMNS)
			->first();
	}

	/**
	 * @param int $contextId
	 * @param int $limit
	 * @return stdClass[] Most similar first
	 */
	public function getByContextId($contextId, $limit = 1000) {
		return Capsule::table('plagiarism_check_documents')
			->where('context_id', (int) $contextId)
			->select(self::SUMMARY_COLUMNS)
			->orderByRaw('score IS NULL, score DESC')
			->orderBy('document_id', 'desc')
			->limit($limit)
			->get()
			->all();
	}

	/**
	 * @param int[] $documentIds
	 * @return stdClass[] keyed by document id
	 */
	public function getSummaries($documentIds) {
		if (!$documentIds) {
			return [];
		}
		return Capsule::table('plagiarism_check_documents')
			->whereIn('document_id', array_map('intval', $documentIds))
			->select(self::SUMMARY_COLUMNS)
			->get()
			->keyBy('document_id')
			->all();
	}

	/**
	 * Get the stored text of one document.
	 *
	 * @param int $documentId
	 * @return string|null
	 */
	public function getText($documentId) {
		return Capsule::table('plagiarism_check_documents')
			->where('document_id', (int) $documentId)
			->value('text');
	}

	/**
	 * Queue a file for checking. An existing row for the same submission file
	 * is reused so re-checking keeps one result per file.
	 *
	 * @param array $data context_id, submission_id, submission_file_id, file_id, source_type, title
	 * @return int document id
	 */
	public function enqueue($data) {
		$values = [
			'context_id' => (int) $data['context_id'],
			'submission_id' => (int) $data['submission_id'],
			'file_id' => (int) $data['file_id'],
			'source_type' => $data['source_type'],
			'title' => mb_substr((string) $data['title'], 0, 255),
			'status' => self::STATUS_QUEUED,
			'error' => null,
			'date_queued' => Core::getCurrentDate(),
		];
		$existing = $this->getBySubmissionFileId($data['submission_file_id']);
		if ($existing) {
			Capsule::table('plagiarism_check_documents')
				->where('document_id', $existing->document_id)
				->update($values);
			return (int) $existing->document_id;
		}
		$values['submission_file_id'] = (int) $data['submission_file_id'];
		return (int) Capsule::table('plagiarism_check_documents')->insertGetId($values, 'document_id');
	}

	/**
	 * @param int $limit
	 * @return int[] Oldest queued document ids
	 */
	public function getQueuedIds($limit) {
		return Capsule::table('plagiarism_check_documents')
			->where('status', self::STATUS_QUEUED)
			->orderBy('document_id')
			->limit((int) $limit)
			->pluck('document_id')
			->all();
	}

	/**
	 * Mark a queued document as being processed. Returns false when another
	 * process already took it.
	 *
	 * @param int $documentId
	 * @return bool
	 */
	public function claim($documentId) {
		return Capsule::table('plagiarism_check_documents')
			->where('document_id', (int) $documentId)
			->whereIn('status', [self::STATUS_QUEUED, self::STATUS_FAILED, self::STATUS_DONE])
			->update(['status' => self::STATUS_PROCESSING, 'date_checked' => Core::getCurrentDate()]) > 0;
	}

	/**
	 * Fail documents stuck in processing. This happens when PHP stopped while
	 * reading the file (memory or time limit), so retrying automatically would
	 * stop every following run at the same file. Editors can still retry by hand.
	 *
	 * @param string $error
	 * @param int $olderThanMinutes
	 * @return int Number of documents marked as failed
	 */
	public function failStale($error, $olderThanMinutes = 30) {
		return Capsule::table('plagiarism_check_documents')
			->where('status', self::STATUS_PROCESSING)
			->where('date_checked', '<', date('Y-m-d H:i:s', time() - $olderThanMinutes * 60))
			->update(['status' => self::STATUS_FAILED, 'error' => $error]);
	}

	/**
	 * @param int $documentId
	 * @param array $values Column => value
	 */
	public function update($documentId, $values) {
		Capsule::table('plagiarism_check_documents')
			->where('document_id', (int) $documentId)
			->update($values);
	}

	/**
	 * @param int $documentId
	 * @param string $error
	 */
	public function markFailed($documentId, $error) {
		$this->update($documentId, [
			'status' => self::STATUS_FAILED,
			'error' => mb_substr($error, 0, 2000),
			'date_checked' => Core::getCurrentDate(),
		]);
	}

	/**
	 * @return stdClass[] Documents waiting for an external result
	 */
	public function getPendingExternal() {
		return Capsule::table('plagiarism_check_documents')
			->where('external_status', 'pending')
			->select(self::SUMMARY_COLUMNS)
			->get()
			->all();
	}

	/**
	 * Replace a document's sampled hashes in the index.
	 *
	 * @param int $documentId
	 * @param int[] $hashes
	 */
	public function replaceFingerprints($documentId, $hashes) {
		Capsule::table('plagiarism_check_fingerprints')->where('document_id', (int) $documentId)->delete();
		foreach (array_chunk($hashes, 1000) as $chunk) {
			Capsule::table('plagiarism_check_fingerprints')->insert(array_map(function ($hash) use ($documentId) {
				return ['document_id' => (int) $documentId, 'hash' => $hash];
			}, $chunk));
		}
	}

	/**
	 * Find the documents sharing the most index hashes with a query.
	 *
	 * @param int[] $hashes Sampled hashes of the checked document
	 * @param int $excludeSubmissionId Files of the same submission are never sources
	 * @param int $limit Maximum number of candidates
	 * @param int $commonDocs Hashes found in at least this many documents are
	 *  treated as boilerplate and not used for ranking (0 = off)
	 * @return array [int[] candidate document ids, int[] boilerplate hashes]
	 */
	public function findCandidates($hashes, $excludeSubmissionId, $limit = 50, $commonDocs = 5) {
		$docsByHash = [];
		foreach (array_chunk($hashes, 1000) as $chunk) {
			$rows = Capsule::table('plagiarism_check_fingerprints as f')
				->join('plagiarism_check_documents as d', 'd.document_id', '=', 'f.document_id')
				->whereIn('f.hash', $chunk)
				->where(function ($query) use ($excludeSubmissionId) {
					$query->whereNull('d.submission_id')->orWhere('d.submission_id', '!=', (int) $excludeSubmissionId);
				})
				->select('f.hash', 'f.document_id')
				->get();
			foreach ($rows as $row) {
				$docsByHash[$row->hash][$row->document_id] = true;
			}
		}

		$common = [];
		$hits = [];
		foreach ($docsByHash as $hash => $documentIds) {
			if ($commonDocs > 0 && count($documentIds) >= $commonDocs) {
				$common[] = (int) $hash;
				continue;
			}
			foreach (array_keys($documentIds) as $documentId) {
				$hits[$documentId] = isset($hits[$documentId]) ? $hits[$documentId] + 1 : 1;
			}
		}
		arsort($hits);
		return [array_slice(array_keys($hits), 0, $limit), $common];
	}

	/**
	 * Delete a document and its index entries.
	 *
	 * @param int $documentId
	 */
	public function deleteById($documentId) {
		Capsule::table('plagiarism_check_fingerprints')->where('document_id', (int) $documentId)->delete();
		Capsule::table('plagiarism_check_documents')->where('document_id', (int) $documentId)->delete();
	}

	/**
	 * Delete all documents of a submission and their index entries.
	 *
	 * @param int $submissionId
	 */
	public function deleteBySubmissionId($submissionId) {
		$documentIds = Capsule::table('plagiarism_check_documents')
			->where('submission_id', (int) $submissionId)
			->pluck('document_id')
			->all();
		foreach ($documentIds as $documentId) {
			$this->deleteById($documentId);
		}
	}

	/**
	 * @param int|null $contextId
	 * @return array status => count
	 */
	public function getStatusCounts($contextId = null) {
		$query = Capsule::table('plagiarism_check_documents');
		if ($contextId) {
			$query->where('context_id', (int) $contextId);
		}
		return $query->groupBy('status')
			->select('status', Capsule::raw('COUNT(*) as total'))
			->pluck('total', 'status')
			->all();
	}
}
