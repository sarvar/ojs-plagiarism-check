<?php

/**
 * @defgroup plugins_generic_plagiarismCheck Plagiarism Check Plugin
 */

/**
 * @file plugins/generic/plagiarismCheck/index.php
 *
 * Distributed under the GNU GPL v3.
 *
 * @ingroup plugins_generic_plagiarismCheck
 * @brief Wrapper for the local plagiarism check plugin.
 */

require_once('PlagiarismCheckPlugin.inc.php');

return new PlagiarismCheckPlugin();
