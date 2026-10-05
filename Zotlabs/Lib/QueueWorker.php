<?php

namespace Zotlabs\Lib;

use Exception;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\Exception\UnableToBuildUuidException;

require_once 'include/dba/dba_transaction.php';

class QueueWorker
{
	public static $queueWorker;
	public static $maxWorkers;
	public static $workerMaxAge;
	public static $workItem;

	public static $defaultPriorities = [
		'Notifier'         => 10,
		'Deliver'          => 10,
		'Cache_query'      => 10,
		'Content_importer' => 1,
		'File_importer'    => 1,
		'Channel_purge'    => 1,
		'Directory'        => 1
	];

	public static $longRunningCmd = [
		'Queue',
		'Expire'
	];

	public function __construct(array $arr)
	{
		self::$workItem = $arr;
		self::$maxWorkers = max(2, Config::Get('queueworker', 'max_queueworkers', 4));
		self::$workerMaxAge = max(120, Config::Get('queueworker', 'max_queueworker_age', 300));
	}

	public function summon(): void
	{
		if ($this->isWorkItem()) {
			$this->insertWork(self::$workItem);
		}

		$this->spawnWorkerIfNeeded();
	}

	public function release(): void
	{
		if ($this->isWorkItem()) {
			$this->insertWork(self::$workItem);
			$this->process();
		}
		else {
			// We already have a worker and a work id.
			$this->process(self::$workItem[1], self::$workItem[2]);
		}
	}

	/**
	 * Insert a new work item.
	 *
	 */
	private function insertWork(array $argv): void
	{
		$priority = self::$defaultPriorities[$argv[0]] ?? 0;
		$workinfo = ['argc' => count($argv), 'argv' => $argv];
		$workinfo_json = json_encode($workinfo);
		$uuid = $this->getUuid($workinfo_json);

		$transaction = new \DbaTransaction(\DBA::$dba);

		$r = q("SELECT * FROM workerq WHERE workerq_uuid = '%s'",
			dbesc($uuid)
		);

		if ($r) {
			logger('Duplicate task - do not insert.', LOGGER_DEBUG);
			logger(print_r($workinfo, true));
			return;
		}

		$r = q("INSERT INTO workerq
			(workerq_priority, workerq_data, workerq_uuid, workerq_cmd)
			VALUES (%d, '%s', '%s', '%s')",
			intval($priority),
			dbesc($workinfo_json),
			dbesc($uuid),
			dbesc($argv[0])
		);

		if (!$r) {
			logger('Insert failed: ' . $workinfo, LOGGER_DEBUG);
			return;
		}
		$transaction->commit();
	}

	/**
	 * Fetch an existing work item.
	 *
	 */
	private function getWork(): array|false
	{
		$work = dbq("SELECT workerq_id, workerq_cmd FROM workerq
			WHERE workerq_reservationid IS NULL
			ORDER BY workerq_priority DESC, workerq_id ASC
			LIMIT 1 FOR UPDATE SKIP LOCKED"
		);

		if ($work) {
			return $work[0];
		}

		return false;
	}

	/**
	 * Reserve one unreserved queue item for $workerid.
	 *
	 * The caller must already have an open transaction.
	 * The returned reservation is the worker's slot.
	 */
	private function reserveWork($workerid): int|false
	{
		$work = $this->getWork();

		if (!$work) {
			return false;
		}

		$id  = intval($work['workerq_id']);
		$cmd = $work['workerq_cmd'];

		$age = self::$workerMaxAge;

		if (in_array($cmd, self::$longRunningCmd)) {
			$age = 3600;
		}

		$r = q("UPDATE workerq SET workerq_reservationid = '%s', workerq_processtimeout = %s + INTERVAL %s
			WHERE workerq_id = %d
			AND workerq_reservationid IS NULL",
			dbesc($workerid),
			db_utcnow(),
			db_quoteinterval($age . " SECOND"),
			intval($id)
		);

		if (!$r) {
			logger('Could not reserve workerq item.', LOGGER_DEBUG);
			return false;
		}

		return $id;
	}

	/**
	 * Initiate the first worker reservation.
	 *
	 * This is used when process() is entered directly, e.g. through
	 * release() instead of spawnWorkerIfNeeded().
	 */
	private function getInitialWorkId(): int|false
	{
		$transaction = new \DbaTransaction(\DBA::$dba);

		if (!$this->getMutex()) {
			logger('Unable to acquire queueworker mutex.', LOGGER_DEBUG);
			return false;
		}

		$this->recoverExpiredWorkers();
		$workers = $this->countWorkers();

		if ($workers >= self::$maxWorkers) {
			$transaction->commit();
			logger("Too many active workers ($workers) max = " . self::$maxWorkers,	LOGGER_DEBUG);
			return false;
		}

		$workerid = uniqid('', true);
		$workid = $this->reserveWork($workerid);

		if (!$workid) {
			$transaction->commit();
			return false;
		}

		self::$queueWorker = $workerid;
		$transaction->commit();

		return $workid;
	}

	/**
	 * Reserve the next job and release the current one.
	 *
	 * There is never a committed state in which this worker has
	 * no reservation as long as there is work.
	 */
	private function getNextWorkId($current_workid): int|false
	{
		$transaction = new \DbaTransaction(\DBA::$dba);
		$work = $this->getWork();

		if ($work) {
			$id  = intval($work['workerq_id']);
			$cmd = $work['workerq_cmd'];

			$age = self::$workerMaxAge;

			if (in_array($cmd, self::$longRunningCmd)) {
				$age = 3600;
			}

			// Reserve the next item first.
			$r = q("UPDATE workerq SET workerq_reservationid = '%s', workerq_processtimeout = %s + INTERVAL %s
				WHERE workerq_id = %d
				AND workerq_reservationid IS NULL",
				dbesc(self::$queueWorker),
				db_utcnow(),
				db_quoteinterval($age . " SECOND"),
				$id
			);

			if (!$r) {
				logger('Could not reserve next workerq item.', LOGGER_DEBUG);
				return false;
			}

			// Then release the old item.
			$r = q("DELETE FROM workerq
				WHERE workerq_id = %d
				AND workerq_reservationid = '%s'",
				intval($current_workid),
				dbesc(self::$queueWorker)
			);

			if (!$r) {
				logger('Could not release previous workerq item.', LOGGER_DEBUG);
				return false;
			}

			$transaction->commit();
			return $id;
		}

		// There is no next job.
		// Removing the current reservation and committing it marks EOL of the worker.
		$r = q("DELETE FROM workerq
			WHERE workerq_id = %d
			AND workerq_reservationid = '%s'",
			intval($current_workid),
			dbesc(self::$queueWorker)
		);

		if (!$r) {
			logger('Could not release final workerq item.', LOGGER_DEBUG);
			return false;
		}

		$transaction->commit();
		return false;
	}



	/**
	 * Spawn a worker if a worker slot is available.
	 *
	 * The worker's first queue reservation is created while the
	 * worker-count mutex is held. Therefore the slot exists in the
	 * database before proc_run() is called.
	 */
	private function spawnWorkerIfNeeded(): void
	{
		$transaction = new \DbaTransaction(\DBA::$dba);

		if (!$this->getMutex()) {
			logger('Unable to acquire queueworker mutex.', LOGGER_DEBUG	);
			return;
		}

		$this->recoverExpiredWorkers();
		$workers = $this->countWorkers();

		if ($workers >= self::$maxWorkers) {
			$transaction->commit();
			logger("Too many active workers ($workers) max = " . self::$maxWorkers,	LOGGER_DEBUG);
			return;
		}

		$workerid = uniqid('', true);
		$workid = $this->reserveWork($workerid);

		if (!$workid) {
			$transaction->commit();
			return;
		}

		$transaction->commit();

		logger($workers . '/' . self::$maxWorkers .	' workers active; spawning worker ' . $workerid . ' for work ' . $workid, LOGGER_DEBUG);

		$phpbin = Config::Get('system',	'phpbin', 'php');
		proc_run($phpbin, 'Zotlabs/Daemon/Master.php', ['Queueworker', $workerid, $workid]);
	}

	public function process($workerid = null, $workid = null): void
	{
		// A worker spawned by spawnWorkerIfNeeded() already owns
		// a reservation and passes both values here.
		if ($workerid !== null && $workid !== null) {
			self::$queueWorker = $workerid;
		}
		else {
			// Direct process() entry, such as release(), must acquire its worker slot atomically
			$workid = $this->getInitialWorkId();
			if (!$workid) {
				killme();
			}
		}

		$jobs = 0;
		$select_stmt = p("SELECT * FROM workerq WHERE workerq_id = ?");

		while ($workid) {
			$workitem = e($select_stmt,	[$workid]);

			if ($workitem) {
				// There is already a reservation belonging to this worker,
				// so another worker can safely be spawned if there is capacity.
				$this->spawnWorkerIfNeeded();

				$jobs++;

				logger('Workinfo: ' . $workitem[0]['workerq_data'], LOGGER_DEBUG);

				$workinfo = json_decode($workitem[0]['workerq_data'], true);
				$argv = $workinfo['argv'];

				$cls = '\\Zotlabs\\Daemon\\' . $argv[0];

				$argv = flatten_array_recursive($argv);
				$argc = count($argv);
				$rnd = random_string(16);

				logger('PROCESSING: ' .	$rnd . ' ' . print_r($argv[0], true));
				$start_timestamp = microtime(true);
				try {
					$cls::run($argc, $argv);
					logger('logger_stats_data cmd:' . $argv[0] . ' start:' . $start_timestamp .	' end:' . microtime(true) . ' meta:' . $rnd);
					logger('COMPLETED: ' . $rnd);

					// Atomically transition: current reservation -> next reservation
					// If there is no next job, this releases the worker slot and returns false.
					$workid = $this->getNextWorkId($workid);
				}
				catch (Exception $ex) {
					$class = get_class($ex);
					logger("EXCEPTION: {$rnd} {$class}: " .	$ex->getMessage());

					// TODO: what do we do with failed jobs?
					$workid = false;
				}
			}
			else {
				logger('NO WORKITEM!', LOGGER_DEBUG);
				$workid = false;
			}
		}

		logger('Worker: ' . self::$queueWorker . ' processed ' . $jobs . ' jobs', LOGGER_DEBUG);
	}

	/**
	 * Generate a name-based v5 UUID with custom namespace.
	 */
	private function getUuid(string $data): string
	{
		$namespace = '3a112e42-f147-4ccf-a78b-f6841339ea2a';
		try {
			$uuid = Uuid::uuid5($namespace, $data)->toString();
		}
		catch (UnableToBuildUuidException $e) {
			logger('UUID generation failed');
			return '';
		}

		return $uuid;
	}

	/**
	 * Recover workers whose reservation has expired.
	 *
	 * This must be called while the caller holds the queueworker
	 * mutex transaction.
	 */
	private function recoverExpiredWorkers(): void
	{
		$r = q("SELECT workerq_id FROM workerq WHERE workerq_reservationid IS NOT NULL
			AND workerq_processtimeout < %s
			FOR UPDATE SKIP LOCKED",
			db_utcnow()
		);

		if ($r) {
			$ids = ids_to_querystr($r, 'workerq_id');
			dbq("UPDATE workerq SET workerq_reservationid = NULL WHERE workerq_id IN ($ids)");
		}
	}

	/**
	 * Count active workers (reservations).
	 *
	 * This must be called while the caller holds the queueworker
	 * mutex transaction.
	 *
	 */
	private function countWorkers(): int
	{
		$workers = dbq("SELECT count(*) AS total FROM workerq
			 WHERE workerq_reservationid IS NOT NULL"
		);

		return intval($workers[0]['total'] ?? 0);
	}

	/**
	 * Get the mutex with a read lock query.
	 *
	 */
	private function getMutex(): bool
	{
		$r = dbq("SELECT mutex_name FROM mutex
			 WHERE mutex_name = 'queueworker'
			 FOR UPDATE"
		);

		return !empty($r);
	}

	private function isWorkItem(): bool
	{
		return self::$workItem[0] !== 'Queueworker';
	}
}
