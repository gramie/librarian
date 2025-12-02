jQuery(document).ready(function() {
    
    jQuery('table#book-listing').on('click', '.request-holding', function() {
        alert('requesting holding');
    });

    loadLoanTable('lending');
    loadLoanTable('borrowing');
});

function loadLoanTable(loanDirection) {
    const table = jQuery('#' + loanDirection + '-table');
    if (table.length > 0) {
        jQuery.ajax({
            url: '/get-' + loanDirection + 's',
            context: document.body
        }).done(function(data) {
            console.log(data);
            const fields = [];
            for (const fieldName in data.visibleFields) {
                fields.push({ data: fieldName, title : data.visibleFields[fieldName] });
            }
            table.dataTable({
                columns: fields,
                data: data.data,
            });
        });
    }
}

