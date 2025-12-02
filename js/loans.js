let loanData;

jQuery(document).ready(function() {
    
    jQuery('table#book-listing').on('click', '.request-holding', function() {
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
        }).done(function(data) {
            loanData = data;
            console.log(data);
            const fields = [];
            for (const fieldName in data.visibleFields) {
                fields.push({ data: fieldName, title : data.visibleFields[fieldName] });
            }
            fields.push({ data: 'actions', title: 'Actions' });
            addActions(data.data);
            data.loansByStatus = separateLoans(data.data);
            console.log(data.loansByStatus);
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


function addActions(data) {
    for (const loan of data) {
        if (isActiveLoan(loan.status)) {
            const actions = [];
            loan.actions = [];
            if (loan.borrower_name) {
                actions.push('Lend');
                actions.push('Refuse');
            } else {
                actions.push('Cancel');
            }

            loan.actions = actions.map(x => `<input type="button" class="${x.toLowerCase()}-button" value="${x}" data-holding="${loan.holding_id}" />`).join('');
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
    const result = { active: [], completed: []};

    for (const loan of loans) {
        if (isActiveLoan(loan.status)) {
            result.active.push(loan);
        } else {
            result.completed.push(loan);
        }
    }

    return result;
}

function isActiveLoan(status) {
    return ['pending', 'lent_out'].includes(status);
}

