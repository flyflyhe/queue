<?php
namespace he\queue\demo;

use he\queue\tool\JobInterface;

class PHPErrorJob implements JobInterface
{
	public function perform()
	{
		callToUndefinedFunction();
	}
}