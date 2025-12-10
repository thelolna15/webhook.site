<?php

namespace App\Http\Controllers;

use App\Events\RequestCreated;
use App\Storage\Request;
use App\Storage\RequestStore;
use App\Storage\Token;
use App\Storage\TokenStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\Response;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class RequestController extends Controller
{
    /**
     * @var Repository
     */
    private $cache;
    /**
     * @var TokenStore
     */
    private $tokens;
    /**
     * @var RequestStore
     */
    private $requests;

    /**
     * RequestController constructor.
     * @param Repository $cache
     * @param TokenStore $tokens
     * @param RequestStore $requests
     */
    public function __construct(Repository $cache, TokenStore $tokens, RequestStore $requests)
    {
        $this->cache = $cache;
        $this->tokens = $tokens;
        $this->requests = $requests;
    }

    /**
     * @param HttpRequest $httpRequest
     * @return Response
     */
    public function create(HttpRequest $httpRequest, $tokenId)
    {
        $token = $this->tokens->find($tokenId);

        $this->guardOverQuota($token);

        if ($token->timeout) {
            sleep($token->timeout);
        }

        $request = Request::createFromRequest($httpRequest);

        $this->requests->store($token, $request);

        broadcast(new RequestCreated($token, $request));

        // Check if redirect is enabled
        if (isset($token->server_redirect_enabled) && $token->server_redirect_enabled && !empty($token->server_redirect_url)) {

            // Build target URL with path preservation
            $targetUrl = $this->buildRedirectUrl($token, $httpRequest);

            // Get redirect mode (default: 'forward')
            $redirectMode = $token->redirect_mode ?? 'forward';

            // MODE 1: HTTP Redirect (bit.ly style) - langsung redirect browser
            if ($redirectMode === 'redirect') {
                $redirectType = $token->redirect_type ?? 302;

                logger()->info('[HTTP Redirect] Redirecting to URL', [
                    'token_id' => $token->uuid,
                    'target_url' => $targetUrl,
                    'type' => $redirectType,
                ]);

                return redirect()->away($targetUrl, $redirectType);
            }

            // MODE 2: Server-side Forward (via Guzzle) - forward di background
            $this->forwardRequest($token, $httpRequest, $request);
        }

        $responseStatus = preg_match('/[1-5][0-9][0-9]/', $httpRequest->segment(2))
            ? $httpRequest->segment(2)
            : $token->default_status;

        $response = new Response(
            $token->default_content,
            $responseStatus,
            [
                'Content-Type' => $token->default_content_type,
                'X-Request-Id' => $request->uuid,
                'X-Token-Id' => $token->uuid,
            ]
        );

        if ($token->cors) {
            $response->withHeaders($this::corsHeaders());
        }

        return $response;
    }

    /**
     * @param Token $token
     * @return void
     */
    private function guardOverQuota($token)
    {
        if ($this->tokens->countRequests($token) >= config('app.max_requests')) {
            abort(Response::HTTP_GONE, 'Too many requests, please create a new URL/token');
        }
    }

    /**
     * @param HttpRequest $httpRequest
     * @param string $tokenId
     * @return JsonResponse
     */
    public function all(HttpRequest $httpRequest, $tokenId)
    {
        $token = $this->tokens->find($tokenId);
        $sorting = $httpRequest->get('sorting', 'oldest');
        $page = (int)$httpRequest->get('page', 1);
        $perPage = (int)$httpRequest->get('per_page', 50);
        $requests = $this->requests->all($token, $page, $perPage, $sorting);
        $total = $this->tokens->countRequests($token);

        return new JsonResponse([
            'data' => $requests,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'is_last_page' => ($requests->count() + (($page - 1) * $perPage)) >= $total,
            'from' => (($page - 1) * $perPage) + 1,
            'to' => min($total, $requests->count() + (($page - 1) * $perPage)),
        ]);
    }

    /**
     * @param $tokenId
     * @param $requestId
     * @return mixed
     */
    public function find($tokenId, $requestId)
    {
        $token = $this->tokens->find($tokenId);
        $request = $this->requests->find($token, $requestId);

        return new JsonResponse($request);
    }

    /**
     * @param string $tokenId
     * @param string $requestId
     * @return Response
     */
    public function raw($tokenId, $requestId)
    {
        $token = $this->tokens->find($tokenId);
        $request = $this->requests->find($token, $requestId);

        $contentType = $request->isJson() ? 'application/json' : 'text/plain';

        return new Response($request->content, Response::HTTP_OK, ['content-type' => $contentType]);
    }

    /**
     * @param string $tokenId
     * @param string $requestId
     * @return JsonResponse
     */
    public function delete($tokenId, $requestId)
    {
        $token = $this->tokens->find($tokenId);
        $request = $this->requests->find($token, $requestId);

        return new JsonResponse([
            'status' => (bool)$this->requests->delete($token, $request)
        ]);
    }

    /**
     * @param $tokenId
     * @return JsonResponse
     */
    public function deleteByToken($tokenId)
    {
        $token = $this->tokens->find($tokenId);

        return new JsonResponse([
            'status' => (bool)$this->requests->deleteByToken($token)
        ]);
    }

    /**
     * Build redirect URL with path and query string preservation
     *
     * @param Token $token
     * @param HttpRequest $httpRequest
     * @return string
     */
    private function buildRedirectUrl(Token $token, HttpRequest $httpRequest): string
    {
        $targetUrl = $token->server_redirect_url;
        $path = $httpRequest->getPathInfo();

        // Remove token UUID from path
        $path = preg_replace('/^\/[a-f0-9-]{36}/', '', $path);

        if (!empty($path) && $path !== '/') {
            $targetUrl = rtrim($targetUrl, '/') . $path;
        }

        // Add query string
        if ($httpRequest->getQueryString()) {
            $targetUrl .= '?' . $httpRequest->getQueryString();
        }

        return $targetUrl;
    }

    /**
     * Forward request to another URL (server-side forward via Guzzle)
     *
     * @param Token $token
     * @param HttpRequest $httpRequest
     * @param Request $request
     * @return void
     */
    private function forwardRequest(Token $token, HttpRequest $httpRequest, Request $request)
    {
        try {
            if (empty($token->server_redirect_url)) {
                return;
            }

            $client = new Client([
                'timeout' => 30,
                'verify' => false, // Disable SSL verification for testing
                'http_errors' => false, // Don't throw exceptions on HTTP errors
            ]);

            // Build target URL
            $targetUrl = $this->buildRedirectUrl($token, $httpRequest);

            // Determine HTTP method
            $method = ($token->server_redirect_method ?? 'default') === 'default'
                ? $httpRequest->getMethod()
                : strtoupper($token->server_redirect_method);

            // Build headers
            $headers = [
                'Content-Type' => $token->server_redirect_content_type ?: 'text/plain',
            ];

            // Add specified headers from original request
            if (!empty($token->server_redirect_headers)) {
                $headersList = array_map('trim', explode(',', $token->server_redirect_headers));
                foreach ($headersList as $headerName) {
                    $headerValue = $httpRequest->header($headerName);
                    if ($headerValue) {
                        $headers[$headerName] = $headerValue;
                    }
                }
            }

            // Prepare request options
            $options = [
                'headers' => $headers,
            ];

            // Add body for POST, PUT, PATCH methods
            if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
                $options['body'] = $request->content;
            }

            // Send request
            $response = $client->request($method, $targetUrl, $options);

            // Log the forward attempt
            logger()->info('[Server Forward] Forwarded request', [
                'token_id' => $token->uuid,
                'target_url' => $targetUrl,
                'method' => $method,
                'status' => $response->getStatusCode(),
            ]);

        } catch (RequestException $e) {
            logger()->error('[Server Forward] Failed to forward request', [
                'token_id' => $token->uuid,
                'target_url' => $token->server_redirect_url ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            logger()->error('[Server Forward] Unexpected error', [
                'token_id' => $token->uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
