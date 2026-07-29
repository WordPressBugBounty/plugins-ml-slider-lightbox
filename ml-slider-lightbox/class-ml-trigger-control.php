<?php

namespace MetaSlider\Lightbox;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Shared renderer + mappers for the single "how to open images" control.
 *
 * Approach A: the control is backed by the two existing boolean settings; this
 * class only maps between them and the mode string and prints the dropdown UI.
 */
class TriggerControl
{
    /**
     * Map the two stored booleans to a single mode.
     *
     * @param bool $show_button Whether a button/icon is shown.
     * @param bool $use_icon    Whether the button shows an icon instead of text.
     * @return string One of image|icon|button.
     */
    public static function modeFromBooleans( $show_button, $use_icon )
    {
        if ( ! $show_button ) {
            return 'image';
        }
        return $use_icon ? 'icon' : 'button';
    }

    /**
     * Map a mode to the two stored booleans.
     *
     * @param string $mode One of image|icon|button.
     * @return array{show_button:bool,use_icon:bool}
     */
    public static function booleansFromMode( $mode )
    {
        return array(
            'show_button' => ( 'icon' === $mode || 'button' === $mode ),
            'use_icon'    => ( 'icon' === $mode ),
        );
    }

    /**
     * Print the hidden inputs + the mode dropdown for the control.
     *
     * The two hidden inputs remain the stored source of truth (Approach A); the
     * unnamed <select> is a UI driver that JS syncs into them on change.
     *
     * @param array $args {
     *     @type string $name_show Field name for the show-button boolean.
     *     @type string $name_icon Field name for the use-icon boolean.
     *     @type string $mode      Active mode: image|icon|button.
     * }
     * @return void
     */
    public static function render( array $args )
    {
        $name_show = $args['name_show'];
        $name_icon = $args['name_icon'];
        $mode      = in_array( $args['mode'], array( 'image', 'icon', 'button' ), true ) ? $args['mode'] : 'image';
        $bools     = self::booleansFromMode( $mode );
        $labels    = array(
            'image'  => __( 'Image', 'ml-slider-lightbox' ),
            'icon'   => __( 'Icon', 'ml-slider-lightbox' ),
            'button' => __( 'Button', 'ml-slider-lightbox' ),
        );
        ?>
        <div class="ml-trigger-mode">
            <input type="hidden" class="ml-trigger-show" name="<?php echo esc_attr( $name_show ); ?>" value="<?php echo $bools['show_button'] ? '1' : '0'; ?>">
            <input type="hidden" class="ml-trigger-icon" name="<?php echo esc_attr( $name_icon ); ?>" value="<?php echo $bools['use_icon'] ? '1' : '0'; ?>">
            <select class="ml-trigger-select">
                <?php foreach ( array( 'image', 'icon', 'button' ) as $m ) : ?>
                    <option value="<?php echo esc_attr( $m ); ?>" <?php selected( $m, $mode ); ?>><?php echo esc_html( $labels[ $m ] ); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php
    }
}
