<?php
//declare(ticks = 1);

namespace he\queue\tool;

use he\queue\Resque;
use he\queue\tool\job\DirtyExitException;
use he\queue\tool\job\Status;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

pcntl_async_signals(true);

class Worker
{
	/**
	* @var LoggerInterface Logging object that impliments the PSR-3 LoggerInterface
	*/
	public $logger;

	/**
	 * @var array Array of all associated queues for this worker.
	 */
	private $queues = array();

	/**
	 * @var string The hostname of this worker.
	 */
	private $hostname;

	/**
	 * @var boolean True if on the next iteration, the worker should shutdown.
	 */
	private $shutdown = false;

	/**
	 * @var boolean True if this worker is paused.
	 */
	private $paused = false;

	/**
	 * @var string String identifying this worker.
	 */
	private $id;

	/**
	 * @var Job Current job, if any, being processed by this worker.
	 */
	private $currentJob = null;

	/**
	 * @var int Process ID of child worker processes.
	 */
	private $child = null;

    /**
     * Instantiate a new worker, given a list of queues that it should be working
     * on. The list of queues should be supplied in the priority that they should
     * be checked for jobs (first come, first served)
     *
     * Passing a single '*' allows the worker to work on all queues in alphabetical
     * order. You can easily add new queues dynamically and have them worked on using
     * this method.
     *
     * @param string|array $queues String with a single queue name, array with multiple.
     */
    public function __construct($queues)
    {
        $this->logger = new Log();

        if(!is_array($queues)) {
            $queues = array($queues);
        }

        $this->queues = $queues;
        $this->hostname = php_uname('n');

        $this->id = $this->hostname . ':'.getmypid() . ':' . implode(',', $this->queues);
    }

	/**
	 * Return all workers known to Resque as instantiated instances.
	 * @return array
	 */
	public static function all()
	{
		$workers = Resque::redis()->smembers('workers');
		if(!is_array($workers)) {
			$workers = array();
		}

		$instances = array();
		foreach($workers as $workerId) {
			$instances[] = self::find($workerId);
		}
		return $instances;
	}

	/**
	 * Given a worker ID, check if it is registered/valid.
	 *
	 * @param string $workerId ID of the worker.
	 * @return boolean True if the worker exists, false if not.
	 */
	public static function exists($workerId): bool
    {
		return (bool)Resque::redis()->sismember('workers', $workerId);
	}

    /**
     * Given a worker ID, find it and return an instantiated worker class for it.
     *
     * @param string $workerId The ID of the worker.
     * @return Worker Instance of the worker. False if the worker does not exist.
     */
	public static function find(string $workerId)
	{
		if(!self::exists($workerId) || false === strpos($workerId, ":")) {
			return null;
		}

		list($hostname, $pid, $queues) = explode(':', $workerId, 3);
		$queues = explode(',', $queues);
		$worker = new self($queues);
		$worker->setId($workerId);
		return $worker;
	}

    /**
     * Set the ID of this worker to a given ID string.
     *
     * @param string $workerId ID for the worker.
     */
	public function setId(string $workerId)
	{
		$this->id = $workerId;
	}

    /**
     * The primary loop for a worker which when called on an instance starts
     * the worker's life cycle.
     *
     * Queues are checked every $interval (seconds) for new jobs.
     *
     * @param int $interval How often to check for new jobs across the queues.
     * @param bool $blocking
     */
	public function work($interval = Resque::DEFAULT_INTERVAL, $blocking = false)
	{
		$this->updateProcLine('Starting');
		$this->startup();

        $child = Resque::fork();

        // Forked and we're the child. Run the job.
        if ($child === 0 || $child === false) {
            $status = 'Processing '.implode(',', $this->queues).' since ' . strftime('%F %T');
            $this->updateProcLine($status);
            $this->logger->log(LogLevel::INFO, $status);
            if ($this->child === false) {
                exit(0);
            }

            while(true) {
                if($this->shutdown) {
                    break;
                }

                // Attempt to find and reserve a job
                $job = false;
                if(!$this->paused) {
                    if($blocking === true) {
                        $this->logger->log(LogLevel::INFO, 'Starting blocking with timeout of {interval}', array('interval' => $interval));
                        $this->updateProcLine('Waiting for ' . implode(',', $this->queues) . ' with blocking timeout ' . $interval);
                    } else {
                        $this->updateProcLine('Waiting for ' . implode(',', $this->queues) . ' with interval ' . $interval);
                    }

                    $job = $this->reserve($blocking, $interval);
                }

                if(!$job) {
                    // For an interval of 0, break now - helps with unit testing etc
                    if($interval == 0) {
                        break;
                    }

                    if($blocking === false)
                    {
                        // If no job was found, we sleep for $interval before continuing and checking again
                        $this->logger->log(LogLevel::INFO, 'Sleeping for {interval}', array('interval' => $interval));
                        if($this->paused) {
                            $this->updateProcLine('Paused');
                        }
                        else {
                            $this->updateProcLine('Waiting for ' . implode(',', $this->queues));
                        }

                        usleep($interval * 1000000);
                    }

                    continue;
                }

                $this->logger->log(LogLevel::NOTICE, 'Starting work on {job}', array('job' => $job));
                Event::trigger('beforeFork', $job);
                $this->workingOn($job);
                $this->perform($job);
                $this->doneWorking();
            }

            exit(0);

        } elseif($child > 0) {
            $this->child = $child;
            // Parent process, sit and wait
            $status = 'wait child ' . $this->child . ' at ' . strftime('%F %T');
            $this->updateProcLine($status);
            $this->logger->log(LogLevel::INFO, $status);

            // Wait until the child process finishes before continuing
            pcntl_wait($status);
            $exitStatus = pcntl_wexitstatus($status);
            //????????????????????? ???????????????????????????
            if($exitStatus === 0) {
                $this->logger->log(LogLevel::INFO, 'child '.$this->child.' ??????');
                $this->child = null;
                $this->unregisterWorker();
            } else {
                $this->logger->log(LogLevel::INFO, 'child '.$this->child.' ????????????');
                $this->work($interval, $blocking);
            }
        }
	}

	/**
	 * Process a single job.
	 *
	 * @param Job $job The job to be processed.
	 */
	public function perform(Job $job)
	{
		try {
			Event::trigger('afterFork', $job);
			$job->perform();
		}
		catch(Exception $e) {
			$this->logger->log(LogLevel::CRITICAL, '{job} has failed {stack}', array('job' => $job, 'stack' => $e));
			$job->fail($e);
			return;
		}

		$job->updateStatus(Status::STATUS_COMPLETE);
		$this->logger->log(LogLevel::NOTICE, '{job} has finished', array('job' => $job));
	}

	/**
	 * @param  bool            $blocking
	 * @param  int             $timeout
	 * @return object|boolean               Instance of Resque_Job if a job is found, false if not.
	 */
	public function reserve($blocking = false, $timeout = null)
	{
		$queues = $this->queues();
		if(!is_array($queues)) {
			return false;
		}

		if($blocking === true) {
			$job = Job::reserveBlocking($queues, $timeout);
			if($job) {
				$this->logger->log(LogLevel::INFO, 'Found job on {queue}', array('queue' => $job->queue));
				return $job;
			}
		} else {
			foreach($queues as $queue) {
				$this->logger->log(LogLevel::INFO, 'Checking {queue} for jobs', array('queue' => $queue));
				$job = Job::reserve($queue);
				if($job) {
					$this->logger->log(LogLevel::INFO, 'Found job on {queue}', array('queue' => $job->queue));
					return $job;
				}
			}
		}

		return false;
	}

	/**
	 * Return an array containing all of the queues that this worker should use
	 * when searching for jobs.
	 *
	 * If * is found in the list of queues, every queue will be searched in
	 * alphabetic order. (@see $fetch)
	 *
	 * @param boolean $fetch If true, and the queue is set to *, will fetch
	 * all queue names from redis.
	 * @return array Array of associated queues.
	 */
	public function queues($fetch = true)
	{
		if(!in_array('*', $this->queues) || $fetch == false) {
			return $this->queues;
		}

		$queues = Resque::queues();
		sort($queues);
		return $queues;
	}

	/**
	 * Perform necessary actions to start a worker.
	 */
	private function startup()
	{
		$this->registerSigHandlers();
		$this->pruneDeadWorkers();
		Event::trigger('beforeFirstFork', $this);
		$this->registerWorker();
	}

    /**
     * On supported systems (with the PECL proctitle module installed), update
     * the name of the currently running process to indicate the current state
     * of a worker.
     *
     * @param string $status The updated process title.
     */
	private function updateProcLine(string $status)
	{
		$processTitle = 'resque-' . Resque::VERSION . ': ' . $status;
		if(function_exists('cli_set_process_title') && PHP_OS !== 'Darwin') {
			cli_set_process_title($processTitle);
		}
		else if(function_exists('setproctitle')) {
			setproctitle($processTitle);
		}
	}

	/**
	 * Register signal handlers that a worker should respond to.
	 *
	 * TERM: Shutdown immediately and stop processing jobs.
	 * INT: Shutdown immediately and stop processing jobs.
	 * QUIT: Shutdown after the current job finishes processing.
	 * USR1: Kill the forked child immediately and continue processing jobs.
	 */
	private function registerSigHandlers()
	{
		if(!function_exists('pcntl_signal')) {
			return;
		}

		pcntl_signal(SIGTERM, array($this, 'shutDownNow'));
		pcntl_signal(SIGINT, array($this, 'shutDownNow'));
		pcntl_signal(SIGQUIT, array($this, 'shutdown'));
		pcntl_signal(SIGUSR1, array($this, 'killChild'));
		pcntl_signal(SIGUSR2, array($this, 'pauseProcessing'));
		pcntl_signal(SIGCONT, array($this, 'unPauseProcessing'));
		$this->logger->log(LogLevel::DEBUG, 'Registered signals');
	}

	/**
	 * Signal handler callback for USR2, pauses processing of new jobs.
	 */
	public function pauseProcessing()
	{
		$this->logger->log(LogLevel::NOTICE, 'USR2 received; pausing job processing');
		$this->paused = true;
	}

	/**
	 * Signal handler callback for CONT, resumes worker allowing it to pick
	 * up new jobs.
	 */
	public function unPauseProcessing()
	{
		$this->logger->log(LogLevel::NOTICE, 'CONT received; resuming job processing');
		$this->paused = false;
	}

	/**
	 * Schedule a worker for shutdown. Will finish processing the current job
	 * and when the timeout interval is reached, the worker will shut down.
	 */
	public function shutdown()
	{
		$this->shutdown = true;
		if ($this->child) {
            posix_kill($this->child, SIGQUIT);//?????????????????????
        }
		$this->logger->log(LogLevel::NOTICE, 'Shutting down');
	}

	/**
	 * Force an immediate shutdown of the worker, killing any child jobs
	 * currently running.
	 */
	public function shutdownNow()
	{
		$this->shutdown();
		$this->killChild();
	}

	/**
	 * Kill a forked child job immediately. The job it is processing will not
	 * be completed.
	 */
	public function killChild()
	{
	    //?????????????????? ??????????????? ?????????????????????????????????
		if(!$this->child) {
			$this->logger->log(LogLevel::DEBUG, 'No child to kill.');
			exit(1);
			return;
		}

		$this->logger->log(LogLevel::INFO, 'Killing child at {child}', array('child' => $this->child));
		if(exec('ps -o pid,state -p ' . $this->child, $output, $returnCode) && $returnCode != 1) {
			$this->logger->log(LogLevel::DEBUG, 'Child {child} found, killing.', array('child' => $this->child));
			posix_kill($this->child, SIGKILL);
			$this->child = null;
		}
		else {
			$this->logger->log(LogLevel::INFO, 'Child {child} not found, restarting.', array('child' => $this->child));
			$this->shutdown();
		}
	}

	/**
	 * Look for any workers which should be running on this server and if
	 * they're not, remove them from Redis.
	 *
	 * This is a form of garbage collection to handle cases where the
	 * server may have been killed and the Resque workers did not die gracefully
	 * and therefore leave state information in Redis.
	 */
	public function pruneDeadWorkers()
	{
		$workerPids = $this->workerPids();
		$workers = self::all();
		foreach($workers as $worker) {
			if (is_object($worker)) {
				list($host, $pid, $queues) = explode(':', (string)$worker, 3);
				if($host != $this->hostname || in_array($pid, $workerPids) || $pid == getmypid()) {
					continue;
				}
				$this->logger->log(LogLevel::INFO, 'Pruning dead worker: {worker}', array('worker' => (string)$worker));
				$worker->unregisterWorker();
			}
		}
	}

	/**
	 * Return an array of process IDs for all of the Resque workers currently
	 * running on this machine.
	 *
	 * @return array Array of Resque worker process IDs.
	 */
	public function workerPids()
	{
		$pids = array();
		exec('ps -A -o pid,command | grep [r]esque', $cmdOutput);
		foreach($cmdOutput as $line) {
			list($pids[],) = explode(' ', trim($line), 2);
		}
		return $pids;
	}

	/**
	 * Register this worker in Redis.
	 */
	public function registerWorker()
	{
		Resque::redis()->sadd('workers', (string)$this);
		Resque::redis()->set('worker:' . (string)$this . ':started', strftime('%a %b %d %H:%M:%S %Z %Y'));
	}

	/**
	 * Unregister this worker in Redis. (shutdown etc)
	 */
	public function unregisterWorker()
	{
		if(is_object($this->currentJob)) {
			$this->currentJob->fail(new DirtyExitException);
		}

		$id = (string)$this;
		Resque::redis()->srem('workers', $id);
		Resque::redis()->del('worker:' . $id);
		Resque::redis()->del('worker:' . $id . ':started');
		Stat::clear('processed:' . $id);
		Stat::clear('failed:' . $id);
	}

    /**
     * Tell Redis which job we're currently working on.
     *
     * @param Job $job instance containing the job we're working on.
     */
	public function workingOn(Job $job)
	{
		$job->worker = $this;
		$this->currentJob = $job;
		$job->updateStatus(Status::STATUS_RUNNING);
		$data = json_encode(array(
			'queue' => $job->queue,
			'run_at' => strftime('%a %b %d %H:%M:%S %Z %Y'),
			'payload' => $job->payload
		));
		Resque::redis()->set('worker:' . $job->worker, $data);
	}

	/**
	 * Notify Redis that we've finished working on a job, clearing the working
	 * state and incrementing the job stats.
	 */
	public function doneWorking()
	{
		$this->currentJob = null;
		Stat::incr('processed');
		Stat::incr('processed:' . (string)$this);
		Resque::redis()->del('worker:' . (string)$this);
	}

	/**
	 * Generate a string representation of this worker.
	 *
	 * @return string String identifier for this worker instance.
	 */
	public function __toString()
	{
		return $this->id;
	}

	/**
	 * Return an object describing the job this worker is currently working on.
	 *
	 * @return array Object with details of current job.
	 */
	public function job()
	{
		$job = Resque::redis()->get('worker:' . $this);
		if(!$job) {
			return array();
		}
		else {
			return json_decode($job, true);
		}
	}

    /**
     * Get a statistic belonging to this worker.
     *
     * @param string $stat Statistic to fetch.
     * @return int Statistic value.
     */
	public function getStat(string $stat): int
    {
		return Stat::get($stat . ':' . $this);
	}

	/**
	 * Inject the logging object into the worker
	 *
	 * @param LoggerInterface $logger
	 */
	public function setLogger(LoggerInterface $logger)
	{
		$this->logger = $logger;
	}
}
