jQuery('#edit-isbn')
	.keypress(function(event) {
		if (event.keyCode == 13) {
			const isbnControl = jQuery(this);
			// isbnControl.parent().append(Drupal.theme.ajaxProgressThrobber(Drupal.t('Loading...'));
			processISBN(isbnControl.val())
			event.preventDefault();
			isbnControl.val('');
		}
	})

function processISBN(isbn) {
	stopDuplicates = true;
	jQuery.getJSON(drupalSettings.path.baseUrl + 'lookup-isbn/?isbn=' + isbn, function (result) {
		const info = result.data.info;
		jQuery('#pending-book-list tbody').append(
			`<tr><td>${info.field_isbn}</td><td>${info.title}</td><td>${info.authors.join(', ')}</td></tr>`
		);
		const booksToAddControl = jQuery('input[name="books_to_add"]');
		const booksToAddLength = booksToAddControl.val().length;
		if (booksToAddControl.val().length > 0) {
			booksToAddControl.val(booksToAddControl.val() + ',');
		}
		booksToAddControl.val(booksToAddControl.val() + isbn);
		// jQuery('.ajax-progress-throbber').remove();
	});
}

function convertAuthorName(name) {
	const nameParts = name.split(' ');
	const firstName = nameParts.shift();
	return nameParts.join(' ') + ', ' + firstName;
}

// (function (Drupal, once) {
// 	Drupal.behaviors.my_module = {
// 		attach: function (context) {

// 			// Add the throbber to the body tag, but you can add it to any element.  
// 			once('librarian', 'body').forEach((element) => {
// 				element.insertAdjacentHTML('afterend', Drupal.theme.ajaxProgressThrobber(Drupal.t('Loading...')));

// 				// Remove throbber after 5 seconds.
// 				const throbber = document.querySelector('.ajax-progress-throbber');
// 				setTimeout(() => throbber.remove(), 5000);
// 			});
// 		}
// 	};
// })(Drupal, once);

