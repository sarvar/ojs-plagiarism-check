<?php

/**
 * @file plugins/generic/plagiarismCheck/classes/SimilarityEngine.inc.php
 *
 * Distributed under the GNU GPL v3.
 *
 * @class SimilarityEngine
 * @ingroup plugins_generic_plagiarismCheck
 *
 * @brief Compares a document against candidate source documents and reports
 *  which runs of words were found in which source. No OJS dependencies.
 */

class SimilarityEngine {

	/** @var TextFingerprinter */
	protected $_fingerprinter;

	/** @var int Matches shorter than this many words are ignored */
	protected $_minMatchWords;

	/** @var int Maximum number of sources kept in a result */
	protected $_maxSources;

	/** @var int A shingle found in this many sources is boilerplate (0 = off) */
	protected $_commonDocs;

	/**
	 * @param TextFingerprinter $fingerprinter
	 * @param int $minMatchWords
	 * @param int $commonDocs Shingles shared by at least this many candidate
	 *  sources are treated as boilerplate (journal front matter, standard legal
	 *  phrases) and never counted as a match
	 * @param int $maxSources
	 */
	public function __construct($fingerprinter, $minMatchWords = 8, $commonDocs = 5, $maxSources = 20) {
		$this->_fingerprinter = $fingerprinter;
		$this->_minMatchWords = max(3, (int) $minMatchWords);
		$this->_commonDocs = (int) $commonDocs;
		$this->_maxSources = (int) $maxSources;
	}

	/**
	 * Compare a document with candidate sources.
	 *
	 * @param string[] $words Normalized words of the checked document
	 * @param iterable $candidates [sourceKey => string[] normalized words]. May be
	 *  a generator so candidates are loaded one at a time.
	 * @param int[] $ignoreHashes Shingle hashes already known to be boilerplate
	 *  (e.g. frequent across the whole index)
	 * @return array [
	 *  'tokenCount' => int,
	 *  'matchedWords' => int words covered by at least one source,
	 *  'score' => float percent of the document covered,
	 *  'sources' => [['key' => sourceKey, 'words' => int, 'percent' => float, 'runs' => [[firstWord, lastWord], ...]], ...]
	 * ]
	 */
	public function compare($words, $candidates, $ignoreHashes = []) {
		$tokenCount = count($words);
		$queryHashes = $this->_fingerprinter->shingleHashes($words);
		$size = $this->_fingerprinter->getShingleSize();
		$wordStarts = TextFingerprinter::wordStarts($words);

		// Positions of query shingles found in each candidate, and in how many
		// candidates each shingle occurs. Candidate hash sets are built and freed
		// one at a time to keep memory low.
		$hitsByCandidate = [];
		$docFrequency = [];
		foreach ($candidates as $key => $candidateWords) {
			$candidateSet = array_flip($this->_fingerprinter->shingleHashes($candidateWords));
			$hits = [];
			$seen = [];
			foreach ($queryHashes as $position => $hash) {
				if (isset($candidateSet[$hash])) {
					$hits[] = $position;
					if (!isset($seen[$hash])) {
						$seen[$hash] = true;
						$docFrequency[$hash] = isset($docFrequency[$hash]) ? $docFrequency[$hash] + 1 : 1;
					}
				}
			}
			unset($candidateSet, $seen);
			if ($hits) {
				$hitsByCandidate[$key] = $hits;
			}
		}

		$ignore = array_flip($ignoreHashes);
		if ($this->_commonDocs > 0) {
			foreach ($docFrequency as $hash => $count) {
				if ($count >= $this->_commonDocs) {
					$ignore[$hash] = true;
				}
			}
		}

		$sources = [];
		foreach ($hitsByCandidate as $key => $hits) {
			$coveredChars = [];
			foreach ($hits as $position) {
				if (isset($ignore[$queryHashes[$position]])) {
					continue;
				}
				for ($c = $position; $c < $position + $size; $c++) {
					$coveredChars[$c] = true;
				}
			}
			$runs = $this->_toWordRuns($coveredChars, $wordStarts);
			if (!$runs) {
				continue;
			}
			$matched = 0;
			foreach ($runs as $run) {
				$matched += $run[1] - $run[0] + 1;
			}
			$sources[] = [
				'key' => $key,
				'words' => $matched,
				'percent' => self::percent($matched, $tokenCount),
				'runs' => $runs,
			];
		}

		usort($sources, function ($a, $b) {
			return $b['words'] - $a['words'];
		});
		$sources = array_slice($sources, 0, $this->_maxSources);

		// Overall score is the union of all kept sources, so a sentence found in
		// three sources is only counted once.
		$union = [];
		foreach ($sources as $source) {
			foreach ($source['runs'] as $run) {
				for ($i = $run[0]; $i <= $run[1]; $i++) {
					$union[$i] = true;
				}
			}
		}

		return [
			'tokenCount' => $tokenCount,
			'matchedWords' => count($union),
			'score' => self::percent(count($union), $tokenCount),
			'sources' => $sources,
		];
	}

	/**
	 * @param int $part
	 * @param int $total
	 * @return float
	 */
	public static function percent($part, $total) {
		return $total > 0 ? round($part * 100 / $total, 1) : 0.0;
	}

	/**
	 * Turn covered character positions into runs of fully covered words,
	 * dropping runs shorter than the minimum match length.
	 *
	 * @param array $coveredChars [charPosition => true]
	 * @param int[] $wordStarts See TextFingerprinter::wordStarts()
	 * @return array [[firstWord, lastWord], ...]
	 */
	protected function _toWordRuns($coveredChars, $wordStarts) {
		if (!$coveredChars) {
			return [];
		}
		$runs = [];
		$start = null;
		$wordCount = count($wordStarts) - 1;
		for ($i = 0; $i <= $wordCount; $i++) {
			$covered = false;
			if ($i < $wordCount) {
				$covered = true;
				for ($c = $wordStarts[$i]; $c < $wordStarts[$i + 1]; $c++) {
					if (!isset($coveredChars[$c])) {
						$covered = false;
						break;
					}
				}
			}
			if ($covered && $start === null) {
				$start = $i;
			} elseif (!$covered && $start !== null) {
				if ($i - $start >= $this->_minMatchWords) {
					$runs[] = [$start, $i - 1];
				}
				$start = null;
			}
		}
		return $runs;
	}
}
