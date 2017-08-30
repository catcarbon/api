<?php
namespace App\Classes\OAuth;

use Closure;
use Eher\OAuth\Consumer;
use Eher\OAuth\HmacSha1;
use Eher\OAuth\Request;

class SSO {

    /*
     * Location of the VATSIM SSO system
     * Set in __construct
     */
    private $base = '';

    /*
     * Format of the data returned by SSO, default json
     * Set in formatResponse method
     */
    private $format = 'json';

    private $allowedFormats = ['json', 'xml'];

    /*
     * cURL timeout (seconds) for all requests
     */
    private $timeout = 10;

    /*
     * The signing method being used to encrypt your request signature.
     * Set the 'signature' method
     */
    private $signature = false;

    /*
     * A request token generated by (or saved to) the class
     */
    private $token = false;

    /*
     * Consumer credentials, instance of OAuthConsumer
     */
    private $consumer = false;

    /**
     * @var array
     */
    private $config;

    /**
     * Configures the SSO class with consumer/organisation credentials
     *
     * @param string      $base   SSO Server URL
     * @param string      $key    Organisation key
     * @param bool|string $secret Secret key corresponding to this organisation (only required if using HMAC)
     * @param bool|string $method RSA|HMAC
     * @param bool|string $cert   openssl RSA private key (only required if using RSA)
     * @param array       $config
     */
    public function __construct ( $base, $key, $secret = false, $method = false, $cert = false, array $config = [] )
    {
        $this->base = $base;

        // Store consumer credentials
        $this->consumer = new Consumer($key, $secret);

        // if signature method is defined, set the signature method now (can be set or changed later)
        if ( $method )
        {
            $this->setSignature($method, $cert);
        }

        $this->config = $config;
    }

    /**
     * Get current return format from VATSIm
     *
     * @return mixed
     */
    public function getFormat ()
    {
        return $this->foramt;
    }

    /**
     * Change the output format (returned by VATSIM)
     *
     * @param bool|string $change json|xml
     *
     * @return string
     * @throws SSOException
     */
    public function setFormat ( $change = null )
    {
        // if set, attempt to change format
        if ( ! is_null($change) )
        {
            // lower case values only
            $change = strtolower($change);

            if ( ! in_array($change, $this->allowedFormats) )
            {
                throw new SSOException("Format '" . $change . "' is not supported.");
            }

            $this->format = $change;
        }

        return $this;
    }

    /**
     * Gets current signature
     *
     * @return object
     */
    public function getSignature ()
    {
        return $this->signature;
    }

    /**
     * Set the signing method to be used to encrypt request signature.
     *
     * @param string      $signature   Signature encryption method: RSA|HMAC
     * @param null|string $private_key openssl RSA private key (only needed if using RSA)
     *
     * @return $this
     * @throws SSOException
     */
    public function setSignature ( $signature, $private_key = null )
    {
        $signature = strtoupper($signature);

        if ( in_array($signature, ['RSA', 'RSA-SHA1']) )
        {
            if ( is_null($private_key) )
            {
                throw new SSOException("Private key must be provided for 'RSA' and 'RSA-SHA1'");
            }

            $this->signature = new RsaSha1($private_key);
        }
        elseif ( in_array($signature, ['HMAC', 'HMAC-SHA1']) )
        {
            $this->signature = new HmacSha1;
        }
        else
        {
            throw new SSOException("Signature method not recognised, use 'HMAC', 'HMAC-SHA1', 'RSA' or 'RSA-SHA1'");
        }

        return $this;
    }

    /**
     * @param              $returnUrl
     * @param Closure      $success
     * @param Closure|null $error
     *
     * @return bool|mixed
     * @throws SSOException
     */
    public function login ( $returnUrl, Closure $success, Closure $error = null )
    {
        try
        {
            $token = $this->requestToken($returnUrl);

            return $this->callResponse($success, [
                (string) $token->token->oauth_token,
                (string) $token->token->oauth_token_secret,
                $this->redirectUrl(),
            ]);
        } catch ( SSOException $e )
        {
            if ( is_null($error) )
                throw $e;

            return $this->callResponse($error, [$e]);
        }
    }

    /**
     * Request a login token from VATSIM (required to send someone for an SSO login)
     *
     * @param bool|string $return_url URL for VATSIM to return memers to after login
     *
     * @return bool|object
     * @throws SSOException
     */
    public function requestToken ( $return_url = false )
    {
        // signature method must have been set
        if ( ! $this->signature )
        {
            throw new SSOException('No signature method has been set');
        }

        // if the return URL isn't specified, assume this file (though don't consider GET data)
        if ( ! $return_url )
        {
            // using https or http?
            $http = ( isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] ) ? 'https://' : 'http://';
            // the current URL
            $return_url = $http . $_SERVER['SERVER_NAME'] . $_SERVER['PHP_SELF'];
        }

        $tokenUrl = $this->buildUrl('login_token');

        // generate a token request from the consumer details
        $req = Request::from_consumer_and_token($this->consumer, false, "POST", $tokenUrl, [
            'oauth_callback'        => (string) $return_url,
            'oauth_allow_suspended' => array_key_exists('allow_suspended', $this->config) && $this->config['allow_suspended'] === true,
            'oauth_allow_inactive'  => array_key_exists('allow_inactive', $this->config) && $this->config['allow_inactive'] === true,
        ]);

        // sign the request using the specified signature/encryption method (set in this class)
        $req->sign_request($this->signature, $this->consumer, false);

        $response = $this->curlRequest($tokenUrl, $req->to_postdata());

        if ( $response )
        {
            // convert using our response format (depending upon user preference)
            $sso = $this->formatResponse($response);

            // did VATSIM return a successful result?
            if ( $sso->request->result === 'success' )
            {
                // this parameter is required by 1.0a spec
                if ( $sso->token->oauth_callback_confirmed === 'true' )
                {
                    // store the token data saved
                    $this->token = new Consumer($sso->token->oauth_token, $sso->token->oauth_token_secret);

                    // return the full object to the user
                    return $sso;
                }
                else
                {
                    throw new SSOException('Callback confirm flag missing - protocol mismatch');
                }
            }
            else
            {
                throw new SSOException($sso->request->message);
            }
        }
    }

    protected function buildUrl ( $for )
    {
        $url = $this->base;

        switch ( $for )
        {
            case 'login_return':
                $url .= 'api/login_return/' . $this->format . '/';
                break;
            case 'login_token':
                $url .= 'api/login_token/' . $this->format . '/';
                break;
            case 'redirect':
                $url .= 'auth/pre_login/?oauth_token=' . $this->token->key;
                break;
        }

        return $url;
    }

    /**
     * Perform a (post) cURL request
     *
     * @param type $url           Destination of request
     * @param type $requestString Query string of data to be posted
     *
     * @return mixed
     * @throws SSOException
     */
    protected function curlRequest ( $url, $requestString )
    {
        // using cURL to post the request to VATSIM
        $ch = curl_init();

        // configure the post request to VATSIM
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url, // the url to make the request to
            CURLOPT_RETURNTRANSFER => 1, // do not output the returned data to the user
            CURLOPT_TIMEOUT        => $this->timeout, // time out the request after this number of seconds
            CURLOPT_POST           => 1, // we are sending this via post
            CURLOPT_POSTFIELDS     => $requestString // a query string to be posted (key1=value1&key2=value2)
        ]);

        // perform the request
        $response = curl_exec($ch);

        // request failed?
        if ( ! $response )
        {
            throw new SSOException(curl_error($ch), curl_errno($ch));
        }

        return $response;
    }

    /**
     * Convert the response into a usable format
     *
     * @param string $response json|xml
     *
     * @return object               Format processed into an object (Simple XML Element or json_decode)
     */
    protected function formatResponse ( $response )
    {
        if ( $this->format == 'xml' )
        {
            return new \SimpleXMLElement($response);
        }
        else
        {
            return json_decode($response);
        }
    }

    protected function callResponse ( Closure $callback, $parameters )
    {
        return call_user_func_array($callback, $parameters);
    }

    /**
     * Get the URL to VATSIM to log in/confirm login
     *
     * @return string
     * @throws SSOException
     */
    protected function redirectUrl ()
    {
        // a token must have been returned to redirect this user
        if ( ! $this->token )
        {
            throw new SSOException("No token has been set");
        }

        return $this->buildUrl('redirect');
    }

    /**
     * @param              $key
     * @param              $secret
     * @param              $verifier
     * @param Closure      $success
     * @param Closure|null $error
     *
     * @return mixed
     * @throws SSOException
     */
    public function validate ( $key, $secret, $verifier, Closure $success, Closure $error = null )
    {
        try
        {
            $request = $this->checkLogin($key, $secret, $verifier);

            return $this->callResponse($success, [
                $request->user,
                $request->request,
            ]);
        } catch ( SSOException $e )
        {
            if ( is_null($error) )
                throw $e;

            return $this->callResponse($error, [$e]);
        }
    }

    /**
     * Obtains a user's login details from a token key and secret
     *
     * @param string $tokenKey    The token key provided by VATSIM
     * @param string $tokenSecret The secret associated with the token
     * @param string $tokenVerifier
     *
     * @return object
     * @throws SSOException
     */
    public function checkLogin ( $tokenKey, $tokenSecret, $tokenVerifier )
    {
        $this->token = new Consumer($tokenKey, $tokenSecret);

        // the location to send a cURL request to to obtain this user's details
        $returnUrl = $this->buildUrl('login_return');

        // generate a token request call using post data
        $req = Request::from_consumer_and_token($this->consumer, $this->token, "POST", $returnUrl, [
            'oauth_token'    => $tokenKey,
            'oauth_verifier' => $tokenVerifier,
        ]);

        // sign the request using the specified signature/encryption method (set in this class)
        $req->sign_request($this->signature, $this->consumer, $this->token);

        // post the details to VATSIM and obtain the result
        $response = $this->curlRequest($returnUrl, $req->to_postdata());

        if ( $response )
        {
            // convert using our response format (depending upon user preference)
            $sso = $this->formatResponse($response);

            // did VATSIM return a successful result?
            if ( $sso->request->result == 'success' )
            {
                // one time use of tokens only, token no longer valid
                $this->token = null;

                // return the full object to the user
                return $sso;
            }
            else
            {
                throw new SSOException($sso->request->message);
            }
        }
    }
}
