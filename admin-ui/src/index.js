import { render } from '@wordpress/element';
import { App } from './App';

const root = document.getElementById( 'wc-mcp-admin-root' );
if ( root ) {
	render( <App />, root );
}
