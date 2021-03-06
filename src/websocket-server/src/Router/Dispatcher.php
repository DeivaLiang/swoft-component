<?php

namespace Swoft\WebSocket\Server\Router;

use Swoft\Core\ErrorHandler;
use Swoft\Http\Message\Server\Request;
use Swoft\Http\Message\Server\Response;
use Swoft\WebSocket\Server\HandlerInterface;
use Swoft\WebSocket\Server\Exception\WsMessageException;
use Swoft\WebSocket\Server\Exception\WsRouteException;
use Swoft\WebSocket\Server\WebSocketContext;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

/**
 * Class WsDispatcher
 * @package Swoft\WebSocket\Server
 */
class Dispatcher
{
    /**
     * dispatch handshake request
     * @param Request $request
     * @param Response $response
     * @return array eg. [status, response]
     * @throws \Swoft\WebSocket\Server\Exception\WsRouteException
     * @throws \InvalidArgumentException
     * @throws \Throwable
     */
    public function handshake(Request $request, Response $response): array
    {
        try {
            $path = $request->getUri()->getPath();
            list($className, ) = $this->getHandler($path);
        } catch (\Throwable $e) {
            /* @var ErrorHandler $errorHandler */
            // $errorHandler = \bean(ErrorHandler::class);
            // $response = $errorHandler->handle($e);
            if ($e instanceof WsRouteException) {
                return [
                    HandlerInterface::HANDSHAKE_FAIL,
                    $response->withStatus(404)
                ];
            }

            // other error
            throw $e;
        }

        /** @var HandlerInterface $handler */
        $handler = \bean($className);

        if (!\method_exists($handler, 'checkHandshake')) {
            return [
                HandlerInterface::HANDSHAKE_OK,
                $response->withAddedHeader('swoft-ws-handshake', 'auto')
            ];
        }

        return $handler->checkHandshake($request, $response);
    }

    /**
     * @param Server $server
     * @param Request $request
     * @param int $fd
     * @throws \Swoft\WebSocket\Server\Exception\WsRouteException
     * @throws \InvalidArgumentException
     */
    public function open(Server $server, Request $request, int $fd)
    {
        $path = $request->getUri()->getPath();
        list($className, ) = $this->getHandler($path);

        /** @var HandlerInterface $handler */
        $handler = \bean($className);

        if (\method_exists($handler, 'onOpen')) {
            $handler->onOpen($server, $request, $fd);
        }
    }

    /**
     * dispatch ws message
     * @param Server $server
     * @param Frame $frame
     * @throws \InvalidArgumentException
     * @throws \Swoft\WebSocket\Server\Exception\WsRouteException
     * @throws \Swoft\WebSocket\Server\Exception\WsMessageException
     */
    public function message(Server $server, Frame $frame)
    {
        $fd = $frame->fd;

        if (!$path = WebSocketContext::getMeta('path', $fd)) {
            throw new WsMessageException("The connection info has lost of the fd $fd");
        }

        list($className, ) = $this->getHandler($path);

        /** @var HandlerInterface $handler */
        $handler = \bean($className);
        $handler->onMessage($server, $frame);
    }

    /**
     * dispatch ws close
     * @param Server $server
     * @param int $fd
     * @throws \InvalidArgumentException
     * @throws \Swoft\WebSocket\Server\Exception\WsRouteException
     * @throws \Swoft\WebSocket\Server\Exception\WsMessageException
     */
    public function close(Server $server, int $fd)
    {
        if (!$path = WebSocketContext::getMeta('path', $fd)) {
            throw new WsMessageException("The connection info has lost of the fd $fd");
        }

        list($className, ) = $this->getHandler($path);

        /** @var HandlerInterface $handler */
        $handler = \bean($className);

        if (\method_exists($handler, 'onClose')) {
            $handler->onClose($server, $fd);
        }
    }

    /**
     * @param string $path
     * @return array
     * @throws \InvalidArgumentException
     * @throws \Swoft\WebSocket\Server\Exception\WsRouteException
     */
    protected function getHandler(string $path): array
    {
        /** @var HandlerMapping $router */
        $router = \bean('wsRouter');
        list($status, $info) = $router->getHandler($path);

        if ($status !== HandlerMapping::FOUND) {
            throw new WsRouteException(sprintf(
                'The requested websocket route "%s" path is not exist! ',
                $path
            ));
        }

        return [
            $info['handler'],
            $info['option'] ?? []
        ];
    }
}
