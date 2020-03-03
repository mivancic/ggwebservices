<?php
/**
 * Base class for handling all incoming webservice calls.
 * API based on the eZSOAPServer class from eZP. See docs in there.
 *
 * Process of handling incoming requests:
 *
 * processRequest
 *   |
 *   -- parseRequest (builds a request obj out of received data)
 *   |
 *   -- handleRequest or handleInternalRequest (builds response as plain php value)
 *   |
 *   -- showResponse (echoes response in correct format, usually via building a response obj and serializing it)
 *        |
 *        -- prepareResponse (adds to response obj any missing info gathered usually during parseRequest)
 *
 * @author G. Giunta
 * @copyright (C) 2009-2020 G. Giunta
 *
 * @todo add a better way to register methods, supporting definition of type of return value and per-param help text
 * @todo add propert property support, for $exception_handling and future ones
 */

abstract class ggWebservicesServer
{
    // error codes generated by server
    const INVALIDREQUESTERROR = -201;
    const INVALIDMETHODERROR = -202;
    const INVALIDPARAMSERROR = -203;
    const INVALIDINTROSPECTIONERROR = -204;
    const GENERICRESPONSEERROR = -205;
    const INVALIDAUTHERROR = -206;
    const INVALIDCOMPRESSIONERROR = -207;

    const INVALIDREQUESTSTRING = 'Request received from client is not valid according to protocol format';
    const INVALIDMETHODSTRING = 'Method not found';
    const INVALIDPARAMSSTRING = 'Parameters not matching method';
    const INVALIDINTROSPECTIONSTRING = 'Can\'t introspect: method unknown';
    const GENERICRESPONSESTRING = 'Internal server error';
    const INVALIDAUTHSTRING = 'Invalid authentication or not enough rights';
    const INVALIDCOMPRESSIONSTRING = 'Request received from client could not be reinflated';

    /**
    * Creates a new server object
    * If raw_data is not passed to it, it initializes self from POST data
    */
    function __construct( $raw_data=null )
    {
        if ( $raw_data === null )
        {
            $this->RawPostData = file_get_contents('php://input');
        }
        else
        {
            $this->RawPostData = $raw_data;
        }
    }

    /**
    * Echoes the response, setting http headers and such
    * @todo it would be easier if the request object was passed to this method.
    *       Right now we are forced to duplicate extra info coming from the request
    *       into the server itself, to reinject it into the response using the
    *       prepareResponse() method. Examples: request ID for jsonrpc, desired
    *       response type for rest protocol.
    */
    function showResponse( $functionName, $namespaceURI, &$value )
    {
        $ResponseClass =  $this->ResponseClass;
        $response = new $ResponseClass( $functionName );
        $response->setValue( $value );
        // allow subclasses to inject in response more info that they have from request
        $this->prepareResponse( $response );
        $payload = $response->payload();

        foreach( $response->responseHeaders() as $header => $value )
        {
            header( "$header: $value" );
        }

        //header( "SOAPServer: eZ soap" );
        $contentType = $response->contentType();
        if ( ( $charset = $response->charset() ) != '')
        {
            $contentType .= "; charset=\"$charset\"";
        }
        header( "Content-Type: $contentType" );
        /// @todo test how this interacts with php/apache later deflating response
        /// @todo this is mandatory eg. for xmlrpc, but if client uses http 1.1,
        ///       we could omit it for other protocols
        header( "Content-Length: " . strlen( $payload ) );

        if ( ob_get_length() )
            ob_end_clean();

        print( $payload );
    }

    /**
    * To be subclassed.
    * A function executed before calling payload() on the response object to generate
    * the response stream. Useful to inject into the response some missing data.
    */
    function prepareResponse( $response )
    {
    }

    /**
    * Takes as input the request payload (body) and returns a request obj or false
    */
    abstract function parseRequest( $payload );

    /**
    * Processes the request and prints out the proper response.
    * @todo if function gzinflate does not exist, we should return a more appropriate http-level error
    */
    function processRequest()
    {
        /* tis' the job of the index page, not of the class!
        global $HTTP_SERVER_VARS;
        if ( $HTTP_SERVER_VARS["REQUEST_METHOD"] != "POST" )
        {
            print( "Error: this web page does only understand POST methods" );
            exit();
        }
        */

        $namespaceURI = 'unknown_namespace_uri';

        /// @todo dechunk, correct encoding, check for supported
        /// http features of the client, etc...
        $data = $this->RawPostData;

        $data = $this->inflateRequest( $data );
        if ( $data === false )
        {
            $this->showResponse(
                'unknown_function_name',
                $namespaceURI,
                new ggWebservicesFault( self::INVALIDCOMPRESSIONERROR, self::INVALIDCOMPRESSIONSTRING ) );
        }

        $request = $this->parseRequest( $data );

        if ( !is_object( $request ) ) /// @todo use is_a instead
        {
            $this->showResponse(
                'unknown_function_name',
                $namespaceURI,
                new ggWebservicesFault( self::INVALIDREQUESTERROR, self::INVALIDREQUESTSTRING ) );
        }
        else
        {
            $functionName = $request->name();
            $params = $request->parameters();
            if ( $this->isInternalRequest( $functionName ) )
            {
                $response = $this->handleInternalRequest( $functionName, $params );
            }
            else
            {
                $response = $this->handleRequest( $functionName, $params );
            }
            $this->showResponse( $functionName, $namespaceURI, $response );
        }
    }

    /**
    * @todo use pass-by-ref to save memory (!important)
    * @todo if content-type is application/x-www-form-urlencoded, we should rebuild $_POST
    */
    function inflateRequest( $data )
    {
        if( isset( $_SERVER['HTTP_CONTENT_ENCODING'] ) )
        {
            $content_encoding = str_replace( 'x-', '', $_SERVER['HTTP_CONTENT_ENCODING'] );

            // check if request body has been compressed and decompress it
            if( $content_encoding != '' && strlen( $data ) )
            {
                if( $content_encoding == 'deflate' || $content_encoding == 'gzip' )
                {
                    // if decoding works, use it. else assume data wasn't gzencoded
                    if( function_exists( 'gzinflate' ) )
                    {
                        if( $content_encoding == 'deflate' && $degzdata = @gzuncompress( $data ) )
                        {
                            //if($this->debug > 1)
                            //    $this->debugmsg("\n+++INFLATED REQUEST+++[".strlen($data)." chars]+++\n" . $data . "\n+++END+++");
                            return $degzdata;
                        }
                        elseif( $content_encoding == 'gzip' && $degzdata = @gzinflate( substr( $data, 10 ) ) )
                        {
                            //if($this->debug > 1)
                            //    $this->debugmsg("+++INFLATED REQUEST+++[".strlen($data)." chars]+++\n" . $data . "\n+++END+++");
                            return $degzdata;
                        }
                        else
                        {
                            //error_log('The server sent deflated data, but an error happened while trying to reinflate it.');
                            return false;
                        }
                    }
                    else
                    {
                        //error_log('The server sent deflated data. Your php install must have the Zlib extension compiled in to support this.');
                        return false;
                    }
                }
            }
        }

        return $data;
    }

    /**
    * Verifies if the given request has been registered as an exposed webservice
    * and executes it. Called by processRequest.
    */
    function handleRequest( $functionName, $params )
    {
        if ( array_key_exists( $functionName, $this->FunctionList ) )
        {
            $paramsOk = false;
            foreach( $this->FunctionList[$functionName] as $paramDesc )
            {
                $paramsOk = ( ( $paramDesc === null ) || $this->validateParams( $params, $paramDesc['in'] ) );
                if ( $paramsOk )
                {
                    break;
                }
            }
            if ( $paramsOk )
            {
                // allow to use the dot as namespace separator in webservices
                $functionName = str_replace( array( '.' ), '_', $functionName );

                try
                {
                    if ( strpos( $functionName, '::' ) )
                    {
                        return call_user_func_array( explode( '::', $functionName ), $params );
                    }
                    else
                    {
                        return call_user_func_array( $functionName, $params );
                    }
                }
                catch( Exception $e )
                {
                    switch ( $this->exception_handling )
                    {
                        case 0:
                            return new ggWebservicesFault( $e->getCode(), $e->getMessage() );
                        case 1:
                            return new ggWebservicesFault( self::GENERICRESPONSEERROR, self::GENERICRESPONSESTRING );
                        case 2:
                        default: // coder did something weird if we get someting else...
                            throw $e;
                    }
                }
            }
            else
            {
                return new ggWebservicesFault( self::INVALIDPARAMSERROR, self::INVALIDPARAMSSTRING );
            }
        }
        else
        {
            return new ggWebservicesFault( self::INVALIDMETHODERROR, self::INVALIDMETHODSTRING . " '$functionName'" );
        }
    }

    /**
    * Return true if the webservice method encapsulated by $request is to be handled
    * internally by the server instead of a registered function.
    * Used to handle eg system.* stuff in xmlrpc or json.
    * To be overridden by descendent classes.
    */
    function isInternalRequest( $functionName )
    {
        return false;
    }

    /**
    * Handle execution of server-reserved webservice methods.
    * Returns a php value ( or fault object ).
    * Used to handle eg system.* stuff in xmlrpc or json.
    * Called by processRequest.
    * To be overridden by descendent classes.
    */
    function handleInternalRequest( $functionName, $params )
    {
        // This method should never be called on the base class server, as it has no internal methods.
        // Hence we return an error upon invocation
        return new ggWebservicesFault( self::GENERICRESPONSEERROR, self::GENERICRESPONSESTRING );
    }

    /**
      Registers all functions of an object on the server.
      @return bool Returns false if the object could not be registered.
      @todo add optional introspection-based param registering
      @todo add single method registration
      @todo add registration of per-method descriptions
    */
    function registerObject( $objectName, $includeFile = null )
    {
        // file_exists check is useless, since it does not scan include path. Let coder eat his own dog food...
        if ( $includeFile !== null ) //&& file_exists( $includeFile ) )
            include_once( $includeFile );

        if ( class_exists( $objectName ) )
        {
            $methods = get_class_methods( $objectName );
            foreach ( $methods as $method )
            {
                /// @todo check also for magic methods not to be registered!
                if ( strcasecmp ( $objectName, $method ) )
                    $this->FunctionList[$objectName."::".$method] = array( 'in' => null, 'out' => 'mixed' );
            }
            return true;
        }
        else
        {
            return false;
        }
    }

    /**
      Registers a new function on the server.
      If params is an array of name => type strings, params will be checked for consistency.
      Multiple signatures can be registered for a given php function (but only one help text)
      @return bool Returns false if the function could not be registered.
      @todo add optional introspection-based param registering
    */
    function registerFunction( $name, $params=null, $result='mixed', $description='' )
    {
        if ( $this->isInternalRequest( $name ) )
        {
            return false;
        }

        // allow to use the dot and slash as namespace separator in webservices names
        $fname = str_replace( array( '.', '/' ), '_', $name );

        if ( function_exists( $fname ) )
        {
            $this->FunctionList[$name][] = array( 'in' => $params, 'out' => $result );

            if ( $description !== '' || !array_key_exists( $name, $this->FunctionDescription ))
            {
                $this->FunctionDescription[$name] = $description;
            }
            return true;
        }
        else
        {
            return false;
        }
    }

    /**
    * Check type (and possibly names) of incoming params against the registered method signature
    * (called once per existing sig if method registerd multiple times with different sigs)
    * To be overridden by descendent classes.
    * @return bool
    */
    function validateParams( $params, $paramDesc )
    {
        return true;
    }

    /**
    * Returns the list of available webservices
    * @return array
    */
    public function registeredMethods()
    {
        return array_keys( $this->FunctionList );
    }

    /// @todo throw an error if method is not registered
    public function methodDescription( $method )
    {
        return array_key_exists( $method, $this->FunctionList ) ? ( array_key_exists( $method, $this->FunctionDescription ) ? $this->FunctionDescription[$method] : '' ) : false;
    }

    /// @todo throw an error if method is not registered
    public function methodSignatures( $method )
    {
        return array_key_exists( $method, $this->FunctionList ) ? $this->FunctionList[$method] : false;
    }

    /// Contains a list over registered functions, and their dscriptions
    protected $FunctionList = array();
    protected $FunctionDescription = array();
    /// Contains the RAW HTTP post data information
    public $RawPostData;

    /**
     * Controls behaviour of server when invoked user function throws an exception:
     * 0 = catch it and return an 'internal error' xmlrpc response (default)
     * 1 = catch it and return an xmlrpc response with the error corresponding to the exception
     * 2 = allow the exception to float to the upper layers
     */
	public $exception_handling = 0;

    protected $ResponseClass = 'ggWebservicesResponse';
}
