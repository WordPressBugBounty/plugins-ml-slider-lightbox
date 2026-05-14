import { useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { useBlockProps, InspectorControls, BlockControls } from '@wordpress/block-editor';
import { PanelBody, ToggleControl, ComboboxControl, Placeholder, ToolbarGroup, ToolbarButton, ExternalLink } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import Preview from './preview';
import icon from './icon';

export default function Edit( { attributes, setAttributes } ) {
    const { galleryId, isFullWidth } = attributes;
    const blockProps = useBlockProps();
    const [ isSelecting, setIsSelecting ] = useState( false );

    const galleries = useSelect( ( select ) => {
        return select( 'core' ).getEntityRecords( 'postType', 'ml_gallery', {
            per_page: -1,
            status:   'publish',
        } );
    }, [] );

    const galleryOptions = ( galleries || [] ).map( ( gallery ) => ( {
        value: gallery.id,
        label: gallery.title?.rendered || __( '(no title)', 'ml-slider-lightbox' ),
    } ) );

    if ( ! galleryId || isSelecting ) {
        return (
            <div { ...blockProps }>
                <Placeholder
                    icon={ icon }
                    label={ __( 'MetaSlider Gallery', 'ml-slider-lightbox' ) }
                >
                    <ComboboxControl
                        label={ __( 'Select a gallery to display', 'ml-slider-lightbox' ) }
                        value={ galleryId || null }
                        options={ galleryOptions }
                        onChange={ ( value ) => {
                            setAttributes( { galleryId: value } );
                            setIsSelecting( false );
                        } }
                    />
                </Placeholder>
            </div>
        );
    }

    return (
        <>
            <BlockControls>
                <ToolbarGroup>
                    <ToolbarButton
                        icon="edit"
                        label={ __( 'Change Gallery', 'ml-slider-lightbox' ) }
                        onClick={ () => setIsSelecting( true ) }
                    />
                </ToolbarGroup>
            </BlockControls>
            <InspectorControls>
                <PanelBody title={ __( 'Gallery Settings', 'ml-slider-lightbox' ) }>
                    <ComboboxControl
                        label={ __( 'Gallery', 'ml-slider-lightbox' ) }
                        value={ galleryId }
                        options={ galleryOptions }
                        onChange={ ( value ) => {
                            setAttributes( { galleryId: value } );
                            setIsSelecting( false );
                        } }
                    />
                    <div style={ { marginTop: '8px', marginBottom: '16px' } }>
                        <ExternalLink href={ `post.php?post=${ galleryId }&action=edit` }>
                            { __( 'Edit Gallery', 'ml-slider-lightbox' ) }
                        </ExternalLink>
                    </div>
                    <ToggleControl
                        label={ __( 'Full Width', 'ml-slider-lightbox' ) }
                        help={ __( 'Stretch the gallery to the full viewport width.', 'ml-slider-lightbox' ) }
                        checked={ isFullWidth }
                        onChange={ ( value ) => setAttributes( { isFullWidth: value } ) }
                    />
                </PanelBody>
            </InspectorControls>
            <div { ...blockProps }>
                <Preview galleryId={ galleryId } />
            </div>
        </>
    );
}
