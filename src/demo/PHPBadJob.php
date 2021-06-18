<?php
namespace he\queue\demo;

use Exception;
use he\queue\tool\JobInterface;

class PHPBadJob  implements JobInterface
{
	public function perform()
	{
		throw new Exception('Unable to run this job!');
	}
}