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

namespace DB\SQL;

//! SQL-managed session handler
class Session extends Mapper
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

    /**
     *   Open session
     * @param $path string
     * @param $name string
     *              *@return TRUE
     */
    public function open($path, $name)
    {
        return true;
    }

    /**
     *   Close session
     * @return TRUE
     **/
    public function close()
    {
        $this->reset();
        $this->sid = null;
        return true;
    }

    /**
     *   Return session data in serialized format
     * @param $id string
     *            *@return string
     */
    public function read($id)
    {
        $this->load(['session_id=?', $this->sid = $id]);
        if ($this->dry()) {
            return '';
        }
        if ($this->get('ip') != $this->_ip || $this->get('agent') != $this->_agent) {
            $fw = \Base::instance();
            if (
                !isset($this->onsuspect) ||
                $fw->call($this->onsuspect, [$this, $id]) === false
            ) {
                //NB: `session_destroy` can't be called at that stage (`session_start` not completed)
                $this->destroy($id);
                $this->close();
                unset($fw->{'COOKIE.' . session_name()});
                $fw->error(403);
            }
        }
        return $this->get('data');
    }

    /**
     *   Write session data
     * @param $id   string
     * @param $data string
     *              *@return TRUE
     */
    public function write($id, $data)
    {
        $this->set('session_id', $id);
        $this->set('data', $data);
        $this->set('ip', $this->_ip);
        $this->set('agent', $this->_agent);
        $this->set('stamp', time());
        $this->save();
        return true;
    }

    /**
     *   Destroy session
     * @param $id string
     *            *@return TRUE
     */
    public function destroy($id)
    {
        $this->erase(['session_id=?', $id]);
        return true;
    }

    /**
     *   Garbage collector
     * @param $max int
     *             *@return TRUE
     */
    public function cleanup($max)
    {
        $this->erase(['stamp+?<?', $max, time()]);
        return true;
    }

    /**
     *   Return session id (if session has started)
     * @return string|NULL
     **/
    public function sid()
    {
        return $this->sid;
    }

    /**
     *   Return anti-CSRF token
     * @return string
     **/
    public function csrf()
    {
        return $this->_csrf;
    }

    /**
     *   Return IP address
     * @return string
     **/
    public function ip()
    {
        return $this->_ip;
    }

    /**
     *   Return Unix timestamp
     * @return string|FALSE
     **/
    public function stamp()
    {
        if (!$this->sid) {
            session_start();
        }
        return $this->dry() ? false : $this->get('stamp');
    }

    /**
     *   Return HTTP user agent
     * @return string
     **/
    public function agent()
    {
        return $this->_agent;
    }

    /**
     *   Instantiate class
     * @param $db        \DB\SQL
     * @param $table     string
     * @param $force     bool
     * @param $onsuspect callback
     * @param $key       string
     **/
    public function __construct(\DB\SQL $db, $table = 'sessions', $force = true, $onsuspect = null, $key = null)
    {
        if ($force) {
            $eol    = "\n";
            $tab    = "\t";
            $sqlsrv = preg_match('/mssql|sqlsrv|sybase/', $db->driver());
            $db->exec(
                ($sqlsrv ?
                    ('IF NOT EXISTS (SELECT * FROM sysobjects WHERE ' .
                        'name=' . $db->quote($table) . ' AND xtype=\'U\') ' .
                        'CREATE TABLE dbo.') :
                    ('CREATE TABLE IF NOT EXISTS ' .
                        ((($name = $db->name()) && $db->driver() != 'pgsql') ?
                            ($db->quotekey($name, false) . '.') : ''))) .
                $db->quotekey($table, false) . ' (' . $eol .
                ($sqlsrv ? $tab . $db->quotekey('id') . ' INT IDENTITY,' . $eol : '') .
                $tab . $db->quotekey('session_id') . ' VARCHAR(255),' . $eol .
                $tab . $db->quotekey('data') . ' TEXT,' . $eol .
                $tab . $db->quotekey('ip') . ' VARCHAR(45),' . $eol .
                $tab . $db->quotekey('agent') . ' VARCHAR(300),' . $eol .
                $tab . $db->quotekey('stamp') . ' INTEGER,' . $eol .
                $tab . 'PRIMARY KEY (' . $db->quotekey($sqlsrv ? 'id' : 'session_id') . ')' . $eol .
                ($sqlsrv ? ',CONSTRAINT [UK_session_id] UNIQUE(session_id)' : '') .
                ');'
            );
        }
        parent::__construct($db, $table);
        $this->onsuspect = $onsuspect;
        session_set_save_handler(
            [$this, 'open'],
            [$this, 'close'],
            [$this, 'read'],
            [$this, 'write'],
            [$this, 'destroy'],
            [$this, 'cleanup']
        );
        register_shutdown_function('session_commit');
        $fw          = \Base::instance();
        $headers     = $fw->HEADERS;
        $this->_csrf = $fw->SEED . '.' . $fw->hash(mt_rand());
        if ($key) {
            $fw->$key = $this->_csrf;
        }
        $this->_agent = isset($headers['User-Agent']) ? $headers['User-Agent'] : '';
        if (strlen($this->_agent) > 300) {
            $this->_agent = substr($this->_agent, 0, 300);
        }
        $this->_ip = $fw->IP;
    }
}
