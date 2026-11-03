<?php

namespace Zotlabs\Update;

class _1273 {
	function run() {
		dbq("START TRANSACTION");

		// Remove duplicates, keeping the entry with the highest hubloc_id
		$r1 = dbq("DELETE FROM hubloc WHERE hubloc_id NOT IN (
			SELECT MAX(hubloc_id)
			FROM (SELECT * FROM hubloc) AS tmp
			GROUP BY hubloc_hash, hubloc_url)"
		);

		if(!$r1) {
			dbq("ROLLBACK");
			return UPDATE_FAILED;
		}

		$r2 = dbq("ALTER TABLE hubloc
			ADD CONSTRAINT hubloc_hash_url
			UNIQUE (hubloc_hash, hubloc_url)"
		);

		if(!$r2) {
			dbq("ROLLBACK");
			return UPDATE_FAILED;
		}

		if(ACTIVE_DBTYPE == DBTYPE_POSTGRES) {
			$r3 = dbq("DROP INDEX hubloc_hash");
		}
		else {
			$r3 = dbq("ALTER TABLE hubloc DROP INDEX hubloc_hash");
		}

		if(!$r3) {
			dbq("ROLLBACK");
			return UPDATE_FAILED;
		}

		dbq("COMMIT");
		return UPDATE_SUCCESS;
	}
}
