<?php
/**
 * This file is part of PHPinnacle/Ridge.
 *
 * (c) PHPinnacle Team <dev@phpinnacle.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PHPinnacle\Ridge;

use PHPinnacle\Ridge\Exception\ConnectionException;
use function Amp\asyncCall, Amp\call, Amp\Socket\connect;
use Amp\Socket\ConnectContext;
use Amp\Loop;
use Amp\Promise;
use Amp\Socket\Socket;
use PHPinnacle\Ridge\Protocol\AbstractFrame;

final class Connection
{
    /**
     * @var string
     */
    private $uri;

    /**
     * @var Parser
     */
    private $parser;

    /**
     * @var Socket|null
     */
    private $socket;

    /**
     * @var callable[][][]
     * @psalm-var array<int, array<class-string<AbstractFrame>, array<int, callable>>>
     */
    private $callbacks = [];

    /**
     * @var int
     */
    private $lastRead = 0;

    /**
     * @var string|null
     */
    private $heartbeatWatcherId;

    public function __construct(string $uri)
    {
        $this->uri = $uri;
        $this->parser = new Parser;
    }

    public function connected(): bool
    {
        return $this->socket !== null && $this->socket->isClosed() === false;
    }

    /**
     * @throws \PHPinnacle\Ridge\Exception\ConnectionException
     */
    public function write(Buffer $payload): Promise
    {
        if ($this->socket !== null) {
            try {
                return $this->socket->write($payload->flush());
            } catch (\Throwable $throwable) {
                throw ConnectionException::writeFailed($throwable);
            }
        }

        throw ConnectionException::socketClosed();
    }

    /**
     * @throws \PHPinnacle\Ridge\Exception\ConnectionException
     */
    public function method(int $channel, Buffer $payload): Promise
    {
        return $this->write((new Buffer)
            ->appendUint8(1)
            ->appendUint16($channel)
            ->appendUint32($payload->size())
            ->append($payload)
            ->appendUint8(206)
        );
    }

    /**
     * @psalm-param class-string<AbstractFrame> $frame
     */
    public function subscribe(int $channel, string $frame, callable $callback): void
    {
        $this->callbacks[$channel][$frame][] = $callback;
    }

    public function cancel(int $channel): void
    {
        unset($this->callbacks[$channel]);
    }

    /**
     * @throws \PHPinnacle\Ridge\Exception\ConnectionException
     */
    public function open(int $timeout, int $maxAttempts, bool $noDelay): Promise
    {
        return call(
            function () use ($timeout, $maxAttempts, $noDelay) {
                $context = new ConnectContext();

                if ($maxAttempts > 0) {
                    $context = $context->withMaxAttempts($maxAttempts);
                }

                if ($timeout > 0) {
                    $context = $context->withConnectTimeout($timeout);
                }

                if ($noDelay) {
                    $context = $context->withTcpNoDelay();
                }

                $this->socket = yield connect($this->uri, $context);
                $this->lastRead = Loop::now();

                asyncCall(
                    function () {
                        if ($this->socket === null) {
                            throw ConnectionException::socketClosed();
                        }

                        while (null !== $chunk = yield $this->socket->read()) {
                            $this->parser->append($chunk);

                            while ($frame = $this->parser->parse()) {
                                $class = \get_class($frame);
                                $this->lastRead = Loop::now();

                                /**
                                 * @psalm-var callable(AbstractFrame):Promise<bool> $callback
                                 */
                                foreach ($this->callbacks[(int)$frame->channel][$class] ?? [] as $i => $callback) {
                                    if (yield call($callback, $frame)) {
                                        unset($this->callbacks[(int)$frame->channel][$class][$i]);
                                    }
                                }
                            }
                        }

                        $this->socket = null;
                    }
                );
            }
        );
    }

    public function heartbeat(int $interval): void
    {
        $this->heartbeatWatcherId = Loop::repeat(
            $interval,
            function (string $watcherId) use ($interval) {
                $currentTime = Loop::now();

                if (null !== $this->socket) {
                    yield $this->write(
                        (new Buffer)
                            ->appendUint8(8)
                            ->appendUint16(0)
                            ->appendUint32(0)
                            ->appendUint8(206)
                    );
                }

                if (
                    0 !== $this->lastRead &&
                    $currentTime > ($this->lastRead + $interval + 1000)
                ) {
                    Loop::cancel($watcherId);
                }

                unset($currentTime);
            }
        );
    }

    public function close(): void
    {
        $this->callbacks = [];

        if ($this->heartbeatWatcherId !== null) {
            Loop::cancel($this->heartbeatWatcherId);

            $this->heartbeatWatcherId = null;
        }

        if ($this->socket !== null) {
            $this->socket->close();
        }
    }
}
