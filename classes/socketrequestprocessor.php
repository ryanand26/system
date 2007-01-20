<?php

/**
 * RequestProcessor using sockets (fsockopen).
 */
class SocketRequestProcessor implements RequestProcessor
{
	private $response_body= '';
	private $response_headers= '';
	private $executed= FALSE;
	
	/**
	 * Maximum number of redirects to follow.
	 */
	private $max_redirs= 5;
	
	private $redir_count= 0;
	
	public function execute( $method, $url, $headers, $body, $timeout )
	{
		list( $response_headers, $response_body )= $this->_request( $method, $url, $headers, $body, $timeout );
		
		$this->response_headers= $response_headers;
		$this->response_body= $response_body;
		$this->executed=TRUE;
		
		return TRUE;
	}
	
	private function _request( $method, $url, $headers, $body, $timeout )
	{
		$urlbits= parse_url( $url );
		
		return $this->_work( $method, $urlbits, $headers, $body, $timeout );
	}
	
	private function _work( $method, $urlbits, $headers, $body, $timeout )
	{
		$_errno= 0;
		$_errstr= '';
		
		if ( !isset( $urlbits['port'] ) ) {
			$urlbits['port']= 80;
		}
		
		$fp= fsockopen( $urlbits['host'], $urlbits['port'], $_errno, $_errstr, $timeout );
		
		if ( !$fp ) {
			return Error::raise( sprintf( 'Could not connect to %s:%d. Aborting.', $urlbits['host'], $urlbits['port'] ) );
		}
		
		// timeout to fsockopen() only applies for connecting
		stream_set_timeout( $fp, $timeout );
		
		// fix headers
		$headers['Host']= $urlbits['host'];
		$headers['Connection']= 'close';
		
		// merge headers into a list
		$merged_headers= array();
		foreach ( $headers as $k => $v ) {
			$merged_headers[]= $k . ': ' . $v;
		}
		
		// build the request
		$request= array();
		
		$request[]= "{$method} {$urlbits['path']} HTTP/1.1";
		$request= array_merge( $request, $merged_headers );
		
		$request[]= '';
		
		if ( $method === 'POST' ) {
			$request[]= $body;
		}
		
		$request[]= '';
		
		$out= implode( "\r\n", $request );
		
		if ( ! fwrite( $fp, $out, strlen( $out ) ) ) {
			return Error::raise( 'Error writing to socket.' );
		}
		
		$in= '';
		
		while ( ! feof( $fp ) ) {
			$in.= fgets( $fp, 1024 );
		}
		
		fclose( $fp );
		
		list( $header, $body )= explode( "\r\n\r\n", $in );
		
		// to make the following REs match $ correctly
		// and thus not break parse_url
		$header= str_replace( "\r\n", "\n", $header );
		
		preg_match( '|^HTTP/1\.[01] ([1-5][0-9][0-9]) ?(.*)|', $header, $status_matches );
		
		if ( $status_matches[1] == '301' || $status_matches[1] == '302' ) {
			if ( preg_match( '|^Location: (.+)$|mi', $header, $location_matches ) ) {
				$redirect_url= $location_matches[1];
				
				$redirect_urlbits= parse_url( $redirect_url );
				
				if ( !isset( $redirect_url['host'] ) ) {
					$redirect_urlbits['host']= $urlbits['host'];
				}
				
				$this->redir_count++;
				
				if ( $this->redir_count > $this->max_redirs ) {
					return Error::raise( 'Maximum number of redirections exceeded.' );
				}
				
				return $this->_work( $method, $redirect_urlbits, $headers, $body, $timeout );
			}
			else {
				return Error::raise( 'Redirection response without Location: header.' );
			}
		}
		
		if ( preg_match( '|^Transfer-Encoding:.*chunked.*|mi', $header ) ) {
			$body= $this->_unchunk( $body );
		}
		
		return array( $header, $body );
	}
	
	private function _unchunk( $body )
	{
		/* see <http://www.w3.org/Protocols/rfc2616/rfc2616-sec3.html> */
		$result= '';
		$chunk_size= 0;
		
		do {
			$chunk= explode( "\r\n", $body, 2 ); 
			list( $chunk_size_str, )= explode( ';', $chunk[0], 2 ); 
			$chunk_size= hexdec( $chunk_size_str );
			
			if ( $chunk_size > 0 ) {
				$result.= substr( $chunk[1], 0, $chunk_size );
				$body= substr( $chunk[1], $chunk_size+1 );
			} 
		}
		while ( $chunk_size > 0 );
		// this ignores trailing header fields
		
		return $result;
	}
	
	public function get_response_body()
	{
		if ( ! $this->executed ) {
			return Error::raise( 'Request did not yet execute.' );
		}
		
		return $this->response_body;
	}
	
	public function get_response_headers()
	{
		if ( ! $this->executed ) {
			return Error::raise( 'Request did not yet execute.' );
		}
		
		return $this->response_headers;
	}
}

?>

