<?php namespace Tuna;

use Exception;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use function GuzzleHttp\Psr7\modify_request;

class CloudflareMiddleware
{
    /**
     * WAIT_RESPONSE_CODE this is the response code which Cloudflare throws when UAM is active
     */
    const WAIT_RESPONSE_CODE = 503;

    /**
     * SERVER_NAME name of the server which Cloudflare uses
     */
    const SERVER_NAME = [
        'cloudflare-nginx',
        'cloudflare'
    ];

    /**
     * REFRESH_EXPRESSION regular expression used to parse the 'Refresh' header
     */
    const REFRESH_EXPRESSION = '/8;URL=(\/cdn-cgi\/l\/chk_jschl\?pass=[0-9]+\.[0-9]+-.*)/';

    /** @var callable */
    private $nextHandler;

    /**
     * @param callable $nextHandler Next handler to invoke.
     */
    public function __construct($nextHandler)
    {
        $this->nextHandler = $nextHandler;
    }

    /**
     * @param RequestInterface $request
     * @param array $options
     * @return ResponseInterface
     */
    public function __invoke(RequestInterface $request, array $options = [])
    {
        $next = $this->nextHandler;

        return $next($request, $options)
            ->then(function (ResponseInterface $response) use ($request, $options) {
                return $this->checkResponse($request, $options, $response);
            });
    }

    /**
     * @param RequestInterface $request
     * @param array $options
     * @param ResponseInterface $response
     * @return ResponseInterface
     * @throws \Exception
     */
    protected function checkResponse(RequestInterface $request, array $options = [], ResponseInterface $response)
    {
        if (!$this->needVerification($response)) return $response;

        if (empty($options['cookies']) || ! $options['cookies']) {
            throw new Exception('you have to use cookies');
        }

        if (empty($options['allow_redirects']) || ! $options['allow_redirects']) {
            throw new Exception('you have to use the allow_redirects options');
        }

        return $this($this->modifyRequest($request, $response), $options);
    }

    /**
     * @param ResponseInterface $response
     * @return bool
     */
    protected function needVerification(ResponseInterface $response)
    {
        return $response->getStatusCode() === static::WAIT_RESPONSE_CODE
            && in_array($response->getHeaderLine('Server'), static::SERVER_NAME, true);
    }

    /**
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @return RequestInterface
     */
    protected function modifyRequest(RequestInterface $request, ResponseInterface $response)
    {
        sleep(8);

        return modify_request($request, [
            'uri' => UriResolver::resolve(
                $request->getUri(),
                $this->getRefreshUri($response)
            ),
            'body' => '',
            'method' => 'GET',
            'set_headers' => [
                'Referer' => $request->getUri()->withUserInfo('', '')
            ]
        ]);
    }

    /**
     * @param ResponseInterface $response
     * @return Uri
     * @throws \Exception
     */
    protected function getRefreshUri(ResponseInterface $response)
    {
        if (preg_match(static::REFRESH_EXPRESSION, $response->getHeaderLine('Refresh'), $matches)) {
            return new Uri($matches[1]);
        }

        throw new Exception('Can not seem to parse the refresh header');
    }

    /**
     * @return \Closure
     */
    public static function create()
    {
        return function ($handler) {
            return new static($handler);
        };
    }
}
