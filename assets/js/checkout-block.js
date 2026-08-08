( function () {
	'use strict';

	if (
		! window.wp ||
		! window.wp.element ||
		! window.wp.i18n ||
		! window.wp.plugins ||
		! window.wc ||
		! window.wc.blocksCheckout
	) {
		return;
	}

	var createElement = window.wp.element.createElement;
	var useState = window.wp.element.useState;
	var __ = window.wp.i18n.__;
	var registerPlugin = window.wp.plugins.registerPlugin;
	var blocksCheckout = window.wc.blocksCheckout;
	var OrderMeta = blocksCheckout.OrderMeta || blocksCheckout.ExperimentalOrderMeta;
	var extensionCartUpdate = blocksCheckout.extensionCartUpdate;

	if ( ! OrderMeta || ! extensionCartUpdate ) {
		return;
	}

	function OfferCard( props ) {
		var offer = props.offer;
		var offerKey = offer.rule_id + ':' + offer.product_id;
		var isPending = props.pendingKey === offerKey;
		var feedback = props.feedback[ offerKey ] || '';

		function updateSelection( event ) {
			var selected = event.target.checked;
			props.setPendingKey( offerKey );
			props.setFeedback( {} );

			extensionCartUpdate( {
				namespace: 'bumpmint',
				data: {
					rule_id: offer.rule_id,
					product_id: offer.product_id,
					add: selected,
				},
			} )
				.catch( function ( error ) {
					var messages = {};
					messages[ offerKey ] = error && error.message
						? error.message
						: __( 'The offer could not be updated. Please try again.', 'bumpmint-order-bump-for-woocommerce' );
					props.setFeedback( messages );
				} )
				.finally( function () {
					props.setPendingKey( '' );
				} );
		}

		return createElement(
			'div',
			{
				className: 'bumpmint-bump-box' + ( isPending ? ' is-loading' : '' ),
			},
			createElement( 'div', { className: 'bumpmint-badge' }, offer.badge ),
			createElement(
				'div',
				{ className: 'bumpmint-bump-content' },
				createElement(
					'label',
					{ className: 'bumpmint-bump-label' },
					createElement( 'input', {
						type: 'checkbox',
						className: 'bumpmint-checkbox',
						checked: !! offer.selected,
						disabled: isPending,
						onChange: updateSelection,
					} ),
					offer.image_url
						? createElement( 'img', {
							className: 'bumpmint-bump-image',
							src: offer.image_url,
							alt: offer.image_alt || '',
						} )
						: null,
					createElement(
						'span',
						{ className: 'bumpmint-bump-copy' },
						createElement( 'span', { className: 'bumpmint-bump-title' }, offer.title ),
						createElement( 'span', {
							className: 'bumpmint-bump-price',
							dangerouslySetInnerHTML: { __html: offer.price_html },
						} )
					)
				),
				createElement( 'p', { className: 'bumpmint-bump-description' }, offer.description ),
				createElement(
					'p',
					{
						className: 'bumpmint-bump-feedback',
						role: 'status',
						'aria-live': 'polite',
					},
					feedback
				)
			)
		);
	}

	function Offers( props ) {
		var state = useState( '' );
		var pendingKey = state[ 0 ];
		var setPendingKey = state[ 1 ];
		var feedbackState = useState( {} );
		var feedback = feedbackState[ 0 ];
		var setFeedback = feedbackState[ 1 ];
		var extensionData = props.extensions && props.extensions.bumpmint;
		var offers = extensionData && Array.isArray( extensionData.offers )
			? extensionData.offers
			: [];

		if ( props.context !== 'woocommerce/checkout' || ! offers.length ) {
			return null;
		}

		return createElement(
			'div',
			{ className: 'bumpmint-position bumpmint-checkout-block' },
			offers.map( function ( offer ) {
				return createElement( OfferCard, {
					key: offer.rule_id + ':' + offer.product_id,
					offer: offer,
					pendingKey: pendingKey,
					setPendingKey: setPendingKey,
					feedback: feedback,
					setFeedback: setFeedback,
				} );
			} )
		);
	}

	registerPlugin( 'bumpmint-checkout-block', {
		render: function () {
			return createElement( OrderMeta, null, createElement( Offers ) );
		},
		scope: 'woocommerce-checkout',
	} );
}() );
