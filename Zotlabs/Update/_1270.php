<?php

namespace Zotlabs\Update;

class _1270 {

	function run() {

		q("START TRANSACTION");

		if(ACTIVE_DBTYPE == DBTYPE_POSTGRES) {
			dbq("DROP INDEX IF EXISTS idx_workerq_uuid");
		}
		else {
			dbq("DROP INDEX workerq_uuid ON workerq");
		}

		$r = dbq("CREATE UNIQUE INDEX workerq_uuid ON workerq (workerq_uuid)");

		if($r) {
			q("COMMIT");
			return UPDATE_SUCCESS;
		}

		q("ROLLBACK");
		return UPDATE_FAILED;

	}

}
