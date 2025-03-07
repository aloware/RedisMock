<?php

namespace M6Web\Component\RedisMock;

use Illuminate\Support\Arr;
use InvalidArgumentException;
use RuntimeException;

/**
 * Redis mock class
 *
 * @author Florent Dubost <fdubost.externe@m6.fr>
 * @author Denis Roussel <denis.roussel@m6.fr>
 */
class RedisMock
{
    protected static array $dataValues = ['' => []];
    protected static array $dataTypes = ['' => []];
    protected static array $dataTtl = ['' => []];
    protected bool $pipeline = false;
    protected bool $savedPipeline = false;
    protected array $pipedInfo = [];

    /**
     * This value enables to mock several Redis nodes regarding
     * storage. By default, the storage area is called '', but
     * by invoking selectStoarge('anotherName') you can switch to
     * a different storage area.
     * The static storage used to be an array ; it is now an
     * assoc array of storage arrays.
     *
     * @var string
     */
    protected string $storage = '';

    /**
     * @param string $storage
     * @author Gabriel Zerbib <gabriel@figdice.org>
     */
    public function selectStorage(string $storage): void
    {
        $this->storage = $storage;
        if (!array_key_exists($storage, self::$dataValues)) {
            self::$dataValues[$storage] = [];
            self::$dataTypes[$storage] = [];
            self::$dataTtl[$storage] = [];
        }
    }

    public function reset(): static
    {
        self::$dataValues[$this->storage] = [];
        self::$dataTypes[$this->storage] = [];
        self::$dataTtl[$this->storage] = [];

        return $this;
    }

    public function getData()
    {
        return self::$dataValues[$this->storage];
    }

    public function getDataTtl()
    {
        return self::$dataTtl[$this->storage];
    }

    public function getDataTypes()
    {
        return self::$dataTypes[$this->storage];
    }

    // Strings

    public function get($key)
    {
        if (!isset(self::$dataValues[$this->storage][$key]) || is_array(self::$dataValues[$this->storage][$key]) || $this->deleteOnTtlExpired($key)) {
            return $this->returnPipedInfo(null);
        }

        return $this->returnPipedInfo((string)self::$dataValues[$this->storage][$key]);
    }

    public function set($key, $value, $seconds = null)
    {
        if (is_array($seconds)) {
            /**
             * Per https://redis.io/commands/set#options
             * EX seconds -- Set the specified expire time, in seconds.
             * PX milliseconds -- Set the specified expire time, in milliseconds.
             * NX -- Only set the key if it does not already exist.
             * XX -- Only set the key if it already exist.
             */
            $options = $seconds;
            if (in_array('nx', $options, true) && $this->get($key)) {
                return $this->returnPipedInfo(0);
            }
            if (in_array('xx', $options, true) && !$this->get($key)) {
                return $this->returnPipedInfo(0);
            }

            $seconds = null;
            if (isset($options['ex'])) {
                $seconds = $options['ex'];
            } elseif (isset($options['px'])) {
                $seconds = $options['px'] / 1000;
            }
        }
        self::$dataValues[$this->storage][$key] = $value;
        self::$dataTypes[$this->storage][$key] = 'string';

        if (!is_null($seconds)) {
            self::$dataTtl[$this->storage][$key] = time() + (int)$seconds;
        }

        return $this->returnPipedInfo('OK');
    }

    //mset/mget (built on set and get above)
    public function mset($pairs)
    {
        $this->stopPipeline();
        foreach ($pairs as $key => $value) {
            $this->set($key, $value);
        }
        $this->restorePipeline();

        return $this->returnPipedInfo('OK');
    }

    public function mget($fields)
    {
        $this->stopPipeline();
        $result = [];
        foreach ($fields as $field) {
            $result[] = $this->get($field);
        }
        $this->restorePipeline();

        return $this->returnPipedInfo($result);
    }

    public function setex($key, $seconds, $value)
    {
        return $this->set($key, $value, $seconds);
    }

    public function setnx($key, $value)
    {
        if (!$this->get($key)) {
            $this->set($key, $value);
            return $this->returnPipedInfo(1);
        }
        return $this->returnPipedInfo(0);
    }

    public function ttl($key)
    {
        if (!array_key_exists($key, self::$dataValues[$this->storage]) || $this->deleteOnTtlExpired($key)) {
            return $this->returnPipedInfo(-2);
        }

        if (!array_key_exists($key, self::$dataTtl[$this->storage])) {
            return $this->returnPipedInfo(-1);
        }

        return $this->returnPipedInfo(self::$dataTtl[$this->storage][$key] - time());
    }

    public function expire($key, $seconds)
    {
        return $this->expireat($key, time() + $seconds);
    }

    public function expireat($key, $timestamp)
    {
        if (!array_key_exists($key, self::$dataValues[$this->storage]) || $this->deleteOnTtlExpired($key)) {
            return $this->returnPipedInfo(0);
        }

        self::$dataTtl[$this->storage][$key] = $timestamp;

        return $this->returnPipedInfo(1);
    }

    public function incr($key)
    {
        return $this->incrby($key, 1);
    }

    public function incrby($key, $increment)
    {
        $this->deleteOnTtlExpired($key);

        if (!isset(self::$dataValues[$this->storage][$key])) {
            self::$dataValues[$this->storage][$key] = (int)$increment;
        } elseif (!is_integer(self::$dataValues[$this->storage][$key])) {
            return $this->returnPipedInfo(null);
        } else {
            self::$dataValues[$this->storage][$key] += (int)$increment;
        }

        self::$dataTypes[$this->storage][$key] = 'string';

        return $this->returnPipedInfo(self::$dataValues[$this->storage][$key]);
    }

    public function incrbyfloat($key, $increment)
    {
        $this->deleteOnTtlExpired($key);

        if (!isset(self::$dataValues[$this->storage][$key])) {
            self::$dataValues[$this->storage][$key] = (float)$increment;
        } elseif (!is_float(self::$dataValues[$this->storage][$key])) {
            return $this->returnPipedInfo(null);
        } else {
            self::$dataValues[$this->storage][$key] += (float)$increment;
        }

        self::$dataTypes[$this->storage][$key] = 'string';

        return $this->returnPipedInfo((string)self::$dataValues[$this->storage][$key]);
    }

    public function decr($key)
    {
        return $this->decrby($key, 1);
    }

    public function decrby($key, $decrement)
    {
        $this->deleteOnTtlExpired($key);

        if (!isset(self::$dataValues[$this->storage][$key])) {
            self::$dataValues[$this->storage][$key] = 0;
        } elseif (!is_integer(self::$dataValues[$this->storage][$key])) {
            return $this->returnPipedInfo(null);
        }

        self::$dataValues[$this->storage][$key] -= (int)$decrement;

        self::$dataTypes[$this->storage][$key] = 'string';

        return $this->returnPipedInfo(self::$dataValues[$this->storage][$key]);
    }

    public function decrbyfloat($key, $decrement)
    {
        $this->deleteOnTtlExpired($key);

        if (!isset(self::$dataValues[$this->storage][$key])) {
            self::$dataValues[$this->storage][$key] = 0;
        } elseif (!is_float(self::$dataValues[$this->storage][$key])) {
            return $this->returnPipedInfo(null);
        }

        self::$dataValues[$this->storage][$key] -= (float)$decrement;

        self::$dataTypes[$this->storage][$key] = 'string';

        return $this->returnPipedInfo((string)self::$dataValues[$this->storage][$key]);
    }

    // Keys

    public function type($key)
    {
        if ($this->deleteOnTtlExpired($key)) {
            return $this->returnPipedInfo('none');
        }

        if (array_key_exists($key, self::$dataTypes[$this->storage])) {
            return $this->returnPipedInfo(self::$dataTypes[$this->storage][$key]);
        } else {
            return $this->returnPipedInfo('none');
        }
    }

    public function exists($key)
    {
        if ($this->deleteOnTtlExpired($key)) {
            return $this->returnPipedInfo(0);
        }

        return $this->returnPipedInfo(array_key_exists($key, self::$dataValues[$this->storage]) ? 1 : 0);
    }

    public function del($key)
    {
        if (is_array($key)) {
            $keys = $key;
        } else {
            $keys = func_get_args();
        }

        $deletedKeyCount = 0;
        // create a clouser to delete the key
        $remover = function ($key) use (&$deletedKeyCount) {
            if (isset(self::$dataValues[$this->storage][$key])) {
                $deletedKeyCount += is_array(self::$dataValues[$this->storage][$key]) ? count(self::$dataValues[$this->storage][$key]) : 1;
                unset(self::$dataValues[$this->storage][$key]);
                unset(self::$dataTypes[$this->storage][$key]);
                if (array_key_exists($key, self::$dataTtl[$this->storage])) {
                    unset(self::$dataTtl[$this->storage][$key]);
                }
            }
        };
        foreach ($keys as $k) {
            if (is_array($k)) {
                foreach ($k as $key) {
                    $remover($key);
                }
            } else {
                $remover($k);
            }
        }

        return $this->returnPipedInfo($deletedKeyCount);
    }

    public function keys($pattern)
    {
        $pattern = preg_replace(['#\*#', '#\?#', '#(\[[^\]]+\])#'], ['.*', '.', '$1+'], $pattern);

        $results = [];
        foreach (self::$dataValues[$this->storage] as $key => $value) {
            if (preg_match('#^' . $pattern . '$#', $key) and !$this->deleteOnTtlExpired($key)) {
                $results[] = $key;
            }
        }

        return $this->returnPipedInfo($results);
    }

    // Sets

    public function sadd($key, $members)
    {
        // Check if members are passed as simple arguments
        // If so convert to an array
        if (func_num_args() > 2) {
            $arg_list = func_get_args();
            $members = array_slice($arg_list, 1);
        }
        // convert single argument to array
        if (!is_array($members)) {
            $members = [$members];
        }

        $this->deleteOnTtlExpired($key);

        // Check if key is defined
        if (isset(self::$dataValues[$this->storage][$key]) && !is_array(self::$dataValues[$this->storage][$key])) {
            return $this->returnPipedInfo(null);
        }

        if (!isset(self::$dataValues[$this->storage][$key])) {
            self::$dataValues[$this->storage][$key] = [];
        }

        // Calculate new members
        $newMembers = array_diff($members, self::$dataValues[$this->storage][$key]);

        // Insert new members (based on diff above, these should be unique)
        self::$dataValues[$this->storage][$key] = array_merge(self::$dataValues[$this->storage][$key], $newMembers);

        self::$dataTypes[$this->storage][$key] = 'set';

        if (array_key_exists($key, self::$dataTtl[$this->storage])) {
            unset(self::$dataTtl[$this->storage][$key]);
        }

        // return number of new members inserted
        return $this->returnPipedInfo(sizeof($newMembers));

    }

    public function sdiff($key)
    {
        $this->stopPipeline();
        $keys = is_array($key) ? $key : func_get_args();
        $result = [];
        foreach ($keys as $key) {
            $result[] = $this->smembers($key);
        }
        $result = array_values(array_diff(...$result));

        $this->restorePipeline();

        return $this->returnPipedInfo($result);
    }

    public function smembers($key)
    {
        if (!isset(self::$dataValues[$this->storage][$key]) || $this->deleteOnTtlExpired($key)) {
            return $this->returnPipedInfo([]);
        }

        return $this->returnPipedInfo(self::$dataValues[$this->storage][$key]);
    }

    public function sunion($key)
    {
        $this->stopPipeline();
        $keys = is_array($key) ? $key : func_get_args();
        $result = [];
        foreach ($keys as $key) {
            $result = array_merge($result, $this->smembers($key));
        }
        $result = array_values(array_unique($result));

        $this->restorePipeline();

        return $this->returnPipedInfo($result);
    }

    public function sinter($key)
    {
        $this->stopPipeline();
        $keys = is_array($key) ? $key : func_get_args();
        $result = [];
        foreach ($keys as $key) {
            $result[] = $this->smembers($key);
        }
        $result = array_values(array_intersect(...$result));

        $this->restorePipeline();

        return $this->returnPipedInfo($result);
    }

    public function scard($key)
    {
        // returns 0 if key not found
        if (!isset(self::$dataValues[$this->storage][$key])) {
            return $this->returnPipedInfo(0);
        }
        return $this->returnPipedInfo(count(self::$dataValues[$this->storage][$key]));
    }

    public function srem($key, $members)
    {
        // Check if members are passed as simple arguments
        // If so convert to an array
        if (func_num_args() > 2) {
            $arg_list = func_get_args();
            $members = array_slice($arg_list, 1);
        }
        // convert single argument to array
        if (!is_array($members)) {
            $members = [$members];
        }

        if (!isset(self::$dataValues[$this->storage][$key]) || $this->deleteOnTtlExpired($key)) {
            return $this->returnPipedInfo(0);
        }

        // Calculable intersection to we know how many members were removed
        $remMembers = array_intersect($members, self::$dataValues[$this->storage][$key]);
        // Remove members
        self::$dataValues[$this->storage][$key] = array_diff(self::$dataValues[$this->storage][$key], $members);

        // Unset key is set empty
        if (0 === count(self::$dataValues[$this->storage][$key])) {
            unset(self::$dataTypes[$this->storage][$key]);
        }

        // return number of members removed
        return $this->returnPipedInfo(sizeof($remMembers));
    }

    public function sismember($key, $member)
    {
        if (!isset(self::$dataValues[$this->storage][$key]) || !in_array($member, self::$dataValues[$this->storage][$key]) || $this->deleteOnTtlExpired($key)) {
            return $this->returnPipedInfo(0);
        }

        return $this->returnPipedInfo(1);
    }


    /**
     * Mock the `sscan` command
     *
     * @see https://redis.io/commands/sscan
     * @param string $key
     * @param int $cursor
     * @param array $options contain options of the command, with values (ex ['MATCH' => 'st*', 'COUNT' => 42] )
     * @return $this|array|null
     */
    public function sscan(string $key, int $cursor = 0, array $options = []): static|array|null
    {
        $match = $options['MATCH'] ?? '*';
        $count = $options['COUNT'] ?? 10;
        $maximumValue = $cursor + $count - 1;

        if (!isset(self::$dataValues[$this->storage][$key]) || $this->deleteOnTtlExpired($key)) {
            return $this->returnPipedInfo([0, []]);
        }

        // List of all keys in the storage (already ordered by index).
        $set = self::$dataValues[$this->storage][$key];
        $maximumListElement = count($set);

        // Next cursor position
        $nextCursorPosition = 0;
        // Matched values.
        $values = [];
        // Pattern, for find matched values.
        $pattern = sprintf('/^%s$/', str_replace(['*', '/'], ['.*', '\/'], $match));

        for ($i = $cursor; $i <= $maximumValue; $i++) {
            if (isset($set[$i])) {
                $nextCursorPosition = $i >= $maximumListElement ? 0 : $i + 1;

                if ('*' === $match || 1 === preg_match($pattern, $set[$i])) {
                    $values[] = $set[$i];
                }

            } else {
                // Out of the arrays values, return first element
                $nextCursorPosition = 0;
            }
        }

        return $this->returnPipedInfo([$nextCursorPosition, $values]);
    }

    // Lists

    public function llen($key)
    {
        if (!isset(self::$dataValues[$this->storage][$key]) || $this->deleteOnTtlExpired($key)) {
            return $this->returnPipedInfo(0);
        }

        return $this->returnPipedInfo(count(self::$dataValues[$this->storage][$key]));
    }

    public function lindex($key, $index)
    {
        if (!isset(self::$dataValues[$this->storage][$key]) || $this->deleteOnTtlExpired($key)) {
            // Doc (http://redis.io/commands/lindex) : "When the value at key is not a list, an error is returned."
            // but what is "an error" ?
            return $this->returnPipedInfo(null);
        }

        $size = count(self::$dataValues[$this->storage][$key]);

        // Empty index or out of range
        if ($size == 0 || abs($index) >= $size) {
            return $this->returnPipedInfo(null);
        }

        // Compute position for positive or negative $index (0 for first, -1 for last, ...)
        $position = ($size + $index) % $size;

        if (!isset(self::$dataValues[$this->storage][$key][$position])) {
            return $this->returnPipedInfo(null);
        }

        return $this->returnPipedInfo(self::$dataValues[$this->storage][$key][$position]);
    }

    public function lrem($key, $value, $count)
    {
        if (!isset(self::$dataValues[$this->storage][$key]) || !in_array($value, self::$dataValues[$this->storage][$key]) || $this->deleteOnTtlExpired($key)) {
            return $this->returnPipedInfo(0);
        }

        $arr = self::$dataValues[$this->storage][$key];
        $reversed = false;

        if ($count < 0) {
            $arr = array_reverse($arr);
            $count = abs($count);
            $reversed = true;
        } else {
            if ($count == 0) {
                $count = count($arr);
            }
        }

        $arr = array_filter($arr, function ($curValue) use (&$count, $value) {
            if ($count && ($curValue == $value)) {
                $count--;
                return false;
            }

            return true;
        });

        $deletedItems = count(self::$dataValues[$this->storage][$key]) - count($arr);

        if ($reversed) {
            $arr = array_reverse($arr);
        }

        self::$dataValues[$this->storage][$key] = array_values($arr);

        return $this->returnPipedInfo($deletedItems);
    }

    public function lpush($key, $value)
    {
        if ($this->deleteOnTtlExpired($key) || !isset(self::$dataValues[$this->storage][$key])) {
            self::$dataValues[$this->storage][$key] = [];
        }

        if (isset(self::$dataValues[$this->storage][$key]) && !is_array(self::$dataValues[$this->storage][$key])) {
            return $this->returnPipedInfo(null);
        }

        // Get all values (less first parameters who is $key)
        $values = func_get_args();
        array_shift($values);

        foreach ($values as $value) {
            array_unshift(self::$dataValues[$this->storage][$key], $value);
        }

        return $this->returnPipedInfo(count(self::$dataValues[$this->storage][$key]));
    }

    public function rpush($key, $value)
    {
        if ($this->deleteOnTtlExpired($key) || !isset(self::$dataValues[$this->storage][$key])) {
            self::$dataValues[$this->storage][$key] = [];
        }

        if (isset(self::$dataValues[$this->storage][$key]) && !is_array(self::$dataValues[$this->storage][$key])) {
            return $this->returnPipedInfo(null);
        }

        self::$dataValues[$this->storage][$key][] = $value;

        return $this->returnPipedInfo(count(self::$dataValues[$this->storage][$key]));
    }

    public function lpop($key)
    {
        if (!isset(self::$dataValues[$this->storage][$key]) || !is_array(self::$dataValues[$this->storage][$key]) || $this->deleteOnTtlExpired($key)) {
            return $this->returnPipedInfo(null);
        }

        return $this->returnPipedInfo(array_shift(self::$dataValues[$this->storage][$key]));
    }

    public function rpop($key)
    {
        if (!isset(self::$dataValues[$this->storage][$key]) || !is_array(self::$dataValues[$this->storage][$key]) || $this->deleteOnTtlExpired($key)) {
            return $this->returnPipedInfo(null);
        }

        return $this->returnPipedInfo(array_pop(self::$dataValues[$this->storage][$key]));
    }

    public function ltrim($key, $start, $stop)
    {
        $this->deleteOnTtlExpired($key);

        if (isset(self::$dataValues[$this->storage][$key]) && !is_array(self::$dataValues[$this->storage][$key])) {
            return $this->returnPipedInfo(null);
        } elseif (!isset(self::$dataValues[$this->storage][$key])) {
            return $this->returnPipedInfo('OK');
        }

        if ($start < 0) {
            if (abs($start) > count(self::$dataValues[$this->storage][$key])) {
                $start = 0;
            } else {
                $start = count(self::$dataValues[$this->storage][$key]) + $start;
            }
        }

        if ($stop >= 0) {
            $length = $stop - $start + 1;
        } else {
            if ($stop == -1) {
                $length = null;
            } else {
                $length = $stop + 1;
            }
        }

        self::$dataValues[$this->storage][$key] = array_slice(self::$dataValues[$this->storage][$key], $start, $length);

        if (!count(self::$dataValues[$this->storage][$key])) {
            $this->stopPipeline();
            $this->del($key);
            $this->restorePipeline();
        }

        return $this->returnPipedInfo('OK');
    }

    public function lrange($key, $start, $stop)
    {
        $this->deleteOnTtlExpired($key);

        if (!isset(self::$dataValues[$this->storage][$key]) || !is_array(self::$dataValues[$this->storage][$key])) {
            return $this->returnPipedInfo([]);
        }

        if ($start < 0) {
            if (abs($start) > count(self::$dataValues[$this->storage][$key])) {
                $start = 0;
            } else {
                $start = count(self::$dataValues[$this->storage][$key]) + $start;
            }
        }

        if ($stop >= 0) {
            $length = $stop - $start + 1;
        } else {
            if ($stop == -1) {
                $length = null;
            } else {
                $length = $stop + 1;
            }
        }

        $data = array_slice(self::$dataValues[$this->storage][$key], $start, $length);

        return $this->returnPipedInfo($data);
    }

    // Hashes

    public function hset($key, $field, $value)
    {
        $this->deleteOnTtlExpired($key);

        if (isset(self::$dataValues[$this->storage][$key]) && !is_array(self::$dataValues[$this->storage][$key])) {
            return $this->returnPipedInfo(null);
        }

        $isNew = !isset(self::$dataValues[$this->storage][$key]) || !isset(self::$dataValues[$this->storage][$key][$field]);

        self::$dataValues[$this->storage][$key][$field] = $value;
        self::$dataTypes[$this->storage][$key] = 'hash';
        if (array_key_exists($key, self::$dataTtl[$this->storage])) {
            unset(self::$dataTtl[$this->storage][$key]);
        }

        return $this->returnPipedInfo((int)$isNew);
    }

    public function hsetnx($key, $field, $value)
    {
        $this->deleteOnTtlExpired($key);

        if (isset(self::$dataValues[$this->storage][$key]) && !is_array(self::$dataValues[$this->storage][$key])) {
            return $this->returnPipedInfo(null);
        }

        $isNew = !isset(self::$dataValues[$this->storage][$key][$field]);

        if ($isNew) {
            self::$dataValues[$this->storage][$key][$field] = $value;
            self::$dataTypes[$this->storage][$key] = 'hash';
            if (array_key_exists($key, self::$dataTtl[$this->storage])) {
                unset(self::$dataTtl[$this->storage][$key]);
            }
        }

        return $this->returnPipedInfo((int)$isNew);
    }

    public function hmset($key, $pairs)
    {
        $this->deleteOnTtlExpired($key);

        if (isset(self::$dataValues[$this->storage][$key]) && !is_array(self::$dataValues[$this->storage][$key])) {
            return $this->returnPipedInfo(null);
        }

        $this->stopPipeline();
        foreach ($pairs as $field => $value) {
            $this->hset($key, $field, $value);
        }
        $this->restorePipeline();

        return $this->returnPipedInfo('OK');
    }


    public function hget($key, $field)
    {
        if (!isset(self::$dataValues[$this->storage][$key][$field]) || $this->deleteOnTtlExpired($key)) {
            return $this->returnPipedInfo(null);
        }

        return $this->returnPipedInfo((string)self::$dataValues[$this->storage][$key][$field]);
    }

    public function hmget($key, $fields)
    {
        $result = [];
        foreach ($fields as $field) {
            if (!isset(self::$dataValues[$this->storage][$key][$field]) || $this->deleteOnTtlExpired($key)) {
                $result[$field] = null;
            } else {
                $result[$field] = (string)self::$dataValues[$this->storage][$key][$field];
            }
        }

        return $this->returnPipedInfo($result);
    }

    /**
     * @throws UnsupportedException
     */
    public function hdel($key, $fields, ...$otherFields)
    {
        if (isset(self::$dataValues[$this->storage][$key]) && !is_array(self::$dataValues[$this->storage][$key])) {
            return $this->returnPipedInfo(null);
        }

        if (!array_key_exists($key, self::$dataValues[$this->storage]) || $this->deleteOnTtlExpired($key)) {
            return $this->returnPipedInfo(0);
        }

        $fields = is_array($fields) ? $fields : [$fields];
        $fields = array_merge($fields, Arr::flatten($otherFields));
        $info = 0;

        foreach ($fields as $field) {
            if (array_key_exists($field, self::$dataValues[$this->storage][$key])) {
                unset(self::$dataValues[$this->storage][$key][$field]);
                if (0 === count(self::$dataValues[$this->storage][$key])) {
                    unset(self::$dataTypes[$this->storage][$key]);
                }

                $info++;
            }
        }

        return $this->returnPipedInfo($info);
    }

    public function hkeys($key)
    {
        if (!isset(self::$dataValues[$this->storage][$key]) || !is_array(self::$dataValues[$this->storage][$key]) || $this->deleteOnTtlExpired($key)) {
            return $this->returnPipedInfo([]);
        }

        return $this->returnPipedInfo(array_keys(self::$dataValues[$this->storage][$key]));
    }

    public function hlen($key)
    {
        if (!isset(self::$dataValues[$this->storage][$key]) || !is_array(self::$dataValues[$this->storage][$key]) || $this->deleteOnTtlExpired($key)) {
            return $this->returnPipedInfo(0);
        }

        return $this->returnPipedInfo(count(self::$dataValues[$this->storage][$key]));
    }

    public function hgetall($key)
    {
        if (!isset(self::$dataValues[$this->storage][$key]) || $this->deleteOnTtlExpired($key)) {
            return $this->returnPipedInfo([]);
        }

        $values = [];
        foreach (self::$dataValues[$this->storage][$key] as $index => $value) {
            $values[$index] = (string)$value;
        }
        return $this->returnPipedInfo($values);
    }

    public function hexists($key, $field)
    {
        $this->deleteOnTtlExpired($key);

        return $this->returnPipedInfo((int)isset(self::$dataValues[$this->storage][$key][$field]));
    }

    public function hincrby($key, $field, $increment)
    {
        $this->deleteOnTtlExpired($key);

        if (!isset(self::$dataValues[$this->storage][$key])) {
            self::$dataValues[$this->storage][$key] = [];
        }

        if (!isset(self::$dataValues[$this->storage][$key][$field])) {
            self::$dataValues[$this->storage][$key][$field] = (int)$increment;
        } elseif (!is_integer(self::$dataValues[$this->storage][$key][$field])) {
            return $this->returnPipedInfo(null);
        } else {
            self::$dataValues[$this->storage][$key][$field] += (int)$increment;
        }

        return $this->returnPipedInfo(self::$dataValues[$this->storage][$key][$field]);
    }

    // Sorted set

    public function zrange($key, $start, $stop, $withscores = false)
    {
        if (!isset(self::$dataValues[$this->storage][$key]) || $this->deleteOnTtlExpired($key)) {
            return $this->returnPipedInfo([]);
        }

        $this->stopPipeline();
        $set = $this->zrangebyscore($key, '-inf', '+inf', ['withscores' => $withscores]);
        $this->restorePipeline();

        if ($start < 0) {
            if (abs($start) > count($set)) {
                $start = 0;
            } else {
                $start = count($set) + $start;
            }
        }

        if ($stop >= 0) {
            $length = $stop - $start + 1;
        } else {
            if ($stop == -1) {
                $length = null;
            } else {
                $length = $stop + 1;
            }
        }

        return $this->returnPipedInfo(array_slice($set, $start, $length));
    }

    public function zrevrange($key, $start, $stop, $withscores = false)
    {
        if (!isset(self::$dataValues[$this->storage][$key]) || $this->deleteOnTtlExpired($key)) {
            return $this->returnPipedInfo([]);
        }

        $this->stopPipeline();
        $set = $this->zrevrangebyscore($key, '+inf', '-inf', ['withscores' => $withscores]);
        $this->restorePipeline();

        if ($start < 0) {
            if (abs($start) > count($set)) {
                $start = 0;
            } else {
                $start = count($set) + $start;
            }
        }

        if ($stop >= 0) {
            $length = $stop - $start + 1;
        } else {
            if ($stop == -1) {
                $length = null;
            } else {
                $length = $stop + 1;
            }
        }

        return $this->returnPipedInfo(array_slice($set, $start, $length));
    }

    protected function zrangebyscoreHelper($key, $min, $max, array $options = [], $rev = false)
    {
        if (!isset(self::$dataValues[$this->storage][$key]) || $this->deleteOnTtlExpired($key)) {
            return $this->returnPipedInfo([]);
        }

        if (!is_array(self::$dataValues[$this->storage][$key])) {
            return $this->returnPipedInfo(null);
        }

        if (!isset($options['limit']) || !is_array($options['limit']) || count($options['limit']) != 2) {
            $options['limit'] = [0, count(self::$dataValues[$this->storage][$key])];
        }

        $set = self::$dataValues[$this->storage][$key];
        uksort(self::$dataValues[$this->storage][$key], function ($a, $b) use ($set, $rev) {
            if ($set[$a] > $set[$b]) {
                return $rev ? -1 : 1;
            } elseif ($set[$a] < $set[$b]) {
                return $rev ? 1 : -1;
            } else {
                return $rev ? -strcmp($a, $b) : strcmp($a, $b);
            }
        });

        if ($min == '-inf' && $max == '+inf') {
            $slice = array_slice(self::$dataValues[$this->storage][$key], $options['limit'][0], $options['limit'][1], true);
            if (isset($options['withscores']) && $options['withscores']) {
                return $this->returnPipedInfo(array_map('strval', $slice));
            } else {
                return $this->returnPipedInfo(array_keys($slice));
            }
        }

        $isInfMax = function ($v) use ($max) {
            if (str_contains($max, '(')) {
                return $v < (int)substr($max, 1);
            } else {
                return $v <= (int)$max;
            }
        };

        $isSupMin = function ($v) use ($min) {
            if (str_contains($min, '(')) {
                return $v > (int)substr($min, 1);
            } else {
                return $v >= (int)$min;
            }
        };

        $results = [];
        foreach (self::$dataValues[$this->storage][$key] as $k => $v) {
            if ($min == '-inf' && $isInfMax($v)) {
                $results[$k] = $v;
            } elseif ($max == '+inf' && $isSupMin($v)) {
                $results[$k] = $v;
            } elseif ($isSupMin($v)) {
                $results[$k] = $v;
            }
        }

        $slice = array_slice($results, $options['limit'][0], $options['limit'][1], true);
        if (isset($options['withscores']) && $options['withscores']) {
            return $this->returnPipedInfo(array_map('strval', $slice));
        } else {
            return $this->returnPipedInfo(array_keys($slice));
        }
    }

    public function zrangebyscore($key, $min, $max, array $options = [])
    {
        return $this->zrangebyscoreHelper($key, $min, $max, $options);
    }

    public function zrevrangebyscore($key, $max, $min, array $options = [])
    {
        return $this->zrangebyscoreHelper($key, $min, $max, $options, true);
    }

    /**
     * @throws UnsupportedException
     */
    public function zadd($key, ...$args)
    {
        if (count($args) === 1) {
            if (count($args[0]) > 1) {
                throw new UnsupportedException('In RedisMock, `zadd` used with an array cannot be used to set more than one element.');
            }
            $score = reset($args[0]);
            $member = key($args[0]);
        } elseif (count($args) === 2) {
            $score = $args[0];
            $member = $args[1];
        } else {
            throw new UnsupportedException('In RedisMock, `zadd` command  can either take two arguments (score and member), or one associative array with member as key and score as value');
        }

        $this->deleteOnTtlExpired($key);

        if (isset(self::$dataValues[$this->storage][$key]) && !is_array(self::$dataValues[$this->storage][$key])) {
            return $this->returnPipedInfo(null);
        }

        $isNew = !isset(self::$dataValues[$this->storage][$key][$member]);

        if (!is_numeric($score)) {
            throw new InvalidArgumentException('Score should be either an integer or a float.');
        }
        $score += 0; // convert potential string value to int or float

        self::$dataValues[$this->storage][$key][$member] = $score;
        self::$dataTypes[$this->storage][$key] = 'zset';
        if (array_key_exists($key, self::$dataTtl[$this->storage])) {
            unset(self::$dataTtl[$this->storage][$key]);
        }

        asort(self::$dataValues[$this->storage][$key]);

        return $this->returnPipedInfo((int)$isNew);
    }

    public function zscore($key, $member)
    {
        if (!isset(self::$dataValues[$this->storage][$key][$member]) || $this->deleteOnTtlExpired($key)) {
            return $this->returnPipedInfo(null);
        }

        return $this->returnPipedInfo((string)self::$dataValues[$this->storage][$key][$member]);
    }

    public function zcard($key)
    {
        // returns 0 if key not found
        if (!isset(self::$dataValues[$this->storage][$key]) || $this->deleteOnTtlExpired($key)) {
            return $this->returnPipedInfo(0);
        }
        return $this->returnPipedInfo(count(self::$dataValues[$this->storage][$key]));
    }

    public function zcount($key, $min, $max)
    {
        $result = $this->zrangebyscore($key, $min, $max);
        if (!is_array($result)) {
            return $result;
        }

        return count($result);
    }

    /**
     * @throws UnsupportedException
     */
    public function zincrby($key, $increment, $member)
    {
        if (!isset(self::$dataValues[$this->storage][$key][$member]) || $this->deleteOnTtlExpired($key)) {
            $this->zadd($key, $increment, $member);
            return $this->returnPipedInfo($increment);
        }

        $newScore = self::$dataValues[$this->storage][$key][$member] + $increment;
        $this->zadd($key, $newScore, $member);

        return $this->returnPipedInfo($newScore);
    }

    public function zrank($key, $member)
    {
        if (!isset(self::$dataValues[$this->storage][$key]) || $this->deleteOnTtlExpired($key)) {
            return $this->returnPipedInfo(null);
        }

        // Get position of key $member (absolute, 0-based)
        $rank = array_search($member, array_keys(self::$dataValues[$this->storage][$key]));

        if ($rank === false) {
            return $this->returnPipedInfo(null);
        }

        return $this->returnPipedInfo($rank);
    }

    public function zrevrank($key, $member)
    {
        $rank = $this->zrank($key, $member);
        if ($rank === null) {
            return $this->returnPipedInfo(null);
        }

        $revRank = count(self::$dataValues[$this->storage][$key]) - $rank - 1;

        return $this->returnPipedInfo($revRank);
    }

    /**
     * @throws UnsupportedException
     */
    public function zremrangebyscore($key, $min, $max)
    {
        if (!isset(self::$dataValues[$this->storage][$key]) || $this->deleteOnTtlExpired($key)) {
            return $this->returnPipedInfo(0);
        }

        $remNumber = 0;

        $this->stopPipeline();

        if ($toRem = $this->zrangebyscore($key, $min, $max)) {
            foreach ($toRem as $member) {
                if ($this->zrem($key, $member)) {
                    $remNumber++;
                }
            }
        }

        $this->restorePipeline();

        return $this->returnPipedInfo($remNumber);
    }

    /**
     * @throws UnsupportedException
     */
    public function zrem($key, $member)
    {
        if (func_num_args() > 2) {
            throw new UnsupportedException('In RedisMock, `zrem` command can not remove more than one member at once.');
        }

        if (isset(self::$dataValues[$this->storage][$key]) && !is_array(self::$dataValues[$this->storage][$key]) || !isset(self::$dataValues[$this->storage][$key][$member]) || $this->deleteOnTtlExpired($key)) {
            return $this->returnPipedInfo(0);
        }

        unset(self::$dataValues[$this->storage][$key][$member]);

        if (0 === count(self::$dataValues[$this->storage][$key])) {
            unset(self::$dataTypes[$this->storage][$key]);
        }

        return $this->returnPipedInfo(1);
    }

    /**
     * @throws UnsupportedException
     */
    public function zunionstore($destination, array $keys, array $options = [])
    {
        $weights = $options['WEIGHTS'] ?? array_fill(0, count($keys), 1);
        $aggregate = $options['AGGREGATE'] ?? 'SUM';

        if (count($weights) !== count($keys)) {
            throw new RuntimeException('there must be one weight per key');
        }

        if ($aggregate !== 'SUM' && $aggregate !== 'MIN' && $aggregate !== 'MAX') {
            throw new RuntimeException('unknown aggregate function');
        }

        $this->del($destination);
        foreach ($keys as $index => $key) {
            foreach ($this->zrangebyscore($key, '-inf', '+inf', ['withscores' => true]) as $member => $score) {
                $weight = $weights[$index];
                $weightedScore = $score * $weight;

                $currentScore = $this->zscore($destination, $member);
                if ($currentScore === null) {
                    $this->zadd($destination, $weightedScore, $member);
                } else {
                    if ($aggregate === 'SUM') {
                        $this->zincrby($destination, $weightedScore, $member);
                    } else {
                        if ($aggregate === 'MIN') {
                            $finalScore = min($currentScore, $weightedScore);
                            $this->zadd($destination, $finalScore, $member);
                        } else {
                            if ($aggregate === 'MAX') {
                                $finalScore = max($currentScore, $weightedScore);
                                $this->zadd($destination, $finalScore, $member);
                            }
                        }
                    }
                }
            }
        }

        return $this->zcount($destination, '-inf', '+inf');
    }

    // Server

    public function dbsize()
    {
        foreach ($this->getData() as $key => $value) {
            $this->deleteOnTtlExpired($key);
        }
        return $this->returnPipedInfo(count($this->getData()));
    }

    public function flushdb()
    {
        $this->reset();

        return $this->returnPipedInfo('OK');
    }

    // Transactions

    public function multi(): static
    {
        $this->pipeline = true;
        $this->pipedInfo = [];

        return $this;
    }

    public function discard(): string
    {
        $this->pipeline = false;
        $this->pipedInfo = [];

        return 'OK';
    }

    public function exec(): array
    {
        $pipedInfo = $this->pipedInfo;

        $this->discard();

        return $pipedInfo;
    }

    public function watch($key): true
    {
        return true;
    }

    public function unwatch(): true
    {
        return true;
    }

    // Client pipeline

    public function pipeline(): static
    {
        $this->pipeline = true;

        return $this;
    }

    public function execute(): static
    {
        $this->pipeline = false;

        return $this;
    }

    // Protected methods

    protected function stopPipeline(): void
    {
        $this->savedPipeline = $this->pipeline;
        $this->pipeline = false;
    }

    protected function restorePipeline(): void
    {
        $this->pipeline = $this->savedPipeline;
    }

    protected function returnPipedInfo($info)
    {
        if (!$this->pipeline) {
            return $info;
        }

        $this->pipedInfo[] = $info;

        return $this;
    }

    protected function deleteOnTtlExpired($key): bool
    {
        if (array_key_exists($key, self::$dataTtl[$this->storage]) and (time() > self::$dataTtl[$this->storage][$key])) {
            // clean datas
            $this->stopPipeline();
            $this->del($key);
            $this->restorePipeline();

            return true;
        }

        return false;
    }

    /**
     * Mock the `quit` command
     *
     * @see https://redis.io/commands/quit
     */
    public function quit(): string
    {
        return 'OK'; // see the doc : always return `OK` ...
    }

    /**
     * Mock the `monitor` command
     *
     * @see https://redis.io/commands/monitor
     */
    public function monitor(): void
    {
        return;
    }

    /**
     * Mock the `scan` command
     *
     * @see https://redis.io/commands/scan
     * @param int   $cursor
     * @param array $options contain options of the command, with values (ex ['MATCH' => 'st*', 'COUNT' => 42] )
     *
     * @return array
     */
    public function scan(int $cursor = 0, array $options = []): array
    {
        // Define default options
        $match = $options['MATCH'] ?? '*';
        $count = $options['COUNT'] ?? 10;
        $maximumValue = $cursor + $count - 1;

        // List of all keys in the storage (already ordered by index).
        $keysArray = array_keys(self::$dataValues[$this->storage]);
        $maximumListElement = count($keysArray);

        // Next cursor position
        $nextCursorPosition = 0;
        // Matched values.
        $values = [];
        // Pattern, for find matched values.
        $escapedMatch = str_replace('/', '\/', $match);
        $pattern = str_replace('*', '.*', sprintf('/^%s$/', $escapedMatch));

        for ($i = $cursor; $i <= $maximumValue; $i++) {
            if (isset($keysArray[$i])) {
                $nextCursorPosition = $i >= $maximumListElement ? 0 : $i + 1;

                if ('*' === $match || 1 === preg_match($pattern, $keysArray[$i])) {
                    $values[] = $keysArray[$i];
                }

            } else {
                // Out of the arrays values, return first element
                $nextCursorPosition = 0;
            }
        }

        return [$nextCursorPosition, $values];
    }

    /**
     * Mock the `rpoplpush` command
     *
     * @see https://redis.io/commands/rpoplpush
     * @param string $sourceList
     * @param string $destinationList
     * @return RedisMock
     */
    public function rpoplpush(string $sourceList, string $destinationList): static
    {
        // RPOP (get last value of the $sourceList)
        $rpopValue = $this->rpop($sourceList);

        // LPUSH (send the value at the end of the $destinationList)
        if (null !== $rpopValue) {
            $this->lpush($destinationList, $rpopValue);
        }

        // return the rpop value;
        return $rpopValue;
    }

    /**
     * Evaluate a LUA script serverside, from the SHA1 hash of the script instead of the script itself.
     *
     * @param string $script
     * @param int    $numkeys
     * @param mixed  $arguments
     * @return mixed
     */
    public function evalsha(string $script, array $arguments, int $numkeys): mixed
    {
        return null;
    }

    /**
     * Evaluate a script and return its result.
     *
     * @param string  $script
     * @param int     $numberOfKeys
     * @param dynamic $arguments
     * @return mixed
     */
    public function eval(string $script, array $arguments, int $numberOfKeys): mixed
    {
        return null;
    }

    /**
     * Mock the `bitcount` command
     *
     * @see https://redis.io/commands/bitcount
     *
     * @param string $key
     * @return int
     */
    public function bitcount(string $key): int
    {
        return count(self::$dataValues[$this->storage][$key] ?? []);
    }

    /**
     * Mock the `setbit` command
     *
     * @see https://redis.io/commands/setbit
     *
     * @param string $key
     * @param int    $offset
     * @param int    $value
     * @return int original value before the update
     */
    public function setbit(string $key, int $offset, int $value): int
    {
        if (!isset(self::$dataValues[$this->storage][$key])) {
            self::$dataValues[$this->storage][$key] = [];
        }

        $originalValue = self::$dataValues[$this->storage][$key][$offset] ?? 0;

        self::$dataValues[$this->storage][$key][$offset] = $value;

        return $originalValue;
    }

    /**
     * Mock the `getbit` command
     *
     * @see https://redis.io/commands/getbit
     *
     * @param string $key
     * @param int    $offset
     * @return int
     */
    public function getbit(string $key, int $offset): int
    {
        return self::$dataValues[$this->storage][$key][$offset] ?? 0;
    }
}
