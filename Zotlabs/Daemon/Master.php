<?php

namespace Zotlabs\Daemon;

use Zotlabs\Lib\QueueWorker;

if (array_search(__file__, get_included_files()) === 0) {
	array_shift($argv);

	if (!empty($argv)) {
		require_once('include/cli_startup.php');
		cli_startup();
		Master::Release($argv);
	}

	return;
}


class Master {
	static public function Summon($arr)
	{
		$queueWorker = new QueueWorker($arr);
		$queueWorker->summon();
		return;
	}

	static public function Release($arr)
	{
		$queueWorker = new QueueWorker($arr);
		$queueWorker->release();
		return;
	}
}
