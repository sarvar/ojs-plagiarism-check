<?php

/**
 * Standalone tests for the text/matching core (no OJS needed).
 *
 * Usage: php tests/EngineTest.php [directory-with-pdfs]
 */

ini_set('memory_limit', '512M'); // the test keeps two extractions of every PDF in memory
require_once(dirname(__DIR__) . '/classes/TextFingerprinter.inc.php');
require_once(dirname(__DIR__) . '/classes/SimilarityEngine.inc.php');
require_once(dirname(__DIR__) . '/classes/TextExtractor.inc.php');

$failures = 0;
function check($label, $condition) {
	global $failures;
	echo ($condition ? '  ok   ' : '  FAIL ') . $label . "\n";
	if (!$condition) $failures++;
}

$fp = new TextFingerprinter();
$engine = new SimilarityEngine($fp, 8);

echo "Normalization\n";
check('Uzbek Cyrillic == Uzbek Latin', TextFingerprinter::normalizeLine("Ўзбекистон Республикаси Олий Мажлиси ғоя ҳуқуқ") === TextFingerprinter::normalizeLine("O‘zbekiston Respublikasi Oliy Majlisi g'oya huquq"));
check('Word-initial е => ye', TextFingerprinter::normalizeWord('ер') === TextFingerprinter::normalizeWord('yer'));
check('Apostrophe variants are equal', TextFingerprinter::normalizeWord("o'zbek") === TextFingerprinter::normalizeWord('oʻzbek') && TextFingerprinter::normalizeWord('oʻzbek') === TextFingerprinter::normalizeWord('o’zbek'));
check('Russian is lowercased + transliterated', TextFingerprinter::normalizeWord('Конституция') === 'konstitusiya');
check('Hyphenation across lines is joined', strpos(TextFingerprinter::cleanText("konstitu-\nsiya"), 'konstitusiya') !== false);

echo "Bibliography and quotes\n";
$body = str_repeat("Matn qismi bu yerda davom etadi va muallif fikrini bayon qiladi.\n", 40);
$doc = $body . "\nFoydalanilgan adabiyotlar ro‘yxati:\n1. Karimov I. Asar. Toshkent, 2010.\n";
$t = $fp->tokenize($doc, ['excludeBibliography' => true]);
check('Bibliography heading found', $t['excludedFrom'] !== null);
check('Bibliography words dropped', !in_array('karimov', $t['words']));
$doc = $body . "\nСПИСОК ЛИТЕРАТУРЫ\n1. Иванов И.И. Право. М., 2010.\n";
check('Russian bibliography heading found', $fp->findBibliographyStart($doc) !== null);
$t = $fp->tokenize('Avval «bu iqtibos olib tashlanadi» keyin', ['excludeQuotes' => true]);
check('Quoted text dropped', $t['words'] === ['avval', 'keyin']);

echo "Matching\n";
$a = $fp->tokenize("Bu birinchi maqola. Huquqiy davlat qurish jarayonida sud hokimiyatining mustaqilligini ta'minlash eng muhim vazifalardan biri hisoblanadi va bu masala doimo dolzarb.")['words'];
$b = $fp->tokenize("Boshqa muallif yozadi: huquqiy davlat qurish jarayonida sud hokimiyatining mustaqilligini taʼminlash eng muhim vazifalardan biri hisoblanadi. Tamom.")['words'];
$c = $fp->tokenize("Butunlay boshqa mavzu haqida matn, hech qanday o'xshashlik yo'q bu yerda umuman olganda.")['words'];
$r = $engine->compare($b, ['a' => $a, 'c' => $c]);
check('Copied sentence found in source a', count($r['sources']) === 1 && $r['sources'][0]['key'] === 'a');
check('Copied run is 13 words', $r['sources'][0]['words'] === 13);
check('Unrelated text not matched', $engine->compare($c, ['a' => $a])['score'] == 0);
$short = $fp->tokenize("Sud hokimiyatining mustaqilligini ta'minlash muhim.")['words'];
check('Matches below the minimum length are ignored', $engine->compare($short, ['a' => $a])['score'] == 0);
$glued = $fp->tokenize("Boshqamuallifyozadi: huquqiydavlatqurishjarayonidasudhokimiyatiningmustaqilliginitaʼminlashengmuhimvazifalardanbirihisoblanadi.")['words'];
check('Spaced text matches a source whose words are merged', $engine->compare($b, ['g' => $glued])['sources'][0]['words'] === 16);
$cyr = $fp->tokenize("Ҳуқуқий давлат қуриш жараёнида суд ҳокимиятининг мустақиллигини таъминлаш энг муҳим вазифалардан бири ҳисобланади.")['words'];
check('Latin text matches the same text in Cyrillic', $engine->compare($b, ['c' => $cyr])['sources'][0]['words'] === 13);
$boiler = [];
for ($i = 0; $i < 6; $i++) {
	$boiler["d$i"] = $fp->tokenize("Lawyer herald ilmiy amaliy jurnal tahririyat kengashi raisi va tahrir hay'ati a'zolari ro'yxati. Noyob matn raqam $i " . str_repeat("so'z$i ", 20))['words'];
}
$q = $fp->tokenize("Lawyer herald ilmiy amaliy jurnal tahririyat kengashi raisi va tahrir hay'ati a'zolari ro'yxati. Yangi maqola matni bu yerda.")['words'];
check('Text shared by 5+ sources is ignored as boilerplate', $engine->compare($q, $boiler)['score'] == 0);

// Real PDFs: extraction + cross-document check with an injected copy
$dir = isset($argv[1]) ? $argv[1] : null;
if ($dir && is_dir($dir)) {
	echo "Real PDFs in $dir\n";
	$files = array_slice(glob(rtrim($dir, '/') . '/*.pdf'), 0, 40);
	$withBinary = new TextExtractor('pdftotext');
	$purephp = new TextExtractor(null);
	$pureDocs = [];
	$docs = [];
	$timePure = 0;
	$pureEmpty = 0;
	foreach ($files as $file) {
		try {
			$text = TextFingerprinter::cleanText($withBinary->extract($file, 'pdf'));
		} catch (Throwable $e) {
			echo "  skip " . basename($file) . ': ' . $e->getMessage() . "\n";
			continue;
		}
		$start = microtime(true);
		try {
			$pure = $purephp->extract($file, 'pdf');
		} catch (Throwable $e) {
			$pure = '';
		}
		$timePure += microtime(true) - $start;
		$tokens = $fp->tokenize($text, ['excludeBibliography' => true]);
		$pureTokens = $fp->tokenize(TextFingerprinter::cleanText($pure), ['excludeBibliography' => true]);
		if (count($pureTokens['words']) < 50) $pureEmpty++;
		$docs[basename($file)] = $tokens['words'];
		$pureDocs[basename($file)] = $pureTokens['words'];
		printf("  %-22s pdftotext %6d words | pure-PHP %6d words | bibliography %s\n", basename($file), count($tokens['words']), count($pureTokens['words']), $tokens['excludedFrom'] !== null ? 'cut' : '-');
	}
	printf("  pure-PHP parser: %.1fs total for %d files, %d with no usable text\n", $timePure, count($docs), $pureEmpty);

	$keys = array_keys($docs);
	$max = 0;
	$start = microtime(true);
	foreach ($keys as $key) {
		$others = $docs;
		unset($others[$key]);
		$res = $engine->compare($docs[$key], $others);
		if ($res['score'] > 5) {
			printf("  natural overlap: %s %.1f%% (top source %s)\n", $key, $res['score'], $res['sources'][0]['key']);
		}
		$max = max($max, $res['score']);
	}
	printf("  all-pairs check of %d docs took %.1fs, highest natural score %.1f%%\n", count($docs), microtime(true) - $start, $max);

	// Build a fake submission: 300 own words + 3 copied passages of 60 words from 3 articles
	$own = $fp->tokenize(str_repeat("Mazkur tadqiqotda muallif mustaqil xulosalar chiqaradi va yangi g'oyalarni ilgari suradi raqam ", 1))['words'];
	$fake = [];
	for ($i = 0; $i < 30; $i++) {
		$fake = array_merge($fake, array_map(function ($w) use ($i) { return $w . $i; }, $own));
	}
	$injected = 0;
	// Copy from the glued (merged-words) PDFs when available: the fake takes
	// clean words from pdftotext, the index holds the pure-PHP extraction.
	$sourceKeys = array_slice(array_values(array_filter($keys, function ($k) use ($docs, $pureDocs) {
		return count($pureDocs[$k]) < count($docs[$k]) * 0.6;
	})), 0, 2);
	$sourceKeys = array_merge($sourceKeys, array_slice(array_diff($keys, $sourceKeys), 0, 3 - count($sourceKeys)));
	foreach ($sourceKeys as $n => $key) {
		// Take the passage from the article body (past the issue front matter)
		$passage = array_slice($docs[$key], (int) (count($docs[$key]) * 0.65), 60);
		$fake = array_merge($fake, $passage);
		$injected += count($passage);
	}
	$res = $engine->compare($fake, $pureDocs);
	$expected = round($injected * 100 / count($fake), 1);
	printf("  injected %d copied words into %d (%.1f%%): detected %.1f%%, sources: %s\n", $injected, count($fake), $expected, $res['score'], implode(', ', array_map(function ($s) { return $s['key'] . ' ' . $s['percent'] . '%'; }, array_slice($res['sources'], 0, 4))));
	echo '  sources copied from: ' . implode(', ', $sourceKeys) . " (index uses pure-PHP extraction)\n";
	check('Injected copies detected (within 3%)', abs($res['score'] - $expected) <= 3.0);
	$top = array_column(array_slice($res['sources'], 0, 3), 'key');
	sort($top);
	$expectedKeys = $sourceKeys;
	sort($expectedKeys);
	check('All 3 source articles identified', $top === $expectedKeys);

	// Index sampling: are the 3 sources retrievable from the sampled index (modulo 4)?
	$index = [];
	foreach ($pureDocs as $key => $words) {
		foreach (TextFingerprinter::sampleHashes($fp->shingleHashes($words), 16) as $h) {
			$index[$h][] = $key;
		}
	}
	$hits = [];
	foreach (array_unique($fp->shingleHashes($fake)) as $h) {
		if (isset($index[$h])) foreach ($index[$h] as $key) $hits[$key] = isset($hits[$key]) ? $hits[$key] + 1 : 1;
	}
	arsort($hits);
	$retrieved = array_slice(array_keys($hits), 0, 50);
	check('Sampled index retrieves all 3 sources', !array_diff($expectedKeys, $retrieved));
	printf("  index size: %d sampled hashes for %d documents\n", array_sum(array_map('count', $index)), count($pureDocs));
}

echo $failures ? "\n$failures FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
