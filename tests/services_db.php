<?php

// tests/services_db.php
//
// What Services::seed() does to real tables on an install, which the stubs of
// tests/smoke.php cannot show: the `owner` backfill, the module's services
// deleted and written again with their assignments kept, a default DEFAULTS
// has dropped removed with what named it, and a RENAMED slug carried.
//
// Needs MySQL or MariaDB (GET_LOCK, multi-table DELETE) and a database it may
// drop tables in:
//
//     ORYK_TEST_DSN='mysql:host=127.0.0.1;dbname=oryk_test' \
//     ORYK_TEST_USER=root ORYK_TEST_PASS=root php tests/services_db.php
//
// With no ORYK_TEST_DSN it says so and passes: there is nothing to run it on.

namespace {
	if (!function_exists('_')) {
		function _($text)
		{
			return $text;
		}
	}

	if (!function_exists('dbug')) {
		function dbug()
		{
		}
	}

	if (!function_exists('freepbx_log')) {
		function freepbx_log()
		{
		}
	}

	if (!defined('FPBX_LOG_ERROR')) {
		define('FPBX_LOG_ERROR', 'ERROR');
	}
}

namespace FreePBX\Modules\Oryk_Provisioner {

	use PDO;

	$dsn = (string) getenv('ORYK_TEST_DSN');

	if ($dsn === '') {
		echo "tests/services_db.php: no ORYK_TEST_DSN, nothing run\n";
		exit(0);
	}

	foreach (['Logs', 'Service', 'Schema', 'Jobs', 'ServiceEngine', 'Reactions', 'Services'] as $class) {
		require dirname(__DIR__) . '/src/' . $class . '.php';
	}

	/** Services as a later release would ship it: `old-support` has become `support`. */
	class RenamingServices extends Services
	{
		const RENAMED = ['old-support' => 'support'];
	}

	$db = new PDO($dsn, (string) getenv('ORYK_TEST_USER'), (string) getenv('ORYK_TEST_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
	$app = new \stdClass();
	$app->Database = $db;
	$app->astman = null;

	$passed = 0;
	$failed = 0;

	$is = function ($what, $got, $want) use (&$passed, &$failed) {
		if ($got === $want) {
			$passed++;
			echo "  [ok] $what\n";

			return;
		}

		$failed++;
		echo "  [FAILED] $what\n    got:  " . json_encode($got) . "\n    want: " . json_encode($want) . "\n";
	};

	$column = function ($sql) use ($db) {
		return array_map('strval', $db->query($sql)->fetchAll(PDO::FETCH_COLUMN));
	};

	// The tables as 1.2.8 wrote them: no `owner`, no `admin`. A snapshot of a
	// released shape, so it is written out here and never follows Installer.
	$db->exec('DROP TABLE IF EXISTS oryk_provisioner_services, oryk_provisioner_service_links, oryk_provisioner_service_assignments, oryk_provisioner_jobs, oryk_provisioner_job_steps');
	$db->exec(
		"CREATE TABLE oryk_provisioner_services (
			slug VARCHAR(64) NOT NULL, name VARCHAR(191) NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (slug), UNIQUE KEY name (name)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
	);
	$db->exec(
		"CREATE TABLE oryk_provisioner_service_links (
			parent VARCHAR(64) NOT NULL, child VARCHAR(64) NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (parent, child), KEY child (child)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
	);
	$db->exec(
		"CREATE TABLE oryk_provisioner_service_assignments (
			extension VARCHAR(20) NOT NULL, service VARCHAR(64) NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (extension, service)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
	);
	$db->exec(
		"CREATE TABLE oryk_provisioner_jobs (
			id INT(11) NOT NULL AUTO_INCREMENT, extension VARCHAR(20) NOT NULL,
			service VARCHAR(64) NOT NULL DEFAULT '', name VARCHAR(191) NOT NULL DEFAULT '',
			reason VARCHAR(16) NOT NULL, source VARCHAR(16) NOT NULL DEFAULT 'gui',
			state VARCHAR(16) NOT NULL DEFAULT 'queued', attempts INT(10) UNSIGNED NOT NULL DEFAULT 0,
			error TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			started_at DATETIME NULL DEFAULT NULL, finished_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY (id)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
	);
	$db->exec(
		"CREATE TABLE oryk_provisioner_job_steps (
			id INT(11) NOT NULL AUTO_INCREMENT, job_id INT(11) NOT NULL,
			position SMALLINT(5) UNSIGNED NOT NULL, service VARCHAR(64) NOT NULL,
			name VARCHAR(191) NOT NULL DEFAULT '', via VARCHAR(64) NULL DEFAULT NULL,
			event VARCHAR(16) NOT NULL, state VARCHAR(16) NOT NULL DEFAULT 'pending',
			done_by VARCHAR(255) NOT NULL DEFAULT '', own_job VARCHAR(64) NULL DEFAULT NULL,
			attempts INT(10) UNSIGNED NOT NULL DEFAULT 0, error TEXT NULL,
			PRIMARY KEY (id)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
	);

	// Every default but Support, which this PBX still has under its old slug;
	// one default since dropped; one service of the operator's own.
	$service = $db->prepare("INSERT INTO oryk_provisioner_services (slug, name, created_at) VALUES (?, ?, '2020-01-01 00:00:00')");
	$link = $db->prepare('INSERT INTO oryk_provisioner_service_links (parent, child) VALUES (?, ?)');
	$assign = $db->prepare('INSERT INTO oryk_provisioner_service_assignments (extension, service) VALUES (?, ?)');
	$old = function ($slug) {
		return $slug === 'support' ? 'old-support' : $slug;
	};

	foreach (Services::DEFAULTS as $slug => $default) {
		$service->execute([$old($slug), $slug === 'support' ? 'Old Support' : $default['name']]);

		foreach ($default['services'] ?? [] as $child) {
			$link->execute([$old($slug), $old($child)]);
		}
	}

	$service->execute(['dropped', 'Dropped Default']);
	$service->execute(['my-own', 'My Own']);
	$link->execute(['basic-user', 'dropped']);
	$link->execute(['basic-user', 'my-own']);

	foreach ([['1001', 'old-support'], ['1002', 'dropped'], ['1002', 'my-own'], ['1003', 'basic-user'], ['1003', 'voicemail']] as $pair) {
		$assign->execute($pair);
	}

	$schema = new Schema($app);
	$jobs = new Jobs($app);
	$jobs->autostart = false;
	$services = new RenamingServices($app, $jobs);

	echo "\n  the owner column, on the install that adds it:\n";

	// `dropped` was the module's on this PBX: a default of the release before.
	$schema->addServiceOwnerColumn(array_merge(array_keys(Services::DEFAULTS), ['dropped', 'old-support']));
	$schema->addJobAdminColumn();
	$is('only a service of the operator\'s own is made theirs', $column('SELECT slug FROM oryk_provisioner_services WHERE owner != 0 ORDER BY slug'), ['my-own']);

	echo "\n  seed():\n";

	$notes = $services->seed();
	$slugs = array_keys(Services::DEFAULTS);
	sort($slugs, SORT_STRING);

	$is('the module\'s services are exactly DEFAULTS', $column('SELECT slug FROM oryk_provisioner_services WHERE owner = 0 ORDER BY slug'), $slugs);
	$is('each written again, not kept', $column("SELECT COUNT(*) FROM oryk_provisioner_services WHERE owner = 0 AND created_at = '2020-01-01 00:00:00'"), ['0']);
	$is('the operator\'s service is as it was', $column("SELECT CONCAT(slug, ' ', created_at) FROM oryk_provisioner_services WHERE owner != 0"), ['my-own 2020-01-01 00:00:00']);
	$is('assignments stay, a renamed slug\'s carried and a dropped default\'s gone', $column("SELECT CONCAT(extension, ':', service) FROM oryk_provisioner_service_assignments ORDER BY 1"), ['1001:support', '1002:my-own', '1003:basic-user', '1003:voicemail']);
	$is('the operator\'s link to a default stays', $column("SELECT COUNT(*) FROM oryk_provisioner_service_links WHERE parent = 'basic-user' AND child = 'my-own'"), ['1']);
	$is('nothing names a service that is gone', $column("SELECT COUNT(*) FROM oryk_provisioner_service_links k WHERE k.parent IN ('dropped', 'old-support') OR k.child IN ('dropped', 'old-support')"), ['0']);

	$want = [];

	foreach (Services::DEFAULTS as $slug => $default) {
		foreach ($default['services'] ?? [] as $child) {
			$want[] = $slug . '>' . $child;
		}
	}

	sort($want, SORT_STRING);
	$is('the links between defaults are DEFAULTS\'', $column("SELECT CONCAT(parent, '>', child) FROM oryk_provisioner_service_links WHERE child != 'my-own' ORDER BY 1"), $want);
	$is('install names the default it removed', count(array_filter($notes, function ($note) { return strpos($note, '"Dropped Default" (dropped)') !== false && strpos($note, '1 assignments') !== false; })), 1);
	$is('everyone who held it is revoked it, and nothing else is touched', $column("SELECT CONCAT(j.extension, ' ', j.reason, '/', j.source, ' ', s.event, ':', s.service) FROM oryk_provisioner_jobs j JOIN oryk_provisioner_job_steps s ON s.job_id = j.id ORDER BY j.extension, s.position"), ['1002 pack-changed/upgrade revoked:dropped', '1003 pack-changed/upgrade revoked:dropped']);
	$is('an upgrade\'s job has no admin', $column('SELECT DISTINCT admin FROM oryk_provisioner_jobs'), ['']);

	echo "\n  and a second install:\n";

	$schema->addServiceOwnerColumn(array_keys(Services::DEFAULTS));
	$is('says nothing', $services->seed(), []);
	$is('makes no job', $column('SELECT COUNT(*) FROM oryk_provisioner_jobs'), ['2']);
	$is('keeps every assignment', $column('SELECT COUNT(*) FROM oryk_provisioner_service_assignments'), ['4']);

	$db->exec('DROP TABLE IF EXISTS oryk_provisioner_services, oryk_provisioner_service_links, oryk_provisioner_service_assignments, oryk_provisioner_jobs, oryk_provisioner_job_steps');

	printf("\n%d passed, %d failed\n", $passed, $failed);

	exit($failed ? 1 : 0);
}
