
/**
* JSON-RPC client for jquery - for easy ajax calls to the eZPublish server
* API based on ezjscore's client for maximum interoperability (but not identical!)
* Works both if included as plain javascript file or if parsed as javascript-generating
* template (see jquery.tpl on how to do that).
* In the first case, the var $.ez.url should be set up with the url of the root
* of the eZ Publish installation _before_ including this
* NB: Uses (needs) jquery.json plugin for json (de)serializing
*
* @author G. Giunta
* @copyright (c) 2009-2012 G. Giunta
* @license code licensed under the GPL License: see LICENSE file
*
* @todo use closures instead of saving stuff around for later
*/
//{literal}
(function($) {

    // this looks weird but is ok, it is just needed for writing meta-js via tpl
    // and not passing it through the template system...
    var _serverUrl = '{/literal}{"/"|ezurl("no", "full")}{literal}', _configBak;
    if ( '{' + '/literal}{"/"|ezurl("no", "full")}{literal}' == _serverUrl )
    {
        if ( typeof $.ez !== "undefined" && typeof $.ez.url !== "undefined" )
        {
            _serverUrl = $.ez.url.replace( '/ezjscore/', '' );
        }
        else
        {
            _serverUrl = ''; // @todo find a better default?
        }
    }

    $.jsonrpc = function _jsonrpc( callMethod, callParams, options )
    {
        var url = _serverUrl + '/webservices/execute/jsonrpc';

        // backup user callback functions, as we inject our decoding success call
        if ( options !== undefined )
            _configBak = options;

        // force json transport
        var c = {
            contentType: 'application/json',
            data: $.toJSON( { method: callMethod, params: callParams, id: 1 } ),
            dataType: 'text', // avoid having jquery parsing response json using eval
            accepts: { text: 'application/json,text/javascript' }, // fix the accept header
            processData: false,
            success: _iojsonrpcSuccess,
            error: options.error,
            type: 'POST',
            url: url
        };
        return $.ajax( c );
    };

    $.wsproxy = function _wsproxy( remoteServer, callMethod, callParams, options )
    {
        var url = _serverUrl + '/webservices/proxy/jsonrpc/' + remoteServer;

        // backup user callback functions, as we inject our decoding success call
        if ( options !== undefined )
            _configBak = options;

        // force json transport
        var c = {
            contentType: 'application/json',
            data: $.toJSON( { method: callMethod, params: callParams, id: 1 } ),
            dataType: 'text', // avoid having jquery parsing response json using eval
            accepts: { text: 'application/json,text/javascript' }, // fix the accept header
            processData: false,
            success: _iojsonrpcSuccess,
            error: options.error,
            type: 'POST',
            url: url
        };
        return $.ajax( c );
    };

    function _iojsonrpcSuccess( data, textStatus )
    {
        //response = { 'content': null, 'error_text': 'parsing of response to be done...' };
        var returnObject = {'responseJSON': null,
                            'readyState':   4, // is this always correct?
                            //'responseText': data,
                            'responseXML':  '',
                            'status':       200, // is this always correct?
                            'statusText':   textStatus };

        var response;
        try {
            response = $.secureEvalJSON( data );
            /// @todo we should check that either result or error are null...
            // we return in responseJSON both the jsonrpc-style members and the ezjscore ones
            response.content = response.result;
            response.error_text = response.error;
            returnObject.responseJSON = response;
        } catch ( error ) {
            var c = _configBak;
            if ( c.error !== undefined )
            {
                returnObject.statusText = error.message + ' error in file ' + error.fileName + ' line ' + error.lineNumber;
                c.error( returnObject );
                return;
            }
            else
            {
                throw error;
            }
        }

        var c = _configBak;
        if ( c.success !== undefined )
        {
            c.success( returnObject );
        }
        else if ( window.console !== undefined )
        {
            if ( returnObject.responseJSON.error_text != null )
                window.console.error( '$.jsonrpc(): ' + $.toJSON( returnObject.responseJSON.error_text ) );
            else
                window.console.log( '$.jsonrpc(): ' + $.toJSON( returnObject.responseJSON.content ) );
        }
    }

    //_jsonrpc.url = _serverUrl;
    //$.jsonrpc = _jsonrpc;
})(jQuery);
//{/literal}