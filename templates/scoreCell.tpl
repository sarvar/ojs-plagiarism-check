{**
 * plugins/generic/plagiarismCheck/templates/scoreCell.tpl
 *
 * Distributed under the GNU GPL v3.
 *
 * Similarity score or status in the file grid
 *}
<span class="plagiarismCheck">
	{if $document->status == 'done'}
		<a href="{$reportUrl|escape}" target="_blank" title="{translate key="plugins.generic.plagiarismCheck.report.open"}"
			class="plagiarismCheck__score {if $document->score >= $warningThreshold}plagiarismCheck__score--high{elseif $document->score >= $warningThreshold/2}plagiarismCheck__score--medium{/if}">
			{$document->score|escape}%
		</a>
		{if $outdated}
			<span class="plagiarismCheck__note" title="{translate key="plugins.generic.plagiarismCheck.column.outdated"}">*</span>
		{/if}
		{if $document->external_status == 'done'}
			<a href="{$document->external_report_url|escape}" target="_blank" class="plagiarismCheck__external">
				{$document->external_provider|escape}: {$document->external_score|escape}%
			</a>
		{elseif $document->external_status == 'pending'}
			<span class="plagiarismCheck__note">{$document->external_provider|escape}: {translate key="plugins.generic.plagiarismCheck.status.queued"}</span>
		{/if}
	{elseif $document->status == 'failed'}
		<span class="plagiarismCheck__failed" title="{$document->error|escape}">{translate key="plugins.generic.plagiarismCheck.status.failed"}</span>
	{else}
		<span class="plagiarismCheck__note">{translate key="plugins.generic.plagiarismCheck.status.queued"}</span>
	{/if}
</span>
<style>
	.plagiarismCheck { display: inline-flex; gap: 6px; align-items: baseline; flex-wrap: wrap; }
	.plagiarismCheck__score { font-weight: 700; color: #00733c; }
	.plagiarismCheck__score--medium { color: #b35c00; }
	.plagiarismCheck__score--high { color: #c00; }
	.plagiarismCheck__failed { color: #c00; cursor: help; }
	.plagiarismCheck__note { color: #666; }
</style>
