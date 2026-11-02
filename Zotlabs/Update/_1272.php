<?php

namespace Zotlabs\Update;

class _1272 {

	function run() {

		q("START TRANSACTION");

		if(ACTIVE_DBTYPE == DBTYPE_POSTGRES) {
			$r = dbq("CREATE INDEX workerq_reservationid_priority_id
				ON workerq (
					workerq_reservationid,
					workerq_priority DESC,
					workerq_id
				)"
			);
		}
		else {
			$r = dbq("ALTER TABLE workerq
				ADD INDEX workerq_reservationid_priority_id (
					workerq_reservationid,
					workerq_priority DESC,
					workerq_id
				)"
			);
		}

		if($r) {
			q("COMMIT");
			return UPDATE_SUCCESS;
		}

		q("ROLLBACK");
		return UPDATE_FAILED;

	}

}
