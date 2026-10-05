<?php

namespace Zotlabs\Update;

class _1268 {

	function run() {

		q("START TRANSACTION");

		if(ACTIVE_DBTYPE == DBTYPE_POSTGRES) {
			dbq("CREATE TABLE mutex (
				mutex_name varchar(64) NOT NULL,
				created timestamp NOT NULL DEFAULT (CURRENT_TIMESTAMP AT TIME ZONE 'UTC'),
				PRIMARY KEY (mutex_name))"
			);
		}
		else {
			dbq("CREATE TABLE mutex (
				mutex_name VARCHAR(64) NOT NULL,
				created DATETIME NOT NULL DEFAULT UTC_TIMESTAMP(),
				PRIMARY KEY (mutex_name)
				) ENGINE = InnoDB DEFAULT CHARSET=utf8mb4"
			);
		}

		dbq("INSERT INTO mutex (mutex_name) VALUES ('queueworker')");

		$r = dbq("SELECT * FROM mutex WHERE mutex_name = 'queueworker'");

		if($r) {
			q("COMMIT");
			return UPDATE_SUCCESS;
		}

		q("ROLLBACK");
		return UPDATE_FAILED;

	}

}
