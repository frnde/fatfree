<?php

/*

    Copyright (c) 2009-2017 F3::Factory/Bong Cosca, All rights reserved.

    This file is part of the Fat-Free Framework (http://fatfreeframework.com).

    This is free software: you can redistribute it and/or modify it under the
    terms of the GNU General Public License as published by the Free Software
    Foundation, either version 3 of the License, or later.

    Fat-Free Framework is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
    General Public License for more details.

    You should have received a copy of the GNU General Public License along
    with Fat-Free Framework.  If not, see <http://www.gnu.org/licenses/>.

*/

namespace CLI;

//! RFC6455 WebSocket server
class WS
{

    //! UUID magic string
    const MAGIC = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    //! Max packet size
    const PACKET = 65536;

    //@{ Mask bits for first byte of header
    const
        TEXT = 0x01,
        BINARY = 0x02,
        CLOSE = 0x08,
        PING = 0x09,
        PONG = 0x0a,
        OPCODE = 0x0f,
        FINALE = 0x80;
    //@}

    //@{ Mask bits for second byte of header
    const
        LENGTH = 0x7f;
    //@}

    protected $addr;
    protected $ctx;
    protected $wait;
    protected $sockets;
    protected $agents = [];
    protected $events = [];

    /**
    *   Allocate stream socket
    *   @return NULL
    *   @param $socket resource
    **/
    public function alloc($socket)
    {
        if (is_bool($str = $this->read($socket))) {
            $this->close($socket);
            return;
        }
        // Get WebSocket headers
        $hdrs = [];
        $CRLF = "\r\n";
        $verb = null;
        $uri = null;
        foreach (explode($CRLF, trim($str)) as $line) {
            if (
                preg_match(
                    '/^(\w+)\s(.+)\sHTTP\/1\.\d$/',
                    trim($line),
                    $match
                )
            ) {
                $verb = $match[1];
                $uri = $match[2];
            } elseif (preg_match('/^(.+): (.+)/', trim($line), $match)) {
                // Standardize header
                $hdrs[
                    strtr(
                        ucwords(
                            strtolower(
                                strtr($match[1], '-', ' ')
                            )
                        ),
                        ' ',
                        '-'
                    )
                ] = $match[2];
            } else {
                $this->close($socket);
                return;
            }
        }
        if (
            empty($hdrs['Upgrade']) &&
            empty($hdrs['Sec-Websocket-Key'])
        ) {
            // Not a WebSocket request
            if ($verb && $uri) {
                $this->write(
                    $socket,
                    $str = 'HTTP/1.1 400 Bad Request' . $CRLF .
                        'Connection: close' . $CRLF . $CRLF
                );
            }
            $this->close($socket);
            return;
        }
        // Handshake
        $bytes = $this->write(
            $socket,
            $str = 'HTTP/1.1 101 Switching Protocols' . $CRLF .
                'Upgrade: websocket' . $CRLF .
                'Connection: Upgrade' . $CRLF .
                'Sec-WebSocket-Accept: ' .
                    base64_encode(
                        sha1(
                            $hdrs['Sec-Websocket-Key'] .
                            self::MAGIC,
                            true
                        )
                    ) . $CRLF . $CRLF
        );
        if ($bytes) {
            // Connect agent to server
            $this->sockets[] = $socket;
            $this->agents[(int)$socket] =
                new Agent($this, $socket, $verb, $uri, $hdrs);
        } else {
            $this->close($socket);
        }
    }

    /**
    *   Close stream socket
    *   @return NULL
    *   @param $socket resource
    **/
    public function close($socket)
    {
        stream_socket_shutdown($socket, STREAM_SHUT_WR);
        @fclose($socket);
    }

    /**
    *   Free stream socket
    *   @return bool
    *   @param $socket resource
    **/
    public function free($socket)
    {
        unset($this->sockets[array_search($socket, $this->sockets)]);
        unset($this->agents[(int)$socket]);
        $this->close($socket);
    }

    /**
    *   Read from stream socket
    *   @return string|FALSE
    *   @param $socket resource
    **/
    public function read($socket)
    {
        if (
            is_string($str = @fread($socket, self::PACKET)) &&
            strlen($str) &&
            strlen($str) < self::PACKET
        ) {
            return $str;
        }
        if (
            isset($this->events['error']) &&
            is_callable($func = $this->events['error'])
        ) {
            $func($this);
        }
        return false;
    }

    /**
    *   Write to stream socket
    *   @return int|FALSE
    *   @param $socket resource
    *   @param $str string
    **/
    public function write($socket, $str)
    {
        for ($i = 0,$bytes = 0; $i < strlen($str); $i += $bytes) {
            if (
                ($bytes = @fwrite($socket, substr($str, $i))) &&
                @fflush($socket)
            ) {
                continue;
            }
            if (
                isset($this->events['error']) &&
                is_callable($func = $this->events['error'])
            ) {
                $func($this);
            }
            return false;
        }
        return $bytes;
    }

    /**
    *   Return socket agents
    *   @return array
    *   @param $uri string
    ***/
    public function agents($uri = null)
    {
        return array_filter(
            $this->agents,
            function ($val) use ($uri) {
                return $uri ? ($val->uri() == $uri) : true;
            }
        );
    }

    /**
    *   Return event handlers
    *   @return array
    **/
    public function events()
    {
        return $this->events;
    }

    /**
    *   Bind function to event handler
    *   @return object
    *   @param $event string
    *   @param $func callable
    **/
    public function on($event, $func)
    {
        $this->events[$event] = $func;
        return $this;
    }

    /**
    *   Terminate server
    *   @return NULL
    *   @param $signal int
    **/
    public function kill($signal)
    {
        die;
    }

    /**
    *   Execute the server process
    *   @return object
    **/
    public function run()
    {
        $fw = \Base::instance();
        // Assign signal handlers
        declare(ticks=1);
        pcntl_signal(SIGINT, [$this,'kill']);
        pcntl_signal(SIGTERM, [$this,'kill']);
        gc_enable();
        // Activate WebSocket listener
        $listen = stream_socket_server(
            $this->addr,
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $this->ctx
        );
        $socket = socket_import_stream($listen);
        register_shutdown_function(function () use ($listen) {
            foreach ($this->sockets as $socket) {
                if ($socket != $listen) {
                    $this->free($socket);
                }
            }
            $this->close($listen);
            if (
                isset($this->events['stop']) &&
                is_callable($func = $this->events['stop'])
            ) {
                $func($this);
            }
        });
        if ($errstr) {
            user_error($errstr, E_USER_ERROR);
        }
        if (
            isset($this->events['start']) &&
            is_callable($func = $this->events['start'])
        ) {
            $func($this);
        }
        $this->sockets = [$listen];
        $empty = [];
        $wait = $this->wait;
        while (true) {
            $active = $this->sockets;
            $mark = microtime(true);
            $count = @stream_select(
                $active,
                $empty,
                $empty,
                (int)$wait,
                round(1e6 * ($wait - (int)$wait))
            );
            if (is_bool($count) && $wait) {
                if (
                    isset($this->events['error']) &&
                    is_callable($func = $this->events['error'])
                ) {
                    $func($this);
                }
                die;
            }
            if ($count) {
                // Process active connections
                foreach ($active as $socket) {
                    if (!is_resource($socket)) {
                        continue;
                    }
                    if ($socket == $listen) {
                        if ($socket = @stream_socket_accept($listen, 0)) {
                            $this->alloc($socket);
                        } elseif (
                            isset($this->events['error']) &&
                            is_callable($func = $this->events['error'])
                        ) {
                            $func($this);
                        }
                    } else {
                        $id = (int)$socket;
                        if (
                            isset($this->agents[$id]) &&
                            $raw = $this->agents[$id]->fetch()
                        ) {
                            list($op,$data) = $raw;
                            // Dispatch
                            switch ($op & self::OPCODE) {
                                case self::PING:
                                    $this->agents[$id]->send(self::PONG);
                                    break;
                                case self::CLOSE:
                                    $this->free($socket);
                                    break;
                                case self::TEXT:
                                    $data = trim($data);
                                    // I think the missing break; is correct
                                case self::BINARY:
                                    if (
                                        isset($this->events['receive']) &&
                                        is_callable($func = $this->events['receive'])
                                    ) {
                                        $func($this->agents[$id], $op, $data);
                                    }
                                    break;
                            }
                        }
                    }
                }
                $wait -= microtime(true) - $mark;
                while ($wait < 1e-6) {
                    $wait += $this->wait;
                    $count = 0;
                }
            }
            if (!$count) {
                $mark = microtime(true);
                foreach ($this->sockets as $socket) {
                    if (!is_resource($socket)) {
                        continue;
                    }
                    $id = (int)$socket;
                    if (
                        $socket != $listen &&
                        isset($this->agents[$id]) &&
                        isset($this->events['idle']) &&
                        is_callable($func = $this->events['idle'])
                    ) {
                        $func($this->agents[$id]);
                    }
                }
                $wait = $this->wait - microtime(true) + $mark;
            }
            gc_collect_cycles();
        }
    }

    /**
    *   Instantiate object
    *   @return object
    *   @param $addr string
    *   @param $ctx resource
    *   @param $wait int
    **/
    public function __construct($addr, $ctx = null, $wait = 60)
    {
        $this->addr = $addr;
        $this->ctx = $ctx ?: stream_context_create();
        $this->wait = $wait;
        $this->events = [];
    }
}

//! RFC6455 remote socket
class Agent
{

    protected $server;
    protected $id;
    protected $socket;
    protected $flag;
    protected $verb;
    protected $uri;
    protected $headers;
    protected $events;
    protected $buffer;

    /**
    *   Return server instance
    *   @return object
    **/
    public function server()
    {
        return $this->server;
    }

    /**
    *   Return socket ID
    *   @return string
    **/
    public function id()
    {
        return $this->id;
    }

    /**
    *   Return request method
    *   @return string
    **/
    public function verb()
    {
        return $this->verb;
    }

    /**
    *   Return request URI
    *   @return string
    **/
    public function uri()
    {
        return $this->uri;
    }

    /**
    *   Return socket headers
    *   @return string
    **/
    public function headers()
    {
        return $this->headers;
    }

    /**
    *   Frame and transmit payload
    *   @return string|FALSE
    *   @param $socket resource
    *   @param $op int
    *   @param $payload string
    **/
    public function send($op, $data = '')
    {
        $mask = WS::FINALE | $op & WS::OPCODE;
        $len = strlen($data);
        $str = '';
        if ($len > 0xffff) {
            $str = pack('CCNN', $mask, 0x7f, $len);
        } elseif ($len > 0x7d) {
            $str = pack('CCn', $mask, 0x7e, $len);
        } else {
            $str = pack('CC', $mask, $len);
        }
        $str .= $data;
        $server = $this->server();
        if (is_bool($server->write($this->socket, $str))) {
            $this->free();
            return false;
        }
        if (
            !in_array($op, [WS::PONG,WS::CLOSE]) &&
            isset($this->events['send']) &&
            is_callable($func = $this->events['send'])
        ) {
            $func($this, $op, $data);
        }
        return $data;
    }

    /**
    *   Retrieve and unmask payload
    *   @return array|FALSE
    **/
    public function fetch()
    {
        // Unmask payload
        $server = $this->server();
        if (is_bool($buf = $server->read($this->socket))) {
            $this->free();
            return false;
        }
        $buf = ($this->buffer .= $buf);
        $op = ord($buf[0]) & WS::OPCODE;
        $len = ord($buf[1]) & WS::LENGTH;
        $pos = 2;
        if ($len == 0x7e) {
            $len = ord($buf[2]) * 256 + ord($buf[3]);
            $pos += 2;
        } elseif ($len == 0x7f) {
            for ($i = 0,$len = 0; $i < 8; $i++) {
                $len = $len * 256 + ord($buf[$i + 2]);
            }
            $pos += 8;
        }
        for ($i = 0,$mask = []; $i < 4; $i++) {
            $mask[$i] = ord($buf[$pos + $i]);
        }
        $pos += 4;
        if (strlen($buf) < $len + $pos) {
            return false;
        }
        for ($i = 0,$data = ''; $i < $len; $i++) {
            $data .= chr(ord($buf[$pos + $i]) ^ $mask[$i % 4]);
        }
        $this->buffer = '';
        return [$op,$data];
    }

    /**
    *   Free stream socket
    *   @return NULL
    **/
    public function free()
    {
        $this->server->free($this->socket);
    }

    /**
    *   Destroy object
    *   @return NULL
    **/
    public function __destruct()
    {
        if (
            isset($this->events['disconnect']) &&
            is_callable($func = $this->events['disconnect'])
        ) {
            $func($this);
        }
    }

    /**
    *   Instantiate object
    *   @return object
    *   @param $server object
    *   @param $socket resource
    *   @param $verb string
    *   @param $uri string
    *   @param $hdrs array
    **/
    public function __construct($server, $socket, $verb, $uri, array $hdrs)
    {
        $this->server = $server;
        $this->id = stream_socket_get_name($socket, true);
        $this->socket = $socket;
        $this->verb = $verb;
        $this->uri = $uri;
        $this->headers = $hdrs;
        $this->events = $server->events();
        $this->buffer = '';
        if (
            isset($this->events['connect']) &&
            is_callable($func = $this->events['connect'])
        ) {
            $func($this);
        }
    }
}
