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

//! Factory class for single-instance objects
abstract class Prefab
{

    /**
     *    Return class instance
     * @return static
     **/
    public static function instance()
    {
        if (!Registry::exists($class = get_called_class())) {
            $ref  = new Reflectionclass($class);
            $args = func_get_args();
            Registry::set($class, $args ? $ref->newinstanceargs($args) : new $class());
        }

        return Registry::get($class);
    }
}

//! Base structure
final class Base extends Prefab implements ArrayAccess
{

    //@{ Framework details
    const
        PACKAGE = 'Fat-Free Framework',
        VERSION = '3.6.3-Release';
    //@}

    //@{ HTTP status codes (RFC 2616)
    const
        HTTP_100 = 'Continue',
        HTTP_101 = 'Switching Protocols',
        HTTP_103 = 'Early Hints',
        HTTP_200 = 'OK',
        HTTP_201 = 'Created',
        HTTP_202 = 'Accepted',
        HTTP_203 = 'Non-Authorative Information',
        HTTP_204 = 'No Content',
        HTTP_205 = 'Reset Content',
        HTTP_206 = 'Partial Content',
        HTTP_300 = 'Multiple Choices',
        HTTP_301 = 'Moved Permanently',
        HTTP_302 = 'Found',
        HTTP_303 = 'See Other',
        HTTP_304 = 'Not Modified',
        HTTP_305 = 'Use Proxy',
        HTTP_307 = 'Temporary Redirect',
        HTTP_400 = 'Bad Request',
        HTTP_401 = 'Unauthorized',
        HTTP_402 = 'Payment Required',
        HTTP_403 = 'Forbidden',
        HTTP_404 = 'Not Found',
        HTTP_405 = 'Method Not Allowed',
        HTTP_406 = 'Not Acceptable',
        HTTP_407 = 'Proxy Authentication Required',
        HTTP_408 = 'Request Timeout',
        HTTP_409 = 'Conflict',
        HTTP_410 = 'Gone',
        HTTP_411 = 'Length Required',
        HTTP_412 = 'Precondition Failed',
        HTTP_413 = 'Request Entity Too Large',
        HTTP_414 = 'Request-URI Too Long',
        HTTP_415 = 'Unsupported Media Type',
        HTTP_416 = 'Requested Range Not Satisfiable',
        HTTP_417 = 'Expectation Failed',
        HTTP_500 = 'Internal Server Error',
        HTTP_501 = 'Not Implemented',
        HTTP_502 = 'Bad Gateway',
        HTTP_503 = 'Service Unavailable',
        HTTP_504 = 'Gateway Timeout',
        HTTP_505 = 'HTTP Version Not Supported';
    //@}

    const
        //! Mapped PHP globals
        GLOBALS = 'GET|POST|COOKIE|REQUEST|SESSION|FILES|SERVER|ENV',
        //! HTTP verbs
        VERBS = 'GET|HEAD|POST|PUT|PATCH|DELETE|CONNECT|OPTIONS',
        //! Default directory permissions
        MODE = 0755,
        //! Syntax highlighting stylesheet
        CSS = 'code.css';

    //@{ Request types
    const
        REQ_SYNC = 1,
        REQ_AJAX = 2,
        REQ_CLI = 4;
    //@}

    //@{ Error messages
    const
        E_PATTERN = 'Invalid routing pattern: %s',
        E_NAMED = 'Named route does not exist: %s',
        E_FATAL = 'Fatal error: %s',
        E_OPEN = 'Unable to open %s',
        E_ROUTES = 'No routes specified',
        E_CLASS = 'Invalid class %s',
        E_METHOD = 'Invalid method %s',
        E_HIVE = 'Invalid hive key %s';
    //@}

    //! Globals
    private $hive;
    //! Initial settings
    private $init;
    //! Language lookup sequence
    private $languages;
    //! Mutex locks
    private $locks = [];
    //! Default fallback language
    private $fallback = 'en';

    /**
     *    Sync PHP global with corresponding hive key
     * @param $key string
     *             *@return array
     */
    public function sync($key)
    {
        return $this->hive[$key] =& $GLOBALS['_' . $key];
    }

    /**
     *    Return the parts of specified hive key
     * @param $key string
     *             *@return array
     */
    private function cut($key)
    {
        return preg_split(
            '/\[\h*[\'"]?(.+?)[\'"]?\h*\]|(->)|\./',
            $key,
            null,
            PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE
        );
    }

    /**
     *    Replace tokenized URL with available token values
     * @param $url  array|string
     * @param $args array
     *              *@return string
     */
    public function build($url, $args = [])
    {
        $args += $this->hive['PARAMS'];
        if (is_array($url)) {
            foreach ($url as &$var) {
                $var = $this->build($var, $args);
                unset($var);
            }
        } else {
            $i   = 0;
            $url = preg_replace_callback(
                '/@(\w+)|(\*)/',
                function ($match) use (&$i, $args) {
                    if (
                        isset($match[1]) &&
                        array_key_exists($match[1], $args)
                    ) {
                        return $args[$match[1]];
                    }
                    if (
                        isset($match[2]) &&
                        array_key_exists($match[2], $args)
                    ) {
                        if (!is_array($args[$match[2]])) {
                            return $args[$match[2]];
                        }
                        $i++;
                        return $args[$match[2]][$i - 1];
                    }
                    return $match[0];
                },
                $url
            );
        }
        return $url;
    }

    /**
     *    Parse string containing key-value pairs
     * @param $str string
     * @return array
     */
    public function parse($str)
    {
        preg_match_all(
            '/(\w+|\*)\h*=\h*(?:\[(.+?)\]|(.+?))(?=,|$)/',
            $str,
            $pairs,
            PREG_SET_ORDER
        );
        $out = [];
        foreach ($pairs as $pair) {
            if ($pair[2]) {
                $out[$pair[1]] = [];
                foreach (explode(',', $pair[2]) as $val) {
                    array_push($out[$pair[1]], $val);
                }
            } else {
                $out[$pair[1]] = trim($pair[3]);
            }
        }
        return $out;
    }

    /**
     * cast string variable to php type or constant
     * @param $val
     * @return mixed
     */
    public function cast($val)
    {
        if (is_numeric($val)) {
            return $val + 0;
        }
        $val = trim($val);
        if (preg_match('/^\w+$/i', $val) && defined($val)) {
            return constant($val);
        }
        return $val;
    }

    /**
     *    Convert JS-style token to PHP expression
     * @param $str string
     *             *@return string
     */
    public function compile($str)
    {
        return preg_replace_callback(
            '/(?<!\w)@(\w+(?:(?:\->|::)\w+)?)' .
            '((?:\.\w+|\[(?:(?:[^\[\]]*|(?R))*)\]|(?:\->|::)\w+|\()*)/',
            function ($expr) {
                $str = '$' . $expr[1];
                if (isset($expr[2])) {
                    $str .= preg_replace_callback(
                        '/\.(\w+)(\()?|\[((?:[^\[\]]*|(?R))*)\]/',
                        function ($sub) {
                            if (empty($sub[2])) {
                                if (ctype_digit($sub[1])) {
                                    $sub[1] = (int)$sub[1];
                                }
                                $out = '[' .
                                    (isset($sub[3]) ?
                                        $this->compile($sub[3]) :
                                        var_export($sub[1], true)) .
                                    ']';
                            } else {
                                $out = function_exists($sub[1]) ?
                                    $sub[0] :
                                    ('[' . var_export($sub[1], true) . ']' . $sub[2]);
                            }
                            return $out;
                        },
                        $expr[2]
                    );
                }
                return $str;
            },
            $str
        );
    }

    /**
     *    Get hive key reference/contents; Add non-existent hive keys,
     *    array elements, and object properties by default
     * @param $key string
     * @param $add bool
     * @param $var mixed
     *             *@return mixed
     */
    public function &ref($key, $add = true, &$var = null)
    {
        $null  = null;
        $parts = $this->cut($key);
        if ($parts[0] == 'SESSION') {
            if (!headers_sent() && session_status() != PHP_SESSION_ACTIVE) {
                session_start();
            }
            $this->sync('SESSION');
        } elseif (!preg_match('/^\w+$/', $parts[0])) {
            user_error(
                sprintf(
                    self::E_HIVE,
                    $this->stringify($key)
                ),
                E_USER_ERROR
            );
        }
        if (is_null($var)) {
            if ($add) {
                $var =& $this->hive;
            } else {
                $var = $this->hive;
            }
        }
        $obj = false;
        foreach ($parts as $part) {
            if ($part == '->') {
                $obj = true;
            } elseif ($obj) {
                $obj = false;
                if (!is_object($var)) {
                    $var = new stdclass();
                }
                if ($add || property_exists($var, $part)) {
                    $var =& $var->$part;
                } else {
                    $var =& $null;
                    break;
                }
            } else {
                if (!is_array($var)) {
                    $var = [];
                }
                if ($add || array_key_exists($part, $var)) {
                    $var =& $var[$part];
                } else {
                    $var =& $null;
                    break;
                }
            }
        }
        return $var;
    }

    /**
     *    Return TRUE if hive key is set
     *    (or return timestamp and TTL if cached)
     * @param $key string
     * @param $val mixed
     *             *@return bool
     */
    public function exists($key, &$val = null)
    {
        $val = $this->ref($key, false);
        return isset($val) ?
            true :
            (Cache::instance()->exists($this->hash($key) . '.var', $val) ?: false);
    }

    /**
     *    Return TRUE if hive key is empty and not cached
     * @param $key string
     * @param $val mixed
     * @return bool
     **/
    public function devoid($key, &$val = null)
    {
        $val = $this->ref($key, false);
        return empty($val) &&
            (!Cache::instance()->exists($this->hash($key) . '.var', $val) ||
                !$val);
    }

    /**
     *    Bind value to hive key
     * @param $key string
     * @param $val mixed
     * @param $ttl int
     *             *@return mixed
     */
    public function set($key, $val, $ttl = 0)
    {
        $time = $this->hive['TIME'];
        if (preg_match('/^(GET|POST|COOKIE)\b(.+)/', $key, $expr)) {
            $this->set('REQUEST' . $expr[2], $val);
            if ($expr[1] == 'COOKIE') {
                $parts = $this->cut($key);
                $jar   = $this->unserialize($this->serialize($this->hive['JAR']));
                if (isset($_COOKIE[$parts[1]])) {
                    $jar['expire'] = 0;
                    call_user_func_array(
                        'setcookie',
                        array_merge([$parts[1], null], $jar)
                    );
                }
                if ($ttl) {
                    $jar['expire'] = $time + $ttl;
                }
                call_user_func_array('setcookie', [$parts[1], $val] + $jar);
                $_COOKIE[$parts[1]] = $val;
                return $val;
            }
        } else {
            switch ($key) {
                case 'CACHE':
                    $val = Cache::instance()->load($val);
                    break;
                case 'ENCODING':
                    ini_set('default_charset', $val);
                    if (extension_loaded('mbstring')) {
                        mb_internal_encoding($val);
                    }
                    break;
                case 'FALLBACK':
                    $this->fallback = $val;
                    $lang           = $this->language($this->hive['LANGUAGE']);
                //go on
                case 'LANGUAGE':
                    if (!isset($lang)) {
                        $val = $this->language($val);
                    }
                    $lex = $this->lexicon($this->hive['LOCALES'], $ttl);
                //go on
                case 'LOCALES':
                    if (isset($lex) || $lex = $this->lexicon($val, $ttl)) {
                        foreach ($lex as $dt => $dd) {
                            $ref =& $this->ref($this->hive['PREFIX'] . $dt);
                            $ref = $dd;
                            unset($ref);
                        }
                    }
                    break;
                case 'TZ':
                    date_default_timezone_set($val);
                    break;
            }
        }
        $ref =& $this->ref($key);
        $ref = $val;
        if (preg_match('/^JAR\b/', $key)) {
            $jar           = $this->unserialize($this->serialize($this->hive['JAR']));
            $jar['expire'] -= $time;
            call_user_func_array('session_set_cookie_params', $jar);
        }
        // Persist the key-value pair
        if ($ttl) {
            Cache::instance()->set($this->hash($key) . '.var', $val, $ttl);
        }
        return $ref;
    }

    /**
     *    Retrieve contents of hive key
     * @param $key  string
     * @param $args string|array
     *              *@return mixed
     */
    public function get($key, $args = null)
    {
        if (is_string($val = $this->ref($key, false)) && !is_null($args)) {
            return call_user_func_array(
                [$this, 'format'],
                array_merge([$val], is_array($args) ? $args : [$args])
            );
        }
        if (is_null($val)) {
            // Attempt to retrieve from cache
            if (Cache::instance()->exists($this->hash($key) . '.var', $data)) {
                return $data;
            }
        }
        return $val;
    }

    /**
     *    Unset hive key
     * @param $key string
     *             *@return NULL
     */
    public function clear($key)
    {
        // Normalize array literal
        $cache = Cache::instance();
        $parts = $this->cut($key);
        // Clear cache contents
        if ($key == 'CACHE') {
            $cache->reset();
        } elseif (preg_match('/^(GET|POST|COOKIE)\b(.+)/', $key, $expr)) {
            $this->clear('REQUEST' . $expr[2]);
            if ($expr[1] == 'COOKIE') {
                $parts         = $this->cut($key);
                $jar           = $this->hive['JAR'];
                $jar['expire'] = 0;
                call_user_func_array(
                    'setcookie',
                    array_merge([$parts[1], null], $jar)
                );
                unset($_COOKIE[$parts[1]]);
            }
        } elseif ($parts[0] == 'SESSION') {
            if (!headers_sent() && session_status() != PHP_SESSION_ACTIVE) {
                session_start();
            }
            if (empty($parts[1])) {
                // End session
                session_unset();
                session_destroy();
                $this->clear('COOKIE.' . session_name());
            }
            $this->sync('SESSION');
        }
        // Reset global to default value
        if (!isset($parts[1]) && array_key_exists($parts[0], $this->init)) {
            $this->hive[$parts[0]] = $this->init[$parts[0]];
        } else {
            eval('unset(' . $this->compile('@this->hive.' . $key) . ');');
            if ($parts[0] == 'SESSION') {
                session_commit();
                session_start();
            }
            // Remove from cache
            if ($cache->exists($hash = $this->hash($key) . '.var')) {
                $cache->clear($hash);
            }
        }
    }

    /**
     *    Return TRUE if hive variable is 'on'
     * @param $key string
     * @return bool
     */
    public function checked($key)
    {
        $ref =& $this->ref($key);
        return $ref == 'on';
    }

    /**
     *    Return TRUE if property has public visibility
     * @param $obj object
     * @param $key string
     *             *@return bool
     */
    public function visible($obj, $key)
    {
        if (property_exists($obj, $key)) {
            $ref = new ReflectionProperty(get_class($obj), $key);
            $out = $ref->ispublic();
            unset($ref);
            return $out;
        }
        return false;
    }

    /**
     *    Multi-variable assignment using associative array
     * @param $vars   array
     * @param $prefix string
     * @param $ttl    int
     *                *@return NULL
     */
    public function mset(array $vars, $prefix = '', $ttl = 0)
    {
        foreach ($vars as $key => $val) {
            $this->set($prefix . $key, $val, $ttl);
        }
    }

    /**
     *    Publish hive contents
     * @return array
     **/
    public function hive()
    {
        return $this->hive;
    }

    /**
     *    Copy contents of hive variable to another
     * @param $src string
     * @param $dst string
     *             *@return mixed
     */
    public function copy($src, $dst)
    {
        $ref =& $this->ref($dst);
        return $ref = $this->ref($src, false);
    }

    /**
     *    Concatenate string to hive string variable
     * @param $key string
     * @param $val string
     *             *@return string
     */
    public function concat($key, $val)
    {
        $ref =& $this->ref($key);
        $ref .= $val;
        return $ref;
    }

    /**
     *    Swap keys and values of hive array variable
     * @param $key string
     * @public
     *             *@return array
     */
    public function flip($key)
    {
        $ref =& $this->ref($key);
        return $ref = array_combine(array_values($ref), array_keys($ref));
    }

    /**
     *    Add element to the end of hive array variable
     * @param $key string
     * @param $val mixed
     *             *@return mixed
     */
    public function push($key, $val)
    {
        $ref   =& $this->ref($key);
        $ref[] = $val;
        return $val;
    }

    /**
     *    Remove last element of hive array variable
     * @param $key string
     *             *@return mixed
     */
    public function pop($key)
    {
        $ref =& $this->ref($key);
        return array_pop($ref);
    }

    /**
     *    Add element to the beginning of hive array variable
     * @param $key string
     * @param $val mixed
     *             *@return mixed
     */
    public function unshift($key, $val)
    {
        $ref =& $this->ref($key);
        array_unshift($ref, $val);
        return $val;
    }

    /**
     *    Remove first element of hive array variable
     * @param $key string
     *             *@return mixed
     */
    public function shift($key)
    {
        $ref =& $this->ref($key);
        return array_shift($ref);
    }

    /**
     *    Merge array with hive array variable
     * @param $key  string
     * @param $src  string|array
     * @param $keep bool
     *              *@return array
     */
    public function merge($key, $src, $keep = false)
    {
        $ref =& $this->ref($key);
        if (!$ref) {
            $ref = [];
        }
        $out = array_merge($ref, is_string($src) ? $this->hive[$src] : $src);
        if ($keep) {
            $ref = $out;
        }
        return $out;
    }

    /**
     *    Extend hive array variable with default values from $src
     * @param $key  string
     * @param $src  string|array
     * @param $keep bool
     *              *@return array
     */
    public function extend($key, $src, $keep = false)
    {
        $ref =& $this->ref($key);
        if (!$ref) {
            $ref = [];
        }
        $out = array_replace_recursive(
            is_string($src) ? $this->hive[$src] : $src,
            $ref
        );
        if ($keep) {
            $ref = $out;
        }
        return $out;
    }

    /**
     *    Convert backslashes to slashes
     * @param $str string
     *             *@return string
     */
    public function fixslashes($str)
    {
        return $str ? strtr($str, '\\', '/') : $str;
    }

    /**
     *    Split comma-, semi-colon, or pipe-separated string
     * @param $str     string
     * @param $noempty bool
     *                 *@return array
     */
    public function split($str, $noempty = true)
    {
        return array_map(
            'trim',
            preg_split('/[,;|]/', $str, 0, $noempty ? PREG_SPLIT_NO_EMPTY : 0)
        );
    }

    /**
     *    Convert PHP expression/value to compressed exportable string
     * @param $arg   mixed
     * @param $stack array
     *               *@return string
     */
    public function stringify($arg, array $stack = null)
    {
        if ($stack) {
            foreach ($stack as $node) {
                if ($arg === $node) {
                    return '*RECURSION*';
                }
            }
        } else {
            $stack = [];
        }
        switch (gettype($arg)) {
            case 'object':
                $str = '';
                foreach (get_object_vars($arg) as $key => $val) {
                    $str .= ($str ? ',' : '') .
                        var_export($key, true) . '=>' .
                        $this->stringify(
                            $val,
                            array_merge($stack, [$arg])
                        );
                }
                return get_class($arg) . '::__set_state([' . $str . '])';
            case 'array':
                $str = '';
                $num = isset($arg[0]) &&
                    ctype_digit(implode('', array_keys($arg)));
                foreach ($arg as $key => $val) {
                    $str .= ($str ? ',' : '') .
                        ($num ? '' : (var_export($key, true) . '=>')) .
                        $this->stringify($val, array_merge($stack, [$arg]));
                }
                return '[' . $str . ']';
            default:
                return var_export($arg, true);
        }
    }

    /**
     *    Flatten array values and return as CSV string
     * @param $args array
     *              *@return string
     */
    public function csv(array $args)
    {
        return implode(
            ',',
            array_map('stripcslashes', array_map([$this, 'stringify'], $args))
        );
    }

    /**
     *    Convert snakecase string to camelcase
     * @param $str string
     *             *@return string
     */
    public function camelcase($str)
    {
        return preg_replace_callback(
            '/_(\pL)/u',
            function ($match) {
                return strtoupper($match[1]);
            },
            $str
        );
    }

    /**
     *    Convert camelcase string to snakecase
     * @param $str string
     *             *@return string
     */
    public function snakecase($str)
    {
        return strtolower(preg_replace('/(?!^)\p{Lu}/u', '_\0', $str));
    }

    /**
     *    Return -1 if specified number is negative, 0 if zero,
     *    or 1 if the number is positive
     * @param $num mixed
     *             *@return int
     */
    public function sign($num)
    {
        return $num ? ($num / abs($num)) : 0;
    }

    /**
     *    Extract values of array whose keys start with the given prefix
     * @param $arr    array
     * @param $prefix string
     *                *@return array
     */
    public function extract($arr, $prefix)
    {
        $out = [];
        foreach (preg_grep('/^' . preg_quote($prefix, '/') . '/', array_keys($arr)) as $key) {
            $out[substr($key, strlen($prefix))] = $arr[$key];
        }
        return $out;
    }

    /**
     *    Convert class constants to array
     * @param $class  object|string
     * @param $prefix string
     *                *@return array
     */
    public function constants($class, $prefix = '')
    {
        $ref = new ReflectionClass($class);
        return $this->extract($ref->getconstants(), $prefix);
    }

    /**
     *    Generate 64bit/base36 hash
     * @param $str
     **@return string
     */
    public function hash($str)
    {
        return str_pad(
            base_convert(
                substr(sha1($str), -16),
                16,
                36
            ),
            11,
            '0',
            STR_PAD_LEFT
        );
    }

    /**
     *    Return Base64-encoded equivalent
     * @param $data string
     * @param $mime string
     *              *@return string
     */
    public function base64($data, $mime)
    {
        return 'data:' . $mime . ';base64,' . base64_encode($data);
    }

    /**
     *    Convert special characters to HTML entities
     * @param $str string
     *             *@return string
     */
    public function encode($str)
    {
        return @htmlspecialchars(
            $str,
            $this->hive['BITMASK'],
            $this->hive['ENCODING']
        ) ?: $this->scrub($str);
    }

    /**
     *    Convert HTML entities back to characters
     * @param $str string
     *             *@return string
     */
    public function decode($str)
    {
        return htmlspecialchars_decode($str, $this->hive['BITMASK']);
    }

    /**
     *    Invoke callback recursively for all data types
     * @param $arg   mixed
     * @param $func  callback
     * @param $stack array
     *               *@return mixed
     */
    public function recursive($arg, $func, $stack = [])
    {
        if ($stack) {
            foreach ($stack as $node) {
                if ($arg === $node) {
                    return $arg;
                }
            }
        }
        switch (gettype($arg)) {
            case 'object':
                $ref = new ReflectionClass($arg);
                if ($ref->iscloneable()) {
                    $arg  = clone($arg);
                    $cast = is_a($arg, 'IteratorAggregate') ?
                        iterator_to_array($arg) : get_object_vars($arg);
                    foreach ($cast as $key => $val) {
                        $arg->$key = $this->recursive(
                            $val,
                            $func,
                            array_merge($stack, [$arg])
                        );
                    }
                }
                return $arg;
            case 'array':
                $copy = [];
                foreach ($arg as $key => $val) {
                    $copy[$key] = $this->recursive(
                        $val,
                        $func,
                        array_merge($stack, [$arg])
                    );
                }
                return $copy;
        }
        return $func($arg);
    }

    /**
     *    Remove HTML tags (except those enumerated) and non-printable
     *    characters to mitigate XSS/code injection attacks
     * @param $arg  mixed
     * @param $tags string
     *              *@return mixed
     */
    public function clean($arg, $tags = null)
    {
        return $this->recursive(
            $arg,
            function ($val) use ($tags) {
                if ($tags != '*') {
                    $val = trim(
                        strip_tags(
                            $val,
                            '<' . implode('><', $this->split($tags)) . '>'
                        )
                    );
                }
                return trim(
                    preg_replace(
                        '/[\x00-\x08\x0B\x0C\x0E-\x1F]/',
                        '',
                        $val
                    )
                );
            }
        );
    }

    /**
     *    Similar to clean(), except that variable is passed by reference
     * @param $var  mixed
     * @param $tags string
     *              *@return mixed
     */
    public function scrub(&$var, $tags = null)
    {
        return $var = $this->clean($var, $tags);
    }

    /**
     *    Return locale-aware formatted string
     * @return string
     **/
    public function format()
    {
        $args = func_get_args();
        $val  = array_shift($args);
        // Get formatting rules
        $conv = localeconv();
        return preg_replace_callback(
            '/\{\s*(?P<pos>\d+)\s*(?:,\s*(?P<type>\w+)\s*' .
            '(?:,\s*(?P<mod>(?:\w+(?:\s*\{.+?\}\s*,?\s*)?)*)' .
            '(?:,\s*(?P<prop>.+?))?)?)?\s*\}/',
            function ($expr) use ($args, $conv) {
                extract($expr);
                extract($conv);
                if (!array_key_exists($pos, $args)) {
                    return $expr[0];
                }
                if (isset($type)) {
                    if (isset($this->hive['FORMATS'][$type])) {
                        return $this->call(
                            $this->hive['FORMATS'][$type],
                            [$args[$pos], isset($mod) ? $mod : null, isset($prop) ? $prop : null]
                        );
                    }
                    switch ($type) {
                        case 'plural':
                            preg_match_all(
                                '/(?<tag>\w+)' . '(?:\s*\{\s*(?<data>.+?)\s*\})/',
                                $mod,
                                $matches,
                                PREG_SET_ORDER
                            );
                            $ord = ['zero', 'one', 'two'];
                            foreach ($matches as $match) {
                                extract($match);
                                if (
                                    isset($ord[$args[$pos]]) &&
                                    $tag == $ord[$args[$pos]] || $tag == 'other'
                                ) {
                                    return str_replace('#', $args[$pos], $data);
                                }
                            }
                            //go on
                        case 'number':
                            if (isset($mod)) {
                                switch ($mod) {
                                    case 'integer':
                                        return number_format($args[$pos], 0, '', $thousands_sep);
                                    case 'currency':
                                        $int = $cstm = false;
                                        if (
                                            isset($prop) &&
                                            $cstm = !$int = ($prop == 'int')
                                        ) {
                                            $currency_symbol = $prop;
                                        }
                                        if (
                                            !$cstm &&
                                            function_exists('numfmt_format_currency')
                                        ) {
                                            $fmt = numfmt_create('de_DE', NumberFormatter::CURRENCY);
                                            return numfmt_format_currency($fmt, $args[$pos], 'EUR');
                                        }
                                        $fmt = [
                                            0   => '(nc)',
                                            1   => '(n c)',
                                            2   => '(nc)',
                                            10  => '+nc',
                                            11  => '+n c',
                                            12  => '+ nc',
                                            20  => 'nc+',
                                            21  => 'n c+',
                                            22  => 'nc +',
                                            30  => 'n+c',
                                            31  => 'n +c',
                                            32  => 'n+ c',
                                            40  => 'nc+',
                                            41  => 'n c+',
                                            42  => 'nc +',
                                            100 => '(cn)',
                                            101 => '(c n)',
                                            102 => '(cn)',
                                            110 => '+cn',
                                            111 => '+c n',
                                            112 => '+ cn',
                                            120 => 'cn+',
                                            121 => 'c n+',
                                            122 => 'cn +',
                                            130 => '+cn',
                                            131 => '+c n',
                                            132 => '+ cn',
                                            140 => 'c+n',
                                            141 => 'c+ n',
                                            142 => 'c +n',
                                        ];
                                        if ($args[$pos] < 0) {
                                            $sgn = $negative_sign;
                                            $pre = 'n';
                                        } else {
                                            $sgn = $positive_sign;
                                            $pre = 'p';
                                        }
                                        return str_replace(
                                            ['+', 'n', 'c'],
                                            [
                                                $sgn,
                                                number_format(
                                                    abs($args[$pos]),
                                                    $frac_digits,
                                                    $decimal_point,
                                                    $thousands_sep
                                                ),
                                                $int ? $int_curr_symbol : $currency_symbol,
                                            ],
                                            $fmt[(int)(
                                                (${$pre . '_cs_precedes'} % 2) .
                                                (${$pre . '_sign_posn'} % 5) .
                                                (${$pre . '_sep_by_space'} % 3)
                                            )]
                                        );
                                    case 'percent':
                                        return number_format(
                                            $args[$pos] * 100,
                                            0,
                                            $decimal_point,
                                            $thousands_sep
                                        ) . '%';
                                }
                            }
                            return number_format(
                                $args[$pos],
                                isset($prop) ? $prop : 2,
                                $decimal_point,
                                $thousands_sep
                            );
                        case 'date':
                            if (empty($mod) || $mod == 'short') {
                                $prop = '%x';
                            } elseif ($mod == 'long') {
                                $prop = '%A, %d %B %Y';
                            }
                            return strftime($prop, $args[$pos]);
                        case 'time':
                            if (empty($mod) || $mod == 'short') {
                                $prop = '%X';
                            }
                            return strftime($prop, $args[$pos]);
                        default:
                            return $expr[0];
                    }
                }
                return $args[$pos];
            },
            $val
        );
    }

    /**
     *    Assign/auto-detect language
     * @param $code string
     *              *@return string
     */
    public function language($code)
    {
        $code            = preg_replace('/\h+|;q=[0-9.]+/', '', $code);
        $code            .= ($code ? ',' : '') . $this->fallback;
        $this->languages = [];
        foreach (array_reverse(explode(',', $code)) as $lang) {
            if (preg_match('/^(\w{2})(?:-(\w{2}))?\b/i', $lang, $parts)) {
                // Generic language
                array_unshift($this->languages, $parts[1]);
                if (isset($parts[2])) {
                    // Specific language
                    $parts[0] = $parts[1] . '-' . ($parts[2] = strtoupper($parts[2]));
                    array_unshift($this->languages, $parts[0]);
                }
            }
        }
        $this->languages = array_unique($this->languages);
        $locales         = [];
        $windows         = preg_match('/^win/i', PHP_OS);
        // Work around PHP's Turkish locale bug
        foreach (preg_grep('/^(?!tr)/i', $this->languages) as $locale) {
            if ($windows) {
                $parts  = explode('-', $locale);
                $locale = @constant('ISO::LC_' . $parts[0]);
                if (
                    isset($parts[1]) &&
                    $country = @constant('ISO::CC_' . strtolower($parts[1]))
                ) {
                    $locale .= '-' . $country;
                }
            }
            $locale    = str_replace('-', '_', $locale);
            $locales[] = $locale . '.' . ini_get('default_charset');
            $locales[] = $locale;
        }
        setlocale(LC_ALL, $locales);
        return $this->hive['LANGUAGE'] = implode(',', $this->languages);
    }

    /**
     *    Return lexicon entries
     * @param $path string
     * @param $ttl  int
     *              *@return array
     */
    public function lexicon($path, $ttl = 0)
    {
        $languages = $this->languages ?: explode(',', $this->fallback);
        $cache     = Cache::instance();
        if (
            $cache->exists($hash = $this->hash(implode(',', $languages)) . '.dic', $lex)
        ) {
            return $lex;
        }
        $lex = [];
        foreach ($languages as $lang) {
            foreach ($this->split($path) as $dir) {
                if (
                    (is_file($file = ($base = $dir . $lang) . '.php') || is_file($file = $base . '.php')) &&
                    is_array($dict = require($file))
                ) {
                    $lex += $dict;
                } elseif (is_file($file = $base . '.ini')) {
                    preg_match_all(
                        '/(?<=^|\n)(?:' .
                        '\[(?<prefix>.+?)\]|' .
                        '(?<lval>[^\h\r\n;].*?)\h*=\h*' .
                        '(?<rval>(?:\\\\\h*\r?\n|.+?)*)' .
                        ')(?=\r?\n|$)/',
                        $this->read($file),
                        $matches,
                        PREG_SET_ORDER
                    );
                    if ($matches) {
                        $prefix = '';
                        foreach ($matches as $match) {
                            if ($match['prefix']) {
                                $prefix = $match['prefix'] . '.';
                            } elseif (
                                !array_key_exists($key = $prefix . $match['lval'], $lex)
                            ) {
                                $lex[$key] = trim(
                                    preg_replace('/\\\\\h*\r?\n/', "\n", $match['rval'])
                                );
                            }
                        }
                    }
                }
            }
        }
        if ($ttl) {
            $cache->set($hash, $lex, $ttl);
        }
        return $lex;
    }

    /**
     *    Return string representation of PHP value
     * @param $arg mixed
     *             *@return string
     */
    public function serialize($arg)
    {
        switch (strtolower($this->hive['SERIALIZER'])) {
            case 'igbinary':
                return igbinary_serialize($arg);
            default:
                return serialize($arg);
        }
    }

    /**
     *    Return PHP value derived from string
     * @param $arg mixed
     *             *@return string
     */
    public function unserialize($arg)
    {
        switch (strtolower($this->hive['SERIALIZER'])) {
            case 'igbinary':
                return igbinary_unserialize($arg);
            default:
                return unserialize($arg);
        }
    }

    /**
     *    Send HTTP status header; Return text equivalent of status code
     * @param $code int
     *              *@return string
     */
    public function status($code)
    {
        $reason = @constant('self::HTTP_' . $code);
        if (!$this->hive['CLI'] && !headers_sent()) {
            header($_SERVER['SERVER_PROTOCOL'] . ' ' . $code . ' ' . $reason);
        }
        return $reason;
    }

    /**
     *    Send cache metadata to HTTP client
     * @param $secs int
     *              *@return NULL
     */
    public function expire($secs = 0)
    {
        if (!$this->hive['CLI'] && !headers_sent()) {
            $secs = (int)$secs;
            if ($this->hive['PACKAGE']) {
                header('X-Powered-By: ' . $this->hive['PACKAGE']);
            }
            if ($this->hive['XFRAME']) {
                header('X-Frame-Options: ' . $this->hive['XFRAME']);
            }
            header('X-XSS-Protection: 1; mode=block');
            header('X-Content-Type-Options: nosniff');
            if ($this->hive['VERB'] == 'GET' && $secs) {
                $time = microtime(true);
                header_remove('Pragma');
                header('Cache-Control: max-age=' . $secs);
                header('Expires: ' . gmdate('r', $time + $secs));
                header('Last-Modified: ' . gmdate('r'));
            } else {
                header('Pragma: no-cache');
                header('Cache-Control: no-cache, no-store, must-revalidate');
                header('Expires: ' . gmdate('r', 0));
            }
        }
    }

    /**
     *    Return HTTP user agent
     * @return string
     **/
    public function agent()
    {
        $headers = $this->hive['HEADERS'];
        return isset($headers['X-Operamini-Phone-UA']) ?
            $headers['X-Operamini-Phone-UA'] :
            (isset($headers['X-Skyfire-Phone']) ?
                $headers['X-Skyfire-Phone'] :
                (isset($headers['User-Agent']) ?
                    $headers['User-Agent'] : ''));
    }

    /**
     *    Return TRUE if XMLHttpRequest detected
     * @return bool
     **/
    public function ajax()
    {
        $headers = $this->hive['HEADERS'];
        return isset($headers['X-Requested-With']) &&
            $headers['X-Requested-With'] == 'XMLHttpRequest';
    }

    /**
     *    Sniff IP address
     * @return string
     **/
    public function ip()
    {
        $headers = $this->hive['HEADERS'];
        return isset($headers['Client-IP']) ?
            $headers['Client-IP'] :
            (isset($headers['X-Forwarded-For']) ?
                explode(',', $headers['X-Forwarded-For'])[0] :
                (isset($_SERVER['REMOTE_ADDR']) ?
                    $_SERVER['REMOTE_ADDR'] : ''));
    }

    /**
     *    Return filtered stack trace as a formatted string (or array)
     * @param $trace  array|NULL
     * @param $format bool
     *                *@return string|array
     */
    public function trace(array $trace = null, $format = true)
    {
        if (!$trace) {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            $frame = $trace[0];
            if (isset($frame['file']) && $frame['file'] == __FILE__) {
                array_shift($trace);
            }
        }
        $debug = $this->hive['DEBUG'];
        $trace = array_filter(
            $trace,
            function ($frame) use ($debug) {
                return isset($frame['file']) &&
                    ($debug > 1 ||
                        ($frame['file'] != __FILE__ || $debug) &&
                        (empty($frame['function']) ||
                            !preg_match('/^(?:(?:trigger|user)_error|' .
                                '__call|call_user_func)/', $frame['function'])));
            }
        );
        if (!$format) {
            return $trace;
        }
        $out = '';
        $eol = "\n";
        // Analyze stack trace
        foreach ($trace as $frame) {
            $line = '';
            if (isset($frame['class'])) {
                $line .= $frame['class'] . $frame['type'];
            }
            if (isset($frame['function'])) {
                $line .= $frame['function'] . '(' .
                    ($debug > 2 && isset($frame['args']) ?
                        $this->csv($frame['args']) : '') . ')';
            }
            $src = $this->fixslashes(str_replace($_SERVER['DOCUMENT_ROOT'] .
                    '/', '', $frame['file'])) . ':' . $frame['line'];
            $out .= '[' . $src . '] ' . $line . $eol;
        }
        return $out;
    }

    /**
     *    Log error; Execute ONERROR handler if defined, else display
     *    default error page (HTML for synchronous requests, JSON string
     *    for AJAX requests)
     * @param $code  int
     * @param $text  string
     * @param $trace array
     * @param $level int
     *               *@return NULL
     */
    public function error($code, $text = '', array $trace = null, $level = 0)
    {
        $prior  = $this->hive['ERROR'];
        $header = $this->status($code);
        $req    = $this->hive['VERB'] . ' ' . $this->hive['PATH'];
        if ($this->hive['QUERY']) {
            $req .= '?' . $this->hive['QUERY'];
        }
        if (!$text) {
            $text = 'HTTP ' . $code . ' (' . $req . ')';
        }
        error_log($text);
        $trace = $this->trace($trace);
        foreach (explode("\n", $trace) as $nexus) {
            if ($nexus) {
                error_log($nexus);
            }
        }
        if (
            $highlight = !$this->hive['CLI'] &&
            !$this->hive['AJAX'] &&
            $this->hive['HIGHLIGHT'] &&
            is_file($css = __DIR__ . '/' . self::CSS)
        ) {
            $trace = $this->highlight($trace);
        }
        $this->hive['ERROR'] = [
            'status' => $header,
            'code'   => $code,
            'text'   => $text,
            'trace'  => $trace,
            'level'  => $level,
        ];
        $this->expire(-1);
        $handler               = $this->hive['ONERROR'];
        $this->hive['ONERROR'] = null;
        $eol                   = "\n";
        if (
            (
                !$handler ||
                $this->call($handler, [$this, $this->hive['PARAMS']], 'beforeroute,afterroute') === false
            ) &&
            !$prior &&
            !$this->hive['CLI'] &&
            !$this->hive['QUIET']
        ) {
            echo $this->hive['AJAX'] ?
                json_encode(array_diff_key($this->hive['ERROR'], $this->hive['DEBUG'] ? [] : ['trace' => 1])) :
                ('<!DOCTYPE html>' . $eol .
                    '<html>' . $eol .
                    '<head>' .
                    '<title>' . $code . ' ' . $header . '</title>' .
                    ($highlight ?
                        ('<style>' . $this->read($css) . '</style>') : '') .
                    '</head>' . $eol .
                    '<body>' . $eol .
                    '<h1>' . $header . '</h1>' . $eol .
                    '<p>' . $this->encode($text ?: $req) . '</p>' . $eol .
                    ($this->hive['DEBUG'] ? ('<pre>' . $trace . '</pre>' . $eol) : '') .
                    '</body>' . $eol .
                    '</html>');
        }
        if ($this->hive['HALT']) {
            die(1);
        }
    }

    /**
     *    Mock HTTP request
     * @param $pattern string
     * @param $args    array
     * @param $headers array
     * @param $body    string
     *                 *@return mixed
     */
    public function mock(
        $pattern,
        array $args = null,
        array $headers = null,
        $body = null
    ) {
        if (!$args) {
            $args = [];
        }
        $types = ['sync', 'ajax', 'cli'];
        preg_match('/([\|\w]+)\h+(?:@(\w+)(?:(\(.+?)\))*|([^\h]+))' .
            '(?:\h+\[(' . implode('|', $types) . ')\])?/', $pattern, $parts);
        $verb = strtoupper($parts[1]);
        if ($parts[2]) {
            if (empty($this->hive['ALIASES'][$parts[2]])) {
                user_error(sprintf(self::E_NAMED, $parts[2]), E_USER_ERROR);
            }
            $parts[4] = $this->hive['ALIASES'][$parts[2]];
            $parts[4] = $this->build(
                $parts[4],
                isset($parts[3]) ? $this->parse($parts[3]) : []
            );
        }
        if (empty($parts[4])) {
            user_error(sprintf(self::E_PATTERN, $pattern), E_USER_ERROR);
        }
        $url = parse_url($parts[4]);
        parse_str(@$url['query'], $GLOBALS['_GET']);
        if (preg_match('/GET|HEAD/', $verb)) {
            $GLOBALS['_GET'] = array_merge($GLOBALS['_GET'], $args);
        }
        $GLOBALS['_POST']    = $verb == 'POST' ? $args : [];
        $GLOBALS['_REQUEST'] = array_merge($GLOBALS['_GET'], $GLOBALS['_POST']);
        foreach ($headers ?: [] as $key => $val) {
            $_SERVER['HTTP_' . strtr(strtoupper($key), '-', '_')] = $val;
        }
        $this->hive['VERB'] = $verb;
        $this->hive['PATH'] = $url['path'];
        $this->hive['URI']  = $this->hive['BASE'] . $url['path'];
        if ($GLOBALS['_GET']) {
            $this->hive['URI'] .= '?' . http_build_query($GLOBALS['_GET']);
        }
        $this->hive['BODY'] = '';
        if (!preg_match('/GET|HEAD/', $verb)) {
            $this->hive['BODY'] = $body ?: http_build_query($args);
        }
        $this->hive['AJAX'] = isset($parts[5]) &&
            preg_match('/ajax/i', $parts[5]);
        $this->hive['CLI']  = isset($parts[5]) &&
            preg_match('/cli/i', $parts[5]);
        return $this->run();
    }

    /**
     *    Assemble url from alias name
     * @param $name   string
     * @param $params array|string
     * @param $query  string|array
     *                *@return string
     */
    public function alias($name, $params = [], $query = null)
    {
        if (!is_array($params)) {
            $params = $this->parse($params);
        }
        if (empty($this->hive['ALIASES'][$name])) {
            user_error(sprintf(self::E_NAMED, $name), E_USER_ERROR);
        }
        $url = $this->build($this->hive['ALIASES'][$name], $params);
        if (is_array($query)) {
            $query = http_build_query($query);
        }
        return $url . ($query ? ('?' . $query) : '');
    }

    /**
     *    Bind handler to route pattern
     * @param $pattern string|array
     * @param $handler callback
     * @param $ttl     int
     * @param $kbps    int
     *                 *@return NULL
     */
    public function route($pattern, $handler, $ttl = 0, $kbps = 0)
    {
        $types = ['sync', 'ajax', 'cli'];
        $alias = null;
        if (is_array($pattern)) {
            foreach ($pattern as $item) {
                $this->route($item, $handler, $ttl, $kbps);
            }
            return;
        }
        preg_match('/([\|\w]+)\h+(?:(?:@?(.+?)\h*:\h*)?(@(\w+)|[^\h]+))' .
            '(?:\h+\[(' . implode('|', $types) . ')\])?/u', $pattern, $parts);
        if (isset($parts[2]) && $parts[2]) {
            $this->hive['ALIASES'][$alias = $parts[2]] = $parts[3];
        } elseif (!empty($parts[4])) {
            if (empty($this->hive['ALIASES'][$parts[4]])) {
                user_error(sprintf(self::E_NAMED, $parts[4]), E_USER_ERROR);
            }
            $parts[3] = $this->hive['ALIASES'][$alias = $parts[4]];
        }
        if (empty($parts[3])) {
            user_error(sprintf(self::E_PATTERN, $pattern), E_USER_ERROR);
        }
        $type = empty($parts[5]) ? 0 : constant('self::REQ_' . strtoupper($parts[5]));
        foreach ($this->split($parts[1]) as $verb) {
            if (!preg_match('/' . self::VERBS . '/', $verb)) {
                $this->error(501, $verb . ' ' . $this->hive['URI']);
            }
            $this->hive['ROUTES'][$parts[3]][$type][strtoupper($verb)] =
                [$handler, $ttl, $kbps, $alias];
        }
    }

    /**
     *    Reroute to specified URI
     * @param $url       array|string
     * @param $permanent bool
     * @param $die       bool
     *                   *@return NULL
     */
    public function reroute($url = null, $permanent = false, $die = true)
    {
        if (!$url) {
            $url = $this->hive['REALM'];
        }
        if (is_array($url)) {
            $url = call_user_func_array([$this, 'alias'], $url);
        } elseif (
            preg_match(
                '/^(?:@?([^\/()?]+)(?:(\(.+?)\))*(\?.+)*)/',
                $url,
                $parts
            ) &&
            isset($this->hive['ALIASES'][$parts[1]])
        ) {
            $url = $this->hive['ALIASES'][$parts[1]];
        }
        $url = $this->build(
            $url,
            isset($parts[2]) ? $this->parse($parts[2]) : []
        ) . (isset($parts[3]) ? $parts[3] : '');
        if (
            ($handler = $this->hive['ONREROUTE']) &&
            $this->call($handler, [$url, $permanent]) !== false
        ) {
            return;
        }
        if (
            $url[0] == '/' &&
            (empty($url[1]) || $url[1] != '/')
        ) {
            $port = $this->hive['PORT'];
            $port = in_array($port, [80, 443]) ? '' : (':' . $port);
            $url  = $this->hive['SCHEME'] . '://' .
                $this->hive['HOST'] . $port . $this->hive['BASE'] . $url;
        }
        if ($this->hive['CLI']) {
            $this->mock('GET ' . $url . ' [cli]');
        } else {
            header('Location: ' . $url);
            $this->status($permanent ? 301 : 302);
            if ($die) {
                die;
            }
        }
    }

    /**
     *    Provide ReST interface by mapping HTTP verb to class method
     * @param $url   string
     * @param $class string|object
     * @param $ttl   int
     * @param $kbps  int
     *               *@return NULL
     */
    public function map($url, $class, $ttl = 0, $kbps = 0)
    {
        if (is_array($url)) {
            foreach ($url as $item) {
                $this->map($item, $class, $ttl, $kbps);
            }
            return;
        }
        foreach (explode('|', self::VERBS) as $method) {
            $this->route(
                $method . ' ' . $url,
                is_string($class) ?
                    $class . '->' . $this->hive['PREMAP'] . strtolower($method) :
                    [$class, $this->hive['PREMAP'] . strtolower($method)],
                $ttl,
                $kbps
            );
        }
    }

    /**
     *    Redirect a route to another URL
     * @param $pattern   string|array
     * @param $url       string
     * @param $permanent bool
     * @return NULL
     */
    public function redirect($pattern, $url, $permanent = true)
    {
        if (is_array($pattern)) {
            foreach ($pattern as $item) {
                $this->redirect($item, $url, $permanent);
            }
            return;
        }
        $this->route($pattern, function ($fw) use ($url, $permanent) {
            $fw->reroute($url, $permanent);
        });
    }

    /**
     *    Return TRUE if IPv4 address exists in DNSBL
     * @param $ip string
     *            *@return bool
     */
    public function blacklisted($ip)
    {
        if (
            $this->hive['DNSBL'] &&
            !in_array(
                $ip,
                is_array($this->hive['EXEMPT']) ?
                    $this->hive['EXEMPT'] :
                    $this->split($this->hive['EXEMPT'])
            )
        ) {
            // Reverse IPv4 dotted quad
            $rev = implode('.', array_reverse(explode('.', $ip)));
            foreach (
                is_array($this->hive['DNSBL']) ?
                     $this->hive['DNSBL'] :
                     $this->split($this->hive['DNSBL']) as $server
            ) {
                // DNSBL lookup
                if (checkdnsrr($rev . '.' . $server, 'A')) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     *    Applies the specified URL mask and returns parameterized matches
     * @param $pattern string
     * @param $url     string|NULL
     *                 *@return $args array
     */
    public function mask($pattern, $url = null)
    {
        if (!$url) {
            $url = $this->rel($this->hive['URI']);
        }
        $case = $this->hive['CASELESS'] ? 'i' : '';
        $wild = preg_quote($pattern, '/');
        $i    = 0;
        while (is_int($pos = strpos($wild, '\*'))) {
            $wild = substr_replace($wild, '(?P<_' . $i . '>[^\?]*)', $pos, 2);
            $i++;
        }
        preg_match(
            '/^' .
            preg_replace(
                '/((\\\{)?@(\w+\b)(?(2)\\\}))/',
                '(?P<\3>[^\/\?]+)',
                $wild
            ) . '\/?$/' . $case . 'um',
            $url,
            $args
        );
        foreach (array_keys($args) as $key) {
            if (preg_match('/^_\d+$/', $key)) {
                if (empty($args['*'])) {
                    $args['*'] = $args[$key];
                } else {
                    if (is_string($args['*'])) {
                        $args['*'] = [$args['*']];
                    }
                    array_push($args['*'], $args[$key]);
                }
                unset($args[$key]);
            } elseif (is_numeric($key) && $key) {
                unset($args[$key]);
            }
        }
        return $args;
    }

    /**
     *    Match routes against incoming URI
     * @return mixed
     **/
    public function run()
    {
        // Spammer detected
        if ($this->blacklisted($this->hive['IP'])) {
            $this->error(403);
        }
        // No routes defined
        if (!$this->hive['ROUTES']) {
            user_error(self::E_ROUTES, E_USER_ERROR);
        }
        // Match specific routes first
        $paths = [];
        foreach ($keys = array_keys($this->hive['ROUTES']) as $key) {
            $path = preg_replace('/@\w+/', '*@', $key);
            if (substr($path, -1) != '*') {
                $path .= '+';
            }
            $paths[] = $path;
        }
        $vals = array_values($this->hive['ROUTES']);
        array_multisort($paths, SORT_DESC, $keys, $vals);
        $this->hive['ROUTES'] = array_combine($keys, $vals);
        // Convert to BASE-relative URL
        $req       = urldecode($this->hive['PATH']);
        $preflight = false;
        if (
            $cors = (
                isset($this->hive['HEADERS']['Origin']) &&
                $this->hive['CORS']['origin']
            )
        ) {
            $cors = $this->hive['CORS'];
            header('Access-Control-Allow-Origin: ' . $cors['origin']);
            header('Access-Control-Allow-Credentials: ' .
                var_export($cors['credentials'], true));
            $preflight =
                isset($this->hive['HEADERS']['Access-Control-Request-Method']);
        }
        $allowed = [];
        foreach ($this->hive['ROUTES'] as $pattern => $routes) {
            if (!$args = $this->mask($pattern, $req)) {
                continue;
            }
            ksort($args);
            $route = null;
            $ptr   = $this->hive['CLI'] ? self::REQ_CLI : $this->hive['AJAX'] + 1;
            if (
                isset($routes[$ptr][$this->hive['VERB']]) ||
                isset($routes[$ptr = 0])
            ) {
                $route = $routes[$ptr];
            }
            if (!$route) {
                continue;
            }
            if (isset($route[$this->hive['VERB']]) && !$preflight) {
                if (
                    $this->hive['VERB'] == 'GET' &&
                    preg_match(
                        '/.+\/$/',
                        $this->hive['PATH']
                    )
                ) {
                    $this->reroute(
                        substr($this->hive['PATH'], 0, -1) .
                        ($this->hive['QUERY'] ? ('?' . $this->hive['QUERY']) : '')
                    );
                }
                list($handler, $ttl, $kbps, $alias) = $route[$this->hive['VERB']];
                // Capture values of route pattern tokens
                $this->hive['PARAMS'] = $args;
                // Save matching route
                $this->hive['ALIAS']   = $alias;
                $this->hive['PATTERN'] = $pattern;
                if ($cors && $cors['expose']) {
                    header(
                        'Access-Control-Expose-Headers: ' .
                        (is_array($cors['expose']) ? implode(',', $cors['expose']) : $cors['expose'])
                    );
                }
                if (is_string($handler)) {
                    // Replace route pattern tokens in handler if any
                    $handler = preg_replace_callback(
                        '/({)?@(\w+\b)(?(1)})/',
                        function ($id) use ($args) {
                            $pid = count($id) > 2 ? 2 : 1;
                            return isset($args[$id[$pid]]) ?
                                $args[$id[$pid]] :
                                $id[0];
                        },
                        $handler
                    );
                    if (
                        preg_match(
                            '/(.+)\h*(?:->|::)/',
                            $handler,
                            $match
                        ) &&
                        !class_exists($match[1])
                    ) {
                        $this->error(404);
                    }
                }
                // Process request
                $result = null;
                $body   = '';
                $now    = microtime(true);
                if (preg_match('/GET|HEAD/', $this->hive['VERB']) && $ttl) {
                    // Only GET and HEAD requests are cacheable
                    $headers = $this->hive['HEADERS'];
                    $cache   = Cache::instance();
                    $cached  = $cache->exists(
                        $hash = $this->hash($this->hive['VERB'] . ' ' . $this->hive['URI']) . '.url',
                        $data
                    );
                    if ($cached) {
                        if (
                            isset($headers['If-Modified-Since']) &&
                            strtotime($headers['If-Modified-Since']) +
                            $ttl > $now
                        ) {
                            $this->status(304);
                            die;
                        }
                        // Retrieve from cache backend
                        list($headers, $body, $result) = $data;
                        if (!$this->hive['CLI']) {
                            array_walk($headers, 'header');
                        }
                        $this->expire($cached[0] + $ttl - $now);
                    } else {
                        // Expire HTTP client-cached page
                        $this->expire($ttl);
                    }
                } else {
                    $this->expire(0);
                }
                if (!strlen($body)) {
                    if (!$this->hive['RAW'] && !$this->hive['BODY']) {
                        $this->hive['BODY'] = file_get_contents('php://input');
                    }
                    ob_start();
                    // Call route handler
                    $result = $this->call(
                        $handler,
                        [$this, $args, $handler],
                        'beforeroute,afterroute'
                    );
                    $body   = ob_get_clean();
                    if (isset($cache) && !error_get_last()) {
                        // Save to cache backend
                        $cache->set(
                            $hash,
                            [
                                // Remove cookies
                                preg_grep(
                                    '/Set-Cookie\:/',
                                    headers_list(),
                                    PREG_GREP_INVERT
                                ),
                                $body,
                                $result,
                            ],
                            $ttl
                        );
                    }
                }
                $this->hive['RESPONSE'] = $body;
                if (!$this->hive['QUIET']) {
                    if ($kbps) {
                        $ctr = 0;
                        foreach (str_split($body, 1024) as $part) {
                            // Throttle output
                            $ctr++;
                            if (
                                $ctr / $kbps > ($elapsed = microtime(true) - $now) &&
                                !connection_aborted()
                            ) {
                                usleep(1e6 * ($ctr / $kbps - $elapsed));
                            }
                            echo $part;
                        }
                    } else {
                        echo $body;
                    }
                }
                if ($result || $this->hive['VERB'] != 'OPTIONS') {
                    return $result;
                }
            }
            $allowed = array_merge($allowed, array_keys($route));
        }
        // URL doesn't match any route
        if (!$allowed) {
            $this->error(404);
        } elseif (!$this->hive['CLI']) {
            // Unhandled HTTP method
            header('Allow: ' . implode(',', array_unique($allowed)));
            if ($cors) {
                header(
                    'Access-Control-Allow-Methods: OPTIONS,' .
                    implode(',', $allowed)
                );
                if ($cors['headers']) {
                    header(
                        'Access-Control-Allow-Headers: ' .
                        (
                            is_array($cors['headers']) ?
                            implode(',', $cors['headers']) :
                            $cors['headers']
                        )
                    );
                }
                if ($cors['ttl'] > 0) {
                    header('Access-Control-Max-Age: ' . $cors['ttl']);
                }
            }
            if ($this->hive['VERB'] != 'OPTIONS') {
                $this->error(405);
            }
        }
        return false;
    }

    /**
     *    Loop until callback returns TRUE (for long polling)
     * @param $func    callback
     * @param $args    array
     * @param $timeout int
     *                 *@return mixed
     */
    public function until($func, $args = null, $timeout = 60)
    {
        if (!$args) {
            $args = [];
        }
        $time  = time();
        $max   = ini_get('max_execution_time');
        $limit = max(0, ($max ? min($timeout, $max) : $timeout) - 1);
        $out   = '';
        // Turn output buffering on
        ob_start();
        // Not for the weak of heart
        while (
            // No error occurred
            !$this->hive['ERROR'] &&
            // Got time left?
            time() - $time + 1 < $limit &&
            // Still alive?
            !connection_aborted() &&
            // Restart session
            !headers_sent() &&
            (session_status() == PHP_SESSION_ACTIVE || session_start()) &&
            // CAUTION: Callback will kill host if it never becomes truthy!
            !$out = $this->call($func, $args)
        ) {
            if (!$this->hive['CLI']) {
                session_commit();
            }
            // Hush down
            sleep(1);
        }
        ob_flush();
        flush();
        return $out;
    }

    /**
     *    Disconnect HTTP client
     **/
    public function abort()
    {
        if (!headers_sent() && session_status() != PHP_SESSION_ACTIVE) {
            session_start();
        }
        $out = '';
        while (ob_get_level()) {
            $out = ob_get_clean() . $out;
        }
        header('Content-Encoding: none');
        header('Content-Length: ' . strlen($out));
        header('Connection: close');
        session_commit();
        echo $out;
        flush();
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }

    /**
     *    Grab the real route handler behind the string expression
     * @param $func string
     * @param $args array
     *              *@return string|array
     */
    public function grab($func, $args = null)
    {
        if (preg_match('/(.+)\h*(->|::)\h*(.+)/s', $func, $parts)) {
            // Convert string to executable PHP callback
            if (!class_exists($parts[1])) {
                user_error(sprintf(self::E_CLASS, $parts[1]), E_USER_ERROR);
            }
            if ($parts[2] == '->') {
                if (is_subclass_of($parts[1], 'Prefab')) {
                    $parts[1] = call_user_func($parts[1] . '::instance');
                } else {
                    $ref      = new ReflectionClass($parts[1]);
                    $parts[1] = method_exists($parts[1], '__construct') && $args ?
                        $ref->newinstanceargs($args) :
                        $ref->newinstance();
                }
            }
            $func = [$parts[1], $parts[3]];
        }
        return $func;
    }

    /**
     *    Execute callback/hooks (supports 'class->method' format)
     * @param $func  callback
     * @param $args  mixed
     * @param $hooks string
     *               *@return mixed|FALSE
     */
    public function call($func, $args = null, $hooks = '')
    {
        if (!is_array($args)) {
            $args = [$args];
        }
        // Grab the real handler behind the string representation
        if (is_string($func)) {
            $func = $this->grab($func, $args);
        }
        // Execute function; abort if callback/hook returns FALSE
        // No route handler
        if (!is_callable($func)) {
            if ($hooks == 'beforeroute,afterroute') {
                $allowed = [];
                if (is_array($func)) {
                    $allowed = array_intersect(
                        array_map('strtoupper', get_class_methods($func[0])),
                        explode('|', self::VERBS)
                    );
                }
                header('Allow: ' . implode(',', $allowed));
                $this->error(405);
            } else {
                user_error(
                    sprintf(
                        self::E_METHOD,
                        is_string($func) ? $func : $this->stringify($func)
                    ),
                    E_USER_ERROR
                );
            }
        }
        $obj = false;
        if (is_array($func)) {
            $hooks = $this->split($hooks);
            $obj   = true;
        }
        // Execute pre-route hook if any
        if (
            $obj &&
            $hooks &&
            in_array($hook = 'beforeroute', $hooks) &&
            method_exists($func[0], $hook) &&
            call_user_func_array([$func[0], $hook], $args) === false
        ) {
            return false;
        }
        // Execute callback
        $out = call_user_func_array($func, $args ?: []);
        if ($out === false) {
            return false;
        }
        // Execute post-route hook if any
        if (
            $obj &&
            $hooks &&
            in_array($hook = 'afterroute', $hooks) &&
            method_exists($func[0], $hook) &&
            call_user_func_array([$func[0], $hook], $args) === false
        ) {
            return false;
        }
        return $out;
    }

    /**
     *    Execute specified callbacks in succession; Apply same arguments
     *    to all callbacks
     * @param $funcs array|string
     * @param $args  mixed
     *               *@return array
     */
    public function chain($funcs, $args = null)
    {
        $out = [];
        foreach (is_array($funcs) ? $funcs : $this->split($funcs) as $func) {
            $out[] = $this->call($func, $args);
        }
        return $out;
    }

    /**
     *    Execute specified callbacks in succession; Relay result of
     *    previous callback as argument to the next callback
     * @param $funcs array|string
     * @param $args  mixed
     *               *@return array
     */
    public function relay($funcs, $args = null)
    {
        foreach (is_array($funcs) ? $funcs : $this->split($funcs) as $func) {
            $args = [$this->call($func, $args)];
        }
        return array_shift($args);
    }

    /**
     *    Configure framework according to .ini-style file settings;
     *    If optional 2nd arg is provided, template strings are interpreted
     * @param $source string|array
     * @param $allow  bool
     *                *@return object
     */
    public function config($source, $allow = false)
    {
        if (is_string($source)) {
            $source = $this->split($source);
        }
        if ($allow) {
            $preview = Preview::instance();
        }
        foreach ($source as $file) {
            preg_match_all(
                '/(?<=^|\n)(?:' .
                '\[(?<section>.+?)\]|' .
                '(?<lval>[^\h\r\n;].*?)\h*=\h*' .
                '(?<rval>(?:\\\\\h*\r?\n|.+?)*)' .
                ')(?=\r?\n|$)/',
                $this->read($file),
                $matches,
                PREG_SET_ORDER
            );
            if ($matches) {
                $sec = 'globals';
                $cmd = [];
                foreach ($matches as $match) {
                    if ($match['section']) {
                        $sec = $match['section'];
                        if (
                            preg_match(
                                '/^(?!(?:global|config|route|map|redirect)s\b)' .
                                '((?:\.?\w)+)/i',
                                $sec,
                                $msec
                            ) &&
                            !$this->exists($msec[0])
                        ) {
                            $this->set($msec[0], null);
                        }
                        preg_match(
                            '/^(config|route|map|redirect)s\b|' .
                            '^((?:\.?\w)+)\s*\>\s*(.*)/i',
                            $sec,
                            $cmd
                        );
                        continue;
                    }
                    if ($allow) {
                        foreach (['lval', 'rval'] as $ndx) {
                            $match[$ndx] = $preview->
                            resolve($match[$ndx], null, 0, false, false);
                        }
                    }
                    if (!empty($cmd)) {
                        isset($cmd[3]) ?
                            $this->call(
                                $cmd[3],
                                [$match['lval'], $match['rval'], $cmd[2]]
                            ) :
                            call_user_func_array(
                                [$this, $cmd[1]],
                                array_merge(
                                    [$match['lval']],
                                    str_getcsv($this->cast($match['rval']))
                                )
                            );
                    } else {
                        $rval = preg_replace(
                            '/\\\\\h*(\r?\n)/',
                            '\1',
                            $match['rval']
                        );
                        $ttl  = null;
                        if (preg_match('/^(.+)\|\h*(\d+)$/', $rval, $tmp)) {
                            array_shift($tmp);
                            list($rval, $ttl) = $tmp;
                        }
                        $args = array_map(
                            function ($val) {
                                $val = $this->cast($val);
                                return is_string($val)
                                    ? preg_replace('/\\\\"/', '"', $val)
                                    : $val;
                            },
                            // Mark quoted strings with 0x00 whitespace
                            str_getcsv(
                                preg_replace(
                                    '/(?<!\\\\)(")(.*?)\1/',
                                    "\\1\x00\\2\\1",
                                    trim($rval)
                                )
                            )
                        );
                        preg_match(
                            '/^(?<section>[^:]+)(?:\:(?<func>.+))?/',
                            $sec,
                            $parts
                        );
                        $func   = isset($parts['func']) ? $parts['func'] : null;
                        $custom = (strtolower($parts['section']) != 'globals');
                        if ($func) {
                            $args = [$this->call($func, $args)];
                        }
                        if (count($args) > 1) {
                            $args = [$args];
                        }
                        if (isset($ttl)) {
                            $args = array_merge($args, [$ttl]);
                        }
                        call_user_func_array(
                            [$this, 'set'],
                            array_merge(
                                [
                                    ($custom ? ($parts['section'] . '.') : '') .
                                    $match['lval'],
                                ],
                                $args
                            )
                        );
                    }
                }
            }
        }
        return $this;
    }

    /**
     *    Create mutex, invoke callback then drop ownership when done
     * @param $id   string
     * @param $func callback
     * @param $args mixed
     *              *@return mixed
     */
    public function mutex($id, $func, $args = null)
    {
        if (!is_dir($tmp = $this->hive['TEMP'])) {
            mkdir($tmp, self::MODE, true);
        }
        // Use filesystem lock
        if (
            is_file(
                $lock = $tmp .
                $this->get('SEED') . '.' . $this->hash($id) . '.lock'
            ) &&
            filemtime($lock) + ini_get('max_execution_time') < microtime(true)
        ) {
            // Stale lock
            @unlink($lock);
        }
        while (!($handle = @fopen($lock, 'x')) && !connection_aborted()) {
            usleep(mt_rand(0, 100));
        }
        $this->locks[$id] = $lock;
        $out              = $this->call($func, $args);
        fclose($handle);
        @unlink($lock);
        unset($this->locks[$id]);
        return $out;
    }

    /**
     *    Read file (with option to apply Unix LF as standard line ending)
     * @param $file string
     * @param $lf   bool
     *              *@return string
     */
    public function read($file, $lf = false)
    {
        $out = @file_get_contents($file);
        return $lf ? preg_replace('/\r\n|\r/', "\n", $out) : $out;
    }

    /**
     *    Exclusive file write
     * @param $file   string
     * @param $data   mixed
     * @param $append bool
     *                *@return int|FALSE
     */
    public function write($file, $data, $append = false)
    {
        return file_put_contents($file, $data, LOCK_EX | ($append ? FILE_APPEND : 0));
    }

    /**
     *    Apply syntax highlighting
     * @param $text string
     *              *@return string
     */
    public function highlight($text)
    {
        $out  = '';
        $pre  = false;
        $text = trim($text);
        if ($text && !preg_match('/^<\?php/', $text)) {
            $text = '<?php ' . $text;
            $pre  = true;
        }
        foreach (token_get_all($text) as $token) {
            if ($pre) {
                $pre = false;
            } else {
                $out .= '<span' .
                    (is_array($token) ?
                        (' class="' .
                            substr(strtolower(token_name($token[0])), 2) . '">' .
                            $this->encode($token[1]) . '') :
                        ('>' . $this->encode($token))) .
                    '</span>';
            }
        }
        return $out ? ('<code>' . $out . '</code>') : $text;
    }

    /**
     *    Dump expression with syntax highlighting
     * @param $expr mixed
     *              *@return NULL
     */
    public function dump($expr)
    {
        echo $this->highlight($this->stringify($expr));
    }

    /**
     *    Return path (and query parameters) relative to the base directory
     * @param $url string
     *             *@return string
     */
    public function rel($url)
    {
        return preg_replace('/^(?:https?:\/\/)?' .
            preg_quote($this->hive['BASE'], '/') . '(\/.*|$)/', '\1', $url);
    }

    /**
     *    Namespace-aware class autoloader
     * @param $class string
     *               *@return mixed
     */
    protected function autoload($class)
    {
        $class = $this->fixslashes(ltrim($class, '\\'));
        $func  = null;
        if (
            is_array($path = $this->hive['AUTOLOAD']) &&
            isset($path[1]) &&
            is_callable($path[1])
        ) {
            list($path, $func) = $path;
        }
        foreach ($this->split($this->hive['PLUGINS'] . ';' . $path) as $auto) {
            if (
                $func &&
                is_file($file = $func($auto . $class) . '.php') ||
                is_file($file = $auto . $class . '.php') ||
                is_file($file = $auto . strtolower($class) . '.php') ||
                is_file($file = strtolower($auto . $class) . '.php')
            ) {
                return require($file);
            }
        }
    }

    /**
     *    Execute framework/application shutdown sequence
     * @param $cwd string
     **/
    public function unload($cwd)
    {
        chdir($cwd);
        if (
            !($error = error_get_last()) &&
            session_status() == PHP_SESSION_ACTIVE
        ) {
            session_commit();
        }
        foreach ($this->locks as $lock) {
            @unlink($lock);
        }
        $handler = $this->hive['UNLOAD'];
        if (
            (!$handler || $this->call($handler, $this) === false) &&
            $error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])
        ) {
            // Fatal error detected
            $this->error(
                500,
                sprintf(self::E_FATAL, $error['message']),
                [$error]
            );
        }
    }

    /**
     *    Convenience method for checking hive key
     * @param $key string
     *             *@return mixed
     */
    public function offsetexists($key)
    {
        return $this->exists($key);
    }

    /**
     *    Convenience method for assigning hive value
     * @param $key string
     * @param $val scalar
     *             *@return mixed
     */
    public function offsetset($key, $val)
    {
        return $this->set($key, $val);
    }

    /**
     *    Convenience method for retrieving hive value
     * @param $key string
     *             *@return mixed
     */
    public function &offsetget($key)
    {
        $val =& $this->ref($key);
        return $val;
    }

    /**
     *    Convenience method for removing hive key
     * @param $key string
     *             *@return NULL
     */
    public function offsetunset($key)
    {
        $this->clear($key);
    }

    /**
     *    Alias for offsetexists()
     * @param $key string
     *             *@return mixed
     */
    public function __isset($key)
    {
        return $this->offsetexists($key);
    }

    /**
     *    Alias for offsetset()
     * @param $key string
     * @param $val mixed
     *             *@return mixed
     */
    public function __set($key, $val)
    {
        return $this->offsetset($key, $val);
    }

    /**
     *    Alias for offsetget()
     * @param $key string
     *             *@return mixed
     */
    public function &__get($key)
    {
        $val =& $this->offsetget($key);
        return $val;
    }

    /**
     *    Alias for offsetunset()
     * @param $key string
     *             *@return mixed
     */
    public function __unset($key)
    {
        $this->offsetunset($key);
    }

    /**
     *    Call function identified by hive key
     * @param $key  string
     * @param $args array
     *              *@return mixed
     */
    public function __call($key, array $args)
    {
        if ($this->exists($key, $val)) {
            return call_user_func_array($val, $args);
        }
        user_error(sprintf(self::E_METHOD, $key), E_USER_ERROR);
    }

    //! Prohibit cloning
    private function __clone()
    {
    }

    //! Bootstrap
    public function __construct()
    {
        // Managed directives
        ini_set('default_charset', $charset = 'UTF-8');
        if (extension_loaded('mbstring')) {
            mb_internal_encoding($charset);
        }
        ini_set('display_errors', 0);
        // Intercept errors/exceptions; PHP5.3-compatible
        $check = error_reporting((E_ALL | E_STRICT) & ~(E_NOTICE | E_USER_NOTICE));
        set_exception_handler(
            function ($obj) {
                $this->hive['EXCEPTION'] = $obj;
                $this->error(
                    500,
                    $obj->getmessage() . ' ' .
                    '[' . $obj->getFile() . ':' . $obj->getLine() . ']',
                    $obj->gettrace()
                );
            }
        );
        set_error_handler(
            function ($level, $text, $file, $line) {
                if ($level & error_reporting()) {
                    $this->error(500, $text, null, $level);
                }
            }
        );
        if (!isset($_SERVER['SERVER_NAME'])) {
            $_SERVER['SERVER_NAME'] = gethostname();
        }
        if ($cli = PHP_SAPI == 'cli') {
            // Emulate HTTP request
            $_SERVER['REQUEST_METHOD'] = 'GET';
            if (!isset($_SERVER['argv'][1])) {
                $_SERVER['argc']++;
                $_SERVER['argv'][1] = '/';
            }
            $req = $query = '';
            if (substr($_SERVER['argv'][1], 0, 1) == '/') {
                $req   = $_SERVER['argv'][1];
                $query = parse_url($req, PHP_URL_QUERY);
            } else {
                foreach ($_SERVER['argv'] as $i => $arg) {
                    if (!$i) {
                        continue;
                    }
                    if (preg_match('/^\-(\-)?(\w+)(?:\=(.*))?$/', $arg, $m)) {
                        foreach ($m[1] ? [$m[2]] : str_split($m[2]) as $k) {
                            $query .= ($query ? '&' : '') . urlencode($k) . '=';
                        }
                        if (isset($m[3])) {
                            $query .= urlencode($m[3]);
                        }
                    } else {
                        $req .= '/' . $arg;
                    }
                }
                if (!$req) {
                    $req = '/';
                }
                if ($query) {
                    $req .= '?' . $query;
                }
            }
            $_SERVER['REQUEST_URI'] = $req;
            parse_str($query, $GLOBALS['_GET']);
        }
        $headers = [];
        if (!$cli) {
            if (function_exists('getallheaders')) {
                foreach (getallheaders() as $key => $val) {
                    $tmp = strtoupper(strtr($key, '-', '_'));
                    // TODO: use ucwords delimiters for php 5.4.32+ & 5.5.16+
                    $key           = strtr(ucwords(strtolower(strtr($key, '-', ' '))), ' ', '-');
                    $headers[$key] = $val;
                    if (isset($_SERVER['HTTP_' . $tmp])) {
                        $headers[$key] =& $_SERVER['HTTP_' . $tmp];
                    }
                }
            } else {
                if (isset($_SERVER['CONTENT_LENGTH'])) {
                    $headers['Content-Length'] =& $_SERVER['CONTENT_LENGTH'];
                }
                if (isset($_SERVER['CONTENT_TYPE'])) {
                    $headers['Content-Type'] =& $_SERVER['CONTENT_TYPE'];
                }
                foreach (array_keys($_SERVER) as $key) {
                    if (substr($key, 0, 5) == 'HTTP_') {
                        $headers[
                            strtr(
                                ucwords(strtolower(strtr(substr($key, 5), '_', ' '))),
                                ' ',
                                '-'
                            )] =& $_SERVER[$key];
                    }
                }
            }
        }
        if (isset($headers['X-HTTP-Method-Override'])) {
            $_SERVER['REQUEST_METHOD'] = $headers['X-HTTP-Method-Override'];
        } elseif ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['_method'])) {
            $_SERVER['REQUEST_METHOD'] = $_POST['_method'];
        }
        $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on' ||
        isset($headers['X-Forwarded-Proto']) &&
        $headers['X-Forwarded-Proto'] == 'https' ? 'https' : 'http';
        // Create hive early on to expose header methods
        $this->hive = ['HEADERS' => &$headers];
        if (function_exists('apache_setenv')) {
            // Work around Apache pre-2.4 VirtualDocumentRoot bug
            $_SERVER['DOCUMENT_ROOT'] = str_replace(
                $_SERVER['SCRIPT_NAME'],
                '',
                $_SERVER['SCRIPT_FILENAME']
            );
            apache_setenv("DOCUMENT_ROOT", $_SERVER['DOCUMENT_ROOT']);
        }
        $_SERVER['DOCUMENT_ROOT'] = realpath($_SERVER['DOCUMENT_ROOT']);
        $base                     = '';
        $uri                      = parse_url(
            (preg_match('/^\w+:\/\//', $_SERVER['REQUEST_URI']) ?
                '' :
                '//' . $_SERVER['SERVER_NAME'])
            . $_SERVER['REQUEST_URI']
        );
        $_SERVER['REQUEST_URI']   = $uri['path'] .
            (isset($uri['query']) ? '?' . $uri['query'] : '') .
            (isset($uri['fragment']) ? '#' . $uri['fragment'] : '');
        $path                     = preg_replace('/^' . preg_quote($base, '/') . '/', '', $uri['path']);
        session_cache_limiter('');
        $jar = [
            'expire'   => 0,
            'path'     => $base ?: '/',
            'domain'   => is_int(strpos($_SERVER['SERVER_NAME'], '.')) &&
            !filter_var($_SERVER['SERVER_NAME'], FILTER_VALIDATE_IP) ?
                $_SERVER['SERVER_NAME'] : '',
            'secure'   => ($scheme == 'https'),
            'httponly' => true,
        ];
        call_user_func_array('session_set_cookie_params', $jar);
        $port = 80;
        if (isset($headers['X-Forwarded-Port'])) {
            $port = $headers['X-Forwarded-Port'];
        } elseif (isset($_SERVER['SERVER_PORT'])) {
            $port = $_SERVER['SERVER_PORT'];
        }
        // Default configuration
        $this->hive += [
            'AGENT'      => $this->agent(),
            'AJAX'       => $this->ajax(),
            'ALIAS'      => null,
            'ALIASES'    => [],
            'AUTOLOAD'   => './',
            'BASE'       => $base,
            'BITMASK'    => ENT_COMPAT,
            'BODY'       => null,
            'CACHE'      => false,
            'CASELESS'   => true,
            'CLI'        => $cli,
            'CORS'       => [
                'headers'     => '',
                'origin'      => false,
                'credentials' => false,
                'expose'      => false,
                'ttl'         => 0,
            ],
            'DEBUG'      => 0,
            'DIACRITICS' => [],
            'DNSBL'      => '',
            'EMOJI'      => [],
            'ENCODING'   => $charset,
            'ERROR'      => null,
            'ESCAPE'     => true,
            'EXCEPTION'  => null,
            'EXEMPT'     => null,
            'FALLBACK'   => $this->fallback,
            'FORMATS'    => [],
            'FRAGMENT'   => isset($uri['fragment']) ? $uri['fragment'] : '',
            'HALT'       => true,
            'HIGHLIGHT'  => false,
            'HOST'       => $_SERVER['SERVER_NAME'],
            'IP'         => $this->ip(),
            'JAR'        => $jar,
            'LANGUAGE'   => isset($headers['Accept-Language']) ?
                $this->language($headers['Accept-Language']) :
                $this->fallback,
            'LOCALES'    => './',
            'LOGS'       => './',
            'MB'         => extension_loaded('mbstring'),
            'ONERROR'    => null,
            'ONREROUTE'  => null,
            'PACKAGE'    => self::PACKAGE,
            'PARAMS'     => [],
            'PATH'       => $path,
            'PATTERN'    => null,
            'PLUGINS'    => $this->fixslashes(__DIR__) . '/',
            'PORT'       => $port,
            'PREFIX'     => null,
            'PREMAP'     => '',
            'QUERY'      => isset($uri['query']) ? $uri['query'] : '',
            'QUIET'      => false,
            'RAW'        => false,
            'REALM'      => $scheme . '://' . $_SERVER['SERVER_NAME'] .
                ($port && !in_array($port, [80, 443]) ? (':' . $port) : '') .
                $_SERVER['REQUEST_URI'],
            'RESPONSE'   => '',
            'ROOT'       => $_SERVER['DOCUMENT_ROOT'],
            'ROUTES'     => [],
            'SCHEME'     => $scheme,
            'SEED'       => $this->hash($_SERVER['SERVER_NAME'] . $base),
            'SERIALIZER' => extension_loaded($ext = 'igbinary') ? $ext : 'php',
            'TEMP'       => 'tmp/',
            'TIME'       => &$_SERVER['REQUEST_TIME_FLOAT'],
            'TZ'         => @date_default_timezone_get(),
            'UI'         => './',
            'UNLOAD'     => null,
            'UPLOADS'    => './',
            'URI'        => &$_SERVER['REQUEST_URI'],
            'VERB'       => &$_SERVER['REQUEST_METHOD'],
            'VERSION'    => self::VERSION,
            'XFRAME'     => 'SAMEORIGIN',
        ];
        if (
            PHP_SAPI == 'cli-server' &&
            preg_match('/^' . preg_quote($base, '/') . '$/', $this->hive['URI'])
        ) {
            $this->reroute('/');
        }
        // Override setting
        if (ini_get('auto_globals_jit')) {
            $GLOBALS += ['_ENV' => $_ENV, '_REQUEST' => $_REQUEST];
        }
        // Sync PHP globals with corresponding hive keys
        $this->init = $this->hive;
        foreach (explode('|', self::GLOBALS) as $global) {
            $sync       = $this->sync($global);
            $this->init += [
                $global => preg_match('/SERVER|ENV/', $global) ? $sync : [],
            ];
        }
        // Error detected
        if ($check && $error = error_get_last()) {
            $this->error(500, sprintf(self::E_FATAL, $error['message']), [$error]);
        }
        date_default_timezone_set($this->hive['TZ']);
        // Register framework autoloader
        spl_autoload_register([$this, 'autoload']);
        // Register shutdown handler
        register_shutdown_function([$this, 'unload'], getcwd());
    }
}

//! Cache engine
class Cache extends Prefab
{

    //! Cache DSN
    protected $dsn;
    //! Prefix for cache entries
    protected $prefix;
    //! MemCache or Redis object
    protected $ref;

    /**
     *    Return timestamp and TTL of cache entry or FALSE if not found
     * @param $key string
     * @param $val mixed
     *             *@return array|FALSE
     */
    public function exists($key, &$val = null)
    {
        $fw = Base::instance();
        if (!$this->dsn) {
            return false;
        }
        $ndx   = $this->prefix . '.' . $key;
        $parts = explode('=', $this->dsn, 2);
        switch ($parts[0]) {
            case 'apc':
            case 'apcu':
                $raw = call_user_func($parts[0] . '_fetch', $ndx);
                break;
            case 'redis':
                $raw = $this->ref->get($ndx);
                break;
            case 'memcache':
                $raw = memcache_get($this->ref, $ndx);
                break;
            case 'memcached':
                $raw = $this->ref->get($ndx);
                break;
            case 'wincache':
                $raw = wincache_ucache_get($ndx);
                break;
            case 'xcache':
                $raw = xcache_get($ndx);
                break;
            case 'folder':
                $raw = $fw->read($parts[1] . $ndx);
                break;
        }
        if (!empty($raw)) {
            list($val, $time, $ttl) = (array)$fw->unserialize($raw);
            if ($ttl === 0 || $time + $ttl > microtime(true)) {
                return [$time, $ttl];
            }
            $val = null;
            $this->clear($key);
        }
        return false;
    }

    /**
     *    Store value in cache
     * @param $key string
     * @param $val mixed
     * @param $ttl int
     *             *@return mixed|FALSE
     */
    public function set($key, $val, $ttl = 0)
    {
        $fw = Base::instance();
        if (!$this->dsn) {
            return true;
        }
        $ndx  = $this->prefix . '.' . $key;
        $time = microtime(true);
        if ($cached = $this->exists($key)) {
            list($time, $ttl) = $cached;
        }
        $data  = $fw->serialize([$val, $time, $ttl]);
        $parts = explode('=', $this->dsn, 2);
        switch ($parts[0]) {
            case 'apc':
            case 'apcu':
                return call_user_func($parts[0] . '_store', $ndx, $data, $ttl);
            case 'redis':
                return $this->ref->set($ndx, $data, $ttl ? ['ex' => $ttl] : []);
            case 'memcache':
                return memcache_set($this->ref, $ndx, $data, 0, $ttl);
            case 'memcached':
                return $this->ref->set($ndx, $data, $ttl);
            case 'wincache':
                return wincache_ucache_set($ndx, $data, $ttl);
            case 'xcache':
                return xcache_set($ndx, $data, $ttl);
            case 'folder':
                return $fw->write($parts[1] . str_replace(['/', '\\'], '', $ndx), $data);
        }
        return false;
    }

    /**
     *    Retrieve value of cache entry
     * @param $key string
     *             *@return mixed|FALSE
     */
    public function get($key)
    {
        return $this->dsn && $this->exists($key, $data) ? $data : false;
    }

    /**
     *    Delete cache entry
     * @param $key string
     *             *@return bool
     */
    public function clear($key)
    {
        if (!$this->dsn) {
            return;
        }
        $ndx   = $this->prefix . '.' . $key;
        $parts = explode('=', $this->dsn, 2);
        switch ($parts[0]) {
            case 'apc':
            case 'apcu':
                return call_user_func($parts[0] . '_delete', $ndx);
            case 'redis':
                return $this->ref->del($ndx);
            case 'memcache':
                return memcache_delete($this->ref, $ndx);
            case 'memcached':
                return $this->ref->delete($ndx);
            case 'wincache':
                return wincache_ucache_delete($ndx);
            case 'xcache':
                return xcache_unset($ndx);
            case 'folder':
                return @unlink($parts[1] . $ndx);
        }
        return false;
    }

    /**
     *    Clear contents of cache backend
     * @param $suffix string
     *                *@return bool
     */
    public function reset($suffix = null)
    {
        if (!$this->dsn) {
            return true;
        }
        $regex = '/' . preg_quote($this->prefix . '.', '/') . '.*' .
            preg_quote($suffix, '/') . '/';
        $parts = explode('=', $this->dsn, 2);
        switch ($parts[0]) {
            case 'apc':
            case 'apcu':
                $info = call_user_func(
                    $parts[0] . '_cache_info',
                    $parts[0] == 'apcu' ? false : 'user'
                );
                if (!empty($info['cache_list'])) {
                    $key = array_key_exists(
                        'info',
                        $info['cache_list'][0]
                    ) ? 'info' : 'key';
                    foreach ($info['cache_list'] as $item) {
                        if (preg_match($regex, $item[$key])) {
                            call_user_func($parts[0] . '_delete', $item[$key]);
                        }
                    }
                }
                return true;
            case 'redis':
                $keys = $this->ref->keys($this->prefix . '.*' . $suffix);
                foreach ($keys as $key) {
                    $this->ref->del($key);
                }
                return true;
            case 'memcache':
                foreach (
                    memcache_get_extended_stats($this->ref, 'slabs') as $slabs
                ) {
                    foreach (array_filter(array_keys($slabs), 'is_numeric') as $id) {
                        foreach (
                            memcache_get_extended_stats($this->ref, 'cachedump', $id) as $data
                        ) {
                            if (is_array($data)) {
                                foreach (array_keys($data) as $key) {
                                    if (preg_match($regex, $key)) {
                                        memcache_delete($this->ref, $key);
                                    }
                                }
                            }
                        }
                    }
                }
                return true;
            case 'memcached':
                foreach ($this->ref->getallkeys() ?: [] as $key) {
                    if (preg_match($regex, $key)) {
                        $this->ref->delete($key);
                    }
                }
                return true;
            case 'wincache':
                $info = wincache_ucache_info();
                foreach ($info['ucache_entries'] as $item) {
                    if (preg_match($regex, $item['key_name'])) {
                        wincache_ucache_delete($item['key_name']);
                    }
                }
                return true;
            case 'xcache':
                if ($suffix && !ini_get('xcache.admin.enable_auth')) {
                    $cnt = xcache_count(XC_TYPE_VAR);
                    for ($i = 0; $i < $cnt; $i++) {
                        $list = xcache_list(XC_TYPE_VAR, $i);
                        foreach ($list['cache_list'] as $item) {
                            if (preg_match($regex, $item['name'])) {
                                xcache_unset($item['name']);
                            }
                        }
                    }
                } else {
                    xcache_unset_by_prefix($this->prefix . '.');
                }
                return true;
            case 'folder':
                if ($glob = @glob($parts[1] . '*')) {
                    foreach ($glob as $file) {
                        if (preg_match($regex, basename($file))) {
                            @unlink($file);
                        }
                    }
                }
                return true;
        }
        return false;
    }

    /**
     *    Load/auto-detect cache backend
     * @param $dsn  bool|string
     * @param $seed bool|string
     *              *@return string
     */
    public function load($dsn, $seed = null)
    {
        $fw = Base::instance();
        if ($dsn = trim($dsn)) {
            if (
                preg_match('/^redis=(.+)/', $dsn, $parts) &&
                extension_loaded('redis')
            ) {
                list($host, $port, $db) = explode(':', $parts[1]) + [1 => 6379, 2 => null];
                $this->ref = new Redis();
                if (!$this->ref->connect($host, $port, 2)) {
                    $this->ref = null;
                }
                if (isset($db)) {
                    $this->ref->select($db);
                }
            } elseif (
                preg_match('/^memcache=(.+)/', $dsn, $parts) &&
                extension_loaded('memcache')
            ) {
                foreach ($fw->split($parts[1]) as $server) {
                    list($host, $port) = explode(':', $server) + [1 => 11211];
                    if (empty($this->ref)) {
                        $this->ref = @memcache_connect($host, $port) ?: null;
                    } else {
                        memcache_add_server($this->ref, $host, $port);
                    }
                }
            } elseif (
                preg_match('/^memcached=(.+)/', $dsn, $parts) &&
                extension_loaded('memcached')
            ) {
                foreach ($fw->split($parts[1]) as $server) {
                    list($host, $port) = explode(':', $server) + [1 => 11211];
                    if (empty($this->ref)) {
                        $this->ref = new Memcached();
                    }
                    $this->ref->addServer($host, $port);
                }
            }
            if (empty($this->ref) && !preg_match('/^folder\h*=/', $dsn)) {
                $dsn = ($grep = preg_grep(
                    '/^(apc|wincache|xcache)/',
                    array_map('strtolower', get_loaded_extensions())
                )) ?
                    // Auto-detect
                    current($grep) :
                    // Use filesystem as fallback
                    ('folder=' . $fw->TEMP . 'cache/');
            }
            if (
                preg_match('/^folder\h*=\h*(.+)/', $dsn, $parts) &&
                !is_dir($parts[1])
            ) {
                mkdir($parts[1], Base::MODE, true);
            }
        }
        $this->prefix = $seed ?: $fw->SEED;
        return $this->dsn = $dsn;
    }

    /**
     *    Class constructor
     * @param $dsn bool|string
     **/
    public function __construct($dsn = false)
    {
        if ($dsn) {
            $this->load($dsn);
        }
    }
}

//! View handler
class View extends Prefab
{

    //! Temporary hive
    private $temp;

    //! Template file
    protected $file;
    //! Post-rendering handler
    protected $trigger;
    //! Nesting level
    protected $level = 0;

    /**
     *    Encode characters to equivalent HTML entities
     * @param $arg mixed
     *             *@return string
     */
    public function esc($arg)
    {
        $fw = Base::instance();
        return $fw->recursive(
            $arg,
            function ($val) use ($fw) {
                return is_string($val) ? $fw->encode($val) : $val;
            }
        );
    }

    /**
     *    Decode HTML entities to equivalent characters
     * @param $arg mixed
     *             *@return string
     */
    public function raw($arg)
    {
        $fw = Base::instance();
        return $fw->recursive(
            $arg,
            function ($val) use ($fw) {
                return is_string($val) ? $fw->decode($val) : $val;
            }
        );
    }

    /**
     *    Create sandbox for template execution
     * @param $hive array
     * @param $mime string
     *              *@return string
     */
    protected function sandbox(array $hive = null, $mime = null)
    {
        $fw       = Base::instance();
        $implicit = false;
        if (is_null($hive)) {
            $implicit = true;
            $hive     = $fw->hive();
        }
        if ($this->level < 1 || $implicit) {
            if (
                !$fw->CLI &&
                $mime &&
                !headers_sent() &&
                !preg_grep('/^Content-Type:/', headers_list())
            ) {
                header('Content-Type: ' . $mime . '; ' .
                    'charset=' . $fw->ENCODING);
            }
            if ($fw->ESCAPE) {
                $hive = $this->esc($hive);
            }
            if (isset($hive['ALIASES'])) {
                $hive['ALIASES'] = $fw->build($hive['ALIASES']);
            }
        }
        $this->temp = $hive;
        unset($fw, $hive, $implicit, $mime);
        extract($this->temp);
        $this->temp = null;
        $this->level++;
        ob_start();
        require($this->file);
        $this->level--;
        return ob_get_clean();
    }

    /**
     *    Render template
     * @param $file string
     * @param $mime string
     * @param $hive array
     * @param $ttl  int
     *              *@return string
     */
    public function render($file, $mime = 'text/html', array $hive = null, $ttl = 0)
    {
        $fw    = Base::instance();
        $cache = Cache::instance();
        foreach ($fw->split($fw->UI) as $dir) {
            if ($cache->exists($hash = $fw->hash($dir . $file), $data)) {
                return $data;
            }
        }
        if (is_file($this->file = $fw->fixslashes($dir . $file))) {
            if (
                isset($_COOKIE[session_name()]) &&
                !headers_sent() &&
                session_status() != PHP_SESSION_ACTIVE
            ) {
                session_start();
            }
            $fw->sync('SESSION');
            $data = $this->sandbox($hive, $mime);
            if (isset($this->trigger['afterrender'])) {
                foreach ($this->trigger['afterrender'] as $func) {
                    $data = $fw->call($func, [$data, $dir . $file]);
                }
            }
            if ($ttl) {
                $cache->set($hash, $data, $ttl);
            }
            return $data;
        }
        user_error(sprintf(Base::E_OPEN, $file), E_USER_ERROR);
    }

    /**
     *    post rendering handler
     * @param $func callback
     */
    public function afterrender($func)
    {
        $this->trigger['afterrender'][] = $func;
    }
}

//! Lightweight template engine
class Preview extends View
{

    //! token filter
    protected $filter = [
        'c'      => '$this->c',
        'esc'    => '$this->esc',
        'raw'    => '$this->raw',
        'alias'  => 'Base::instance()->alias',
        'format' => 'Base::instance()->format',
    ];

    //! newline interpolation
    protected $interpolation = true;

    /**
     * enable/disable markup parsing interpolation
     * mainly used for adding appropriate newlines
     * @param $bool bool
     */
    public function interpolation($bool)
    {
        $this->interpolation = $bool;
    }

    /**
     *    Return C-locale equivalent of number
     * @param $val int|float
     *             *@return string
     */
    public function c($val)
    {
        $fw     = Base::instance();
        $locale = setlocale(LC_NUMERIC, 0);
        setlocale(LC_NUMERIC, 'C');
        $out    = (string)(float)$val;
        $locale = setlocale(LC_NUMERIC, $locale);
        return $out;
    }

    /**
     *    Convert token to variable
     * @param $str string
     *             *@return string
     */
    public function token($str)
    {
        $fw  = Base::instance();
        $str = trim(
            preg_replace(
                '/\{\{(.+?)\}\}/s',
                trim('\1'),
                $fw->compile($str)
            )
        );
        if (
            preg_match(
                '/^(.+)(?<!\|)\|((?:\h*\w+(?:\h*[,;]?))+)$/s',
                $str,
                $parts
            )
        ) {
            $str = trim($parts[1]);
            foreach ($fw->split($parts[2]) as $func) {
                $str = is_string($cmd = $this->filter($func)) ?
                    $cmd . '(' . $str . ')' :
                    'Base::instance()->' .
                    'call($this->filter(\'' . $func . '\'),[' . $str . '])';
            }
        }
        return $str;
    }

    /**
     *    Register or get (a specific one or all) token filters
     * @param string         $key
     * @param string|closure $func
     * @return array|closure|string
     */
    public function filter($key = null, $func = null)
    {
        if (!$key) {
            return array_keys($this->filter);
        }
        $key = strtolower($key);
        if (!$func) {
            return $this->filter[$key];
        }
        $this->filter[$key] = $func;
    }

    /**
     *    Assemble markup
     * @param $node string
     *              *@return string
     */
    protected function build($node)
    {
        return preg_replace_callback(
            '/\{~(.+?)~\}|\{\*(.+?)\*\}|\{\-(.+?)\-\}|' .
            '\{\{(.+?)\}\}((\r?\n)*)/s',
            function ($expr) {
                if ($expr[1]) {
                    $str = '<?php ' . $this->token($expr[1]) . ' ?>';
                } elseif ($expr[2]) {
                    return '';
                } elseif ($expr[3]) {
                    $str = $expr[3];
                } else {
                    $str = '<?= (' . trim($this->token($expr[4])) . ')' .
                        ($this->interpolation ?
                            (!empty($expr[6]) ? '."' . $expr[6] . '"' : '') : '') . ' ?>';
                    if (isset($expr[5])) {
                        $str .= $expr[5];
                    }
                }
                return $str;
            },
            $node
        );
    }

    /**
     *    Render template string
     * @param $node    string|array
     * @param $hive    array
     * @param $ttl     int
     * @param $persist bool
     * @param $escape  bool
     *                 *@return string
     */
    public function resolve($node, array $hive = null, $ttl = 0, $persist = false, $escape = null)
    {
        $fw    = Base::instance();
        $cache = Cache::instance();
        if ($escape !== null) {
            $esc        = $fw->ESCAPE;
            $fw->ESCAPE = $escape;
        }
        if ($ttl || $persist) {
            $hash = $fw->hash($fw->serialize($node));
        }
        if ($ttl && $cache->exists($hash, $data)) {
            return $data;
        }
        if ($persist) {
            if (!is_dir($tmp = $fw->TEMP)) {
                mkdir($tmp, Base::MODE, true);
            }
            if (!is_file($this->file = ($tmp . $fw->SEED . '.' . $hash . '.php'))) {
                $fw->write($this->file, $this->build($node));
            }
            if (
                isset($_COOKIE[session_name()]) &&
                !headers_sent() &&
                session_status() != PHP_SESSION_ACTIVE
            ) {
                session_start();
            }
            $fw->sync('SESSION');
            $data = $this->sandbox($hive);
        } else {
            if (!$hive) {
                $hive = $fw->hive();
            }
            if ($fw->ESCAPE) {
                $hive = $this->esc($hive);
            }
            extract($hive);
            unset($hive);
            ob_start();
            eval(' ?>' . $this->build($node) . '<?php ');
            $data = ob_get_clean();
        }
        if ($ttl) {
            $cache->set($hash, $data, $ttl);
        }
        if ($escape !== null) {
            $fw->ESCAPE = $esc;
        }
        return $data;
    }

    /**
     *    Parse template string
     * @param $text string
     *              *@return string
     */
    public function parse($text)
    {
        // Remove PHP code and comments
        return preg_replace(
            '/\h*<\?(?!xml)(?:php|\s*=)?.+?\?>\h*|' . '\{\*.+?\*\}/is',
            '',
            $text
        );
    }

    /**
     *    Render template
     * @param $file string
     * @param $mime string
     * @param $hive array
     * @param $ttl  int
     *              *@return string
     */
    public function render($file, $mime = 'text/html', array $hive = null, $ttl = 0)
    {
        $fw    = Base::instance();
        $cache = Cache::instance();
        if (!is_dir($tmp = $fw->TEMP)) {
            mkdir($tmp, Base::MODE, true);
        }
        foreach ($fw->split($fw->UI) as $dir) {
            if ($cache->exists($hash = $fw->hash($dir . $file), $data)) {
                return $data;
            }
            if (is_file($view = $fw->fixslashes($dir . $file))) {
                if (
                    !is_file(
                        $this->file = ($tmp . $fw->SEED . '.' . $fw->hash($view) . '.php')
                    ) ||
                    filemtime($this->file) < filemtime($view)
                ) {
                    $contents = $fw->read($view);
                    if (isset($this->trigger['beforerender'])) {
                        foreach ($this->trigger['beforerender'] as $func) {
                            $contents = $fw->call($func, [$contents, $view]);
                        }
                    }
                    $text = $this->parse($contents);
                    $fw->write($this->file, $this->build($text));
                }
                if (
                    isset($_COOKIE[session_name()]) &&
                    !headers_sent() &&
                    session_status() != PHP_SESSION_ACTIVE
                ) {
                    session_start();
                }
                $fw->sync('SESSION');
                $data = $this->sandbox($hive, $mime);
                if (isset($this->trigger['afterrender'])) {
                    foreach ($this->trigger['afterrender'] as $func) {
                        $data = $fw->call($func, [$data, $view]);
                    }
                }
                if ($ttl) {
                    $cache->set($hash, $data, $ttl);
                }
                return $data;
            }
        }
        user_error(sprintf(Base::E_OPEN, $file), E_USER_ERROR);
    }

    /**
     *    post rendering handler
     * @param $func callback
     */
    public function beforerender($func)
    {
        $this->trigger['beforerender'][] = $func;
    }
}

//! ISO language/country codes
class ISO extends Prefab
{

    //@{ ISO 3166-1 country codes
    const
        CC_AF = 'Afghanistan',
        CC_AX = 'Åland Islands',
        CC_AL = 'Albania',
        CC_DZ = 'Algeria',
        CC_AS = 'American Samoa',
        CC_AD = 'Andorra',
        CC_AO = 'Angola',
        CC_AI = 'Anguilla',
        CC_AQ = 'Antarctica',
        CC_AG = 'Antigua and Barbuda',
        CC_AR = 'Argentina',
        CC_AM = 'Armenia',
        CC_AW = 'Aruba',
        CC_AU = 'Australia',
        CC_AT = 'Austria',
        CC_AZ = 'Azerbaijan',
        CC_BS = 'Bahamas',
        CC_BH = 'Bahrain',
        CC_BD = 'Bangladesh',
        CC_BB = 'Barbados',
        CC_BY = 'Belarus',
        CC_BE = 'Belgium',
        CC_BZ = 'Belize',
        CC_BJ = 'Benin',
        CC_BM = 'Bermuda',
        CC_BT = 'Bhutan',
        CC_BO = 'Bolivia',
        CC_BQ = 'Bonaire, Sint Eustatius and Saba',
        CC_BA = 'Bosnia and Herzegovina',
        CC_BW = 'Botswana',
        CC_BV = 'Bouvet Island',
        CC_BR = 'Brazil',
        CC_IO = 'British Indian Ocean Territory',
        CC_BN = 'Brunei Darussalam',
        CC_BG = 'Bulgaria',
        CC_BF = 'Burkina Faso',
        CC_BI = 'Burundi',
        CC_KH = 'Cambodia',
        CC_CM = 'Cameroon',
        CC_CA = 'Canada',
        CC_CV = 'Cape Verde',
        CC_KY = 'Cayman Islands',
        CC_CF = 'Central African Republic',
        CC_TD = 'Chad',
        CC_CL = 'Chile',
        CC_CN = 'China',
        CC_CX = 'Christmas Island',
        CC_CC = 'Cocos (Keeling) Islands',
        CC_CO = 'Colombia',
        CC_KM = 'Comoros',
        CC_CG = 'Congo',
        CC_CD = 'Congo, The Democratic Republic of',
        CC_CK = 'Cook Islands',
        CC_CR = 'Costa Rica',
        CC_CI = 'Côte d\'ivoire',
        CC_HR = 'Croatia',
        CC_CU = 'Cuba',
        CC_CW = 'Curaçao',
        CC_CY = 'Cyprus',
        CC_CZ = 'Czech Republic',
        CC_DK = 'Denmark',
        CC_DJ = 'Djibouti',
        CC_DM = 'Dominica',
        CC_DO = 'Dominican Republic',
        CC_EC = 'Ecuador',
        CC_EG = 'Egypt',
        CC_SV = 'El Salvador',
        CC_GQ = 'Equatorial Guinea',
        CC_ER = 'Eritrea',
        CC_EE = 'Estonia',
        CC_ET = 'Ethiopia',
        CC_FK = 'Falkland Islands (Malvinas)',
        CC_FO = 'Faroe Islands',
        CC_FJ = 'Fiji',
        CC_FI = 'Finland',
        CC_FR = 'France',
        CC_GF = 'French Guiana',
        CC_PF = 'French Polynesia',
        CC_TF = 'French Southern Territories',
        CC_GA = 'Gabon',
        CC_GM = 'Gambia',
        CC_GE = 'Georgia',
        CC_DE = 'Germany',
        CC_GH = 'Ghana',
        CC_GI = 'Gibraltar',
        CC_GR = 'Greece',
        CC_GL = 'Greenland',
        CC_GD = 'Grenada',
        CC_GP = 'Guadeloupe',
        CC_GU = 'Guam',
        CC_GT = 'Guatemala',
        CC_GG = 'Guernsey',
        CC_GN = 'Guinea',
        CC_GW = 'Guinea-Bissau',
        CC_GY = 'Guyana',
        CC_HT = 'Haiti',
        CC_HM = 'Heard Island and McDonald Islands',
        CC_VA = 'Holy See (Vatican City State)',
        CC_HN = 'Honduras',
        CC_HK = 'Hong Kong',
        CC_HU = 'Hungary',
        CC_IS = 'Iceland',
        CC_IN = 'India',
        CC_ID = 'Indonesia',
        CC_IR = 'Iran, Islamic Republic of',
        CC_IQ = 'Iraq',
        CC_IE = 'Ireland',
        CC_IM = 'Isle of Man',
        CC_IL = 'Israel',
        CC_IT = 'Italy',
        CC_JM = 'Jamaica',
        CC_JP = 'Japan',
        CC_JE = 'Jersey',
        CC_JO = 'Jordan',
        CC_KZ = 'Kazakhstan',
        CC_KE = 'Kenya',
        CC_KI = 'Kiribati',
        CC_KP = 'Korea, Democratic People\'s Republic of',
        CC_KR = 'Korea, Republic of',
        CC_KW = 'Kuwait',
        CC_KG = 'Kyrgyzstan',
        CC_LA = 'Lao People\'s Democratic Republic',
        CC_LV = 'Latvia',
        CC_LB = 'Lebanon',
        CC_LS = 'Lesotho',
        CC_LR = 'Liberia',
        CC_LY = 'Libya',
        CC_LI = 'Liechtenstein',
        CC_LT = 'Lithuania',
        CC_LU = 'Luxembourg',
        CC_MO = 'Macao',
        CC_MK = 'Macedonia, The Former Yugoslav Republic of',
        CC_MG = 'Madagascar',
        CC_MW = 'Malawi',
        CC_MY = 'Malaysia',
        CC_MV = 'Maldives',
        CC_ML = 'Mali',
        CC_MT = 'Malta',
        CC_MH = 'Marshall Islands',
        CC_MQ = 'Martinique',
        CC_MR = 'Mauritania',
        CC_MU = 'Mauritius',
        CC_YT = 'Mayotte',
        CC_MX = 'Mexico',
        CC_FM = 'Micronesia, Federated States of',
        CC_MD = 'Moldova, Republic of',
        CC_MC = 'Monaco',
        CC_MN = 'Mongolia',
        CC_ME = 'Montenegro',
        CC_MS = 'Montserrat',
        CC_MA = 'Morocco',
        CC_MZ = 'Mozambique',
        CC_MM = 'Myanmar',
        CC_NA = 'Namibia',
        CC_NR = 'Nauru',
        CC_NP = 'Nepal',
        CC_NL = 'Netherlands',
        CC_NC = 'New Caledonia',
        CC_NZ = 'New Zealand',
        CC_NI = 'Nicaragua',
        CC_NE = 'Niger',
        CC_NG = 'Nigeria',
        CC_NU = 'Niue',
        CC_NF = 'Norfolk Island',
        CC_MP = 'Northern Mariana Islands',
        CC_NO = 'Norway',
        CC_OM = 'Oman',
        CC_PK = 'Pakistan',
        CC_PW = 'Palau',
        CC_PS = 'Palestinian Territory, Occupied',
        CC_PA = 'Panama',
        CC_PG = 'Papua New Guinea',
        CC_PY = 'Paraguay',
        CC_PE = 'Peru',
        CC_PH = 'Philippines',
        CC_PN = 'Pitcairn',
        CC_PL = 'Poland',
        CC_PT = 'Portugal',
        CC_PR = 'Puerto Rico',
        CC_QA = 'Qatar',
        CC_RE = 'Réunion',
        CC_RO = 'Romania',
        CC_RU = 'Russian Federation',
        CC_RW = 'Rwanda',
        CC_BL = 'Saint Barthélemy',
        CC_SH = 'Saint Helena, Ascension and Tristan da Cunha',
        CC_KN = 'Saint Kitts and Nevis',
        CC_LC = 'Saint Lucia',
        CC_MF = 'Saint Martin (French Part)',
        CC_PM = 'Saint Pierre and Miquelon',
        CC_VC = 'Saint Vincent and The Grenadines',
        CC_WS = 'Samoa',
        CC_SM = 'San Marino',
        CC_ST = 'Sao Tome and Principe',
        CC_SA = 'Saudi Arabia',
        CC_SN = 'Senegal',
        CC_RS = 'Serbia',
        CC_SC = 'Seychelles',
        CC_SL = 'Sierra Leone',
        CC_SG = 'Singapore',
        CC_SK = 'Slovakia',
        CC_SX = 'Sint Maarten (Dutch Part)',
        CC_SI = 'Slovenia',
        CC_SB = 'Solomon Islands',
        CC_SO = 'Somalia',
        CC_ZA = 'South Africa',
        CC_GS = 'South Georgia and The South Sandwich Islands',
        CC_SS = 'South Sudan',
        CC_ES = 'Spain',
        CC_LK = 'Sri Lanka',
        CC_SD = 'Sudan',
        CC_SR = 'Suriname',
        CC_SJ = 'Svalbard and Jan Mayen',
        CC_SZ = 'Swaziland',
        CC_SE = 'Sweden',
        CC_CH = 'Switzerland',
        CC_SY = 'Syrian Arab Republic',
        CC_TW = 'Taiwan, Province of China',
        CC_TJ = 'Tajikistan',
        CC_TZ = 'Tanzania, United Republic of',
        CC_TH = 'Thailand',
        CC_TL = 'Timor-Leste',
        CC_TG = 'Togo',
        CC_TK = 'Tokelau',
        CC_TO = 'Tonga',
        CC_TT = 'Trinidad and Tobago',
        CC_TN = 'Tunisia',
        CC_TR = 'Turkey',
        CC_TM = 'Turkmenistan',
        CC_TC = 'Turks and Caicos Islands',
        CC_TV = 'Tuvalu',
        CC_UG = 'Uganda',
        CC_UA = 'Ukraine',
        CC_AE = 'United Arab Emirates',
        CC_GB = 'United Kingdom',
        CC_US = 'United States',
        CC_UM = 'United States Minor Outlying Islands',
        CC_UY = 'Uruguay',
        CC_UZ = 'Uzbekistan',
        CC_VU = 'Vanuatu',
        CC_VE = 'Venezuela',
        CC_VN = 'Viet Nam',
        CC_VG = 'Virgin Islands, British',
        CC_VI = 'Virgin Islands, U.S.',
        CC_WF = 'Wallis and Futuna',
        CC_EH = 'Western Sahara',
        CC_YE = 'Yemen',
        CC_ZM = 'Zambia',
        CC_ZW = 'Zimbabwe';
    //@}

    //@{ ISO 639-1 language codes (Windows-compatibility subset)
    const
        LC_AF = 'Afrikaans',
        LC_AM = 'Amharic',
        LC_AR = 'Arabic',
        LC_AS = 'Assamese',
        LC_BA = 'Bashkir',
        LC_BE = 'Belarusian',
        LC_BG = 'Bulgarian',
        LC_BN = 'Bengali',
        LC_BO = 'Tibetan',
        LC_BR = 'Breton',
        LC_CA = 'Catalan',
        LC_CO = 'Corsican',
        LC_CS = 'Czech',
        LC_CY = 'Welsh',
        LC_DA = 'Danish',
        LC_DE = 'German',
        LC_DV = 'Divehi',
        LC_EL = 'Greek',
        LC_EN = 'English',
        LC_ES = 'Spanish',
        LC_ET = 'Estonian',
        LC_EU = 'Basque',
        LC_FA = 'Persian',
        LC_FI = 'Finnish',
        LC_FO = 'Faroese',
        LC_FR = 'French',
        LC_GD = 'Scottish Gaelic',
        LC_GL = 'Galician',
        LC_GU = 'Gujarati',
        LC_HE = 'Hebrew',
        LC_HI = 'Hindi',
        LC_HR = 'Croatian',
        LC_HU = 'Hungarian',
        LC_HY = 'Armenian',
        LC_ID = 'Indonesian',
        LC_IG = 'Igbo',
        LC_IS = 'Icelandic',
        LC_IT = 'Italian',
        LC_JA = 'Japanese',
        LC_KA = 'Georgian',
        LC_KK = 'Kazakh',
        LC_KM = 'Khmer',
        LC_KN = 'Kannada',
        LC_KO = 'Korean',
        LC_LB = 'Luxembourgish',
        LC_LO = 'Lao',
        LC_LT = 'Lithuanian',
        LC_LV = 'Latvian',
        LC_MI = 'Maori',
        LC_ML = 'Malayalam',
        LC_MR = 'Marathi',
        LC_MS = 'Malay',
        LC_MT = 'Maltese',
        LC_NE = 'Nepali',
        LC_NL = 'Dutch',
        LC_NO = 'Norwegian',
        LC_OC = 'Occitan',
        LC_OR = 'Oriya',
        LC_PL = 'Polish',
        LC_PS = 'Pashto',
        LC_PT = 'Portuguese',
        LC_QU = 'Quechua',
        LC_RO = 'Romanian',
        LC_RU = 'Russian',
        LC_RW = 'Kinyarwanda',
        LC_SA = 'Sanskrit',
        LC_SI = 'Sinhala',
        LC_SK = 'Slovak',
        LC_SL = 'Slovenian',
        LC_SQ = 'Albanian',
        LC_SV = 'Swedish',
        LC_TA = 'Tamil',
        LC_TE = 'Telugu',
        LC_TH = 'Thai',
        LC_TK = 'Turkmen',
        LC_TR = 'Turkish',
        LC_TT = 'Tatar',
        LC_UK = 'Ukrainian',
        LC_UR = 'Urdu',
        LC_VI = 'Vietnamese',
        LC_WO = 'Wolof',
        LC_YO = 'Yoruba',
        LC_ZH = 'Chinese';
    //@}

    /**
     *    Return list of languages indexed by ISO 639-1 language code
     * @return array
     **/
    public function languages()
    {
        return \Base::instance()->constants($this, 'LC_');
    }

    /**
     *    Return list of countries indexed by ISO 3166-1 country code
     * @return array
     **/
    public function countries()
    {
        return \Base::instance()->constants($this, 'CC_');
    }
}

//! Container for singular object instances
final class Registry
{

    //! Object catalog
    private static $table;

    /**
     *    Return TRUE if object exists in catalog
     * @param $key string
     *             *@return bool
     */
    public static function exists($key)
    {
        return isset(self::$table[$key]);
    }

    /**
     *    Add object to catalog
     * @param $key string
     * @param $obj object
     *             *@return object
     */
    public static function set($key, $obj)
    {
        return self::$table[$key] = $obj;
    }

    /**
     *    Retrieve object from catalog
     * @param $key string
     *             *@return object
     */
    public static function get($key)
    {
        return self::$table[$key];
    }

    /**
     *    Delete object from catalog
     * @param $key string
     *             *@return NULL
     */
    public static function clear($key)
    {
        self::$table[$key] = null;
        unset(self::$table[$key]);
    }

    //! Prohibit cloning
    private function __clone()
    {
    }

    //! Prohibit instantiation
    private function __construct()
    {
    }
}

return Base::instance();
