<?php /** @file */

namespace Zotlabs\Lib;

use DBA;
use Zotlabs\Lib\Config;

/**
 *  cache api
 */
class Cache {

    /**
     * @brief Returns cached content
     *
     * @param string $key
     * @param string $age in SQL format, default is '30 DAY'
     * @return string
     */

	public static function get($key, $age = '') {
		$hash = uuid_from_url($key);
		$object_cache_days = intval(Config::Get('system','object_cache_days', '30'));

		$r = q("SELECT v FROM cache WHERE k = '%s' AND updated > %s - INTERVAL %s LIMIT 1",
			dbesc($hash),
			db_utcnow(),
			db_quoteinterval(($age ? $age : $object_cache_days . ' DAY'))
		);

		return $r[0]['v'] ?? null;
	}

	public static function set($key,$value) {
		$hash = uuid_from_url($key);

		DBA::$dba->upsert(
			'cache',
			[
				'k'       => $hash,
				'v'       => $value,
				'updated' => datetime_convert(),
			],
			[
				'v',
				'updated',
			],
			[
				'k',
			]
		);
	}
}
