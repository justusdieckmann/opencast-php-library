<?php
namespace OpencastApi\Rest;

use GuzzleHttp\Client;
use Psr\Http\Message\RequestInterface;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\CurlHandler;
use Exception;

class OcRestClient extends Client
{
    private $version;
    private $headerExceptions = [];
    /* 
        $config = [
            'url' => 'https://develop.opencast.org/',  // The base uri of the opencast instance
            'username' => 'admin',                          // The API username.
            'password' => 'opencast',                       // The API password.
            'timeout' => 30000,                             // The API timeout. In miliseconds (Default 30000 miliseconds or 30 seconds).
            'version' => null                               // The API Version. Default null.
        ]
    */
    public function __construct($config)
    {
        $this->config = $config;

        if (empty($config['url']) || empty($config['username']) || empty($config['password'])) {
            throw new Exception("Invalid API configuration!");
        }

        $stack = new HandlerStack();
        $stack->setHandler(new CurlHandler());

        $options = [];
        $options['base_uri'] = $config['url'];

        $basicAuth = base64_encode($config['username'] . ":" . $config['password']);
        $stack->push($this->addHeader('Authorization', "Basic $basicAuth"));

        if (isset($config['version'])) {
            $version = str_replace(['application/', 'v', '+json'], ['', '', ''], $config['version']);
            $stack->push($this->addHeader('Accept', "application/v{$version}+json"));
            $this->setVersion($config['version']);
        }

        $options['handler'] = $stack;

        $options['timeout'] = isset($config['timeout']) ? $config['timeout'] : 30000;

        parent::__construct($options);
    }

    public function registerHeaderException($header, $path) {
        $path = ltrim($path, '/');
        if (!isset($this->headerExceptions[$header]) || !in_array($path, $this->headerExceptions[$header])) {
            $this->headerExceptions[$header][] = $path;
        }
    }

    private function addHeader($header, $value)
    {
        return function (callable $handler) use ($header, $value) {
            return function (
                RequestInterface $request,
                array $options
            ) use ($handler, $header, $value) {
                $headerExceptions = $this->headerExceptions;
                $path = explode('/', ltrim($request->getUri()->getPath(), '/'))[0];
                if (in_array($header, array_keys($headerExceptions)) && in_array($path, $headerExceptions[$header])) {
                    return $handler($request, $options);
                }
                $request = $request->withHeader($header, $value);
                return $handler($request, $options);
            };
        };
    }

    public function hasVersion($version)
    {
        if (empty($this->version)) {
            $defaultVersion = $this->performGet('/api/version/default');
            if (!empty($defaultVersion['body']) && isset($defaultVersion['body']->default)) {
                $this->setVersion(str_replace(['application/', 'v', '+json'], ['', '', ''], $defaultVersion['body']->default));
            } else {
                return false;
            }
        }
        return version_compare($this->version, $version, '>=');

    }

    private function setVersion($version)
    {
        $version = str_replace(['application/', 'v', '+json'], ['', '', ''], $version);
        $this->version = $version;
    }

    public function getVersion() {
        return $this->version;
    }

    private function resolveResponseBody(string $body)
    {
        $result = json_decode($body);
        if ($result !== null) {
            return $result;
        }
        // TODO: Here we can add more return type if needed...

        if (!empty($body)) {
            return $body;
        }

        return null;
    }

    private function returnResult($response)
    {
        $result = [];
        $result['code'] = $response->getStatusCode();
        $result['reasone'] = $response->getReasonPhrase();
        $body = '';
        if ($result['code'] < 400 && !empty((string) $response->getBody())) {
            $body = $this->resolveResponseBody((string) $response->getBody());
        }
        $result['body'] = $body;

        $location = '';
        if ($response->hasHeader('Location')) {
            $location = $response->getHeader('Location');
        }
        $result['location'] = $location;
        return $result;
    }

    public function performGet($uri, $options = [])
    {
        $response = $this->get($uri, $options);
        return $this->returnResult($response);
    }

    public function performPost($uri, $options = [])
    {
        $response = $this->post($uri, $options);
        return $this->returnResult($response);
    }


    public function performPut($uri, $options = [])
    {
        $response = $this->put($uri, $options);
        return $this->returnResult($response);
    }

    public function performDelete($uri, $options = [])
    {
        $response = $this->delete($uri, $options);
        return $this->returnResult($response);
    }

    public function getFormParams($params)
    {
        $options = [];
        $formParams = [];
        foreach ($params as $field_name => $field_value) {
            $formParams[$field_name] = (!is_string($field_value)) ? json_encode($field_value) : $field_value;
        }
        if (!empty($formParams)) {
            $options['form_params'] = $formParams;
        }
        return $options;
    }

    public function getMultiPartFormParams($params)
    {
        $options = [];
        $multiParams = [];
        foreach ($params as $field_name => $field_value) {
            $multiParams[] = [
                'name' => $field_name,
                'contents' => $field_value
            ];
        }
        if (!empty($multiParams)) {
            $options['multipart'] = $multiParams;
        }
        return $options;
    }

    public function getQueryParams($params)
    {
        $options = [];
        $queryParams = [];
        foreach ($params as $field_name => $field_value) {
            $value = is_bool($field_value) ? json_encode($field_value) : $field_value;
            $queryParams[$field_name] = $value;
        }
        if (!empty($queryParams)) {
            $options['query'] = $queryParams;
        }
        return $options;
    }
}
?>