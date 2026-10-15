<?php
/**
 * Shared classic-checkout and admin-preview offer card.
 *
 * @package BumpMint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$bumpmint_is_preview             = isset( $bumpmint_is_preview ) && true === $bumpmint_is_preview;
$bumpmint_view_data              = isset( $bumpmint_view_data ) && is_array( $bumpmint_view_data ) ? $bumpmint_view_data : array();
$bumpmint_image_html             = isset( $bumpmint_image_html ) && is_string( $bumpmint_image_html ) ? $bumpmint_image_html : '';
$bumpmint_input_id               = isset( $bumpmint_input_id ) && is_string( $bumpmint_input_id ) ? $bumpmint_input_id : '';
$bumpmint_requires_configuration = ! empty( $bumpmint_view_data['requires_configuration'] ) && empty( $bumpmint_view_data['selected'] );
$bumpmint_design                 = isset( $bumpmint_view_data['design'] ) && is_array( $bumpmint_view_data['design'] )
	? $bumpmint_view_data['design']
	: BumpMint_Design::get_render_data( null );
$bumpmint_preset                 = isset( $bumpmint_design['preset'] ) && isset( BumpMint_Design::get_presets()[ $bumpmint_design['preset'] ] )
	? $bumpmint_design['preset']
	: 'classic';
$bumpmint_css_variables          = isset( $bumpmint_design['css_variables'] ) ? BumpMint_Design::serialize_variables( $bumpmint_design['css_variables'] ) : '';
$bumpmint_card_classes           = array( 'bumpmint-bump-box', 'bumpmint-design-' . $bumpmint_preset );
if ( $bumpmint_requires_configuration ) {
	$bumpmint_card_classes[] = 'is-configurable';
}
if ( $bumpmint_is_preview ) {
	$bumpmint_card_classes[] = 'bumpmint-preview-card';
}

$bumpmint_badge       = isset( $bumpmint_view_data['badge'] ) && is_scalar( $bumpmint_view_data['badge'] ) ? (string) $bumpmint_view_data['badge'] : '';
$bumpmint_title       = isset( $bumpmint_view_data['title'] ) && is_scalar( $bumpmint_view_data['title'] ) ? (string) $bumpmint_view_data['title'] : '';
$bumpmint_description = isset( $bumpmint_view_data['description'] ) && is_scalar( $bumpmint_view_data['description'] ) ? (string) $bumpmint_view_data['description'] : '';
$bumpmint_price_html  = isset( $bumpmint_view_data['price_html'] ) && is_string( $bumpmint_view_data['price_html'] ) ? $bumpmint_view_data['price_html'] : '';
$bumpmint_is_checked  = ! empty( $bumpmint_view_data['selected'] );
?>
<div
	<?php if ( $bumpmint_input_id ) : ?>id="<?php echo esc_attr( $bumpmint_input_id ); ?>-card"<?php endif; ?>
	class="<?php echo esc_attr( implode( ' ', $bumpmint_card_classes ) ); ?>"
	<?php if ( '' !== $bumpmint_css_variables ) : ?>style="<?php echo esc_attr( $bumpmint_css_variables ); ?>"<?php endif; ?>
	<?php if ( $bumpmint_is_preview ) : ?>data-bumpmint-preview-card="true"<?php else : ?>data-bumpmint-rule-id="<?php echo esc_attr( isset( $bumpmint_view_data['rule_id'] ) ? $bumpmint_view_data['rule_id'] : '' ); ?>"<?php endif; ?>
>
	<div class="bumpmint-badge"<?php if ( $bumpmint_is_preview ) : ?> data-bumpmint-preview-badge<?php endif; ?>><?php echo esc_html( $bumpmint_badge ); ?></div>
	<div class="bumpmint-bump-content">
		<?php if ( $bumpmint_requires_configuration && ! $bumpmint_is_preview ) : ?>
			<div class="bumpmint-bump-label">
		<?php else : ?>
			<label<?php if ( ! $bumpmint_is_preview ) : ?> for="<?php echo esc_attr( $bumpmint_input_id ); ?>"<?php endif; ?> class="bumpmint-bump-label">
		<?php endif; ?>
		<?php if ( ! $bumpmint_requires_configuration || $bumpmint_is_preview ) : ?>
			<input
				type="checkbox"
				class="bumpmint-checkbox"
				<?php if ( $bumpmint_is_preview ) : ?>id="<?php echo esc_attr( $bumpmint_input_id ); ?>" data-bumpmint-preview-checkbox<?php else : ?>id="<?php echo esc_attr( $bumpmint_input_id ); ?>" data-rule-id="<?php echo esc_attr( isset( $bumpmint_view_data['rule_id'] ) ? $bumpmint_view_data['rule_id'] : '' ); ?>" data-product-id="<?php echo esc_attr( isset( $bumpmint_view_data['product_id'] ) ? $bumpmint_view_data['product_id'] : 0 ); ?>"<?php endif; ?>
				<?php checked( $bumpmint_is_checked, true ); ?>
				<?php disabled( $bumpmint_requires_configuration ); ?>
			/>
		<?php endif; ?>
		<?php
		if ( '' !== $bumpmint_image_html ) {
			echo wp_kses_post( $bumpmint_image_html );
		}
		?>
			<span class="bumpmint-bump-copy">
				<span class="bumpmint-bump-title"<?php if ( $bumpmint_is_preview ) : ?> data-bumpmint-preview-title<?php endif; ?>><?php echo esc_html( $bumpmint_title ); ?></span>
				<span class="bumpmint-bump-price"><?php echo wp_kses_post( $bumpmint_price_html ); ?></span>
			</span>
		<?php if ( $bumpmint_requires_configuration && ! $bumpmint_is_preview ) : ?>
			</div>
		<?php else : ?>
			</label>
		<?php endif; ?>

		<p class="bumpmint-bump-description"<?php if ( $bumpmint_is_preview ) : ?> data-bumpmint-preview-description<?php endif; ?>><?php echo esc_html( $bumpmint_description ); ?></p>
		<?php if ( $bumpmint_requires_configuration && ! $bumpmint_is_preview && ! empty( $bumpmint_view_data['configuration_url'] ) ) : ?>
			<div class="bumpmint-native-actions">
				<a class="bumpmint-configure-button" href="<?php echo esc_url( $bumpmint_view_data['configuration_url'] ); ?>">
					<?php echo esc_html( isset( $bumpmint_view_data['configuration_label'] ) ? $bumpmint_view_data['configuration_label'] : '' ); ?>
				</a>
				<p class="bumpmint-native-hint"><?php echo esc_html( isset( $bumpmint_view_data['configuration_hint'] ) ? $bumpmint_view_data['configuration_hint'] : '' ); ?></p>
			</div>
		<?php elseif ( $bumpmint_is_preview ) : ?>
			<div class="bumpmint-native-actions" data-bumpmint-preview-bookable hidden>
				<a class="bumpmint-configure-button" href="#" data-bumpmint-preview-link>
					<?php echo esc_html( isset( $bumpmint_view_data['configuration_label'] ) ? $bumpmint_view_data['configuration_label'] : '' ); ?>
				</a>
				<p class="bumpmint-native-hint"><?php echo esc_html( isset( $bumpmint_view_data['configuration_hint'] ) ? $bumpmint_view_data['configuration_hint'] : '' ); ?></p>
			</div>
		<?php endif; ?>
		<p class="bumpmint-bump-feedback" role="status" aria-live="polite"<?php if ( $bumpmint_is_preview ) : ?> data-bumpmint-preview-feedback<?php endif; ?>></p>
	</div>
</div>
