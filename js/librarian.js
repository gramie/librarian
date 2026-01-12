jQuery('document').ready(function () {
	jQuery('<input type="button" id="lookup-isbn" value="Lookup" style="margin-left: 4px;" />').insertAfter('#edit-field-isbn-0-value')
		.css('height', jQuery('#edit-field-isbn-0-value').css('height'))
		.click(function () {
			lookupISBNValue();
		});

	jQuery('#cover-images img').click(function() {
		const image = jQuery(this);
		jQuery('#cover-display img').removeClass('selected');
		image.addClass('selected');
		const url = image.attr('src');
		jQuery('#edit-field-remote-image-url-0-value').val(url);
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

	for (let authorIdx = 0; authorIdx < 5; authorIdx++) {
		const authorName = data.authors[authorIdx] ? data.authors[authorIdx] : [];
		jQuery('#edit-field-authors-' + authorIdx + '-value').val(authorName.join(', '));
	}

	const editorKeys = Drupal.CKEditor5Instances.keys().toArray();
	const editor = Drupal.CKEditor5Instances.get(editorKeys.shift());
	editor.setData(data.description);

	jQuery('div#cover-display p').hide();

	jQuery('#cover-display img').hide();
	if (data.coverImage.length > 0) {
		jQuery('#cover-image-0').attr('src', data.coverImage[0]).addClass('selected').show();
		jQuery('#edit-field-remote-image-url-0-value').val(data.coverImage[0]);
	}
	if (data.coverImage.length > 1) {
		jQuery('div#cover-display p').show();
		jQuery('#cover-image-1').attr('src', data.coverImage[1]).show();
	}

}
