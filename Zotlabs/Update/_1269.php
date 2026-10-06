<?php

namespace Zotlabs\Update;

class _1269 {

	function run() {

		q("START TRANSACTION");

		if(ACTIVE_DBTYPE == DBTYPE_POSTGRES) {
			$r = dbq("CREATE INDEX cache_updated_idx ON cache (updated)");
		}
		else {
			$r = dbq("ALTER TABLE cache ADD INDEX updated (updated)");
		}

		if($r) {
			q("COMMIT");
			return UPDATE_SUCCESS;
		}

		q("ROLLBACK");
		return UPDATE_FAILED;

	}

}
