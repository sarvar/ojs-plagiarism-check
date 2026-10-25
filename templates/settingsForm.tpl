{**
 * plugins/generic/plagiarismCheck/templates/settingsForm.tpl
 *
 * Distributed under the GNU GPL v3.
 *
 * Plagiarism check plugin settings
 *}
<script>
	$(function() {ldelim}
		$('#plagiarismCheckSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form class="pkp_form" id="plagiarismCheckSettingsForm" method="post" action="{url router=$smarty.const.ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}">
	{csrf}
	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="plagiarismCheckSettingsFormNotification"}

	<p>{translate key="plugins.generic.plagiarismCheck.settings.description"}</p>

	<p>
		<strong>{translate key="plugins.generic.plagiarismCheck.settings.status"}</strong>
		{translate key="plugins.generic.plagiarismCheck.status.done"}: {$statusCounts.done|default:0},
		{translate key="plugins.generic.plagiarismCheck.status.queued"}: {$statusCounts.queued|default:0},
		{translate key="plugins.generic.plagiarismCheck.status.failed"}: {$statusCounts.failed|default:0}
	</p>

	{fbvFormArea id="plagiarismCheckWhen"}
		{fbvFormSection title="plugins.generic.plagiarismCheck.settings.when" list=true}
			{fbvElement type="checkbox" id="autoCheckSubmissions" checked=$autoCheckSubmissions label="plugins.generic.plagiarismCheck.settings.autoCheckSubmissions"}
			{fbvElement type="checkbox" id="checkPublishedGalleys" checked=$checkPublishedGalleys label="plugins.generic.plagiarismCheck.settings.checkPublishedGalleys"}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormArea id="plagiarismCheckMatching"}
		{fbvFormSection title="plugins.generic.plagiarismCheck.settings.matching" list=true}
			{fbvElement type="checkbox" id="excludeBibliography" checked=$excludeBibliography label="plugins.generic.plagiarismCheck.settings.excludeBibliography"}
			{fbvElement type="checkbox" id="excludeQuotes" checked=$excludeQuotes label="plugins.generic.plagiarismCheck.settings.excludeQuotes"}
		{/fbvFormSection}
		{fbvFormSection description="plugins.generic.plagiarismCheck.settings.minMatchWords.description"}
			{fbvElement type="text" id="minMatchWords" value=$minMatchWords size=$fbvStyles.size.SMALL label="plugins.generic.plagiarismCheck.settings.minMatchWords"}
		{/fbvFormSection}
		{fbvFormSection description="plugins.generic.plagiarismCheck.settings.commonDocs.description"}
			{fbvElement type="text" id="commonDocs" value=$commonDocs size=$fbvStyles.size.SMALL label="plugins.generic.plagiarismCheck.settings.commonDocs"}
		{/fbvFormSection}
		{fbvFormSection description="plugins.generic.plagiarismCheck.settings.warningThreshold.description"}
			{fbvElement type="text" id="warningThreshold" value=$warningThreshold size=$fbvStyles.size.SMALL label="plugins.generic.plagiarismCheck.settings.warningThreshold"}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormArea id="plagiarismCheckExtraction"}
		{fbvFormSection title="plugins.generic.plagiarismCheck.settings.extraction"}
			<p>
				{if $pdftotextAvailable}
					{translate key="plugins.generic.plagiarismCheck.settings.pdftotext.available"}
				{else}
					{translate key="plugins.generic.plagiarismCheck.settings.pdftotext.missing"}
				{/if}
			</p>
			{fbvElement type="text" id="pdftotextPath" value=$pdftotextPath label="plugins.generic.plagiarismCheck.settings.pdftotextPath"}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormArea id="plagiarismCheckExternal"}
		{fbvFormSection title="plugins.generic.plagiarismCheck.settings.external" description="plugins.generic.plagiarismCheck.settings.external.description"}
			{fbvElement type="select" id="externalProvider" from=$externalProviders selected=$externalProvider translate=false label="plugins.generic.plagiarismCheck.settings.externalProvider"}
			{fbvElement type="text" id="externalApiUrl" value=$externalApiUrl label="plugins.generic.plagiarismCheck.settings.externalApiUrl"}
			{fbvElement type="text" password=true id="externalApiKey" value=$externalApiKey label="plugins.generic.plagiarismCheck.settings.externalApiKey"}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormButtons}
</form>
