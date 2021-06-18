<?php

namespace he\queue\tool\job;


use he\queue\tool\JobInterface;

interface FactoryInterface
{
	/**
	 * @param $className
	 * @param array $args
	 * @param $queue
	 * @return JobInterface
	 */
	public function create($className, $args, $queue): JobInterface;
}
