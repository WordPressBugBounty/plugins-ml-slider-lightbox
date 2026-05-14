import { useState, useEffect, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { Spinner } from '@wordpress/components';

export default function Preview( { galleryId } ) {
	const [ html, setHtml ]       = useState( '' );
	const [ loading, setLoading ] = useState( false );
	const [ height, setHeight ]   = useState( 300 );
	const iframeRef = useRef( null );

	useEffect( () => {
		if ( ! galleryId ) return;
		let cancelled = false;
		setLoading( true );
		apiFetch( { path: `/ml-slider-lightbox/v1/gallery/preview?id=${ galleryId }` } )
			.then( ( res ) => {
				if ( cancelled ) return;
				setHtml( res.html );
				setLoading( false );
			} )
			.catch( () => { if ( ! cancelled ) setLoading( false ); } );
		return () => { cancelled = true; };
	}, [ galleryId ] );

	function onLoad() {
		const doc = iframeRef.current && iframeRef.current.contentDocument;
		if ( doc && doc.body ) {
			const h = doc.body.scrollHeight;
			if ( h > 0 ) setHeight( h + 16 );
		}
	}

	if ( loading ) {
		return (
			<div style={ { display: 'flex', justifyContent: 'center', padding: '24px' } }>
				<Spinner />
			</div>
		);
	}

	return (
		<div style={ { position: 'relative' } }>
			<iframe
				ref={ iframeRef }
				srcDoc={ html }
				onLoad={ onLoad }
				scrolling="no"
				style={ { width: '100%', height: `${ height }px`, border: 'none', display: 'block' } }
				title={ __( 'Gallery preview', 'ml-slider-lightbox' ) }
			/>
			{ /* Transparent overlay so editor click events reach the block, not the iframe */ }
			<div style={ { position: 'absolute', inset: 0 } } />
		</div>
	);
}
