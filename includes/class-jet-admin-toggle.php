<?php
/**
 * Помощен рендър: превключвател 0/1 за настройките в админа.
 *
 * @package jetcredit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Клас за HTML toggle (скрито поле + бутон) с стилове от `jet_admin.css`.
 */
class Jet_Admin_Toggle {

	/**
	 * Връща HTML за един ред toggle.
	 *
	 * @param array $args {
	 *     @type string      $name       Атрибут `name` на скритото поле; стойност „0“ или „1“ при POST.
	 *     @type int|string  $value      Текуща стойност (0/1).
	 *     @type string      $id         `id` на input; по подразбиране съвпада с `$name`.
	 *     @type string      $aria_label  `aria-label` на превключвателя.
	 *     @type string      $label_on    Текст „включен“.
	 *     @type string      $label_off   Текст „изключен“.
	 *     @type string      $row_class   Опционален допълнителен клас върху `.jet_toggle_row`.
	 * }
	 * @return string
	 */
	public static function render( array $args ) {
		$name = isset( $args['name'] ) ? (string) $args['name'] : '';
		if ( $name === '' ) {
			return '';
		}

		$input_id  = ( isset( $args['id'] ) && (string) $args['id'] !== '' ) ? (string) $args['id'] : $name;
		$is_on     = isset( $args['value'] ) && (int) $args['value'] === 1;
		$aria      = isset( $args['aria_label'] ) ? (string) $args['aria_label'] : '';
		$label_on  = isset( $args['label_on'] ) ? (string) $args['label_on'] : 'Включен';
		$label_off = isset( $args['label_off'] ) ? (string) $args['label_off'] : 'Изключен';
		$row_extra = isset( $args['row_class'] ) ? trim( (string) $args['row_class'] ) : '';

		$switch_id = $input_id . '_switch';
		$state_id  = $input_id . '_state';

		$classes = 'jet_switch';
		if ( $is_on ) {
			$classes .= ' is-on';
		}

		$row_class = 'jet_toggle_row';
		if ( $row_extra !== '' ) {
			$row_class .= ' ' . $row_extra;
		}

		ob_start();
		?>
		<div class="<?php echo esc_attr( $row_class ); ?>">
			<input
				type="hidden"
				name="<?php echo esc_attr( $name ); ?>"
				id="<?php echo esc_attr( $input_id ); ?>"
				value="<?php echo $is_on ? '1' : '0'; ?>"
			/>
			<button
				type="button"
				class="<?php echo esc_attr( $classes ); ?>"
				id="<?php echo esc_attr( $switch_id ); ?>"
				role="switch"
				aria-checked="<?php echo $is_on ? 'true' : 'false'; ?>"
				<?php
				if ( $aria !== '' ) {
					echo ' aria-label="' . esc_attr( $aria ) . '"';
				}
				?>
				data-jet-toggle-for="<?php echo esc_attr( $input_id ); ?>"
				data-jet-label-on="<?php echo esc_attr( $label_on ); ?>"
				data-jet-label-off="<?php echo esc_attr( $label_off ); ?>"
			>
				<span class="jet_switch__track" aria-hidden="true"><span class="jet_switch__thumb"></span></span>
				<span class="jet_switch__state" id="<?php echo esc_attr( $state_id ); ?>"><?php echo $is_on ? esc_html( $label_on ) : esc_html( $label_off ); ?></span>
			</button>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
