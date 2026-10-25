<?php

/**
 * @file plugins/generic/plagiarismCheck/classes/TextFingerprinter.inc.php
 *
 * Distributed under the GNU GPL v3.
 *
 * @class TextFingerprinter
 * @ingroup plugins_generic_plagiarismCheck
 *
 * @brief Turns raw document text into normalized word tokens and shingle
 *  hashes. Uzbek (Latin and Cyrillic), Russian and English are normalized to a
 *  common Latin form so the same passage matches regardless of script.
 *
 *  Shingles are taken over the normalized letters with word boundaries
 *  removed, so PDFs whose extracted text has merged words ("sudhokimiyati")
 *  still match properly spaced text.
 *
 *  This class has no OJS dependencies so it can be unit tested standalone.
 */

class TextFingerprinter {

	/** @var int Number of normalized characters in one shingle */
	protected $_shingleSize;

	/** @var array Cyrillic => Latin map (Uzbek official orthography, also used for Russian) */
	protected static $_translit = [
		'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'yo',
		'ж' => 'j', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
		'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
		'ф' => 'f', 'х' => 'x', 'ц' => 's', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sh', 'ъ' => '',
		'ы' => 'i', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
		'ў' => 'o', 'қ' => 'q', 'ғ' => 'g', 'ҳ' => 'h',
		// Kazakh/Karakalpak letters that occasionally appear
		'ә' => 'a', 'ө' => 'o', 'ү' => 'u', 'ұ' => 'u', 'ң' => 'ng', 'і' => 'i',
	];

	/** @var string Characters used as the Uzbek apostrophe (o‘, g‘, ʼ) – removed during normalization */
	const APOSTROPHES = "'`\u{02BB}\u{02BC}\u{2018}\u{2019}\u{02B9}\u{00B4}";

	/** @var array Normalized bibliography headings (see normalizeLine()) */
	protected static $_bibliographyHeadings = [
		'references', 'reference list', 'list of references', 'bibliography', 'literature',
		'list of literature', 'used literature', 'sources', 'list of sources',
		'spisok literaturi', 'spisok ispolzovannoy literaturi', 'spisok ispolzovannix istochnikov',
		'literatura', 'ispolzovannaya literatura', 'bibliograficheskiy spisok', 'istochniki',
		'foydalanilgan adabiyotlar', 'foydalanilgan adabiyotlar royxati', 'adabiyotlar',
		'adabiyotlar royxati', 'foydalanilgan manbalar', 'manbalar', 'foydalanilgan manbalar royxati',
		// Footnote headings, often combined: "Иқтибослар/Сноски/References"
		'iqtiboslar', 'iktiboslar', 'iqriboslar', 'snoski', 'snocki', 'footnotes', 'endnotes', 'notes',
		'izohlar', 'primechaniya',
	];

	/**
	 * @param int $shingleSize Number of consecutive normalized characters hashed
	 *  together (35 characters is roughly five words)
	 */
	public function __construct($shingleSize = 35) {
		$this->_shingleSize = max(15, (int) $shingleSize);
	}

	/**
	 * @return int
	 */
	public function getShingleSize() {
		return $this->_shingleSize;
	}

	/**
	 * Clean extraction artifacts that would otherwise split words.
	 *
	 * @param string $text
	 * @return string
	 */
	public static function cleanText($text) {
		if (!mb_check_encoding($text, 'UTF-8')) {
			$text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
		}
		$text = str_replace(["\r\n", "\r", "\u{00AD}", "\u{200B}", "\u{FEFF}"], ["\n", "\n", '', '', ''], $text);
		$text = self::stripControlChars($text);
		$text = self::repairLegacyCyrillic($text);
		// Rejoin words hyphenated across a line break: "konstitu-\nsiya" => "konstitusiya"
		$text = preg_replace('/(\p{Ll})-[ \t]*\n[ \t]*(\p{Ll})/u', '$1$2', $text);
		return $text;
	}

	/**
	 * Replace control characters some PDFs extract (page breaks, font junk)
	 * with spaces. Each byte becomes one space, so byte offsets are kept.
	 *
	 * @param string $text
	 * @return string
	 */
	public static function stripControlChars($text) {
		return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', ' ', $text);
	}

	/**
	 * Older PDFs typeset with legacy 8-bit Uzbek fonts (CP1251 plus қ ў ғ ҳ
	 * in the 0xA2–0xB5 slots) extract as Latin-1 mojibake, e.g.
	 * "Ìà³îëàäà µó³ó³íè" instead of "Мақолада ҳуқуқни". Convert such words
	 * back to Cyrillic when a document clearly uses that encoding.
	 *
	 * @param string $text
	 * @return string
	 */
	public static function repairLegacyCyrillic($text) {
		$pattern = '/(?<![A-Za-z\x{0400}-\x{04FF}])[\x{00A2}-\x{00A5}\x{00A8}\x{00B2}-\x{00B5}\x{00B8}\x{00C0}-\x{00FF}]{2,}(?![A-Za-z\x{0400}-\x{04FF}])/u';
		$suspects = preg_match_all($pattern, $text);
		$words = preg_match_all('/\p{L}{2,}/u', $text);
		if ($suspects < 10 || $suspects < $words * 0.03) {
			return $text;
		}

		static $map = null;
		if ($map === null) {
			$map = [
				"\u{00A2}" => 'Ғ', "\u{00B2}" => 'ғ', "\u{00A3}" => 'Қ', "\u{00B3}" => 'қ',
				"\u{00A4}" => 'Ў', "\u{00B4}" => 'ў', "\u{00A5}" => 'Ҳ', "\u{00B5}" => 'ҳ',
				"\u{00A8}" => 'Ё', "\u{00B8}" => 'ё',
			];
			// 0xC0–0xFF map to А–я in CP1251
			for ($code = 0xC0; $code <= 0xFF; $code++) {
				$map[mb_chr($code, 'UTF-8')] = mb_chr(0x0410 + $code - 0xC0, 'UTF-8');
			}
		}

		$text = preg_replace_callback($pattern, function ($m) use ($map) {
			return strtr($m[0], $map);
		}, $text);
		return str_replace("\u{00B9}", '№', $text);
	}

	/**
	 * Normalize a single word to the comparison form.
	 *
	 * @param string $word
	 * @return string Empty string when nothing comparable remains
	 */
	public static function normalizeWord($word) {
		$word = mb_strtolower($word, 'UTF-8');
		// Uzbek Cyrillic: word-initial "е" is "ye" in Latin (ер => yer)
		if (mb_substr($word, 0, 1, 'UTF-8') === 'е') {
			$word = 'ye' . mb_substr($word, 1, null, 'UTF-8');
		}
		$word = strtr($word, self::$_translit);
		$word = str_replace(self::_apostropheChars(), '', $word);
		// Drop combining marks and anything that is not a letter or digit
		$word = preg_replace('/[^\p{L}\p{N}]+/u', '', $word);
		return $word === null ? '' : $word;
	}

	/**
	 * Normalize a whole line, used to recognize headings.
	 *
	 * @param string $line
	 * @return string
	 */
	public static function normalizeLine($line) {
		preg_match_all('/[\p{L}\p{M}\p{N}' . preg_quote(self::APOSTROPHES, '/') . ']+/u', $line, $m);
		$words = array_filter(array_map([self::class, 'normalizeWord'], $m[0]), 'strlen');
		return implode(' ', $words);
	}

	/**
	 * Split text into normalized tokens, keeping each token's byte offset in
	 * the original text so matches can be highlighted later.
	 *
	 * @param string $text Cleaned text (see cleanText())
	 * @param array $options [
	 *  @option bool excludeBibliography Drop everything after the reference list heading
	 *  @option bool excludeQuotes Drop text inside quotation marks
	 * ]
	 * @return array [
	 *  'words' => string[] normalized words,
	 *  'offsets' => array of [byteStart, byteLength] in the original text,
	 *  'excludedFrom' => int|null byte offset where the bibliography starts,
	 * ]
	 */
	public function tokenize($text, $options = []) {
		$excludedRanges = [];
		$bibliographyStart = null;
		if (!empty($options['excludeBibliography'])) {
			$bibliographyStart = $this->findBibliographyStart($text);
			if ($bibliographyStart !== null) {
				$excludedRanges[] = [$bibliographyStart, strlen($text)];
			}
		}
		if (!empty($options['excludeQuotes'])) {
			$excludedRanges = array_merge($excludedRanges, $this->findQuotedRanges($text));
		}

		preg_match_all('/[\p{L}\p{M}\p{N}' . preg_quote(self::APOSTROPHES, '/') . ']+/u', $text, $matches, PREG_OFFSET_CAPTURE);

		$words = [];
		$offsets = [];
		foreach ($matches[0] as $match) {
			list($raw, $offset) = $match;
			if ($excludedRanges && self::_inRanges($offset, $excludedRanges)) {
				continue;
			}
			$word = self::normalizeWord($raw);
			if ($word === '') {
				continue;
			}
			$words[] = $word;
			$offsets[] = [$offset, strlen($raw)];
		}

		return ['words' => $words, 'offsets' => $offsets, 'excludedFrom' => $bibliographyStart];
	}

	/**
	 * Hash every run of $shingleSize consecutive characters of the words
	 * joined without separators.
	 *
	 * @param string[] $words Normalized words
	 * @return int[] Shingle hash for each character position
	 */
	public function shingleHashes($words) {
		$stream = implode('', $words);
		$hashes = [];
		$count = strlen($stream) - $this->_shingleSize + 1;
		for ($i = 0; $i < $count; $i++) {
			$hashes[] = crc32(substr($stream, $i, $this->_shingleSize));
		}
		return $hashes;
	}

	/**
	 * Character position where each word starts in the joined stream used by
	 * shingleHashes(), plus a final entry for the stream length.
	 *
	 * @param string[] $words
	 * @return int[]
	 */
	public static function wordStarts($words) {
		$starts = [];
		$position = 0;
		foreach ($words as $word) {
			$starts[] = $position;
			$position += strlen($word);
		}
		$starts[] = $position;
		return $starts;
	}

	/**
	 * Select the subset of hashes stored in the search index. Keeping only
	 * hashes divisible by $modulo keeps the index small while long copied
	 * passages are still found with near certainty.
	 *
	 * @param int[] $hashes
	 * @param int $modulo
	 * @return int[] Unique sampled hashes
	 */
	public static function sampleHashes($hashes, $modulo) {
		$sampled = [];
		foreach ($hashes as $hash) {
			if ($hash % $modulo === 0) {
				$sampled[$hash] = true;
			}
		}
		return array_keys($sampled);
	}

	/**
	 * Find where the reference list starts. Only headings in the second half
	 * of the document are considered so a "Literature review" section near the
	 * start is not mistaken for the bibliography.
	 *
	 * @param string $text
	 * @return int|null Byte offset of the heading line
	 */
	public function findBibliographyStart($text) {
		// Longest phrases first so "foydalanilgan adabiyotlar royxati" wins over "adabiyotlar"
		$headings = self::$_bibliographyHeadings;
		usort($headings, function ($a, $b) {
			return strlen($b) - strlen($a);
		});
		$half = (int) (strlen($text) / 2);
		$found = null;
		$offset = 0;
		foreach (explode("\n", $text) as $line) {
			$lineLength = strlen($line) + 1;
			if ($offset >= $half && strlen($line) < 120) {
				// Strip leading numbering like "5." or "IV."
				$normalized = preg_replace('/^(?:\d+|[ivx]+)\s+/', '', self::normalizeLine($line));
				if ($normalized !== '' && trim(str_replace($headings, '', $normalized)) === '') {
					$found = $offset;
					break;
				}
			}
			$offset += $lineLength;
		}
		return $found;
	}

	/**
	 * Byte ranges of short quotations: «...», “...”, „...“ and "...".
	 *
	 * @param string $text
	 * @return array of [start, end]
	 */
	public function findQuotedRanges($text) {
		$ranges = [];
		$pattern = '/«[^«»]{1,600}»|“[^“”]{1,600}”|„[^„“”]{1,600}[“”]|"[^"\n]{1,600}"/u';
		if (preg_match_all($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
			foreach ($m[0] as $match) {
				$ranges[] = [$match[1], $match[1] + strlen($match[0])];
			}
		}
		return $ranges;
	}

	/**
	 * @return string[]
	 */
	protected static function _apostropheChars() {
		static $chars = null;
		if ($chars === null) {
			$chars = preg_split('//u', self::APOSTROPHES, -1, PREG_SPLIT_NO_EMPTY);
		}
		return $chars;
	}

	/**
	 * @param int $offset
	 * @param array $ranges
	 * @return bool
	 */
	protected static function _inRanges($offset, $ranges) {
		foreach ($ranges as $range) {
			if ($offset >= $range[0] && $offset < $range[1]) {
				return true;
			}
		}
		return false;
	}
}
