<?php
namespace he\queue\demo;

use he\queue\tool\JobInterface;

class PHPJob implements JobInterface
{
	public function perform()
	{
        fwrite(STDOUT, 'Start job! -> ');
		sleep(1);
		fwrite(STDOUT, 'Job ended!' . PHP_EOL);
	}
}