/**
 * Media Library picker for the social sharing preview image.
 *
 * @package WellActually
 */
( function () {
	'use strict';

	var selectButton = document.getElementById( 'wellactually-social-image-select' );
	var removeButton = document.getElementById( 'wellactually-social-image-remove' );
	var imageIdInput = document.getElementById( 'wellactually-social-image-id' );
	var previewImage = document.getElementById( 'wellactually-social-image-preview' );
	var mediaFrame;

	if ( ! selectButton || ! removeButton || ! imageIdInput || ! previewImage || ! window.wp || ! wp.media ) {
		return;
	}

	selectButton.addEventListener( 'click', function () {
		if ( mediaFrame ) {
			mediaFrame.open();
			return;
		}

		mediaFrame = wp.media( {
			title: wellactuallySettings.imageTitle,
			button: {
				text: wellactuallySettings.imageButton
			},
			library: {
				type: 'image'
			},
			multiple: false
		} );

		mediaFrame.on( 'select', function () {
			var attachment = mediaFrame.state().get( 'selection' ).first().toJSON();
			var previewUrl = attachment.url;

			if ( attachment.sizes && attachment.sizes.medium ) {
				previewUrl = attachment.sizes.medium.url;
			}

			imageIdInput.value = attachment.id;
			previewImage.src = previewUrl;
			previewImage.hidden = false;
			removeButton.hidden = false;
		} );

		mediaFrame.open();
	} );

	removeButton.addEventListener( 'click', function () {
		imageIdInput.value = '';
		previewImage.src = '';
		previewImage.hidden = true;
		removeButton.hidden = true;
	} );
}() );
