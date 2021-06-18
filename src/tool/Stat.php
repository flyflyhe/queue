<?php
namespace he\queue\tool;

use he\queue\Resque;

class Stat
{
    /**
     * Get the value of the supplied statistic counter for the specified statistic.
     *
     * @param string $stat The name of the statistic to get the stats for.
     * @return mixed Value of the statistic.
     */
	public static function get(string $stat)
	{
		return (int)Resque::redis()->get('stat:' . $stat);
	}

    /**
     * Increment the value of the specified statistic by a certain amount (default is 1)
     *
     * @param string $stat The name of the statistic to increment.
     * @param int $by The amount to increment the statistic by.
     * @return boolean True if successful, false if not.
     */
	public static function incr(string $stat, $by = 1): bool
    {
		return (bool)Resque::redis()->incrby('stat:' . $stat, $by);
	}

	/**
	 * Decrement the value of the specified statistic by a certain amount (default is 1)
	 *
	 * @param string $stat The name of the statistic to decrement.
	 * @param int $by The amount to decrement the statistic by.
	 * @return boolean True if successful, false if not.
	 */
	public static function decr($stat, $by = 1): bool
    {
		return (bool)Resque::redis()->decrby('stat:' . $stat, $by);
	}

	/**
	 * Delete a statistic with the given name.
	 *
	 * @param string $stat The name of the statistic to delete.
	 * @return boolean True if successful, false if not.
	 */
	public static function clear($stat): bool
    {
		return (bool)Resque::redis()->del('stat:' . $stat);
	}
}