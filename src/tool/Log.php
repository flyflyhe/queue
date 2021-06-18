<?php
namespace he\queue\tool;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

class Log extends AbstractLogger
{
	public $verbose;

	public function __construct($verbose = false) {
		$this->verbose = $verbose;
	}

	/**
	 * Logs with an arbitrary level.
	 *
	 * @param mixed   $level    PSR-3 log level constant, or equivalent string
	 * @param string  $message  Message to log, may contain a { placeholder }
	 * @param array   $context  Variables to replace { placeholder }
	 * @return null
	 */
	public function log($level, $message, array $context = array())
	{
		if ($this->verbose) {
			fwrite(
				STDOUT,
				'[' . $level . '] [' . strftime('%T %Y-%m-%d') . '] ' . $this->interpolate($message, $context) . PHP_EOL
			);
			return;
		}

		if (!($level === LogLevel::INFO || $level === LogLevel::DEBUG)) {
			fwrite(
				STDOUT,
				'[' . $level . '] ' . $this->interpolate($message, $context) . PHP_EOL
			);
		}
	}

    /**
     * Fill placeholders with the provided context
     * @param string $message Message to be logged
     * @param array $context Array of variables to use in message
     * @return string
     * @author Jordi Boggiano j.boggiano@seld.be
     *
     */
	public function interpolate(string $message, array $context = array()): string
    {
		// build a replacement array with braces around the context keys
		$replace = array();
		foreach ($context as $key => $val) {
			$replace['{' . $key . '}'] = $val;
		}
	
		// interpolate replacement values into the message and return
		return strtr($message, $replace);
	}
}
