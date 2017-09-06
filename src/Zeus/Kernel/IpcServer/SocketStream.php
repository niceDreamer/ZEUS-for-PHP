<?php

namespace Zeus\Kernel\IpcServer;

use Zeus\Kernel\IpcServer\Adapter\Helper\MessagePackager;
use Zeus\Networking\Stream\AbstractStream;
use Zeus\Networking\Stream\FlushableConnectionInterface;

class SocketStream extends IpcDriver
{
    /**
     * @var AbstractStream
     */
    protected $stream;

    /** @var int */
    protected $sid;

    use MessagePackager;

    public function __construct($stream, $senderId)
    {
        $this->sid = $senderId;

        $this->stream = $stream;
    }

    /**
     * @param $message
     * @param string $audience
     * @param int $number
     * @return $this
     */
    public function send($message, $audience = self::AUDIENCE_ALL, int $number = 0)
    {
        $payload = [
            'sid' => $this->sid,
            'aud' => $audience,
            'msg' => $message,
            'num' => $number,
        ];

        $data = $this->packMessage($payload);

        $this->stream->write($data . "\0");

        if ($this->stream instanceof FlushableConnectionInterface) {
            $this->stream->flush();
        }

        return $this;
    }

    /**
     * @param bool $returnRaw
     * @return mixed[]
     */
    public function readAll($returnRaw = false)
    {
        $messages = [];

        while ($data = $this->stream->read("\0")) {
            $message = $this->unpackMessage($data);
            $messages[] = $returnRaw ? $message : $message['msg'];
        }

        return $messages;
    }
}