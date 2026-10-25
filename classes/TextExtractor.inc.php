<?php

/**
 * @file plugins/generic/plagiarismCheck/classes/TextExtractor.inc.php
 *
 * Distributed under the GNU GPL v3.
 *
 * @class TextExtractor
 * @ingroup plugins_generic_plagiarismCheck
 *
 * @brief Extract plain text from PDF, DOCX, ODT, HTML and TXT files.
 *  PDFs use the `pdftotext` binary when the server allows running it and fall
 *  back to the bundled pure-PHP smalot/pdfparser on shared hosting.
 */

class TextExtractor {

	const MIME_PDF = 'application/pdf';
	const MIME_DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
	const MIME_ODT = 'application/vnd.oasis.opendocument.text';

	/** @var string|null Path or name of the pdftotext binary, null to never use it */
	protected $_pdftotext;

	/**
	 * @param string|null $pdftotext
	 */
	public function __construct($pdftotext = 'pdftotext') {
		$this->_pdftotext = $pdftotext;
	}

	/**
	 * @param string $mimetype
	 * @param string $filename Used to guess the type when the mimetype is generic
	 * @return string|null One of pdf, docx, odt, html, txt or null if unsupported
	 */
	public static function getFormat($mimetype, $filename = '') {
		$extension = strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION));
		if ($mimetype === self::MIME_PDF || $extension === 'pdf') return 'pdf';
		if ($mimetype === self::MIME_DOCX || $extension === 'docx') return 'docx';
		if ($mimetype === self::MIME_ODT || $extension === 'odt') return 'odt';
		if (in_array($mimetype, ['text/html', 'application/xhtml+xml']) || in_array($extension, ['html', 'htm'])) return 'html';
		if ($mimetype === 'text/plain' || $extension === 'txt') return 'txt';
		return null;
	}

	/**
	 * @param string $path Absolute path of the file
	 * @param string $format See getFormat()
	 * @return string Extracted text (may be empty for scanned PDFs)
	 * @throws Exception When the file can not be read
	 */
	public function extract($path, $format) {
		if (!is_readable($path)) {
			throw new Exception("File is not readable: $path");
		}
		switch ($format) {
			case 'pdf': return $this->_extractPdf($path);
			case 'docx': return $this->_extractZipXml($path, 'word/document.xml', ['</w:p>', '<w:br/>', '<w:tab/>']);
			case 'odt': return $this->_extractZipXml($path, 'content.xml', ['</text:p>', '</text:h>', '<text:line-break/>', '<text:tab/>']);
			case 'html': return $this->_htmlToText(file_get_contents($path));
			case 'txt': return file_get_contents($path);
		}
		throw new Exception("Unsupported format: $format");
	}

	/**
	 * Whether the pdftotext binary can be run on this server.
	 *
	 * @return bool
	 */
	public function canUsePdftotext() {
		if (!$this->_pdftotext || !function_exists('exec')) {
			return false;
		}
		$disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
		if (in_array('exec', $disabled)) {
			return false;
		}
		$output = [];
		$status = 1;
		@exec(escapeshellcmd($this->_pdftotext) . ' -v 2>&1', $output, $status);
		return $status === 0 || stripos(implode(' ', $output), 'pdftotext') !== false;
	}

	/**
	 * @param string $path
	 * @return string
	 */
	protected function _extractPdf($path) {
		if ($this->canUsePdftotext()) {
			$output = [];
			$status = 1;
			$tmp = tempnam(sys_get_temp_dir(), 'plg');
			@exec(escapeshellcmd($this->_pdftotext) . ' -enc UTF-8 -q ' . escapeshellarg($path) . ' ' . escapeshellarg($tmp), $output, $status);
			$text = $status === 0 ? (string) file_get_contents($tmp) : '';
			@unlink($tmp);
			if (trim($text) !== '') {
				return $text;
			}
		}

		self::loadVendor();
		$parser = new \Smalot\PdfParser\Parser();
		return $parser->parseFile($path)->getText();
	}

	/**
	 * Read text from an XML part inside a zip based document (DOCX/ODT).
	 *
	 * @param string $path
	 * @param string $entry Zip entry holding the body XML
	 * @param string[] $breaks Tags that should become whitespace
	 * @return string
	 */
	protected function _extractZipXml($path, $entry, $breaks) {
		if (!class_exists('ZipArchive')) {
			throw new Exception('The PHP zip extension is required to read DOCX/ODT files.');
		}
		$zip = new ZipArchive();
		if ($zip->open($path) !== true) {
			throw new Exception("Could not open document: $path");
		}
		$xml = $zip->getFromName($entry);
		$zip->close();
		if ($xml === false) {
			throw new Exception("Document body not found in: $path");
		}
		$replacements = [];
		foreach ($breaks as $tag) {
			$replacements[$tag] = strpos($tag, 'tab') !== false ? "\t" : "\n";
		}
		$xml = strtr($xml, $replacements);
		return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
	}

	/**
	 * @param string $html
	 * @return string
	 */
	protected function _htmlToText($html) {
		$html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html);
		$html = preg_replace('#<(br|/p|/div|/h[1-6]|/li|/tr)\b[^>]*>#i', "\n", $html);
		return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}

	/**
	 * Load the plugin's bundled composer dependencies.
	 */
	public static function loadVendor() {
		static $loaded = false;
		if (!$loaded) {
			require_once(dirname(__DIR__) . '/vendor/autoload.php');
			$loaded = true;
		}
	}
}
