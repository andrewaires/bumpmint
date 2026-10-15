/* global bumpmintDesignEditor */
( function () {
	'use strict';

	var config = window.bumpmintDesignEditor;
	var form = document.querySelector( '.bumpmint-rule-form' );

	if ( ! config || ! form ) {
		return;
	}

	var tabs = Array.prototype.slice.call( form.querySelectorAll( '[data-bumpmint-tab]' ) );
	var panels = Array.prototype.slice.call( form.querySelectorAll( '[data-bumpmint-panel]' ) );
	var activeTabField = form.querySelector( '.bumpmint-active-tab' );
	var touchedField = form.querySelector( '.bumpmint-design-touched' );
	var presetField = form.querySelector( '.bumpmint-design-preset' );
	var descriptionField = form.querySelector( '.bumpmint-design-preset-description' );
	var currentPreset = presetField ? presetField.value : 'classic';
	var presetDrafts = {};
	var previewFrame = null;
	var previewDocument = null;
	var previewFrameLoaded = false;
	var previewFrameHolder = form.querySelector( '.bumpmint-preview-frame-holder' );
	var previewTemplate = form.querySelector( '.bumpmint-preview-document' );
	var previewUpdateFrame = 0;
	var availablePresets = Object.keys( config.presets || {} );

	if ( ! tabs.length || ! panels.length || ! presetField || ! previewFrameHolder || ! previewTemplate ) {
		return;
	}

	function getTabPanel( name ) {
		return form.querySelector( '[data-bumpmint-panel="' + name + '"]' );
	}

	function activateTab( name, focusTab ) {
		var selected = tabs.filter( function ( tab ) {
			return tab.getAttribute( 'data-bumpmint-tab' ) === name;
		} )[ 0 ];

		if ( ! selected || ! getTabPanel( name ) ) {
			return;
		}

		tabs.forEach( function ( tab ) {
			var isActive = tab === selected;
			tab.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
			tab.setAttribute( 'tabindex', isActive ? '0' : '-1' );
		} );
		panels.forEach( function ( panel ) {
			var isActive = panel.getAttribute( 'data-bumpmint-panel' ) === name;
			panel.hidden = ! isActive;
			panel.setAttribute( 'aria-hidden', isActive ? 'false' : 'true' );
		} );

		if ( activeTabField ) {
			activeTabField.value = name;
		}
		if ( focusTab ) {
			selected.focus();
		}
		if ( 'design' === name ) {
			createPreviewFrame();
			queuePreviewUpdate();
		}
	}

	function initializeTabs() {
		var tabList = tabs[ 0 ].parentNode;
		var initialTab = activeTabField && 'design' === activeTabField.value ? 'design' : 'settings';

		tabList.setAttribute( 'role', 'tablist' );
		tabs.forEach( function ( tab ) {
			var name = tab.getAttribute( 'data-bumpmint-tab' );
			var panel = getTabPanel( name );
			tab.setAttribute( 'role', 'tab' );
			tab.setAttribute( 'id', 'bumpmint-' + name + '-tab' );
			tab.setAttribute( 'aria-controls', panel.id );
			tab.setAttribute( 'aria-selected', 'false' );
			tab.setAttribute( 'tabindex', '-1' );
		} );
		panels.forEach( function ( panel ) {
			var name = panel.getAttribute( 'data-bumpmint-panel' );
			panel.setAttribute( 'role', 'tabpanel' );
			panel.setAttribute( 'aria-labelledby', 'bumpmint-' + name + '-tab' );
			panel.setAttribute( 'tabindex', '0' );
		} );

		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				activateTab( tab.getAttribute( 'data-bumpmint-tab' ), false );
			} );
			tab.addEventListener( 'keydown', function ( event ) {
				var currentIndex = tabs.indexOf( tab );
				var nextIndex = currentIndex;
				if ( 'ArrowRight' === event.key ) {
					nextIndex = ( currentIndex + 1 ) % tabs.length;
				} else if ( 'ArrowLeft' === event.key ) {
					nextIndex = ( currentIndex + tabs.length - 1 ) % tabs.length;
				} else if ( 'Home' === event.key ) {
					nextIndex = 0;
				} else if ( 'End' === event.key ) {
					nextIndex = tabs.length - 1;
				} else {
					return;
				}
				event.preventDefault();
				activateTab( tabs[ nextIndex ].getAttribute( 'data-bumpmint-tab' ), true );
			} );
		} );

		form.addEventListener( 'invalid', function ( event ) {
			var panel = event.target.closest ? event.target.closest( '[data-bumpmint-panel]' ) : null;
			if ( panel ) {
				activateTab( panel.getAttribute( 'data-bumpmint-panel' ), false );
			}
		}, true );
		form.addEventListener( 'submit', function () {
			if ( activeTabField ) {
				activeTabField.value = panels.filter( function ( panel ) {
					return ! panel.hidden;
				} ).map( function ( panel ) {
					return panel.getAttribute( 'data-bumpmint-panel' );
				} )[ 0 ] || 'settings';
			}
		} );

		activateTab( initialTab, false );
	}

	function getFieldValue( key ) {
		var field = form.querySelector( '[data-design-key="' + key + '"]' );
		return field ? field.value : '';
	}

	function readPresetValues() {
		var values = {};
		Object.keys( config.fields || {} ).forEach( function ( key ) {
			values[ key ] = getFieldValue( key );
		} );
		return values;
	}

	function markDesignTouched() {
		if ( touchedField ) {
			touchedField.value = '1';
		}
	}

	function writePresetValues( preset, values ) {
		var defaults = ( config.defaults && config.defaults[ preset ] ) || {};
		Object.keys( config.fields || {} ).forEach( function ( key ) {
			var field = config.fields[ key ];
			var control = form.querySelector( '[data-design-key="' + key + '"]' );
			var value = Object.prototype.hasOwnProperty.call( values, key ) ? values[ key ] : defaults[ key ];
			var wrapper = form.querySelector( '[data-design-field="' + key + '"]' );
			var range = wrapper ? wrapper.querySelector( '.bumpmint-design-range' ) : null;
			var colorPicker = wrapper ? wrapper.querySelector( '.bumpmint-design-color-picker' ) : null;

			if ( ! control ) {
				return;
			}
			if ( 'undefined' === typeof value || null === value ) {
				value = defaults[ key ];
			}
			control.value = String( value );
			control.setAttribute( 'data-default', String( defaults[ key ] ) );
			if ( range ) {
				range.value = String( value );
			}
			var normalizedColor = normalizeColor( value );
			if ( colorPicker && normalizedColor ) {
				colorPicker.value = normalizedColor;
			}
			if ( field.type === 'integer' && range && ! /^\d+$/.test( String( value ) ) ) {
				range.value = String( defaults[ key ] );
			}
		} );
	}

	function updatePresetDescription() {
		var preset = config.presets && config.presets[ currentPreset ];
		if ( descriptionField ) {
			descriptionField.textContent = preset ? preset.description : '';
		}
	}

	function storeCurrentDraft() {
		presetDrafts[ currentPreset ] = readPresetValues();
	}

	function handlePresetChange() {
		var nextPreset = presetField.value;
		if ( availablePresets.indexOf( nextPreset ) === -1 ) {
			presetField.value = currentPreset;
			return;
		}

		storeCurrentDraft();
		currentPreset = nextPreset;
		writePresetValues( currentPreset, presetDrafts[ currentPreset ] || ( config.defaults && config.defaults[ currentPreset ] ) || {} );
		storeCurrentDraft();
		updatePresetDescription();
		markDesignTouched();
		queuePreviewUpdate();
	}

	function createPreviewFrame() {
		if ( previewFrame || ! previewFrameHolder ) {
			return;
		}

		previewFrame = document.createElement( 'iframe' );
		previewFrame.setAttribute( 'title', config.preview && config.preview.frameTitle ? config.preview.frameTitle : 'Order bump design preview' );
		previewFrame.setAttribute( 'sandbox', 'allow-same-origin' );
		previewFrame.setAttribute( 'scrolling', 'auto' );
		previewFrame.loading = 'lazy';
		previewFrame.style.height = '250px';
		previewFrame.style.width = '600px';
		previewFrame.style.maxWidth = '100%';
		previewFrameHolder.textContent = '';
		previewFrameHolder.appendChild( previewFrame );
		previewFrame.addEventListener( 'load', function () {
			try {
				previewDocument = previewFrame.contentDocument;
				previewFrameLoaded = !! previewDocument;
				if ( previewDocument ) {
					previewDocument.addEventListener( 'change', handlePreviewCheckboxChange );
					previewDocument.addEventListener( 'click', handlePreviewLinkClick );
					var image = previewDocument.querySelector( '.bumpmint-bump-image' );
					if ( image ) {
						image.addEventListener( 'load', resizePreviewFrame );
					}
				}
				queuePreviewUpdate();
			} catch ( error ) {
				previewFrameLoaded = false;
			}
		} );
		previewFrame.srcdoc = previewTemplate.content ? previewTemplate.content.textContent : previewTemplate.textContent;
	}

	function handlePreviewCheckboxChange( event ) {
		if ( ! event.target.matches || ! event.target.matches( '[data-bumpmint-preview-checkbox]' ) ) {
			return;
		}
		var state = form.querySelector( '.bumpmint-preview-state' );
		if ( state ) {
			state.value = event.target.checked ? 'selected' : 'unselected';
		}
		queuePreviewUpdate();
	}

	function handlePreviewLinkClick( event ) {
		if ( event.target.closest && event.target.closest( '[data-bumpmint-preview-link]' ) ) {
			event.preventDefault();
		}
	}

	function getCssValue( key, value, field ) {
		if ( 'color' === field.type ) {
			return normalizeColor( value );
		}
		if ( 'integer' === field.type ) {
			var number = Number( value );
			if ( ! Number.isInteger( number ) || number < field.min || number > field.max ) {
				return null;
			}
			return String( number ) + ( field.unit || 'px' );
		}
		return config.cssValues && config.cssValues[ key ] ? config.cssValues[ key ][ value ] || null : null;
	}

	function normalizeColor( value ) {
		var color = String( value ).toLowerCase();
		if ( /^#[a-f0-9]{3}$/.test( color ) ) {
			return '#' + color.charAt( 1 ) + color.charAt( 1 ) + color.charAt( 2 ) + color.charAt( 2 ) + color.charAt( 3 ) + color.charAt( 3 );
		}
		return /^#[a-f0-9]{6}$/.test( color ) ? color : null;
	}

	function updatePreviewContent() {
		if ( ! previewFrameLoaded || ! previewDocument ) {
			return;
		}

		var card = previewDocument.querySelector( '[data-bumpmint-preview-card]' );
		if ( ! card ) {
			return;
		}

		availablePresets.forEach( function ( preset ) {
			card.classList.remove( 'bumpmint-design-' + preset );
		} );
		card.classList.add( 'bumpmint-design-' + currentPreset );
		Object.keys( config.fields || {} ).forEach( function ( key ) {
			var field = config.fields[ key ];
			var value = getFieldValue( key );
			var defaults = config.defaults && config.defaults[ currentPreset ] ? config.defaults[ currentPreset ] : {};
			var variable = field.css_var;
			card.style.removeProperty( variable );
			if ( String( value ) !== String( defaults[ key ] ) ) {
				var cssValue = getCssValue( key, value, field );
				if ( cssValue ) {
					card.style.setProperty( variable, cssValue );
				}
			}
		} );

		var productName = config.preview && config.preview.productName ? config.preview.productName : 'Sample product';
		var priceText = config.preview && config.preview.priceText ? config.preview.priceText : '';
		var titleInput = form.querySelector( '#bumpmint-offer-title' );
		var descriptionInput = form.querySelector( '#bumpmint-description' );
		var badgeInput = form.querySelector( '#bumpmint-badge-text' );
		var titleTemplate = titleInput && titleInput.value ? titleInput.value : ( config.preview && config.preview.titleDefault ? config.preview.titleDefault : 'Add {product} to your order' );
		var descriptionTemplate = descriptionInput && descriptionInput.value ? descriptionInput.value : ( config.preview && config.preview.descriptionDefault ? config.preview.descriptionDefault : 'Add {product} for only {price}.' );
		var badgeText = badgeInput && badgeInput.value ? badgeInput.value : ( config.preview && config.preview.badgeDefault ? config.preview.badgeDefault : '10% OFF' );
		var title = replacePlaceholders( titleTemplate, productName, priceText );
		var description = replacePlaceholders( descriptionTemplate, productName, priceText );
		var titleNode = previewDocument.querySelector( '[data-bumpmint-preview-title]' );
		var descriptionNode = previewDocument.querySelector( '[data-bumpmint-preview-description]' );
		var badgeNode = previewDocument.querySelector( '[data-bumpmint-preview-badge]' );
		if ( titleNode ) {
			titleNode.textContent = title;
		}
		if ( descriptionNode ) {
			descriptionNode.textContent = description;
		}
		if ( badgeNode ) {
			badgeNode.textContent = badgeText;
		}

		var imageNode = previewDocument.querySelector( '.bumpmint-bump-image' );
		var selectedImage = form.querySelector( '.bumpmint-image-preview' );
		var fallbackImage = config.preview && config.preview.placeholderUrl ? config.preview.placeholderUrl : '';
		if ( imageNode ) {
			imageNode.src = selectedImage && selectedImage.src && selectedImage.style.display !== 'none' ? selectedImage.src : fallbackImage;
			imageNode.alt = productName;
		}

		var productType = form.querySelector( '.bumpmint-preview-product-type' );
		var isBookable = productType && 'bookable' === productType.value;
		var checkbox = previewDocument.querySelector( '[data-bumpmint-preview-checkbox]' );
		var bookableActions = previewDocument.querySelector( '[data-bumpmint-preview-bookable]' );
		var stateField = form.querySelector( '.bumpmint-preview-state' );
		var state = stateField ? stateField.value : 'unselected';
		var feedback = previewDocument.querySelector( '[data-bumpmint-preview-feedback]' );
		var loading = 'loading' === state;

		card.classList.toggle( 'is-configurable', !! isBookable );
		card.classList.toggle( 'is-loading', loading );
		if ( checkbox ) {
			checkbox.hidden = !! isBookable;
			checkbox.checked = 'selected' === state || ( isBookable && 'loading' === state );
			checkbox.disabled = !! isBookable || loading;
		}
		if ( bookableActions ) {
			bookableActions.hidden = ! isBookable;
		}
		if ( feedback ) {
			feedback.textContent = 'error' === state && config.preview ? config.preview.errorText : '';
		}

		resizePreviewFrame();
	}

	function replacePlaceholders( text, productName, priceText ) {
		return String( text ).replace( /\{product\}|\{produto\}|\{producto\}/gi, productName )
			.replace( /\{price\}|\{preco\}|\{precio\}/gi, priceText );
	}

	function resizePreviewFrame() {
		if ( ! previewFrame || ! previewDocument ) {
			return;
		}
		var widthField = form.querySelector( '.bumpmint-preview-width' );
		var desiredWidth = widthField ? parseInt( widthField.value, 10 ) : 600;
		previewFrame.style.width = Math.max( 280, desiredWidth || 600 ) + 'px';
		previewFrame.style.maxWidth = '100%';
		window.requestAnimationFrame( function () {
			if ( ! previewDocument || ! previewFrame ) {
				return;
			}
			var contentHeight = Math.max( previewDocument.documentElement.scrollHeight, previewDocument.body ? previewDocument.body.scrollHeight : 0 );
			previewFrame.style.height = Math.min( 620, Math.max( 200, contentHeight ) ) + 'px';
		} );
	}

	function queuePreviewUpdate() {
		if ( previewUpdateFrame ) {
			window.cancelAnimationFrame( previewUpdateFrame );
		}
		previewUpdateFrame = window.requestAnimationFrame( function () {
			previewUpdateFrame = 0;
			updatePreviewContent();
		} );
	}

	function resetField( key ) {
		var defaults = config.defaults && config.defaults[ currentPreset ] ? config.defaults[ currentPreset ] : {};
		var value = defaults[ key ];
		var wrapper = form.querySelector( '[data-design-field="' + key + '"]' );
		var control = form.querySelector( '[data-design-key="' + key + '"]' );
		var range = wrapper ? wrapper.querySelector( '.bumpmint-design-range' ) : null;
		var colorPicker = wrapper ? wrapper.querySelector( '.bumpmint-design-color-picker' ) : null;
		if ( ! control || 'undefined' === typeof value ) {
			return;
		}
		control.value = String( value );
		if ( range ) {
			range.value = String( value );
		}
		if ( colorPicker ) {
			colorPicker.value = String( value );
		}
		storeCurrentDraft();
		markDesignTouched();
		queuePreviewUpdate();
	}

	function bindDesignControls() {
		form.addEventListener( 'input', function ( event ) {
			var target = event.target;
			var didUpdateDesignControl = false;
			if ( target.classList.contains( 'bumpmint-design-range' ) ) {
				var field = target.closest( '[data-design-field]' );
				var numberInput = field ? field.querySelector( '.bumpmint-design-value' ) : null;
				if ( numberInput ) {
					numberInput.value = target.value;
				}
				didUpdateDesignControl = true;
			}
			if ( target.classList.contains( 'bumpmint-design-color-picker' ) ) {
				var colorField = target.closest( '[data-design-field]' );
				var colorText = colorField ? colorField.querySelector( '.bumpmint-design-value' ) : null;
				if ( colorText ) {
					colorText.value = target.value;
				}
				didUpdateDesignControl = true;
			}
			if ( target.matches( '[data-design-key]' ) || didUpdateDesignControl ) {
				var wrapper = target.closest( '[data-design-field]' );
				if ( didUpdateDesignControl && wrapper ) {
					target = wrapper.querySelector( '.bumpmint-design-value' ) || target;
				}
				var picker = wrapper ? wrapper.querySelector( '.bumpmint-design-color-picker' ) : null;
				var slider = wrapper ? wrapper.querySelector( '.bumpmint-design-range' ) : null;
				var normalizedColor = normalizeColor( target.value );
				if ( picker && normalizedColor ) {
					picker.value = normalizedColor;
				}
				if ( slider && /^\d+$/.test( target.value ) ) {
					slider.value = target.value;
				}
				markDesignTouched();
				storeCurrentDraft();
				queuePreviewUpdate();
			}
			if ( target.matches( '#bumpmint-offer-title, #bumpmint-description, #bumpmint-badge-text' ) ) {
				queuePreviewUpdate();
			}
		} );

		form.addEventListener( 'change', function ( event ) {
			var target = event.target;
			if ( target === presetField ) {
				handlePresetChange();
			} else if ( target.matches( '.bumpmint-design-value[data-design-key]' ) ) {
				markDesignTouched();
				storeCurrentDraft();
				queuePreviewUpdate();
			} else if ( target.matches( '.bumpmint-preview-width, .bumpmint-preview-state, .bumpmint-preview-product-type' ) ) {
				queuePreviewUpdate();
			}
		} );

		form.addEventListener( 'bumpmint:offer-content-change', queuePreviewUpdate );
		window.addEventListener( 'resize', function () {
			if ( previewFrame ) {
				queuePreviewUpdate();
			}
		} );
		form.addEventListener( 'click', function ( event ) {
			var reset = event.target.closest ? event.target.closest( '[data-reset-design-field]' ) : null;
			if ( reset ) {
				event.preventDefault();
				resetField( reset.getAttribute( 'data-reset-design-field' ) );
				return;
			}
			if ( event.target.closest && event.target.closest( '.bumpmint-design-reset-all' ) ) {
				event.preventDefault();
				writePresetValues( currentPreset, config.defaults[ currentPreset ] || {} );
				storeCurrentDraft();
				markDesignTouched();
				queuePreviewUpdate();
			}
		} );
	}

	presetDrafts[ currentPreset ] = readPresetValues();
	updatePresetDescription();
	bindDesignControls();
	initializeTabs();
}() );
