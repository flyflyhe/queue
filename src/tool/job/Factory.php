<?php

namespace he\queue\tool\job;

use he\queue\tool\Exception;
use he\queue\tool\JobInterface;

class Factory implements FactoryInterface
{

    /**
     * @param $className
     * @param array $args
     * @param $queue
     * @return JobInterface
     * @throws Exception
     */
    public function create($className, $args, $queue): JobInterface
    {
        if (!class_exists($className)) {
            throw new Exception(
                'Could not find job class ' . $className . '.'
            );
        }

        if (!method_exists($className, 'perform')) {
            throw new Exception(
                'Job class ' . $className . ' does not contain a perform method.'
            );
        }

        $instance = new $className;
        $instance->args = $args;
        $instance->queue = $queue;
        return $instance;
    }
}
