<?php
namespace MetaSlider\Lightbox;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GalleryImageSize {

    const META_KEY      = '_ml_custom_sizes';
    const MIN_DIMENSION = 50;
    const MAX_DIMENSION = 4000;

    public static function sizeKey( $w, $h, $crop ) {
        return 'ml-' . (int) $w . 'x' . (int) $h . ( $crop ? '-c' : '' );
    }

    public static function sanitizeDimension( $value ) {
        if ( ! is_numeric( $value ) ) {
            return 0;
        }

        $value = (int) $value;

        return ( $value >= self::MIN_DIMENSION && $value <= self::MAX_DIMENSION ) ? $value : 0;
    }

    public static function normalizeSettings( array $lg ) {
        if ( empty( $lg['gallery_size'] ) ) {
            $lg['gallery_size'] = isset( $lg['lightbox_size'] ) ? $lg['lightbox_size'] : 'full';

            if ( 'custom' === $lg['gallery_size'] ) {
                foreach ( array( 'w', 'h', 'crop' ) as $suffix ) {
                    if ( isset( $lg[ 'lightbox_size_' . $suffix ] ) ) {
                        $lg[ 'gallery_size_' . $suffix ] = $lg[ 'lightbox_size_' . $suffix ];
                    }
                }
            }
        }

        foreach ( array( 'gallery_size', 'lightbox_size' ) as $key ) {
            $lg[ $key . '_w' ]    = self::sanitizeDimension( isset( $lg[ $key . '_w' ] ) ? $lg[ $key . '_w' ] : 0 );
            $lg[ $key . '_h' ]    = self::sanitizeDimension( isset( $lg[ $key . '_h' ] ) ? $lg[ $key . '_h' ] : 0 );
            $lg[ $key . '_crop' ] = empty( $lg[ $key . '_crop' ] ) ? 0 : 1;

            if ( 'custom' === ( isset( $lg[ $key ] ) ? $lg[ $key ] : '' )
                && ( 0 === $lg[ $key . '_w' ] || 0 === $lg[ $key . '_h' ] ) ) {
                $lg[ $key ] = 'full';
            }
        }

        return $lg;
    }

    public static function resolve( $attachment_id, $size, $w, $h, $crop ) {
        if ( 'custom' !== $size ) {
            return $size;
        }

        $w = self::sanitizeDimension( $w );
        $h = self::sanitizeDimension( $h );

        if ( 0 === $w || 0 === $h ) {
            return 'full';
        }

        $key  = self::sizeKey( $w, $h, $crop );
        $meta = wp_get_attachment_metadata( $attachment_id );

        if ( is_array( $meta ) && isset( $meta['sizes'][ $key ] ) ) {
            return $key;
        }

        if ( self::restoreSizeEntry( $attachment_id, $key, $meta ) ) {
            return $key;
        }

        return self::closestRegisteredSize( $w, $h );
    }

    public static function closestRegisteredSize( $w, $h ) {
        $best       = 'full';
        $best_width = PHP_INT_MAX;

        foreach ( wp_get_registered_image_subsizes() as $name => $dims ) {
            $width = (int) $dims['width'];

            if ( $width >= $w && $width < $best_width ) {
                $best       = $name;
                $best_width = $width;
            }
        }

        return $best;
    }

    public static function generate( $attachment_id, $w, $h, $crop ) {
        $w = self::sanitizeDimension( $w );
        $h = self::sanitizeDimension( $h );

        if ( 0 === $w || 0 === $h ) {
            return false;
        }

        $meta = wp_get_attachment_metadata( $attachment_id );

        if ( ! is_array( $meta ) || empty( $meta['file'] ) ) {
            return false;
        }

        $key = self::sizeKey( $w, $h, $crop );

        if ( isset( $meta['sizes'][ $key ] ) ) {
            return true;
        }

        if ( (int) ( $meta['width'] ?? 0 ) < $w || (int) ( $meta['height'] ?? 0 ) < $h ) {
            return false;
        }

        $file = get_attached_file( $attachment_id );

        if ( ! $file ) {
            return false;
        }

        $editor = wp_get_image_editor( $file );

        if ( is_wp_error( $editor ) ) {
            return false;
        }

        if ( is_wp_error( $editor->resize( $w, $h, (bool) $crop ) ) ) {
            return false;
        }

        $saved = $editor->save();

        if ( is_wp_error( $saved ) || empty( $saved['file'] ) ) {
            return false;
        }

        $entry = array(
            'file'      => $saved['file'],
            'width'     => $saved['width'],
            'height'    => $saved['height'],
            'mime-type' => $saved['mime-type'],
        );

        $meta['sizes'][ $key ] = $entry;
        wp_update_attachment_metadata( $attachment_id, $meta );

        $own = get_post_meta( $attachment_id, self::META_KEY, true );
        $own = is_array( $own ) ? $own : array();

        $own[ $key ] = $entry;
        update_post_meta( $attachment_id, self::META_KEY, $own );

        return true;
    }

    private static function restoreSizeEntry( $attachment_id, $key, $meta ) {
        if ( ! is_array( $meta ) || empty( $meta['file'] ) ) {
            return false;
        }

        $own = get_post_meta( $attachment_id, self::META_KEY, true );

        if ( ! is_array( $own ) || empty( $own[ $key ]['file'] ) ) {
            return false;
        }

        $uploads = wp_get_upload_dir();
        $subdir  = dirname( $meta['file'] );
        $dir     = trailingslashit( $uploads['basedir'] ) . ( '.' === $subdir ? '' : trailingslashit( $subdir ) );

        if ( ! file_exists( $dir . $own[ $key ]['file'] ) ) {
            return false;
        }

        $meta['sizes'][ $key ] = $own[ $key ];
        return (bool) wp_update_attachment_metadata( $attachment_id, $meta );
    }
}
