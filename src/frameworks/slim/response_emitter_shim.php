<?php
/*
 * DDLess - PHP Debug Engine
 *
 * @author    Jefferson T.S
 * @copyright 2025-2026 DDLess
 * @license   Proprietary
 *
 * CLI-only shim for Slim's ResponseEmitter.
 *
 * Slim builds its emitter inline in App::run() (`new ResponseEmitter()`), so it
 * cannot be injected. Under the CLI SAPI header() is a no-op and headers_list()
 * is always empty, so the headers Slim produced never reach DDLess and the
 * client gets a body with no Content-Type.
 *
 * Declaring Slim\ResponseEmitter before Slim's autoloader reaches it makes
 * App::run() construct this class instead, which keeps a reference to the
 * outgoing PSR-7 response. The emitting logic mirrors Slim's own, so behaviour
 * is unchanged; the reference is the only addition.
 *
 * Loaded only under CLI. In proxy mode headers work natively and Slim's real
 * emitter is left alone.
 */

declare(strict_types=1);

namespace Slim;

use Psr\Http\Message\ResponseInterface;

class ResponseEmitter
{
    private int $responseChunkSize;

    public function __construct(int $responseChunkSize = 4096)
    {
        $this->responseChunkSize = $responseChunkSize;
    }

    public function emit(ResponseInterface $response): void
    {
        $GLOBALS['__DDLESS_SLIM_RESPONSE__'] = $response;

        $isEmpty = $this->isResponseEmpty($response);
        if (headers_sent() === false) {
            $this->emitHeaders($response);

            $this->emitStatusLine($response);
        }

        if (!$isEmpty) {
            $this->emitBody($response);
        }
    }

    private function emitHeaders(ResponseInterface $response): void
    {
        foreach ($response->getHeaders() as $name => $values) {
            $first = strtolower($name) !== 'set-cookie';
            foreach ($values as $value) {
                header(sprintf('%s: %s', $name, $value), $first);
                $first = false;
            }
        }
    }

    private function emitStatusLine(ResponseInterface $response): void
    {
        $statusLine = sprintf(
            'HTTP/%s %s %s',
            $response->getProtocolVersion(),
            $response->getStatusCode(),
            $response->getReasonPhrase()
        );
        header($statusLine, true, $response->getStatusCode());
    }

    private function emitBody(ResponseInterface $response): void
    {
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        $amountToRead = (int) $response->getHeaderLine('Content-Length');
        if (!$amountToRead) {
            $amountToRead = $body->getSize();
        }

        if ($amountToRead) {
            while ($amountToRead > 0 && !$body->eof()) {
                $length = min($this->responseChunkSize, $amountToRead);
                $data = $body->read($length);
                echo $data;

                $amountToRead -= strlen($data);

                if (connection_status() !== CONNECTION_NORMAL) {
                    break;
                }
            }
        } else {
            while (!$body->eof()) {
                echo $body->read($this->responseChunkSize);
                if (connection_status() !== CONNECTION_NORMAL) {
                    break;
                }
            }
        }
    }

    public function isResponseEmpty(ResponseInterface $response): bool
    {
        if (in_array($response->getStatusCode(), [204, 205, 304], true)) {
            return true;
        }
        $stream = $response->getBody();
        $seekable = $stream->isSeekable();
        if ($seekable) {
            $stream->rewind();
        }
        return $seekable ? $stream->read(1) === '' : $stream->eof();
    }
}
