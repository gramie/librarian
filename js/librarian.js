jQuery('document').ready(function () {
	jQuery('<input type="button" id="lookup-isbn" value="Lookup" style="margin-left: 4px;" />').insertAfter('#edit-field-isbn-0-value')
		.css('height', jQuery('#edit-field-isbn-0-value').css('height'))
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
	const isbn = jQuery('#edit-field-isbn-0-value').val();
	
	jQuery('#lookup-isbn').after(Drupal.theme.ajaxProgressThrobber());
	jQuery('#lookup-isbn').attr('disabled', true);

	jQuery.getJSON(drupalSettings.path.baseUrl + 'lookup-isbn/?isbn=' + isbn, function (result) {
		if (Object.keys(result).length > 0 && !result.error) {
			fillFormWithLookupData(result);
		} else {
			alert(result.error);
		}
		jQuery('.ajax-progress').remove();
		jQuery('#lookup-isbn').attr('disabled', false);
	});
}

/**
 * Take the data and put it into the Drupal Add Book form
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
