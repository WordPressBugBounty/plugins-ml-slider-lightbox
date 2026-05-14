import { registerBlockType } from '@wordpress/blocks';
import metadata from '../block.json';
import Edit from './edit';
import icon from './icon';

registerBlockType( metadata.name, {
    icon,
    edit: Edit,
    save: () => null,
} );
