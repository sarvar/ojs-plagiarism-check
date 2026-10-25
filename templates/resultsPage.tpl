{**
 * plugins/generic/plagiarismCheck/templates/resultsPage.tpl
 *
 * Distributed under the GNU GPL v3.
 *
 * Back-office page with all plagiarism check results
 *}
{extends file="layouts/backend.tpl"}

{block name="page"}
	<h1 class="app__pageHeading">
		{translate key="plugins.generic.plagiarismCheck.results.title"}
	</h1>

	<div class="app__contentPanel pcResults">
		<div class="pcResults__toolbar">
			<p class="pcResults__counts">
				{translate key="plugins.generic.plagiarismCheck.status.done"}: <strong>{$statusCounts.done|default:0}</strong>
				· {translate key="plugins.generic.plagiarismCheck.status.queued"}: <strong>{$statusCounts.queued|default:0}</strong>
				· {translate key="plugins.generic.plagiarismCheck.status.failed"}: <strong>{$statusCounts.failed|default:0}</strong>
			</p>
			{if $documents}
				<input type="search" id="pcResultsSearch" class="pcResults__search"
					placeholder="{translate key="plugins.generic.plagiarismCheck.results.search"}"
					aria-label="{translate key="plugins.generic.plagiarismCheck.results.search"}">
			{/if}
		</div>

		{if !$documents}
			<p>{translate key="plugins.generic.plagiarismCheck.results.empty"}</p>
		{else}
			<div class="pcResults__tableWrap">
				<table class="pcResults__table">
					<thead>
						<tr>
							<th class="pcResults__num">{translate key="plugins.generic.plagiarismCheck.report.similarity"}</th>
							<th>{translate key="plugins.generic.plagiarismCheck.results.article"}</th>
							<th>{translate key="plugins.generic.plagiarismCheck.results.type"}</th>
							<th>{translate key="plugins.generic.plagiarismCheck.results.status"}</th>
							<th>{translate key="plugins.generic.plagiarismCheck.results.checked"}</th>
						</tr>
					</thead>
					<tbody>
						{foreach from=$documents item=document}
							<tr>
								<td class="pcResults__num">
									{if $document->status == 'done' && $document->score !== null}
										<a href="{$reportUrls[$document->document_id]|escape}" target="_blank"
											class="pcResults__score {if $document->score >= $warningThreshold}pcResults__score--high{elseif $document->score >= $warningThreshold/2}pcResults__score--medium{/if}">{$document->score}%</a>
									{else}—{/if}
								</td>
								<td class="pcResults__title">
									<a href="{$workflowUrls[$document->document_id]|escape}">{$document->title|escape}</a>
									<span class="pcResults__muted">#{$document->submission_id}</span>
								</td>
								<td class="pcResults__nowrap">{translate key="plugins.generic.plagiarismCheck.source."|cat:$document->source_type}</td>
								<td class="pcResults__nowrap {if $document->status == 'failed'}pcResults__failed{/if}" {if $document->status == 'failed'}title="{$document->error|escape}"{/if}>
									{translate key="plugins.generic.plagiarismCheck.status."|cat:$document->status}
								</td>
								<td class="pcResults__nowrap">{if $document->date_checked}{$document->date_checked|date_format:$dateFormatShort}{/if}</td>
							</tr>
						{/foreach}
					</tbody>
				</table>
			</div>
		{/if}
	</div>

{/block}
