<?php

namespace themattharris;

use CURLFile;
use Exception;

/**
 * tmhOAuth
 *
 * An OAuth library written in PHP.
 * The library supports file uploading using multipart/form as well as general
 * REST requests. OAuth authentication is sent using an Authorization Header.
 *
 * @author themattharris
 * @version 0.8.5
 *
 * 09 Jun 2017
 */
class tmhOAuth
{
  public const VERSION = '0.8.5';
  public array $response = [];
  private ?string $buffer = null;
  public array $config = [];
  private array $request_settings = [];
  private array $metrics = [];



  /**
   * Creates a new tmhOAuth object
   *
   * @param array $config the configuration to use for this request
   */
  public function __construct(array $config = [])
  {
    $this->reconfigure($config);
    $this->reset_request_settings();
    $this->set_user_agent();
  }

  public function reconfigure(array $config = []): void
  {
    // default configuration options
    $this->config = array_merge(
      [
        // leave 'user_agent' blank for default, otherwise set this to
        // something that clearly identifies your app
        'user_agent'                 => '',
        'host'                       => 'api.twitter.com',
        'method'                     => 'GET',

        'consumer_key'               => '',
        'consumer_secret'            => '',
        'token'                      => '',
        'secret'                     => '',

        // RSA private key (for RSA-SHA1 and RSA-SHA256 methods)
        // Please note that this is expected to be a string representing
        // the PEM-formatted key itself and NOT the file name
        'private_key_pem'            => '',

        // OAuth2 bearer token. This should already be URL encoded
        'bearer'                     => '',

        // oauth signing variables that are not dynamic
        'oauth_version'              => '1.0',
        'oauth_signature_method'     => 'HMAC-SHA1',

        // you probably don't want to change any of these curl values
        'curl_http_version'          => CURL_HTTP_VERSION_1_1,
        'curl_connecttimeout'        => 30,
        'curl_timeout'               => 10,

        // for security this should always be set to 2.
        'curl_ssl_verifyhost'        => 2,
        // for security this should always be set to true.
        'curl_ssl_verifypeer'        => true,
        // for security this should always be set to true.
        'use_ssl'                    => true,

        // you can get the latest cacert.pem from here http://curl.haxx.se/ca/cacert.pem
        // if you're getting HTTP 0 responses, check cacert.pem exists and is readable
        // without it curl won't be able to create an SSL connection
        'curl_cainfo'                => __DIR__ . DIRECTORY_SEPARATOR . 'cacert.pem',
        'curl_capath'                => __DIR__,

        // in some cases (very very odd ones) the SSL version must be set manually.
        // unless you know why your are changing this, you should leave it as false
        // to allow PHP to determine the value for this setting itself.
        'curl_sslversion'            => false,

        'curl_followlocation'        => false, // whether to follow redirects or not

        // support for proxy servers
        'curl_proxy'                 => false, // really you don't want to use this if you are using streaming
        'curl_proxyuserpwd'          => false, // format username:password for proxy, if required
        'curl_encoding'              => '',    // leave blank for all supported formats, else use gzip, deflate, identity etc

        // streaming API configuration
        'is_streaming'               => false,
        'streaming_eol'              => "\r\n",
        'streaming_metrics_interval' => 10,

        // header or querystring. You should always use header!
        // this is just to help me debug other developers implementations
        'as_header'                  => true,
        'force_nonce'                => false, // used for checking signatures. leave as false for auto
        'force_timestamp'            => false, // used for checking signatures. leave as false for auto
      ],
      $config
    );
  }

  private function reset_request_settings(array $options = []): void
  {
    $this->request_settings = [
      'params'    => [],
      'headers'   => [],
      'with_user' => true,
      'multipart' => false,
    ];

    if (!empty($options))
      $this->request_settings = array_merge($this->request_settings, $options);
  }

  /**
   * Sets the useragent for PHP to use
   * If '$this->config['user_agent']' already has a value it is used instead of one
   * being generated.
   */
  private function set_user_agent(): void
  {
    if (!empty($this->config['user_agent']))
      return;

    $ssl = ($this->config['curl_ssl_verifyhost'] && $this->config['curl_ssl_verifypeer'] && $this->config['use_ssl']) ? '+' : '-';
    $ua = 'tmhOAuth ' . self::VERSION . $ssl . 'SSL - //github.com/themattharris/tmhOAuth';
    $this->config['user_agent'] = $ua;
  }

  /**
   * Generates a random OAuth nonce.
   * If 'force_nonce' is false a nonce will be generated, otherwise the value of '$this->config['force_nonce']' will be used.
   */
  private function nonce(int $length = 12, bool $include_time = true): string
  {
    if ($this->config['force_nonce'] === false) {
      $prefix = $include_time ? microtime() : '';
      return md5(substr($prefix . uniqid(), 0, $length));
    } else {
      return $this->config['force_nonce'];
    }
  }

  /**
   * Generates a timestamp.
   * If 'force_timestamp' is false a timestamp will be generated, otherwise the value of '$this->config['force_timestamp']' will be used.
   */
  private function timestamp(): string
  {
    if ($this->config['force_timestamp'] === false) {
      $time = time();
    } else {
      $time = $this->config['force_timestamp'];
    }
    return (string) $time;
  }

  /**
   * Encodes the string or array passed in a way compatible with OAuth.
   * If an array is passed each array value will will be encoded.
   */
  private function safe_encode(mixed $data): string|array
  {
    if (is_array($data)) {
      return array_map([$this, 'safe_encode'], $data);
    } else if (is_scalar($data)) {
      return str_ireplace(
        ['+', '%7E'],
        [' ', '~'],
        rawurlencode($data)
      );
    } else {
      return '';
    }
  }

  /**
   * Decodes the string or array from it's URL encoded form
   * If an array is passed each array value will will be decoded.
   */
  private function safe_decode(mixed $data): string|array
  {
    if (is_array($data)) {
      return array_map([$this, 'safe_decode'], $data);
    } else if (is_scalar($data)) {
      return rawurldecode($data);
    } else {
      return '';
    }
  }

  /**
   * Prepares OAuth1 signing parameters.
   */
  private function prepare_oauth1_params(): void
  {
    $defaults = [
      'oauth_nonce'            => $this->nonce(),
      'oauth_timestamp'        => $this->timestamp(),
      'oauth_version'          => $this->config['oauth_version'],
      'oauth_consumer_key'     => $this->config['consumer_key'],
      'oauth_signature_method' => $this->config['oauth_signature_method'],
    ];

    // include the user token if it exists
    if ($oauth_token = $this->token())
      $defaults['oauth_token'] = $oauth_token;

    $this->request_settings['oauth1_params'] = [];

    // safely encode
    foreach ($defaults as $k => $v) {
      $this->request_settings['oauth1_params'][$this->safe_encode($k)] = $this->safe_encode($v);
    }
  }

  private function token(): string
  {
    if ($this->request_settings['with_user']) {
      if (isset($this->config['token']) && !empty($this->config['token'])) return $this->config['token'];
      elseif (isset($this->config['user_token'])) return $this->config['user_token'];
    }
    return '';
  }

  private function secret(): string
  {
    if ($this->request_settings['with_user']) {
      if (isset($this->config['secret']) && !empty($this->config['secret'])) return $this->config['secret'];
      elseif (isset($this->config['user_secret'])) return $this->config['user_secret'];
    }
    return '';
  }

  /**
   * Extracts and decodes OAuth parameters from the passed string
   */
  public function extract_params(string $body): array
  {
    $kvs = explode('&', $body);
    $decoded = [];
    foreach ($kvs as $kv) {
      $kv = explode('=', $kv, 2);
      $kv[0] = $this->safe_decode($kv[0]);
      $kv[1] = $this->safe_decode($kv[1]);
      $decoded[$kv[0]] = $kv[1];
    }
    return $decoded;
  }

  /**
   * Prepares the HTTP method for use in the base string by converting it to
   * uppercase.
   */
  private function prepare_method(): void
  {
    $this->request_settings['method'] = strtoupper($this->request_settings['method']);
  }

  /**
   * Prepares the URL for use in the base string by ripping it apart and
   * reconstructing it.
   *
   * Ref: 3.4.1.2
   */
  private function prepare_url(): void
  {
    $parts = parse_url($this->request_settings['url']);

    $port   = $parts['port'] ?? false;
    $scheme = $parts['scheme'];
    $host   = $parts['host'];
    $path   = $parts['path'] ?? false;

    $port or $port = ($scheme == 'https') ? '443' : '80';

    if (($scheme == 'https' && $port != '443') || ($scheme == 'http' && $port != '80')) {
      $host = "$host:$port";
    }

    // the scheme and host MUST be lowercase
    $this->request_settings['url'] = strtolower("$scheme://$host");
    // but not the path
    $this->request_settings['url'] .= $path;
  }

  /**
   * If the request uses multipart, and the parameter isn't a file path, prepend a space
   * otherwise return the original value.
   */
  private function multipart_escape(string $value): string
  {
    if (!$this->request_settings['multipart'] || !str_starts_with($value, '@'))
      return $value;

    // see if the parameter is a file.
    // we split on the semi-colon as it's the delimiter used on media uploads
    // for fields with semi-colons this will return the original string
    [$file] = explode(';', substr($value, 1), 2);
    if (file_exists($file))
      return $value;

    return " $value";
  }


  /**
   * Prepares all parameters for the base string and request.
   * Multipart parameters are ignored as they are not defined in the specification,
   * all other types of parameter are encoded for compatibility with OAuth.
   */
  private function prepare_params(): void
  {
    $doing_oauth1 = false;
    $this->request_settings['prepared_params'] = [];
    $prepared = &$this->request_settings['prepared_params'];
    $prepared_pairs = [];
    $prepared_pairs_with_oauth = [];

    if (isset($this->request_settings['oauth1_params'])) {
      $oauth1  = &$this->request_settings['oauth1_params'];
      $doing_oauth1 = true;
      $params = array_merge($oauth1, $this->request_settings['params']);

      // Remove oauth_signature if present
      // Ref: Spec: 9.1.1 ("The oauth_signature parameter MUST be excluded.")
      unset($params['oauth_signature']);

      // empty the oauth1 array. we reset these values later in this method
      $oauth1 = [];
    } else {
      $params = $this->request_settings['params'];
    }

    // Parameters are sorted by name, using lexicographical byte value ordering.
    // Ref: Spec: 9.1.1 (1)
    uksort($params, 'strcmp');

    // set this now so we're not doing it on every parameter
    $supports_curl_file = class_exists('CurlFile', false);

    // encode params unless we're doing multipart
    foreach ($params as $k => $v) {
      $k = $this->request_settings['multipart'] ? $k : $this->safe_encode($k);

      if (is_array($v))
        $v = implode(',', $v);

      // we don't need to do the multipart escaping if we support curlfile
      if ($supports_curl_file && ($v instanceof CURLFile)) {
        // leave $v alone
      } elseif ($this->request_settings['multipart']) {
        $v = $this->multipart_escape($v);
      } else {
        $v = $this->safe_encode($v);
      }

      // split parameters for the basestring and authorization header, and recreate the oauth1 array
      if ($doing_oauth1) {
        // if we're doing multipart, only store the oauth_* params, ignore the users request params
        if (str_starts_with($k, 'oauth') || !$this->request_settings['multipart'])
          $prepared_pairs_with_oauth[] = "{$k}={$v}";

        if (str_starts_with($k, 'oauth')) {
          $oauth1[$k] = $v;
          continue;
        }
      }
      $prepared[$k] = $v;

      if (!$this->request_settings['multipart'])
        $prepared_pairs[] = "{$k}={$v}";
    }

    if ($doing_oauth1) {
      $this->request_settings['basestring_params'] = implode('&', $prepared_pairs_with_oauth);
    }

    // setup params for GET/POST/PUT method handling
    if (!empty($prepared)) {
      $content = implode('&', $prepared_pairs);

      switch ($this->request_settings['method']) {
        case 'PUT':
          // fall through to POST as PUT should be treated the same
        case 'POST':
          $this->request_settings['postfields'] = $this->request_settings['multipart'] ? $prepared : $content;
          break;
        default:
          $this->request_settings['querystring'] = $content;
          break;
      }
    }
  }

  /**
   * Prepares the OAuth signing key
   */
  private function prepare_signing_key(): void
  {
    $left = $this->safe_encode($this->config['consumer_secret']);
    $right = $this->safe_encode($this->secret());
    $this->request_settings['signing_key'] = $left . '&' . $right;
  }

  /**
   * Prepare the base string.
   * Ref: Spec: 9.1.3 ("Concatenate Request Elements")
   */
  private function prepare_base_string(): void
  {
    $url = $this->request_settings['url'];

    // if the host header is set we need to rewrite the basestring to use
    // that, instead of the request host. otherwise the signature won't match
    // on the server side
    if (!empty($this->request_settings['headers']['Host'])) {
      $url = str_ireplace(
        $this->config['host'],
        $this->request_settings['headers']['Host'],
        $url
      );
    }

    $base = [
      $this->request_settings['method'],
      $url,
      $this->request_settings['basestring_params']
    ];
    $this->request_settings['basestring'] = implode('&', $this->safe_encode($base));
  }

  /**
   * Signs the OAuth 1 request
   */
  private function prepare_oauth_signature(): void
  {
    switch ($this->config['oauth_signature_method']) {
      case 'HMAC-SHA1':
        $signature = $this->sign_with_hmac('sha1');
        break;
      case 'HMAC-SHA256':
        $signature = $this->sign_with_hmac('sha256');
        break;
      case 'RSA-SHA1':
        $signature = $this->sign_with_rsa(OPENSSL_ALGO_SHA1);
        break;
      case 'RSA-SHA256':
        $signature = $this->sign_with_rsa(OPENSSL_ALGO_SHA256);
        break;
      default:
        throw new Exception("Unsupported oauth_signature_method: '" . $this->config['oauth_signature_method'] . "'");
    }
    $this->request_settings['oauth1_params']['oauth_signature'] = $this->safe_encode(base64_encode($signature));
  }

  /**
   * Signs the OAuth 1 request using HMAC-based signature algorithm
   */
  private function sign_with_hmac(string $algorithm): string
  {
    return hash_hmac(
      $algorithm,
      $this->request_settings['basestring'],
      $this->request_settings['signing_key'],
      true
    );
  }

  /**
   * Signs the OAuth 1 request using RSA-based signature algorithm
   */
  private function sign_with_rsa(int|string $algorithm): string
  {
    if (!function_exists('openssl_sign')) {
      throw new Exception("openssl_sign function does not exist. Please make sure Openssl extension is installed");
    }
    if ($this->config['private_key_pem'] == '') {
      throw new Exception("No private key PEM is configured, cannot sign");
    }
    $ok = openssl_sign($this->request_settings['basestring'], $signature, $this->config['private_key_pem'], $algorithm);
    if (!$ok) {
      throw new Exception("Cannot sign: " . openssl_error_string());
    }
    return $signature;
  }

  /**
   * Prepares the Authorization header
   */
  private function prepare_auth_header(): void
  {
    if (!$this->config['as_header'])
      return;

    // oauth1
    if (isset($this->request_settings['oauth1_params'])) {
      // sort again as oauth_signature was added post param preparation
      uksort($this->request_settings['oauth1_params'], 'strcmp');
      $encoded_quoted_pairs = [];
      foreach ($this->request_settings['oauth1_params'] as $k => $v) {
        $encoded_quoted_pairs[] = "{$k}=\"{$v}\"";
      }
      $header = 'OAuth ' . implode(', ', $encoded_quoted_pairs);
    } elseif (!empty($this->config['bearer'])) {
      $header = 'Bearer ' . $this->config['bearer'];
    }

    if (isset($header))
      $this->request_settings['headers']['Authorization'] = $header;
  }

  /**
   * Create the bearer token for OAuth2 requests from the consumer_key and consumer_secret.
   */
  public function bearer_token_credentials(): string
  {
    $credentials = implode(':', [
      $this->safe_encode($this->config['consumer_key']),
      $this->safe_encode($this->config['consumer_secret'])
    ]);
    return base64_encode($credentials);
  }

  /**
   * Make an HTTP request using this library. This method doesn't return anything.
   * Instead the response should be inspected directly.
   *
   * @return int the http response code for the request. 0 is returned if a connection could not be made
   */
  public function request(string $method, string $url, array $params = [], bool $useauth = true, bool $multipart = false, array $headers = []): int
  {
    $options = [
      'method'    => $method,
      'url'       => $url,
      'params'    => $params,
      'with_user' => true,
      'multipart' => $multipart,
      'headers'   => $headers
    ];
    $options = array_merge($this->default_options(), $options);

    if ($useauth) {
      return $this->user_request($options);
    } else {
      return $this->unauthenticated_request($options);
    }
  }

  public function apponly_request(array $options = []): int
  {
    $options = array_merge($this->default_options(), $options, [
      'with_user' => false,
    ]);
    $this->reset_request_settings($options);
    if ($options['without_bearer']) {
      return $this->oauth1_request();
    } else {
      $this->prepare_method();
      $this->prepare_url();
      $this->prepare_params();
      $this->prepare_auth_header();
      return $this->curlit();
    }
  }

  public function user_request(array $options = []): int
  {
    $options = array_merge($this->default_options(), $options, [
      'with_user' => true,
    ]);
    $this->reset_request_settings($options);
    return $this->oauth1_request();
  }

  public function unauthenticated_request(array $options = []): int
  {
    $options = array_merge($this->default_options(), $options, [
      'with_user' => false,
    ]);
    $this->reset_request_settings($options);
    $this->prepare_method();
    $this->prepare_url();
    $this->prepare_params();
    return $this->curlit();
  }

  /**
   * Signs the request and adds the OAuth signature. This runs all the request
   * parameter preparation methods.
   */
  private function oauth1_request(): int
  {
    $this->prepare_oauth1_params();
    $this->prepare_method();
    $this->prepare_url();
    $this->prepare_params();
    $this->prepare_base_string();
    $this->prepare_signing_key();
    $this->prepare_oauth_signature();
    $this->prepare_auth_header();
    return $this->curlit();
  }

  private function default_options(): array
  {
    return [
      'method'         => 'GET',
      'params'         => [],
      'with_user'      => true,
      'multipart'      => false,
      'headers'        => [],
      'without_bearer' => false,
    ];
  }

  /**
   * Make a long poll HTTP request using this library. This method is
   * different to the other request methods as it isn't supposed to disconnect
   *
   * Using this method expects a callback which will receive the streaming
   * responses.
   */
  public function streaming_request(string $method, string $url, array $params = [], string|callable $callback = ''): void
  {
    if (!empty($callback)) {
      if (!is_callable($callback)) {
        return;
      }
      $this->config['streaming_callback'] = $callback;
    }
    $this->metrics['start']          = time();
    $this->metrics['interval_start'] = $this->metrics['start'];
    $this->metrics['messages']       = 0;
    $this->metrics['last_messages']  = 0;
    $this->metrics['bytes']          = 0;
    $this->metrics['last_bytes']     = 0;
    $this->config['is_streaming']    = true;
    $this->request($method, $url, $params);
  }

  /**
   * Handles the updating of the current Streaming API metrics.
   */
  private function update_metrics(): ?array
  {
    $now = time();
    if (($this->metrics['interval_start'] + $this->config['streaming_metrics_interval']) > $now)
      return null;

    $this->metrics['mps'] = round(($this->metrics['messages'] - $this->metrics['last_messages']) / $this->config['streaming_metrics_interval'], 2);
    $this->metrics['bps'] = round(($this->metrics['bytes'] - $this->metrics['last_bytes']) / $this->config['streaming_metrics_interval'], 2);

    $this->metrics['last_bytes'] = $this->metrics['bytes'];
    $this->metrics['last_messages'] = $this->metrics['messages'];
    $this->metrics['interval_start'] = $now;
    return $this->metrics;
  }

  /**
   * Utility function to create the request URL in the requested format.
   * If a fully-qualified URI is provided, it will be returned.
   * Any multi-slashes (except for the protocol) will be replaced with a single slash.
   */
  public function url(string $request, string $extension = 'json'): string
  {
    // remove multi-slashes
    $request = preg_replace('$([^:])//+$', '$1/', $request);

    if (str_starts_with(strtolower($request), 'http') || str_starts_with($request, '//')) {
      return $request;
    }

    $extension = strlen($extension) > 0 ? ".$extension" : '';
    $proto  = $this->config['use_ssl'] ? 'https:/' : 'http:/';

    // trim trailing slash
    $request = ltrim($request, '/');

    $pos = strlen($request) - strlen($extension);
    if (substr($request, $pos) === $extension)
      $request = substr_replace($request, '', $pos);

    return implode('/', [
      $proto,
      $this->config['host'],
      $request . $extension
    ]);
  }

  /**
   * Public access to the private safe decode/encode methods
   */
  public function transformText(string $text, string $mode = 'encode'): string|array
  {
    return $this->{"safe_$mode"}($text);
  }

  /**
   * Utility function to parse the returned curl headers and store them in the
   * class array variable.
   */
  private function curlHeader(\CurlHandle $ch, string $header): int
  {
    $this->response['raw'] .= $header;

    [$key, $value] = array_pad(explode(':', $header, 2), 2, null);

    $key = trim($key);
    $value = trim($value ?? '');

    if (!isset($this->response['headers'][$key])) {
      $this->response['headers'][$key] = $value;
    } else {
      if (!is_array($this->response['headers'][$key])) {
        $this->response['headers'][$key] = [$this->response['headers'][$key]];
      }
      $this->response['headers'][$key][] = $value;
    }

    return strlen($header);
  }

  /**
   * Utility function to parse the returned curl buffer and store them until
   * an EOL is found. The buffer for curl is an undefined size so we need
   * to collect the content until an EOL is found.
   *
   * This function calls the previously defined streaming callback method.
   */
  private function curlWrite(\CurlHandle $ch, string $data): int
  {
    $l = strlen($data);
    if (!str_contains($data, $this->config['streaming_eol'])) {
      $this->buffer .= $data;
      return $l;
    }

    $buffered = explode($this->config['streaming_eol'], $data);
    $content = $this->buffer . $buffered[0];

    $this->metrics['messages']++;
    $this->metrics['bytes'] += strlen($content);

    if (!is_callable($this->config['streaming_callback']))
      return 0;

    $metrics = $this->update_metrics();
    $stop = call_user_func(
      $this->config['streaming_callback'],
      $content,
      strlen($content),
      $metrics
    );
    $this->buffer = $buffered[1];
    if ($stop)
      return 0;

    return $l;
  }

  /**
   * Makes a curl request. Takes no parameters as all should have been prepared
   * by the request method
   *
   * @return int the http response code for the request. 0 is returned if a connection could not be made
   */
  private function curlit(): int
  {
    $this->response = [
      'raw' => ''
    ];

    // configure curl
    $c = curl_init();

    if ($this->request_settings['method'] == 'GET' && isset($this->request_settings['querystring'])) {
      $this->request_settings['url'] = $this->request_settings['url'] . '?' . $this->request_settings['querystring'];
    } elseif ($this->request_settings['method'] == 'POST' || $this->request_settings['method'] == 'PUT') {
      $postfields = [];
      if (isset($this->request_settings['postfields']))
        $postfields = $this->request_settings['postfields'];

      curl_setopt($c, CURLOPT_POSTFIELDS, $postfields);
    }

    curl_setopt($c, CURLOPT_CUSTOMREQUEST, $this->request_settings['method']);

    curl_setopt_array($c, [
      CURLOPT_HTTP_VERSION   => $this->config['curl_http_version'],
      CURLOPT_USERAGENT      => $this->config['user_agent'],
      CURLOPT_CONNECTTIMEOUT => $this->config['curl_connecttimeout'],
      CURLOPT_TIMEOUT        => $this->config['curl_timeout'],
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_SSL_VERIFYPEER => $this->config['curl_ssl_verifypeer'],
      CURLOPT_SSL_VERIFYHOST => $this->config['curl_ssl_verifyhost'],

      CURLOPT_FOLLOWLOCATION => $this->config['curl_followlocation'],
      CURLOPT_PROXY          => $this->config['curl_proxy'],
      CURLOPT_ENCODING       => $this->config['curl_encoding'],
      CURLOPT_URL            => $this->request_settings['url'],
      // process the headers
      CURLOPT_HEADERFUNCTION => [$this, 'curlHeader'],
      CURLOPT_HEADER         => false,
      CURLINFO_HEADER_OUT    => true,
    ]);

    if ($this->config['curl_cainfo'] !== false)
      curl_setopt($c, CURLOPT_CAINFO, $this->config['curl_cainfo']);

    if ($this->config['curl_capath'] !== false)
      curl_setopt($c, CURLOPT_CAPATH, $this->config['curl_capath']);

    if ($this->config['curl_proxyuserpwd'] !== false)
      curl_setopt($c, CURLOPT_PROXYUSERPWD, $this->config['curl_proxyuserpwd']);

    if ($this->config['curl_sslversion'] !== false)
      curl_setopt($c, CURLOPT_SSLVERSION, $this->config['curl_sslversion']);

    if ($this->config['is_streaming']) {
      // process the body
      $this->response['content-length'] = 0;
      curl_setopt($c, CURLOPT_TIMEOUT, 0);
      curl_setopt($c, CURLOPT_WRITEFUNCTION, [$this, 'curlWrite']);
    }

    if (!empty($this->request_settings['headers'])) {
      foreach ($this->request_settings['headers'] as $k => $v) {
        $headers[] = trim($k . ': ' . $v);
      }
      curl_setopt($c, CURLOPT_HTTPHEADER, $headers);
    }

    if (isset($this->config['block']) && (true === $this->config['block']))
      return 0;

    // do it!
    $response = curl_exec($c);
    $code = curl_getinfo($c, CURLINFO_HTTP_CODE);
    $info = curl_getinfo($c);
    $error = curl_error($c);
    $errno = curl_errno($c);
    curl_close($c);

    // store the response
    $this->response['code'] = $code;
    $this->response['response'] = $response;
    $this->response['info'] = $info;
    $this->response['error'] = $error;
    $this->response['errno'] = $errno;

    if (!isset($this->response['raw'])) {
      $this->response['raw'] = '';
    }
    $this->response['raw'] .= $response;

    return $code;
  }
}
