( function( wp ) {
    var el = wp.element.createElement;
    var registerBlockType = wp.blocks.registerBlockType;
    var ServerSideRender = wp.serverSideRender;
    var InspectorControls = wp.blockEditor ? wp.blockEditor.InspectorControls : wp.editor.InspectorControls;
    var PanelBody = wp.components.PanelBody;
    var TextControl = wp.components.TextControl;
    var ToggleControl = wp.components.ToggleControl;

    registerBlockType( 'wf/superstar-record-gb', {
        title: 'WF Superstar Record (GenerateBlocks)',
        icon: 'admin-users',
        category: 'generateblocks',
        supports: {
            html: false
        },
        attributes: {
            postId: { type: 'integer' },
            showBio: { type: 'boolean', default: true },
            showChamps: { type: 'boolean', default: true },
            showFeuds: { type: 'boolean', default: true },
        },
        edit: function( props ) {
            var attributes = props.attributes;

            function onChangePostId( value ) {
                var id = parseInt( value, 10 ) || 0;
                props.setAttributes( { postId: id } );
            }

            function onToggle( key ) {
                return function( val ) {
                    var obj = {};
                    obj[key] = val;
                    props.setAttributes( obj );
                };
            }

            return el( 'div', { className: 'wf-gb-block-editor-preview' },
                el( InspectorControls, {},
                    el( PanelBody, { title: 'WF Superstar Settings', initialOpen: true },
                        el( TextControl, {
                            label: 'Preview Post ID (leave empty to use current post)',
                            value: attributes.postId || '',
                            onChange: onChangePostId,
                            help: 'Enter the Superstar post ID to preview a different profile in the editor.'
                        } ),
                        el( ToggleControl, {
                            label: 'Show Bio',
                            checked: attributes.showBio !== false,
                            onChange: onToggle( 'showBio' )
                        } ),
                        el( ToggleControl, {
                            label: 'Show Championships',
                            checked: attributes.showChamps !== false,
                            onChange: onToggle( 'showChamps' )
                        } ),
                        el( ToggleControl, {
                            label: 'Show Notable Feuds',
                            checked: attributes.showFeuds !== false,
                            onChange: onToggle( 'showFeuds' )
                        } )
                    )
                ),
                el( ServerSideRender, {
                    block: 'wf/superstar-record-gb',
                    attributes: props.attributes
                } )
            );
        },
        save: function() {
            return null;
        }
    } );
} )( window.wp );