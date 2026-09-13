<?php

declare(strict_types=1);

namespace Hampel\XenForo\Api\Laravel\Tests;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that records what reaches it and answers with a minimal user.
 *
 * It answers rather than throwing, so a test that expected some other client to be used fails
 * on a named assertion about $sent rather than on an unrelated exception.
 */
final class RecordingClient implements ClientInterface
{
    /** @var list<string> */
    public array $sent = [];

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->sent[] = (string) $request->getUri();

        return new Response(200, ['Content-Type' => 'application/json'], '{"user":{"user_id":1}}');
    }
}
