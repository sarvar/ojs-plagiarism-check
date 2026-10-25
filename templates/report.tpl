{**
 * plugins/generic/plagiarismCheck/templates/report.tpl
 *
 * Distributed under the GNU GPL v3.
 *
 * Similarity report: score, matching sources and the highlighted text
 *}
<!DOCTYPE html>
<html lang="{$currentLocale|replace:"_":"-"|escape}">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>{translate key="plugins.generic.plagiarismCheck.report.title"}: {$document->title|escape}</title>
	{include file=$stylesTemplate}
</head>
<body>
<header class="pc-header">
	<div class="pc-wrap">
		<p class="pc-eyebrow">{translate key="plugins.generic.plagiarismCheck.report.title"}</p>
		<h1>{$document->title|escape}</h1>
		<p class="pc-meta">
			<a href="{$workflowUrl|escape}" target="_blank">{translate key="plugins.generic.plagiarismCheck.report.openSubmission" id=$document->submission_id}</a>
			· {translate key="plugins.generic.plagiarismCheck.source."|cat:$document->source_type}
			{if $document->date_checked} · {$document->date_checked|date_format:$datetimeFormatShort}{/if}
		</p>
	</div>
</header>

<main class="pc-wrap">
{if !$report}
	<p class="pc-empty">
		{if $document->status == 'failed'}
			{translate key="plugins.generic.plagiarismCheck.status.failed"}: {$document->error|escape}
		{else}
			{translate key="plugins.generic.plagiarismCheck.report.notReady"}
		{/if}
	</p>
{else}
	{assign var=result value=$report.result}
	<section class="pc-summary">
		<div class="pc-score {if $result.score >= $warningThreshold}pc-score--high{elseif $result.score >= $warningThreshold/2}pc-score--medium{/if}">
			<span class="pc-score__value">{$result.score}%</span>
			<span class="pc-score__label">{translate key="plugins.generic.plagiarismCheck.report.similarity"}</span>
		</div>
		<dl class="pc-facts">
			<div><dt>{translate key="plugins.generic.plagiarismCheck.report.words"}</dt><dd>{$result.tokenCount}</dd></div>
			<div><dt>{translate key="plugins.generic.plagiarismCheck.report.matchedWords"}</dt><dd>{$result.matchedWords}</dd></div>
			<div><dt>{translate key="plugins.generic.plagiarismCheck.report.sources"}</dt><dd>{$result.sources|@count}</dd></div>
			<div><dt>{translate key="plugins.generic.plagiarismCheck.report.compared"}</dt><dd>{$result.candidates}</dd></div>
		</dl>
	</section>

	<p class="pc-note">
		{translate key="plugins.generic.plagiarismCheck.report.scope"}
		{translate key="plugins.generic.plagiarismCheck.report.settings" minWords=$result.options.minMatchWords commonDocs=$result.options.commonDocs}
		{if $result.options.excludeBibliography}{translate key="plugins.generic.plagiarismCheck.report.bibliographyExcluded"}{/if}
		{if $result.options.excludeQuotes}{translate key="plugins.generic.plagiarismCheck.report.quotesExcluded"}{/if}
	</p>

	{if $result.sources}
		<section>
			<h2>{translate key="plugins.generic.plagiarismCheck.report.sources"}</h2>
			<ol class="pc-sources">
				{foreach from=$result.sources item=source key=index}
					<li class="pc-source">
						<span class="pc-swatch pc-c{$index%10}"></span>
						<span class="pc-source__title">
							{if $sourceLinks[$index]}<a href="{$sourceLinks[$index]|escape}" target="_blank">{$source.title|escape}</a>{else}{$source.title|escape}{/if}
							<small>{translate key="plugins.generic.plagiarismCheck.source."|cat:$source.sourceType} · #{$source.submissionId}</small>
						</span>
						<span class="pc-source__score">{$source.percent}% <small>({translate key="plugins.generic.plagiarismCheck.report.wordsCount" count=$source.words})</small></span>
					</li>
				{/foreach}
			</ol>
		</section>
	{/if}

	<section>
		<h2>{translate key="plugins.generic.plagiarismCheck.report.text"}</h2>
		<div class="pc-text">{strip}
			{foreach from=$report.segments item=segment}
				{if $segment.excluded}
					<span class="pc-excluded" title="{translate key="plugins.generic.plagiarismCheck.report.excludedPart"}">{$segment.text|escape}</span>
				{elseif $segment.source !== null}
					<mark class="pc-c{$segment.source%10}" title="{$result.sources[$segment.source].title|escape}">{$segment.text|escape}</mark>
				{else}
					{$segment.text|escape}
				{/if}
			{/foreach}
		{/strip}</div>
	</section>
{/if}
</main>
</body>
</html>
