// Minimal MCP stdio <-> Streamable HTTP bridge (no dependencies). Reads newline-delimited JSON-RPC
// from stdin, POSTs each message to COWBOY_MCP_URL with the Bearer key, and writes every JSON-RPC
// message in the reply (plain JSON or SSE) to stdout. Messages are forwarded in order.
import { createInterface } from 'node:readline';

const url = process.env.COWBOY_MCP_URL;
const key = process.env.COWBOY_MCP_KEY;
if ( ! url || ! key ) {
	console.error( 'COWBOY_MCP_URL and COWBOY_MCP_KEY are required' );
	process.exit( 1 );
}

let session = null;
let protocol = null;
const out = ( msg ) => process.stdout.write( JSON.stringify( msg ) + '\n' );

async function forward( line ) {
	let msg;
	try {
		msg = JSON.parse( line );
	} catch {
		out( { jsonrpc: '2.0', id: null, error: { code: -32700, message: 'Parse error' } } );
		return;
	}
	const headers = {
		'Content-Type': 'application/json',
		Accept: 'application/json, text/event-stream',
		Authorization: `Bearer ${ key }`,
	};
	if ( session ) headers[ 'Mcp-Session-Id' ] = session;
	if ( protocol ) headers[ 'MCP-Protocol-Version' ] = protocol;
	try {
		const res = await fetch( url, { method: 'POST', headers, body: line } );
		session = res.headers.get( 'mcp-session-id' ) || session;
		const body = await res.text();
		if ( ! body.trim() ) return;
		const replies = ( res.headers.get( 'content-type' ) || '' ).includes( 'text/event-stream' )
			? body.split( '\n' ).filter( ( l ) => l.startsWith( 'data:' ) ).map( ( l ) => JSON.parse( l.slice( 5 ) ) )
			: [ JSON.parse( body ) ];
		for ( const reply of replies ) {
			if ( msg.method === 'initialize' && reply.result?.protocolVersion ) protocol = reply.result.protocolVersion;
			out( reply );
		}
	} catch ( e ) {
		console.error( 'bridge:', e.message );
		if ( msg.id !== undefined ) out( { jsonrpc: '2.0', id: msg.id, error: { code: -32603, message: 'Cowboy MCP endpoint unreachable' } } );
	}
}

let queue = Promise.resolve();
createInterface( { input: process.stdin } ).on( 'line', ( line ) => {
	if ( line.trim() ) queue = queue.then( () => forward( line ) );
} ).on( 'close', () => queue.then( () => process.exit( 0 ) ) );
