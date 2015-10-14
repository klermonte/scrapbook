<?php

namespace MatthiasMullie\Scrapbook\Buffered;

use MatthiasMullie\Scrapbook\Exception\UncommittedTransaction;
use MatthiasMullie\Scrapbook\KeyValueStore;

/**
 * This is a helper class for BufferedStore & TransactionalStore, which buffer
 * real cache requests in memory.
 *
 * This class accepts 2 caches: a KeyValueStore object (the real cache) and a
 * Buffer instance (to read data from as long as it hasn't been committed)
 *
 * Every write action will first store the data in the Buffer instance, and
 * then store the update to be performed in $deferred.
 * Once commit() is called, all those $deferred updates are executed against
 * the real cache. All deferred writes that fail to apply will cause that cache
 * key to be deleted, to ensure cache consistency.
 * Until commit() is called, all data is read from the temporary Buffer instance.
 *
 * @author Matthias Mullie <scrapbook@mullie.eu>
 * @copyright Copyright (c) 2014, Matthias Mullie. All rights reserved.
 * @license MIT License
 */
class Transaction implements KeyValueStore
{
    /**
     * @var KeyValueStore
     */
    protected $cache;

    /**
     * @var Buffer
     */
    protected $local;

    /**
     * We'll return stub CAS tokens in order to reliably replay the CAS actions
     * to the real cache. This will hold a map of stub token => value, used to
     * verify when we do the actual CAS.
     *
     * @see cas()
     *
     * @var mixed[]
     */
    protected $tokens = array();

    /**
     * Deferred updates to be committed to real cache.
     *
     * @see defer()
     *
     * @var array
     */
    protected $deferred = array();

    /**
     * Array of keys we've written to. They'll briefly be stored here after
     * being committed, until all other writes in the transaction have been
     * committed. This way, if a later write fails, we can invalidate previous
     * updates based on those keys we wrote to.
     *
     * @see commit()
     *
     * @var string[]
     */
    protected $committed = array();

    /**
     * Suspend reads from real cache. This is used when a flush is issued but it
     * has not yet been committed. In that case, we don't want to fall back to
     * real cache values, because they're about to be flushed.
     *
     * @var bool
     */
    protected $suspend = false;

    /**
     * @param Buffer        $local
     * @param KeyValueStore $cache
     */
    public function __construct(Buffer $local, KeyValueStore $cache)
    {
        $this->cache = $cache;

        // (uncommitted) writes must never be evicted (even if that means
        // crashing because we run out of memory)
        $this->local = $local;
    }

    /**
     * @throws UncommittedTransaction
     */
    public function __destruct()
    {
        if (!empty($this->deferred)) {
            throw new UncommittedTransaction(
                'Transaction is about to be destroyed without having been '.
                'committed or rolled back.'
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function get($key, &$token = null)
    {
        $value = $this->local->get($key);

        // short-circuit reading from real cache if we have an uncommitted flush
        if ($this->suspend && $value === false) {
            // flush hasn't been committed yet, don't read from real cache!
            return false;
        }

        if ($value === false) {
            if ($this->local->expired($key)) {
                /*
                 * Item used to exist in local cache, but is now expired. This
                 * is used when values are to be deleted: we don't want to reach
                 * out to real storage because that would respond with the not-
                 * yet-deleted value.
                 */

                return false;
            }

            // unknown in local cache = fetch from source cache
            $value = $this->cache->get($key, $token);
        }

        /*
         * $token will be unreliable to the deferred updates so generate
         * a custom one and keep the associated value around.
         * Read more details in PHPDoc for function cas().
         * uniqid is ok here. Doesn't really have to be unique across
         * servers, just has to be unique every time it's called in this
         * one particular request - which it is.
         */
        $token = uniqid();
        $this->tokens[$token] = serialize($value);

        return $value;
    }

    /**
     * {@inheritdoc}
     */
    public function getMulti(array $keys, array &$tokens = null)
    {
        // retrieve all that we can from local cache
        $values = $this->local->getMulti($keys);

        // short-circuit reading from real cache if we have an uncommitted flush
        if (!$this->suspend) {
            // figure out which missing key we need to get from real cache
            $keys = array_diff($keys, array_keys($values));
            foreach ($keys as $i => $key) {
                // don't reach out to real cache for keys that are about to be gone
                if ($this->local->expired($key)) {
                    unset($keys[$i]);
                }
            }

            // fetch missing values from real cache
            if ($keys) {
                $missing = $this->cache->getMulti($keys);
                $values += $missing;
            }
        }

        // any tokens we get will be unreliable, so generate some replacements
        // (more elaborate explanation in get())
        foreach ($values as $key => $value) {
            $token = uniqid();
            $tokens[$key] = $token;
            $this->tokens[$token] = serialize($value);
        }

        return $values;
    }

    /**
     * {@inheritdoc}
     */
    public function set($key, $value, $expire = 0)
    {
        // store the value in memory, so that when we ask for it again later in
        // this same request, we get the value we just set
        $success = $this->local->set($key, $value, $expire);
        if ($success === false) {
            return false;
        }

        $this->defer(array($this->cache, __FUNCTION__), func_get_args(), $key);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function setMulti(array $items, $expire = 0)
    {
        $cache = $this->cache;

        /*
         * We'll use the return value of all buffered writes to check if they
         * should be "rolled back" (which means deleting the keys to prevent
         * corruption).
         *
         * Always return 1 single true: commit() expects a single bool, not a
         * per-key array of success bools
         *
         * @param mixed[] $items
         * @param int $expire
         * @return bool
         */
        $setMulti = function ($items, $expire = 0) use ($cache) {
            $success = $cache->setMulti($items, $expire);

            return !in_array(false, $success);
        };

        // store the values in memory, so that when we ask for it again later in
        // this same request, we get the value we just set
        $success = $this->local->setMulti($items, $expire);

        // only attempt to store those that we've set successfully to local
        $successful = array_intersect_key($items, $success);
        if (!empty($successful)) {
            $this->defer($setMulti, array($successful, $expire), array_keys($successful));
        }

        return $success;
    }

    /**
     * {@inheritdoc}
     */
    public function delete($key)
    {
        $cache = $this->cache;

        /*
         * We'll use the return value of all buffered writes to check if they
         * should be "rolled back" (which means deleting the keys to prevent
         * corruption).
         *
         * delete() can return false if the delete was issued on a non-existing
         * key. That is no corruption of data, though (the requested action
         * actually succeeded: the key is gone). Instead, make this callback
         * always return true, regardless of whether or not the key existed.
         *
         * @param string $key
         * @return bool
         */
        $delete = function ($key) use ($cache) {
            $cache->delete($key);

            return true;
        };

        // check the current value to see if it currently exists, so we can
        // properly return true/false as would be expected from KeyValueStore
        $value = $this->get($key);
        if ($value === false) {
            return false;
        }

        /*
         * To make sure that subsequent get() calls for this key don't return
         * a value (it's supposed to be deleted), we'll make it expired in our
         * temporary bag (as opposed to deleting it from out bag, in which case
         * we'd fall back to fetching it from real store, where the transaction
         * might not yet be committed)
         */
        $this->local->set($key, $value, -1);
        $this->defer($delete, func_get_args(), $key);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteMulti(array $keys)
    {
        $cache = $this->cache;

        /*
         * We'll use the return value of all buffered writes to check if they
         * should be "rolled back" (which means deleting the keys to prevent
         * corruption).
         *
         * Always return 1 single true: commit() expects a single bool, not a
         * per-key array of success bools (+ see comment in delete() about there
         * not being any data corruption)
         *
         * @param string[] $keys
         * @return bool
         */
        $deleteMulti = function ($keys) use ($cache) {
            $cache->deleteMulti($keys);

            return true;
        };

        // check the current values to see if they currently exists, so we can
        // properly return true/false as would be expected from KeyValueStore
        $items = $this->getMulti($keys);
        $success = array();
        foreach ($keys as $key) {
            $success[$key] = array_key_exists($key, $items);
        }

        // only attempt to store those that we've deleted successfully to local
        $values = array_intersect_key($success, array_flip($keys));
        if (empty($values)) {
            return array();
        }

        // mark all as expired in local cache (see comment in delete())
        $this->local->setMulti($values, -1);

        $this->defer($deleteMulti, array(array_keys($values)), array_keys($values));

        return $success;
    }

    /**
     * {@inheritdoc}
     */
    public function add($key, $value, $expire = 0)
    {
        // before adding, make sure the value doesn't yet exist (in real cache,
        // nor in memory)
        if ($this->get($key) !== false) {
            return false;
        }

        // store the value in memory, so that when we ask for it again later
        // in this same request, we get the value we just set
        $success = $this->local->set($key, $value, $expire);
        if ($success === false) {
            return false;
        }

        $this->defer(array($this->cache, __FUNCTION__), func_get_args(), $key);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function replace($key, $value, $expire = 0)
    {
        // before replacing, make sure the value actually exists (in real cache,
        // or already created in memory)
        if ($this->get($key) === false) {
            return false;
        }

        // store the value in memory, so that when we ask for it again later
        // in this same request, we get the value we just set
        $success = $this->local->set($key, $value, $expire);
        if ($success === false) {
            return false;
        }

        $this->defer(array($this->cache, __FUNCTION__), func_get_args(), $key);

        return true;
    }

    /**
     * Since our CAS is deferred, the CAS token we got from our original
     * get() will likely not be valid by the time we want to store it to
     * the real cache. Imagine this scenario:
     * * a value is fetched from (real) cache
     * * an new value key is CAS'ed (into temp cache - real CAS is deferred)
     * * this key's value is fetched again (this time from temp cache)
     * * and a new value is CAS'ed again (into temp cache...).
     *
     * In this scenario, when we finally want to replay the write actions
     * onto the real cache, the first 3 actions would likely work fine.
     * The last (second CAS) however would not, since it never got a real
     * updated $token from the real cache.
     *
     * To work around this problem, all get() calls will return a unique
     * CAS token and store the value-at-that-time associated with that
     * token. All we have to do when we want to write the data to real cache
     * is, right before was CAS for real, get the value & (real) cas token
     * from storage & compare that value to the one we had stored. If that
     * checks out, we can safely resume the CAS with the real token we just
     * received.
     *
     * Should a deferred CAS fail, however, we'll delete the key in cache
     * since it's no longer reliable.
     *
     * {@inheritdoc}
     */
    public function cas($token, $key, $value, $expire = 0)
    {
        $cache = $this->cache;
        $originalValue = isset($this->tokens[$token]) ? $this->tokens[$token] : null;

        /*
         * @param mixed $token
         * @param string $key
         * @param mixed $value
         * @param int $expire
         * @return bool
         */
        $cas = function ($token, $key, $value, $expire = 0) use ($cache, $originalValue) {
            // check if given (local) CAS token was known
            if ($originalValue === null) {
                return false;
            }

            // fetch data from real cache, getting new valid CAS token
            $current = $cache->get($key, $token);

            // check if the value we just read from real cache is still the same
            // as the one we saved when doing the original fetch
            if (serialize($current) === $originalValue) {
                // everything still checked out, CAS the value for real now
                return $cache->cas($token, $key, $value, $expire);
            }

            return false;
        };

        // value is no longer the same as what we used for token
        if (serialize($this->get($key)) !== $originalValue) {
            return false;
        }

        // "CAS" value to local cache/memory
        $success = $this->local->set($key, $value, $expire);
        if ($success === false) {
            return false;
        }

        // only schedule the CAS to be performed on real cache if it was OK on
        // local cache
        $this->defer($cas, func_get_args(), $key);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function increment($key, $offset = 1, $initial = 0, $expire = 0)
    {
        if ($offset <= 0 || $initial < 0) {
            return false;
        }

        // get existing value (from real cache or memory) so we know what to
        // increment in memory (where we may not have anything yet, so we should
        // adjust our initial value to what's already in real cache)
        $value = $this->get($key);
        if ($value === false) {
            $value = $initial - $offset;
        }

        if (!is_numeric($value) || !is_numeric($offset)) {
            return false;
        }

        // store the value in memory, so that when we ask for it again later
        // in this same request, we get the value we just set
        $value = max(0, $value + $offset);
        $success = $this->local->set($key, $value, $expire);
        if ($success === false) {
            return false;
        }

        $this->defer(array($this->cache, __FUNCTION__), func_get_args(), $key);

        return $value;
    }

    /**
     * {@inheritdoc}
     */
    public function decrement($key, $offset = 1, $initial = 0, $expire = 0)
    {
        if ($offset <= 0 || $initial < 0) {
            return false;
        }

        // get existing value (from real cache or memory) so we know what to
        // increment in memory (where we may not have anything yet, so we should
        // adjust our initial value to what's already in real cache)
        $value = $this->get($key);
        if ($value === false) {
            $value = $initial + $offset;
        }

        if (!is_numeric($value) || !is_numeric($offset)) {
            return false;
        }

        // store the value in memory, so that when we ask for it again later
        // in this same request, we get the value we just set
        $value = max(0, $value - $offset);
        $success = $this->local->set($key, $value, $expire);
        if ($success === false) {
            return false;
        }

        $this->defer(array($this->cache, __FUNCTION__), func_get_args(), $key);

        return $value;
    }

    /**
     * {@inheritdoc}
     */
    public function touch($key, $expire)
    {
        // grab existing value (from real cache or memory) and re-save (to
        // memory) with updated expiration time
        $value = $this->get($key);
        if ($value === false) {
            return false;
        }

        $success = $this->local->set($key, $value, $expire);
        if ($success === false) {
            return false;
        }

        $this->defer(array($this->cache, __FUNCTION__), func_get_args(), $key);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function flush()
    {
        $success = $this->local->flush();
        if ($success === false) {
            return false;
        }

        // clear all buffered writes, flush wipes them out anyway
        $this->clear();

        // make sure that reads, from now on until commit, don't read from cache
        $this->suspend = true;

        $this->defer(array($this->cache, __FUNCTION__), func_get_args(), array());

        return true;
    }

    /**
     * Commits all deferred updates to real cache.
     * If the any write fails, all subsequent writes will be aborted & all keys
     * that had already been written to will be deleted.
     *
     * @return bool
     */
    public function commit()
    {
        foreach ($this->deferred as $update) {
            $success = call_user_func_array($update[0], $update[1]);

            // store keys that data has been written to (so we can rollback)
            $this->committed += array_flip($update[2]);

            // if we failed to commit data at any point, roll back
            if ($success === false) {
                $this->rollback();

                return false;
            }
        }

        $this->clear();

        return true;
    }

    /**
     * Roll back all scheduled changes.
     *
     * @return bool
     */
    public function rollback()
    {
        // delete all those keys from cache, they may be corrupt
        $keys = array_keys($this->committed);
        if ($keys) {
            $this->cache->deleteMulti($keys);
        }

        $this->clear();

        return true;
    }

    /**
     * @param callable        $callback
     * @param array           $arguments
     * @param string|string[] $key       Key(s) being written to
     */
    protected function defer($callback, $arguments, $key)
    {
        // keys can be either 1 single string or array of multiple keys
        $keys = (array) $key;

        $this->deferred[] = array($callback, $arguments, $keys);
    }

    /**
     * Clears all transaction-related data stored in memory.
     */
    protected function clear()
    {
        $this->deferred = array();
        $this->committed = array();
        $this->tokens = array();
        $this->suspend = false;
    }
}