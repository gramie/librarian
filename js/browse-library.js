jQuery(document).ready(function() {
    jQuery('table#book-listing').on('click', '.request-holding', function() {
        alert('requesting holding');
    });
});

// Formatting function for row details - modify as you need
function format(d) {
    // `d` is the original data object for the row
    let result = '<div class="book-details">';

    result += '  <div class="book-info">';
    result += '  <div class="book-text">'
    result += `    <div class="book-title">${d.title}</div>`;
    if (d.subtitle) {
        result += `    <div class="book-subtitle">${d.subtitle}</div>`;
    }
    result += `    <div class="book-description">${d.description}</div>`;
    result += '</div>';
    if (d.cover_url) {
        result += `    <div class="book-cover"><img src="${d.cover_url}" alt="image of book cover" /></div>`;
    }
 
    result += '  </div>';
    
    result += '  <div class="book-buttons">';
    result += '  <div class="button-title">Available from:</div>';
    for (const holdingID of Object.keys(d.holdings)) {
        const holding = d.holdings[holdingID];
        result += this.renderHoldingButtons(holdingID, holding);
    }
    result += '  </div>';
    result += '</div>';
    result += '  </div>';

    return result;
}

function renderHoldingButtons(holdingID, holding) {
    let nameToDisplay = holding.owner_name;
    if (holding.is_owner) {
        nameToDisplay += ' (you)';
    }
    const disabledButton = holding.is_available == 1 && !holding.is_owner ? '' : 'disabled="disabled"';
    const result = `    <input type="button" class="request-holding" value="${nameToDisplay}" ${disabledButton} title="click to request this book" />`;

    return result;
}


jQuery.ajax('/get-library')
    .done(function(data) {
        const books = data.books.map(function(book) { 
            book.authors_joined = book.authors.join('; ');
            if (book.holdings) {
                for (const holding of Object.values(book.holdings)) {
                    const user = data.users[holding.owner_id];
                    holding.owner_name = user.firstname + ' ' + user.lastname;
                }
            }            
            return book;
        });
        const libraryTable = new DataTable('#book-listing', {
            columns: [
                {
                    className: 'dt-control',
                    orderable: false,
                    data: null,
                    defaultContent: ''
                },
                { data: 'title' },
                { data: 'authors_joined' },
                { data: 'category' }
            ],
            data: data.books,
            order: [[1, 'asc']]
        });
 

        // Add event listener for opening and closing details
        libraryTable.on('click', 'tbody td.dt-control', function (e) {
            let tr = e.target.closest('tr');
            let row = libraryTable.row(tr);
        
            if (row.child.isShown()) {
                // This row is already open - close it
                row.child.hide();
            }
            else {
                // Open this row
                row.child(format(row.data())).show();
            }
        });
    });
 
