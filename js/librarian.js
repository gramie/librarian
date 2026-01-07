jQuery('document').ready(function () {
	jQuery('#lookup-button')
		.html('<input type="button" id="lookup-isbn" value="Lookup" />')
		.click(function () {
			lookupISBNValue();
		});
});

jQuery('#edit-field-isbn-0-value')
	.keypress(function (event) {
		if (event.keyCode == 13) {
			lookupISBNValue();
			event.preventDefault();
		}
	});

function lookupISBNValue() {
	const ISBNControl = jQuery('#edit-field-isbn-0-value');
	processISBN(ISBNControl.val())
}

function processISBN(isbn) {
	stopDuplicates = true;
	jQuery.getJSON(drupalSettings.path.baseUrl + 'lookup-isbn/?isbn=' + isbn, function (result) {
		if (Object.keys(result).length > 0) {
			fillFormWithLookupData(result);
		} else {
			alert("No book found for this ISBN");
		}
	});
}

/**
 * Take the data and put it into the Drupal form
 * 
 * @param {Object} data 
 */
function fillFormWithLookupData(data) {
	console.log(data);
	const fields = {
		title: 'edit-title-0-value',
		subtitle: 'edit-field-subtitle-0-value',
		publication_year: 'edit-field-publication-year-0-value',
	}

	for (const fieldName of Object.keys(fields)) {
		const control = jQuery('#' + fields[fieldName]);
		control.val(data[fieldName]);
	}

	for (const authorIdx in data.authors) {
		jQuery('#edit-field-authors-' + authorIdx + '-value').val(data.authors[authorIdx].join(', '));
	}

	const editorKeys = Drupal.CKEditor5Instances.keys().toArray();
	const editor = Drupal.CKEditor5Instances.get(editorKeys.shift());
	editor.setData(data.description);

	jQuery('#cover-image').attr('src', data.coverImage);
	jQuery('#edit-field-remote-image-url-0-value').val(data.coverImage);
}
