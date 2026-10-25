/**
 * Plagiarism check results page: filter rows by title or submission number.
 * Delegated so it keeps working after the back-office Vue app renders the page.
 */
$(document).on('input', '#pcResultsSearch', function() {
	var term = this.value.toLowerCase();
	$('.pcResults__table tbody tr').each(function() {
		$(this).toggle($(this).text().toLowerCase().indexOf(term) !== -1);
	});
});
