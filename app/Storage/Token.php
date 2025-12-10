<?php

namespace App\Storage;

use App\Http\Requests\CreateTokenRequest;
use Carbon\Carbon;
use Ramsey\Uuid\Uuid;

class Token extends Entity
{
    /**
     * @param $tokenId
     * @return string
     */
    public static function getIdentifier($tokenId = null)
    {
        return sprintf('token:%s', $tokenId);
    }

    /**
     * @param CreateTokenRequest $request
     * @return Token
     */
    public static function createFromRequest(CreateTokenRequest $request)
    {
        return new self([
            'uuid' => Uuid::uuid4()->toString(),
            'ip' => $request->ip(),
            'user_agent' => $request->header('User-Agent'),
            'default_content' => $request->get('default_content', ''),
            'default_status' => (int)$request->get('default_status', 200),
            'default_content_type' => $request->get('default_content_type', 'text/plain'),
            'timeout' => (int)$request->get('timeout', null),
            'cors' => false,
            // Server-side redirect settings
            'server_redirect_enabled' => false,
            'server_redirect_url' => $request->get('server_redirect_url', ''),
            'server_redirect_method' => $request->get('server_redirect_method', 'default'),
            'server_redirect_headers' => $request->get('server_redirect_headers', ''),
            'server_redirect_content_type' => $request->get('server_redirect_content_type', 'text/plain'),
            // Redirect mode: 'forward' (Guzzle) or 'redirect' (HTTP 301/302)
            'redirect_mode' => $request->get('redirect_mode', 'forward'),
            // HTTP redirect type: 301, 302, 307, 308
            'redirect_type' => (int)$request->get('redirect_type', 302),
            // Preserve path from original request? (default: false untuk bit.ly style)
            'preserve_path' => (bool)$request->get('preserve_path', false),
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ]);
    }
}