// Global object
let loanData;

jQuery(document).ready(function () {

    jQuery('table#book-listing').on('click', '.request-holding', function () {
        alert('requesting holding');
    });

    loadLoanTable('lending');
    loadLoanTable('borrowing');
});

/**
 * Get the loans, either "lending" or "borrowing" for the current user
 * 
 * @param {string} loanDirection 
 */
function loadLoanTable(loanDirection) {
    const table = jQuery('#' + loanDirection + '-table-active');
    // The current page will have either "lending" or "borrowing", not both
    if (table.length > 0) {
        jQuery.ajax({
            url: '/get-' + loanDirection + 's',
            context: document.body
        }).done(function (data) {
            loanData = data;
            const fields = [];
            for (const fieldName in data.visibleFields) {
                fields.push({ data: fieldName, title: data.visibleFields[fieldName] });
            }
            fields.push({ data: 'actions', title: 'Actions' });
            addActions(data.data);
            data.loansByStatus = separateLoans(data.data);
            table.dataTable({
                columns: fields,
                data: data.loansByStatus.active,
            });
            jQuery('#' + loanDirection + '-table-completed').dataTable({
                columns: fields,
                data: data.loansByStatus.completed,
            });
        });
    }
}


/**
 * Add action buttons for each Loan
 * 
 * @param {array} data 
 */
function addActions(data) {
    for (const loan of data) {
        if (isActiveLoan(loan.status)) {
            const actions = [];
            loan.actions = [];
            if (loan.borrower_name) {
                // The user looking at his lending page
                switch (loan.status) {
                    case 'pending':
                        actions.push('Lend');
                        actions.push('Refuse');
                        break;
                    case 'lent_out':
                        actions.push('Complete');
                        break;
                }
            } else {
                switch (loan.status) {
                    case 'pending':
                        actions.push('Cancel');
                        break;
                }
            }

            loan.actions = actions.map(x => `<input type="button" class="action-button ${x.toLowerCase()}-button" value="${x}" data-holding="${loan.id}" />`).join('');
        } else {
            const actions = [];
            switch (loan.status) {
                case 'complete':
                    actions.push('Incomplete');
                    break;
                case 'not_returned':
                    actions.push('Complete');
                    break;
            }
            loan.actions = actions.map(x => `<input type="button" class="action-button ${x.toLowerCase()}-button" value="${x}" data-holding="${loan.id}" />`).join('');
        }
    }
}

/**
 * Divide loans into active and completed statuses
 * 
 * @param {array} loans 
 * @returns 
 */
function separateLoans(loans) {
    const result = { active: [], completed: [] };

    for (const loan of loans) {
        if (isActiveLoan(loan.status)) {
            result.active.push(loan);
        } else {
            result.completed.push(loan);
        }
    }

    return result;
}

/**
 * Is the status considered Active or not?
 * 
 * @param {string} status 
 * @returns 
 */
function isActiveLoan(status) {
    return ['pending', 'lent_out'].includes(status);
}

function requestAction(action, holding_id) {
    jQuery.ajax({
        url: '/loan-action',
        data: { action: action, holding_id : holding_id },
        context: document.body
    }).done(function (data) {
    
    });
}
