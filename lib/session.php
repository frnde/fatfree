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

//! Cache-based session handler
class Session
{

    //! Session ID
    protected $sid;
    //! Anti-CSRF token
    protected $_csrf;
    //! User agent
    protected $_agent;
    //! IP,
    protected $_ip;
    //! Suspect callback
    protected $onsuspect;
    //! Cache instance
     protected $_cache;

    /**
    *   Open session
    *   @return TRUE
    *   @param $path string
    *   @param $name string
    **/
    public function open($path, $name)
    {
        return true;
    }

    /**
    *   Close session
    *   @return TRUE
    **/
    public function close()
    {
        $this->sid = null;
        return true;
    }

    /**
    *   Return session data in serialized format
    *   @return string
    *   @param $id string
    **/
    public function read($id)
    {
        $this->sid = $id;
        if (!$data = $this->_cache->get($id . '.@')) {
            return '';
        }
        if ($data['ip'] != $this->_ip || $data['agent'] != $this->_agent) {
            $fw = Base::instance();
            if (
                !isset($this->onsuspect) ||
                $fw->call($this->onsuspect, [$this,$id]) === false
            ) {
                //NB: `session_destroy` can't be called at that stage (`session_start` not completed)
                $this->destroy($id);
                $this->close();
                unset($fw->{'COOKIE.' . session_name()});
                $fw->error(403);
            }
        }
        return $data['data'];
    }

    /**
    *   Write session data
    *   @return TRUE
    *   @param $id string
    *   @param $data string
    **/
    public function write($id, $data)
    {
        $fw = Base::instance();
        $jar = $fw->JAR;
        $this->_cache->set(
            $id . '.@',
            [
                'data' => $data,
                'ip' => $this->_ip,
                'agent' => $this->_agent,
                'stamp' => time()
            ],
            $jar['expire']
        );
        return true;
    }

    /**
    *   Destroy session
    *   @return TRUE
    *   @param $id string
    **/
    public function destroy($id)
    {
        $this->_cache->clear($id . '.@');
        return true;
    }

    /**
    *   Garbage collector
    *   @return TRUE
    *   @param $max int
    **/
    public function cleanup($max)
    {
        $this->_cache->reset('.@', $max);
        return true;
    }

    /**
     *  Return session id (if session has started)
     *  @return string|NULL
     **/
    public function sid()
    {
        return $this->sid;
    }

    /**
     *  Return anti-CSRF token
     *  @return string
     **/
    public function csrf()
    {
        return $this->_csrf;
    }

    /**
     *  Return IP address
     *  @return string
     **/
    public function ip()
    {
        return $this->_ip;
    }

    /**
     *  Return Unix timestamp
     *  @return string|FALSE
     **/
    public function stamp()
    {
        if (!$this->sid) {
            session_start();
        }
        return $this->_cache->exists($this->sid . '.@', $data) ?
            $data['stamp'] : false;
    }

    /**
     *  Return HTTP user agent
     *  @return string
     **/
    public function agent()
    {
        return $this->_agent;
    }

    /**
    *   Instantiate class
    *   @param $onsuspect callback
    *   @param $key string
    **/
    public function __construct($onsuspect = null, $key = null, $cache = null)
    {
        $this->onsuspect = $onsuspect;
        $this->_cache = $cache ?: Cache::instance();
        session_set_save_handler(
            [$this,'open'],
            [$this,'close'],
            [$this,'read'],
            [$this,'write'],
            [$this,'destroy'],
            [$this,'cleanup']
        );
        register_shutdown_function('session_commit');
        $fw = \Base::instance();
        $headers = $fw->HEADERS;
        $this->_csrf = $fw->SEED . '.' . $fw->hash(mt_rand());
        if ($key) {
            $fw->$key = $this->_csrf;
        }
        $this->_agent = isset($headers['User-Agent']) ? $headers['User-Agent'] : '';
        $this->_ip = $fw->IP;
    }
}
