<?php

namespace Zotlabs\Update;

class _1271 {

	function run() {

		q("START TRANSACTION");

		if(ACTIVE_DBTYPE == DBTYPE_POSTGRES) {
			$r = dbq("ALTER TABLE workerq ADD COLUMN workerq_pid INTEGER DEFAULT NULL");
		}
		else {
			$r = dbq("ALTER TABLE workerq ADD COLUMN workerq_pid INT UNSIGNED DEFAULT NULL");
		}

		if($r) {
			q("COMMIT");
			return UPDATE_SUCCESS;
		}

		q("ROLLBACK");
		return UPDATE_FAILED;

	}

}
