<?php
namespace he\queue\demo;

use he\queue\tool\JobInterface;

class LongJob implements JobInterface
{
	public function perform()
	{
		sleep(600);
	}
}