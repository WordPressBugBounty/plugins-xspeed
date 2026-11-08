/**
 * xSpeed: "Main (LCP) image" panel in the block editor's document sidebar (#703).
 *
 * Reads and writes the `_xspeed_lcp_image` post meta, which the editor saves
 * through REST with the post: '' (Auto), 'featured', 'none' or an image URL.
 * The classic editor gets the same choice from a meta box rendered in PHP.
 *
 * Plain script on the editor's own globals, so it needs no build step.
 */
( function ( wp ) {
	if ( ! wp || ! wp.plugins || ! wp.element || ! wp.data || ! wp.components ) {
		return;
	}
	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var __ = wp.i18n.__;
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;
	var SelectControl = wp.components.SelectControl;
	var TextControl = wp.components.TextControl;
	var Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	var META = '_xspeed_lcp_image';

	if ( ! Panel ) {
		return;
	}

	/** Image URLs in the post content, in order, without repeats. */
	function contentImages( html ) {
		var out = [];
		var re = /<img\b[^>]*?\ssrc=(["'])([^"']+)\1/gi;
		var m;
		while ( ( m = re.exec( html || '' ) ) ) {
			var src = m[ 2 ].replace( /&amp;/g, '&' );
			if ( /^(https?:)?\//i.test( src ) && out.indexOf( src ) === -1 ) {
				out.push( src );
			}
		}
		return out;
	}

	function fileName( url ) {
		var path = url.split( '?' )[ 0 ];
		return path.substring( path.lastIndexOf( '/' ) + 1 ) || url;
	}

	function modeOf( value ) {
		if ( ! value ) {
			return 'auto';
		}
		return value === 'featured' || value === 'none' ? value : 'image';
	}

	function LcpPanel() {
		var data = useSelect( function ( select ) {
			var editor = select( 'core/editor' );
			var meta = editor.getEditedPostAttribute( 'meta' ) || {};
			var featuredId = editor.getEditedPostAttribute( 'featured_media' );
			var media = featuredId ? select( 'core' ).getMedia( featuredId ) : null;
			return {
				hasMeta: Object.prototype.hasOwnProperty.call( meta, META ),
				value: meta[ META ] || '',
				content: editor.getEditedPostContent(),
				featured: media && media.source_url ? media.source_url : '',
			};
		}, [] );
		var editPost = useDispatch( 'core/editor' ).editPost;
		// "Choose an image" with nothing picked yet is not a stored value,
		// so the mode is held here until an image is picked.
		var state = useState( null );
		var pending = state[ 0 ];
		var setPending = state[ 1 ];
		// What is typed in the URL box, saved once it reads as a URL.
		var typed = useState( null );
		var draft = typed[ 0 ];
		var setDraft = typed[ 1 ];

		if ( ! data.hasMeta ) {
			return null;
		}

		var mode = pending || modeOf( data.value );
		var images = contentImages( data.content );
		if ( data.featured && images.indexOf( data.featured ) === -1 ) {
			images.push( data.featured );
		}

		function save( value ) {
			var meta = {};
			meta[ META ] = value;
			editPost( { meta: meta } );
		}

		var children = [
			el( SelectControl, {
				key: 'mode',
				label: __( 'Main image to load first', 'xspeed' ),
				value: mode,
				options: [
					{ value: 'auto', label: __( 'Auto (recommended)', 'xspeed' ) },
					{ value: 'featured', label: __( 'Featured image', 'xspeed' ) },
					{ value: 'image', label: __( 'Choose an image…', 'xspeed' ) },
					{ value: 'none', label: __( 'None', 'xspeed' ) },
				],
				onChange: function ( next ) {
					if ( next === 'image' ) {
						setPending( 'image' );
						return;
					}
					setPending( null );
					save( next === 'auto' ? '' : next );
				},
				__nextHasNoMarginBottom: true,
			} ),
		];

		if ( mode === 'image' ) {
			var listed = images.indexOf( data.value ) !== -1;
			if ( images.length ) {
				children.push(
					el( SelectControl, {
						key: 'pick',
						label: __( 'Image on this page', 'xspeed' ),
						value: listed ? data.value : '',
						options: [ { value: '', label: __( 'Pick an image', 'xspeed' ) } ].concat(
							images.map( function ( src ) {
								return { value: src, label: fileName( src ) };
							} )
						),
						onChange: function ( src ) {
							if ( src ) {
								setPending( null );
								setDraft( null );
								save( src );
							}
						},
						__nextHasNoMarginBottom: true,
					} )
				);
			}
			children.push(
				el( TextControl, {
					key: 'url',
					type: 'url',
					label: __( 'Or an image URL', 'xspeed' ),
					help: __( 'For a hero set as a CSS background.', 'xspeed' ),
					value: draft !== null ? draft : ( modeOf( data.value ) === 'image' && ! listed ? data.value : '' ),
					onChange: function ( url ) {
						setDraft( url );
						url = ( url || '' ).trim();
						if ( /^(https?:\/\/|\/(?!\/))/i.test( url ) ) {
							setPending( null );
							save( url );
						}
					},
					__nextHasNoMarginBottom: true,
				} )
			);
		}

		children.push(
			el(
				'p',
				{ key: 'help', className: 'components-base-control__help' },
				__( 'The image picked here is fetched first and gets high priority. None turns off the main-image preload on this page.', 'xspeed' )
			)
		);

		return el(
			Panel,
			{ name: 'xspeed-lcp-image', title: __( 'Main (LCP) image', 'xspeed' ) },
			el( 'div', { style: { display: 'grid', gap: '12px' } }, children )
		);
	}

	wp.plugins.registerPlugin( 'xspeed-lcp-image', { render: LcpPanel, icon: null } );
}( window.wp ) );
