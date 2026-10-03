<?php

// tests/smoke.php
//
// Ported from oryk_connect with the Users tab. Run from anywhere, with
// nothing installed:
//
//     php tests/smoke.php
//
// This does not need FreePBX, a database or Asterisk -- tests/stubs.php
// stands in for all three. It is not a substitute for trying a renumber on
// a real PBX. It is here to catch the class of mistake refactoring makes --
// a namespace, a constructor, a method that moved and left a caller behind,
// a guard that throws instead of declining -- before a deploy does.

require_once __DIR__ . '/stubs.php';
require_once __DIR__ . '/namespacing.php';

use FreePBX\Modules\Oryk_Provisioner\AsteriskConfig;
use FreePBX\Modules\Oryk_Provisioner\Bans;
use FreePBX\Modules\Oryk_Provisioner\CdrHistory;
use FreePBX\Modules\Oryk_Provisioner\Clients;
use FreePBX\Modules\Oryk_Provisioner\Endpoint;
use FreePBX\Modules\Oryk_Provisioner\EndpointSettings;
use FreePBX\Modules\Oryk_Provisioner\ExtensionManager;
use FreePBX\Modules\Oryk_Provisioner\ExtensionRenumberer;
use FreePBX\Modules\Oryk_Provisioner\Fail2ban;
use FreePBX\Modules\Oryk_Provisioner\FileRepo;
use FreePBX\Modules\Oryk_Provisioner\Freepbx as PbxDevices;
use FreePBX\Modules\Oryk_Provisioner\LogRepo;
use FreePBX\Modules\Oryk_Provisioner\Mac;
use FreePBX\Modules\Oryk_Provisioner\NumberAllocator;
use FreePBX\Modules\Oryk_Provisioner\Profiles;
use FreePBX\Modules\Oryk_Provisioner\Settings;
use FreePBX\Modules\Oryk_Provisioner\Tokens;
use FreePBX\Modules\Oryk_Provisioner\Transcoder;
use FreePBX\Modules\Oryk_Provisioner\UcpAssignments;
use FreePBX\Modules\Oryk_Provisioner\UsermanManager;
use FreePBX\Modules\Oryk_Provisioner\Users;
use FreePBX\Modules\Oryk_Provisioner\Vendor;
use FreePBX\Modules\Oryk_Provisioner\VoicemailManager;

// What the PBX is set to, for the tests that would otherwise depend on what
// the machine running them happens to be called
define('TEST_FROM_DOMAIN', 'oryk.io');

$passed = 0;
$failed = 0;

function is_eq($label, $got, $want)
{
	global $passed, $failed;

	$ok = $got === $want;
	$ok ? $passed++ : $failed++;

	printf(
		"  [%s] %-54s %s\n",
		$ok ? 'ok' : 'FAIL',
		$label,
		$ok ? '' : 'got ' . json_encode($got) . ', wanted ' . json_encode($want)
	);
}

$TEMPORARY = [];

/** A scratch file that goes away when the test run ends. */
function scratch_file()
{
	global $TEMPORARY;

	$path = tempnam(sys_get_temp_dir(), 'oryk-conf-');
	$TEMPORARY[] = $path;

	return $path;
}

/** Build the whole set, the way the module class does. */
function build()
{
	FreePBX::$core = new StubCore();
	FreePBX::$cdr = new StubCdr();
	FreePBX::$conf = new StubConfig();
	FreePBX::$config = ['ASTSPOOLDIR' => '/var/spool/asterisk'];

	$app = new StubApp();

	// The one thing here that touches the disk. It is pointed at a scratch
	// file rather than /etc/asterisk, and the tests read it back.
	$conf = scratch_file();
	$endpoints = new EndpointSettings($app, new AsteriskConfig($app, $conf), TEST_FROM_DOMAIN);

	$voicemail = new VoicemailManager($app);
	$cdr = new CdrHistory($app, $voicemail);
	$userman = new UsermanManager($app);
	$ucp = new UcpAssignments($app);
	$extensions = new ExtensionManager($app);
	$numbers = new NumberAllocator($app, $userman);
	$clients = new Clients(
		$app, new PbxDevices($app), new Profiles($app, new FileRepo($app)), new Tokens($app), new LogRepo($app)
	);
	$renumberer = new ExtensionRenumberer(
		$app, $extensions, $voicemail, $userman, $ucp, $cdr, $endpoints, $clients
	);
	$users = new Users(
		$app, $numbers, $renumberer, $extensions, $userman, $voicemail,
		$ucp, $cdr, $endpoints, $clients
	);

	return compact(
		'app', 'voicemail', 'cdr', 'userman', 'ucp', 'clients',
		'extensions', 'numbers', 'renumberer', 'users', 'endpoints', 'conf'
	);
}

$s = build();

echo "everything builds, and wires together the way the module does:\n";

foreach (['voicemail' => 'VoicemailManager',
          'cdr' => 'CdrHistory', 'userman' => 'UsermanManager',
          'ucp' => 'UcpAssignments', 'extensions' => 'ExtensionManager',
          'numbers' => 'NumberAllocator', 'renumberer' => 'ExtensionRenumberer',
          'endpoints' => 'EndpointSettings', 'clients' => 'Clients',
          'users' => 'Users'] as $key => $class) {
	is_eq($class, get_class($s[$key]), 'FreePBX\Modules\Oryk_Provisioner\\' . $class);
}

echo "\nwhat a user is:\n";

is_eq('a pjsip device that is its own extension', Users::SHAPE, "d.tech = 'pjsip' AND d.id = d.user");
is_eq('media encryption is forced on', Users::FORCED['media_encryption'] ?? null, 'sdes');

echo "\nwhat reaches a mailbox, which the call history lines up by position:\n";

is_eq('a long enough number gets the prefix as well',
	$s['voicemail']->dialableNumbers('1001'),
	['vmu1001', 'vmb1001', 'vms1001', 'vmi1001', '*1001']);
is_eq('a two digit one would collide with the feature codes',
	$s['voicemail']->dialableNumbers('98'),
	['vmu98', 'vmb98', 'vms98', 'vmi98']);
is_eq('two numbers of the same shape line up',
	count($s['voicemail']->dialableNumbers('1001')),
	count($s['voicemail']->dialableNumbers('2002')));

echo "\nnumber allocation:\n";

$s['app']->Database->answers = ['MAX(CAST(id' => '9990000005'];
is_eq('the next id follows the highest taken', $s['numbers']->generate(), '9990000006');

$s['app']->Database->answers = [];
is_eq('nothing taken yet starts the range', $s['numbers']->generate(), '9990000001');
is_eq('a free number is accepted', $s['numbers']->assertAvailable('1001'), '1001');
is_eq('a free number is not a conflict', $s['numbers']->findConflict('1001'), null);

foreach (['abc', '10a1', '', '10 01', '-5'] as $bad) {
	$threw = false;
	try {
		$s['numbers']->assertAvailable($bad);
	} catch (\Exception $e) {
		$threw = true;
	}
	is_eq('"' . $bad . '" is refused', $threw, true);
}

$threw = false;
try {
	$s['numbers']->assertAvailable('12345678901');
} catch (\Exception $e) {
	$threw = true;
}
is_eq('too long is refused', $threw, true);

$s['app']->Database->answers = ['SELECT description FROM devices' => 'Front Desk'];
is_eq('a number a device holds is a conflict',
	is_string($s['numbers']->findConflict('1001')), true);
is_eq('but the device may keep its own number',
	$s['numbers']->assertAvailable('1001', '1001'), '1001');
$s['app']->Database->answers = [];

echo "\nnothing installed: every subsystem declines rather than throwing:\n";

is_eq('moveMailbox', $s['voicemail']->moveMailbox('1001', '2002'), false);
is_eq('hasMailbox', $s['voicemail']->hasMailbox('1001'), false);
is_eq('syncEmail', $s['voicemail']->syncEmail('1001', 'a@example.com'), false);
is_eq('cdr migrate', $s['cdr']->migrate('1001', '2002'), 0);
is_eq('cdr purge', $s['cdr']->purge('1001'), ['rows' => 0, 'recordings' => 0]);
is_eq('userman findByExtension', $s['userman']->findByExtension('1001'), null);
is_eq('userman ownedAccount', $s['userman']->ownedAccount('1001'), null);
is_eq('userman ensure', $s['userman']->ensure('1001', 'Desk'), false);
is_eq('userman sync', $s['userman']->sync('1001', 'Desk'), false);
is_eq('userman removeOwnedAccount', $s['userman']->removeOwnedAccount('1001'), false);

echo "\nthe purge guard: what is not a number would match the whole table:\n";

$LOG = [];
is_eq('a path is refused', $s['cdr']->purge('../etc'), ['rows' => 0, 'recordings' => 0]);
is_eq('and said so once', count($LOG), 1);
is_eq('with the module prefix',
	strpos($LOG[0], 'ERROR: oryk_provisioner: refusing to purge') === 0, true);
is_eq('nothing is refused', $s['cdr']->purge(''), ['rows' => 0, 'recordings' => 0]);
is_eq('a fragment of SQL is refused', $s['cdr']->purge('1001 OR 1=1'), ['rows' => 0, 'recordings' => 0]);
is_eq('a number with a space is refused', $s['cdr']->purge('10 01'), ['rows' => 0, 'recordings' => 0]);

echo "\nsaving a user -- what store() actually builds:\n";

$s = build();
$input = [
	'id' => '',
	'extension' => '1001',
	'name' => 'Front Desk',
	'email' => 'desk@example.com',
	'secret' => 'from-the-form',
	'media_encryption' => 'no',
];
$before = $input;

$uid = $s['users']->store($input);
$added = FreePBX::$core->added;
$settings = $added['settings'];

is_eq('the typed number becomes the device id', $uid, '1001');
is_eq('store() leaves the caller\'s form untouched', $input, $before);
is_eq('the device is added under that id', (string) $added['id'], '1001');
is_eq('as pjsip', $added['tech'], 'pjsip');
is_eq('account is the id', $settings['account']['value'], '1001');
is_eq('dial follows the driver', $settings['dial']['value'], 'PJSIP/1001');
is_eq('mailbox is the device alias', $settings['mailbox']['value'], '1001@device');
is_eq('user is the id', $settings['user']['value'], '1001');
is_eq('the description is what was typed', $settings['description']['value'], 'Front Desk');
is_eq('the email is kept on the device', $settings['email']['value'], 'desk@example.com');
is_eq('the secret from the form wins', $settings['secret']['value'], 'from-the-form');
is_eq('it is marked the kind oryk_connect lists as Extension/User', $settings['kind']['value'], 'pjsip');
is_eq('emergency cid defaults to the id', $settings['emergency_cid']['value'], '1001');
is_eq('every setting has a value', count(array_filter($settings, function ($x) {
	return !array_key_exists('value', $x);
})), 0);
is_eq('every setting has a flag', count(array_filter($settings, function ($x) {
	return !array_key_exists('flag', $x);
})), 0);
is_eq('including the ones not returned by the driver',
	[isset($settings['account']['flag']), isset($settings['dial']['flag']),
	 isset($settings['mailbox']['flag']), isset($settings['media_encryption_optimistic']['flag'])],
	[true, true, true, true]);

echo "\n  some settings are pinned down, whatever the form said:\n";

is_eq('media encryption is forced on', $settings['media_encryption']['value'], 'sdes');
is_eq('and optimistically', $settings['media_encryption_optimistic']['value'], 'yes');

echo "\n  a blank name falls back to the number:\n";

$s = build();
$uid = $s['users']->store(['id' => '', 'extension' => '1002', 'name' => '']);
is_eq('the device is named after itself',
	FreePBX::$core->added['settings']['description']['value'], '1002');
is_eq('and the extension is created with that name',
	FreePBX::$core->users['1002']['name'] ?? null, '1002');

echo "\n  a blank number on a new user is generated, not left empty:\n";

$s = build();
$s['app']->Database->answers = ['MAX(CAST(id' => '9990000012'];
$uid = $s['users']->store(['id' => '', 'extension' => '', 'name' => 'Generated']);
is_eq('the next id in the range', $uid, '9990000013');
is_eq('the endpoint manager is run for it', FreePBX::$core->epm, ['9990000013']);
is_eq('a blank secret leaves Core\'s generated one',
	FreePBX::$core->added['settings']['secret']['value'], 'from-core');

echo "\n  a number already taken is refused, and nothing is written:\n";

$s = build();
$s['app']->Database->answers = ['SELECT description FROM devices' => 'Somebody Else'];
$threw = false;
try {
	$s['users']->store(['id' => '', 'extension' => '1001', 'name' => 'Mine']);
} catch (\Exception $e) {
	$threw = true;
}
is_eq('it throws', $threw, true);
is_eq('no device was added', FreePBX::$core->added, null);
is_eq('no extension was created', FreePBX::$core->users, []);

$s = build();
$threw = false;
try {
	$s['users']->store(['id' => '1001', 'extension' => '1001', 'name' => 'Gone']);
} catch (\Exception $e) {
	$threw = true;
}
is_eq('so is saving a user that has gone', $threw, true);
is_eq('which writes nothing either', FreePBX::$core->added, null);

echo "\n  saving an existing user starts from what it had:\n";

/** A user as Core holds it, with settings the editor has no field for. */
function stored_user($id)
{
	FreePBX::$core->devices[$id] = [
		'id' => $id, 'tech' => 'pjsip', 'user' => $id, 'description' => 'Front Desk',
		'secret' => 'stored-secret', 'emergency_cid' => $id, 'callerid' => 'Front Desk <' . $id . '>',
		'media_encryption' => 'no', 'email' => 'kept@example.com', 'from_domain' => 'kept.example.net',
		'not_a_driver_setting' => 'stray',
	];
}

$s = build();
stored_user('1001');
$uid = $s['users']->store(['id' => '1001', 'extension' => '', 'name' => 'Front Desk', 'secret' => '']);
$settings = FreePBX::$core->added['settings'];

is_eq('a blank number keeps its own', $uid, '1001');
is_eq('a blank secret keeps the stored one', $settings['secret']['value'], 'stored-secret');
is_eq('an email the form did not post is kept', $settings['email']['value'], 'kept@example.com');
is_eq('so is a from domain', $settings['from_domain']['value'], 'kept.example.net');
is_eq('a keyword no driver names is not written to sip', isset($settings['not_a_driver_setting']), false);
is_eq('the forced settings still win', $settings['media_encryption']['value'], 'sdes');
is_eq('the old row is replaced in edit mode', FreePBX::$core->deleted, [['1001', true]]);

$s = build();
FreePBX::$core->devices['9990000101'] = ['id' => '9990000101', 'tech' => 'pjsip', 'user' => '1001'];
$threw = false;
try {
	$s['users']->store(['id' => '9990000101', 'extension' => '', 'name' => 'Handset']);
} catch (\Exception $e) {
	$threw = true;
}
is_eq('a handset posted as a user is refused', $threw, true);
is_eq('and left alone', [FreePBX::$core->deleted, FreePBX::$core->added], [[], null]);

/** A Core that will not write the device. */
class RefusingCore extends StubCore
{
	public function addDevice($id, $tech, $settings, $editmode = false)
	{
		return false;
	}
}

$s = build();
FreePBX::$core = new RefusingCore();
$threw = false;
try {
	$s['users']->store(['id' => '', 'extension' => '1001', 'name' => 'Desk']);
} catch (\Exception $e) {
	$threw = true;
}
is_eq('a device Core will not write is a failed save', $threw, true);
is_eq('and saveUser says so', $s['users']->saveUser(['id' => '', 'extension' => '1001'])['status'], false);

echo "\n  a changed number renumbers, and the clients follow:\n";

$s = build();
stored_user('1001');
$uid = $s['users']->store(['id' => '1001', 'extension' => '2002', 'name' => 'Front Desk', 'secret' => '']);
$settings = FreePBX::$core->added['settings'];
$repointed = array_filter($s['app']->Database->seen, function ($q) {
	return strpos($q, 'UPDATE `oryk_provisioner_clients` SET device_id = :new WHERE device_id = :old') !== false;
});

is_eq('the user is on the new number', $uid, '2002');
is_eq('added there', (string) FreePBX::$core->added['id'], '2002');
is_eq('the old device went once, by the renumbering', FreePBX::$core->deleted, [['1001', false]]);
is_eq('the secret came with it', $settings['secret']['value'], 'stored-secret');
is_eq('an emergency cid that was the old number is the new one', $settings['emergency_cid']['value'], '2002');
is_eq('the clients pointing at it were repointed', count($repointed), 1);

echo "\n  deleting a user deletes every client pointing at it:\n";

$s = build();
stored_user('1001');
$s['app']->Database->fetchAlls = ['SELECT id FROM `oryk_provisioner_clients` WHERE device_id = :id' => ['5', '6']];
$s['app']->Database->answers['SELECT mac FROM `oryk_provisioner_clients` WHERE id = :id'] = '0004f282e824';
is_eq('remove() says it deleted', $s['users']->remove('1001'), true);
is_eq('the device went', FreePBX::$core->deleted, [['1001', false]]);
is_eq('both its clients were deleted, whatever their MAC',
	array_values(array_map(function ($p) { return $p[1][':id']; }, array_filter($s['app']->Database->params, function ($p) {
		return strpos($p[0], 'DELETE FROM `oryk_provisioner_clients` WHERE id = :id') !== false;
	}))), [5, 6]);
is_eq('and each one\'s Logs tab entries went with it, by its MAC',
	count(array_filter($s['app']->Database->params, function ($p) {
		return strpos($p[0], 'DELETE FROM `oryk_provisioner_logs` WHERE mac = :mac') !== false && $p[1][':mac'] === '0004f282e824';
	})), 2);
is_eq('and none was left with no device',
	(bool) array_filter($s['app']->Database->seen, function ($q) {
		return strpos($q, 'SET device_id = NULL') !== false;
	}), false);

$s = build();
FreePBX::$core->devices['9990000101'] = ['id' => '9990000101', 'tech' => 'pjsip', 'user' => '1001'];
is_eq('a handset is not a user, and is not deleted as one', $s['users']->remove('9990000101'), false);
is_eq('nothing was deleted', FreePBX::$core->deleted, []);


echo "\nevery global class named in src/ is qualified or imported:\n";

$unqualified = [];

foreach (glob(__DIR__ . '/../src/*.php') as $file) {
	$unqualified = array_merge($unqualified, oryk_unqualified_classes($file));
}

// PDO::FETCH_COLUMN inside namespace FreePBX\Modules\Oryk_Provisioner is
// FreePBX\Modules\Oryk_Provisioner\PDO, which does not exist. It fatals only
// when the line runs, and the line that found this one runs when somebody
// deletes a device with call history.
is_eq('nothing unqualified', $unqualified, []);

echo "\nwith the CDR module installed, the history is actually walked:\n";

$s = build();
$s['app']->Modules->active = ['cdr'];

$LOG = [];
$rows = $s['cdr']->migrate('1001', '2002');

is_eq('migrate rewrites rows', $rows > 0, true);
is_eq('and says how many it moved',
	(bool) array_filter($LOG, function ($l) {
		return strpos($l, 'moved') !== false && strpos($l, 'call history rows') !== false;
	}), true);

$statements = FreePBX::$cdr->handle->statements;

// migrate() deliberately does not read the columns first: it names the ones
// the reports read and lets runUpdate() step over any this site does not
// have. purge() cannot do that -- it has to build one match clause -- which
// is why only it reads them.
is_eq('it checked which tables the site has',
	(bool) array_filter($statements, function ($q) {
		return strpos($q, 'SHOW TABLES LIKE') !== false;
	}), true);
is_eq('and did not need to read the columns',
	(bool) array_filter($statements, function ($q) {
		return strpos($q, 'SHOW COLUMNS') !== false;
	}), false);
is_eq('it rewrote the plain number columns',
	(bool) array_filter($statements, function ($q) {
		return strpos($q, 'SET `src` = :new') !== false;
	}), true);
is_eq('it rewrote inside channel names',
	(bool) array_filter($statements, function ($q) {
		return strpos($q, 'REPLACE(`channel`') !== false;
	}), true);
is_eq('it rewrote the caller id string',
	(bool) array_filter($statements, function ($q) {
		return strpos($q, 'SET clid = REPLACE(clid') !== false;
	}), true);
is_eq('it moved the voicemail pseudo extensions too',
	(bool) array_filter($statements, function ($q) {
		return strpos($q, 'SET dst = :new WHERE dst = :old') !== false;
	}), true);

$s = build();
$s['app']->Modules->active = ['cdr'];

$LOG = [];
$removed = $s['cdr']->purge('1001');

is_eq('purge finds and deletes the calls', $removed['rows'] > 0, true);
is_eq('it read the columns before matching',
	(bool) array_filter(FreePBX::$cdr->handle->statements, function ($q) {
		return strpos($q, 'SHOW COLUMNS') !== false;
	}), true);
is_eq('it deleted by call identifier, not by extension',
	(bool) array_filter(FreePBX::$cdr->handle->statements, function ($q) {
		return strpos($q, 'DELETE FROM') !== false
			&& (strpos($q, 'uniqueid') !== false || strpos($q, 'linkedid') !== false);
	}), true);
is_eq('nothing was logged as a failure',
	array_values(array_filter($LOG, function ($l) {
		return strpos($l, 'ERROR') === 0;
	})), []);

echo "\nthe custom endpoint file: everything this module did not write survives:\n";

$s = build();
$conf = $s['endpoints']->config();

file_put_contents($s['conf'], <<<'CONF'
;
; pjsip.endpoint_custom_post.conf
;

[9990000001](+)
from_domain=old.example.com
callerid=Front Desk <9990000001>  ; typed by hand

[trunk-to-carrier](+)
; another module put this here
from_domain=carrier.example.net

;--
[9990000009](+)
from_domain=never-read.example.com
--;

CONF
);

is_eq('a section is read back whole', $conf->values('9990000001'),
	['from_domain' => 'old.example.com', 'callerid' => 'Front Desk <9990000001>']);
is_eq('a section inside a comment block is not a section',
	$conf->has('9990000009'), false);
is_eq('and the rest of the file is seen',
	$conf->sections(), ['9990000001', 'trunk-to-carrier']);

$conf->edit(function (AsteriskConfig $c) {
	$c->set('9990000001', [
		'from_domain' => 'oryk.io',
		'callerid' => 'Front Desk <1001>',
	], '(+)');
});

$text = file_get_contents($s['conf']);

is_eq('the value is rewritten where it stood',
	strpos($text, "[9990000001](+)\nfrom_domain=oryk.io\n") !== false, true);
is_eq('the old value is gone', strpos($text, 'old.example.com'), false);
is_eq('a comment after a value it changed is kept',
	strpos($text, 'callerid=Front Desk <1001> ; typed by hand') !== false, true);
is_eq("another module's section is untouched",
	strpos($text, "[trunk-to-carrier](+)\n; another module put this here\nfrom_domain=carrier.example.net") !== false,
	true);
is_eq('the comment block is still commented out',
	strpos($text, ";--\n[9990000009](+)") !== false, true);
is_eq('the file it did not open is the file it did not change',
	substr_count($text, 'from_domain='), 3);

echo "\n  a setting the section does not have yet goes inside it:\n";

$conf->edit(function (AsteriskConfig $c) {
	$c->set('9990000001', ['send_rpid' => 'yes'], '(+)');
});

$text = file_get_contents($s['conf']);

is_eq('after the last setting in the section, not at the end of the file',
	strpos($text, "callerid=Front Desk <1001> ; typed by hand\nsend_rpid=yes\n") !== false, true);
is_eq('and the section after it is where it was',
	strpos($text, "[trunk-to-carrier](+)") !== false, true);

echo "\n  writing what the file already says changes nothing:\n";

$before = file_get_contents($s['conf']);

$conf->edit(function (AsteriskConfig $c) {
	$c->set('9990000001', ['send_rpid' => 'yes'], '(+)');
});

is_eq('the file is byte for byte what it was', file_get_contents($s['conf']), $before);
is_eq('and nothing was marked as needing a write', $conf->changed(), false);

echo "\n  a section that is not there yet is added, with the flags it needs:\n";

$conf->edit(function (AsteriskConfig $c) {
	$c->set('9990000042', ['from_domain' => 'oryk.io'], '(+)');
});

$text = file_get_contents($s['conf']);

is_eq('appended, adding to the generated endpoint rather than replacing it',
	strpos($text, "[9990000042](+)\nfrom_domain=oryk.io") !== false, true);

echo "\n  and taken out again without taking anything else with it:\n";

$conf->edit(function (AsteriskConfig $c) {
	$c->removeSection('9990000001');
});

$text = file_get_contents($s['conf']);

is_eq('the section is gone', $conf->has('9990000001'), false);
is_eq('every setting in it went too', strpos($text, 'send_rpid'), false);
is_eq("the other module's section is still there",
	$conf->values('trunk-to-carrier'), ['from_domain' => 'carrier.example.net']);
is_eq('so is the file header', strpos($text, '; pjsip.endpoint_custom_post.conf') !== false, true);
is_eq('so is the comment block', strpos($text, '--;') !== false, true);

echo "\n  what would come back as something else is refused, not written:\n";

foreach ([
	'a value carrying a newline' => ['9990000001', ['from_domain' => "x.example.com\nmatch=203.0.113.4"]],
	'a section name carrying a bracket' => ['999]000[1', ['from_domain' => 'x.example.com']],
	'a section name that is empty' => ['   ', ['from_domain' => 'x.example.com']],
	'a setting name with a space in it' => ['9990000001', ['from domain' => 'x.example.com']],
] as $label => $bad) {
	$threw = false;

	try {
		$conf->set($bad[0], $bad[1], '(+)');
	} catch (\InvalidArgumentException $e) {
		$threw = true;
	}

	is_eq($label . ' is refused', $threw, true);
}

echo "\nsaving a user writes its endpoint settings, deleting it takes them back:\n";

$s = build();
$uid = $s['users']->store(['id' => '', 'extension' => '1001', 'name' => 'Front Desk']);

$text = file_get_contents($s['conf']);

is_eq('the endpoint gets a section that adds to the generated one',
	strpos($text, '[1001](+)') !== false, true);
is_eq('carrying the from domain',
	$s['endpoints']->config()->get('1001', 'from_domain'), TEST_FROM_DOMAIN);

FreePBX::$core->devices['1001'] = [
	'id' => '1001', 'tech' => 'pjsip', 'kind' => 'pjsip',
	'description' => 'Front Desk', 'user' => '1001',
];

$s['users']->remove('1001');

is_eq('and gives it up with the device',
	$s['endpoints']->config()->has('1001'), false);

echo "\n  a renumbered device takes its settings to the new number:\n";

$s = build();
$s['endpoints']->apply('1001', ['send_rpid' => 'yes']);
$s['endpoints']->move('1001', '2002');
$conf = $s['endpoints']->config();

is_eq('the old number gives them up', $conf->has('1001'), false);
is_eq('the new number has what every endpoint gets',
	$conf->get('2002', 'from_domain'), TEST_FROM_DOMAIN);
is_eq('and what was written for this device alone',
	$conf->get('2002', 'send_rpid'), 'yes');

echo "\nwhere the from domain comes from, asked in order:\n";

$s = build();

is_eq('what the PBX is set to, when the device says nothing',
	$s['endpoints']->fromDomain('1001'), TEST_FROM_DOMAIN);

FreePBX::$core->devices['1001'] = [
	'id' => '1001', 'tech' => 'pjsip', 'kind' => 'pjsip',
	'description' => 'Front Desk', 'user' => '1001',
	'from_domain' => 'desk.example.net',
];

is_eq('what the device says, when it says something',
	$s['endpoints']->fromDomain('1001'), 'desk.example.net');
is_eq('and the PBX answer is still there for everything else',
	$s['endpoints']->fromDomain('1002'), TEST_FROM_DOMAIN);

$s['endpoints']->apply('1001');

is_eq('which is what gets written',
	$s['endpoints']->config()->get('1001', 'from_domain'), 'desk.example.net');

$loose = new EndpointSettings(new StubApp(), new AsteriskConfig(new StubApp(), scratch_file()));
$fallback = $loose->fromDomain(null);

is_eq('with nothing set at all, the PBX name or nothing -- never a name that is not a domain',
	$fallback === '' || (strpos($fallback, '.') !== false
		&& strpos($fallback, 'localhost') === false
		&& substr($fallback, -6) !== '.local'), true);

echo "\n  the PBX-wide answer is a FreePBX setting, in Advanced Settings:\n";

$s = build();
$endpoints = new EndpointSettings($s['app'], new AsteriskConfig($s['app'], scratch_file()));
$settings = new Settings($s['app']);

$settings->register();
$defined = FreePBX::Config()->defined[Settings::FROM_DOMAIN] ?? [];

is_eq('it is registered where an administrator would look for it',
	[$defined['category'] ?? null, $defined['type'] ?? null, $defined['module'] ?? null],
	['Oryk Provisioner', 'text', 'oryk_provisioner']);
is_eq('blank is allowed, since blank is what asks for the hostname',
	$defined['emptyok'] ?? null, 1);
is_eq('and what is typed there has to look like a domain',
	[
		(bool) preg_match($defined['options'], 'oryk.io'),
		(bool) preg_match($defined['options'], 'not a domain'),
	],
	[true, false]);

is_eq('the Settings tab refuses what Advanced Settings would',
	$settings->set(Settings::FROM_DOMAIN, 'not a domain') !== null, true);
is_eq('and leaves the value as it was',
	FreePBX::Config()->get(Settings::FROM_DOMAIN), '');

$settings->set(Settings::FROM_DOMAIN, 'set-in-advanced.example.net');

is_eq('what is set there is what an endpoint gets',
	$endpoints->fromDomain('1002'), 'set-in-advanced.example.net');

$fresh = new EndpointSettings($s['app'], new AsteriskConfig($s['app'], scratch_file()));

is_eq('and it is read from the setting, not remembered from the setting call',
	$fresh->fromDomain('1002'), 'set-in-advanced.example.net');

$settings->register();

is_eq('registering again on an upgrade leaves it where it is',
	FreePBX::Config()->get(Settings::FROM_DOMAIN), 'set-in-advanced.example.net');

$fields = array_column($settings->fields([Settings::FROM_DOMAIN => 'pbx.example.net']), null, 'keyword');

is_eq('the Settings tab draws it with its value and what blank comes to',
	[$fields[Settings::FROM_DOMAIN]['value'], $fields[Settings::FROM_DOMAIN]['placeholder']],
	['set-in-advanced.example.net', 'pbx.example.net']);

is_eq('a keyword that is not a setting is ignored, not written',
	[$settings->saveSettings(['settings' => ['AMPWEBROOT' => '/tmp']])['status'], FreePBX::Config()->get('AMPWEBROOT')],
	[true, '']);

$settings->set(Settings::FROM_DOMAIN, '');

is_eq('and blank is saved as blank',
	FreePBX::Config()->get(Settings::FROM_DOMAIN), '');

echo "\n  the Hostname setting comes before this machine's name:\n";

$s = build();
$named = new EndpointSettings($s['app'], new AsteriskConfig($s['app'], scratch_file()));
FreePBX::$config[Settings::HOSTNAME] = 'pbx.example.net';

is_eq('a blank From Domain is the Hostname setting', $named->fromDomain('1002'), 'pbx.example.net');

FreePBX::$config[Settings::HOSTNAME] = '203.0.113.7';

is_eq('but not when that is an address', $named->hostname() !== '203.0.113.7', true);

unset(FreePBX::$config[Settings::HOSTNAME]);

echo "\n  a setting that works out to nothing is taken off the endpoint:\n";

$s = build();
$s['endpoints']->apply('1001', ['send_rpid' => 'yes']);

is_eq('it was written', $s['endpoints']->config()->get('1001', 'from_domain'), TEST_FROM_DOMAIN);

$s['endpoints']->apply('1001', ['from_domain' => '']);

is_eq('and now it is gone', $s['endpoints']->config()->get('1001', 'from_domain'), null);
is_eq('while what was not asked about stays',
	$s['endpoints']->config()->get('1001', 'send_rpid'), 'yes');

echo "\n  taking the setting over from oryk_connect keeps its value:\n";

/** A FreePBX that, at worst, would reset a value when a setting is defined again. */
class ResettingConfig extends StubConfig
{
	public function define_conf_setting($keyword, $vars, $commit = false)
	{
		$this->defined[$keyword] = $vars;
		FreePBX::$config[$keyword] = $vars['value'];

		return true;
	}
}

$s = build();
FreePBX::$conf = new ResettingConfig();
FreePBX::$config[Settings::FROM_DOMAIN] = 'set-in-connect.example.net';

(new Settings($s['app']))->register();

is_eq('the value set under Connect survives being registered here',
	FreePBX::Config()->get(Settings::FROM_DOMAIN), 'set-in-connect.example.net');
is_eq('and the setting now belongs to this module',
	FreePBX::Config()->defined[Settings::FROM_DOMAIN]['module'] ?? null, 'oryk_provisioner');


echo "\n  fail2ban: what the helper's answer to check means:\n";

is_eq('no helper installed is missing', Fail2ban::state('missing', null, 1)['state'], 'missing');
is_eq('no JSON at all is sudo refusing',
	Fail2ban::state(null, ['ok' => false, 'exit' => 1, 'error' => 'sudo: a password is required'], 1)['state'], 'sudo');
is_eq('and what sudo said is passed on',
	Fail2ban::state(null, ['ok' => false, 'exit' => 1, 'error' => 'sudo: a password is required'], 1)['detail'], 'sudo: a password is required');
is_eq('another version installed is stale',
	Fail2ban::state(null, ['ok' => true, 'version' => 1, 'exit' => 0], 2)['state'], 'stale');
is_eq('stale even while fail2ban is down',
	Fail2ban::state(null, ['ok' => false, 'version' => 1, 'exit' => 69], 2)['state'], 'stale');
is_eq('a current helper with fail2ban down is fail2ban',
	Fail2ban::state(null, ['ok' => false, 'version' => 2, 'exit' => 69, 'error' => 'not running'], 2)['state'], 'fail2ban');
is_eq('a current helper with fail2ban up is ok',
	Fail2ban::state(null, ['ok' => true, 'version' => 2, 'fail2ban' => '1.0.2', 'exit' => 0], 2)['state'], 'ok');
is_eq('the shipped helper has a version line',
	Fail2ban::helperVersion(file_get_contents(__DIR__ . '/../bin/oryk-fail2ban')) > 0, true);
is_eq('a file without one has none', Fail2ban::helperVersion("#!/bin/sh\n"), null);

echo "\n  bans: rows, keys and addresses:\n";

$answer = [
	'ok' => true,
	'now' => 1000,
	'bans' => [
		['jail' => 'asterisk', 'ip' => '188.165.236.15', 'banned' => '2026-10-01 00:32:58', 'banned_at' => 400, 'bantime' => 3600, 'permanent' => false, 'expires' => '2026-10-01 01:32:58', 'expires_at' => 4000],
		['jail' => 'asterisk', 'ip' => '51.68.19.88', 'banned' => '2026-10-01 00:39:48', 'banned_at' => 900, 'bantime' => 3600, 'permanent' => false, 'expires' => '2026-10-01 01:39:48', 'expires_at' => 4500],
		['jail' => 'sshd', 'ip' => '2001:DB8::0001', 'banned' => '2026-10-01 00:10:00', 'banned_at' => 100, 'bantime' => -1, 'permanent' => true, 'expires' => null, 'expires_at' => null],
		['jail' => 'sshd', 'ip' => 'not an address', 'banned_at' => 1],
	],
];
$rows = Bans::rows($answer);

is_eq('a row that is not an address is dropped', count($rows), 3);
is_eq('a row is keyed jail/ip', $rows[0]['id'], 'asterisk/188.165.236.15');
is_eq('ages are on the helper clock', [$rows[0]['banned_age'], $rows[0]['expires_in']], [600, 3000]);
is_eq('an IPv6 address is spelled canonically', $rows[2]['ip'], '2001:db8::1');
is_eq('a permanent ban has no expiry', [$rows[2]['permanent'], $rows[2]['expires_in']], [true, null]);

$page = Bans::page($rows, 'ip', 'asc', '', 0, 10);
is_eq('addresses sort by their bytes, IPv4 first',
	array_column($page['rows'], 'ip'), ['51.68.19.88', '188.165.236.15', '2001:db8::1']);
is_eq('a permanent ban expires last',
	array_column(Bans::page($rows, 'expires_at', 'asc', '', 0, 10)['rows'], 'ip'), ['188.165.236.15', '51.68.19.88', '2001:db8::1']);
is_eq('an unknown sort key sorts by address',
	array_column(Bans::page($rows, 'nope; DROP', 'asc', '', 0, 10)['rows'], 'ip'), ['51.68.19.88', '188.165.236.15', '2001:db8::1']);
is_eq('search finds the jail', Bans::page($rows, 'ip', 'asc', 'SSH', 0, 10)['total'], 1);
is_eq('the total is of what matched, the rows one page of it',
	[Bans::page($rows, 'ip', 'asc', '', 1, 1)['total'], count(Bans::page($rows, 'ip', 'asc', '', 1, 1)['rows'])], [3, 1]);

is_eq('a key splits into jail and address', Bans::splitKey('asterisk/2001:DB8::1'), ['asterisk', '2001:db8::1']);
is_eq('a key with a bad jail is refused', Bans::splitKey('ast;erisk/1.2.3.4'), null);
is_eq('a key with a range is refused', Bans::splitKey('asterisk/10.0.0.0/8'), null);
is_eq('a key with no slash is refused', Bans::splitKey('1.2.3.4'), null);
is_eq('canonical refuses a range', Bans::canonical('10.0.0.0/8'), null);
is_eq('canonical refuses a zone id', Bans::canonical('fe80::1%eth0'), null);

is_eq('your own address is refused', Bans::refusal('203.0.113.9', '203.0.113.9', []) !== null, true);
is_eq('loopback is refused', Bans::refusal('127.0.0.2', '198.51.100.1', []) !== null, true);
is_eq('IPv6 loopback is refused', Bans::refusal('::1', '198.51.100.1', []) !== null, true);
is_eq('the PBX\'s own address is refused', Bans::refusal('192.0.2.10', '198.51.100.1', ['192.0.2.10']) !== null, true);
is_eq('anything else is allowed', Bans::refusal('203.0.113.7', '198.51.100.1', ['192.0.2.10']), null);

echo "\n  fail2ban: the Settings tab switches the Bans tab on and off:\n";

$s = build();
FreePBX::$conf = new StubConfig();
unset(FreePBX::$config[Settings::FAIL2BAN]);
$settings = new Settings($s['app']);
$fail2ban = new Fail2ban($s['app'], $settings);

is_eq('before an install has registered it, it is on', $fail2ban->enabled(), true);

$settings->register();

is_eq('registered, it starts on', FreePBX::Config()->get(Settings::FAIL2BAN), true);
is_eq('switched off from the tab it is off',
	[$settings->set(Settings::FAIL2BAN, '0'), $fail2ban->enabled()], [null, false]);
is_eq('off, the state is disabled without asking sudo', $fail2ban->status()['state'], 'disabled');
is_eq('off, every question answers not-ok', [$fail2ban->jails(), $fail2ban->count(), $fail2ban->bans()['ok']], [[], 0, false]);
is_eq('off, a ban is refused', (new Bans($s['app'], $fail2ban))->saveBan(['jail' => 'asterisk', 'ip' => '203.0.113.7'])['status'], false);
is_eq('off, an unban is refused', (new Bans($s['app'], $fail2ban))->deleteBan('asterisk/203.0.113.7')['status'], false);
is_eq('and on again', [$settings->set(Settings::FAIL2BAN, '1'), $settings->get(Settings::FAIL2BAN)], [null, true]);

echo "\nopen provisioning:\n";

echo "\n  what openClient() answers before a user is found:\n";

$s = build();
$settings = new Settings($s['app']);
$template = new \FreePBX\Modules\Oryk_Provisioner\Template($s['app'], new PbxDevices($s['app']), $settings);
$endpoint = new Endpoint(
	$s['app'], $s['clients'], new \FreePBX\Modules\Oryk_Provisioner\Matcher($s['app'], $template), $template,
	new FileRepo($s['app']), new LogRepo($s['app']), new \FreePBX\Modules\Oryk_Provisioner\ProvisioningLog($s['app']),
	new Profiles($s['app'], new FileRepo($s['app'])), $s['users']
);
$codes = function ($user, $pass, $address) use ($endpoint) {
	return $endpoint->openClient($user, $pass, $address)['code'] ?? 200;
};

is_eq('no credentials is a 401, the challenge', $codes('', '', '192.0.2.1'), 401);
is_eq('a username that cannot be one is a 400', $codes(' bob', 'pw', '192.0.2.1'), 400);
is_eq('no User Manager is a 409', $codes('bob', 'pw', '192.0.2.1'), 409);

echo "\nthe vendor a User-Agent names:\n";

foreach ([
	'FileTransport PolycomVVX-VVX_411-UA/5.9.5.0614' => 'Polycom',
	'Yealink SIP-T46S 66.86.0.15' => 'Yealink',
	'Grandstream Model HW GXP2170 SW 1.0.11.3 DevId c074ad123456' => 'Grandstream',
	'Mozilla/4.0 (compatible; snom320-SIP 8.7.5.35' => 'Snom',
	'AUDC-IPPhone/2.0.0_build_15 (420HD; 00908F3BBCBA)' => 'AudioCodes',
	'Aastra6731i MAC:00-08-5D-12-34-56 V:3.3.1.4305-SIP' => 'Mitel',
	'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15' => 'WebKit',
	'Mozilla/5.0 (Linux; Android 7.0; GXV3380) AppleWebKit/537.36 Grandstream' => 'Grandstream',
	'curl/8.4.0' => null,
	'' => null,
] as $agent => $vendor) {
	is_eq($agent === '' ? '(no User-Agent)' : substr($agent, 0, 50), Vendor::fromUserAgent($agent), $vendor);
}

echo "\n  a client with no profile is served the one named after its vendor:\n";

$db = $s['app']->Database;
$client = ['id' => '5', 'mac' => '0004f282e824', 'token' => null, 'enabled' => '1', 'device_id' => null,
	'profile_id' => null, 'public_ip' => null, 'private_ip' => null, 'extension' => null, 'description' => null,
	'tech' => null, 'profile_name' => null, 'profile_enabled' => null];
$db->fetches = ['WHERE pc.mac = :mac' => [$client], 'WHERE LOWER(name) = LOWER(:name)' => [['id' => '3', 'name' => 'polycom', 'enabled' => '0']]];

$disabled = $endpoint->resolveRequest('0004f282e824', null, null, 'GET', 'Polycom');

is_eq('the vendor profile is the one it gets', $disabled['message'] ?? null, 'The polycom profile is disabled.');
is_eq('and a disabled profile is a 403, not a 404', $disabled['code'] ?? 404, 403);

$db->fetches = ['WHERE pc.mac = :mac' => [$client], 'WHERE LOWER(name) = LOWER(:name)' => []];

is_eq('no profile by that name, no profile',
	$endpoint->resolveRequest('0004f282e824', null, null, 'GET', 'Polycom')['message'] ?? null, '0004f282e824 has no profile assigned.');

$db->fetches = ['WHERE pc.mac = :mac' => [$client]];
$db->seen = [];

is_eq('and no vendor, no lookup',
	[$endpoint->resolveRequest('0004f282e824')['message'] ?? null,
		(bool) array_filter($db->seen, function ($q) { return strpos($q, 'LOWER(name)') !== false; })],
	['0004f282e824 has no profile assigned.', false]);

$db->fetches = ['WHERE pc.mac = :mac' => [['enabled' => '0'] + $client]];
$disabled = $endpoint->resolveRequest('0004f282e824', null, null, 'GET', 'Polycom');

is_eq('a disabled client is a 403 too, before any profile is looked at',
	[$disabled['code'] ?? 404, $disabled['message'] ?? null], [403, '0004f282e824 is disabled.']);

$db->fetches = ['WHERE pc.mac = :mac' => [['profile_id' => '9', 'profile_name' => 'Own', 'profile_enabled' => '0'] + $client],
	'WHERE LOWER(name) = LOWER(:name)' => [['id' => '3', 'name' => 'Polycom', 'enabled' => '1']]];

is_eq('a profile of its own wins over the vendor',
	$endpoint->resolveRequest('0004f282e824', null, null, 'GET', 'Polycom')['message'] ?? null, 'The Own profile is disabled.');

$db->fetches = [];

echo "\n  what findOrCreate() refuses before asking User Manager anything:\n";

/** The class of what a call threw, or null when it returned. */
function thrown(callable $call)
{
	try {
		$call();
	} catch (\Exception $e) {
		return get_class($e);
	}

	return null;
}

$s = build();

is_eq('a blank username', thrown(function () use ($s) { $s['users']->findOrCreate('', 'secret'); }), 'InvalidArgumentException');
is_eq('a username with a space around it', thrown(function () use ($s) { $s['users']->findOrCreate(' bob', 'secret'); }), 'InvalidArgumentException');
is_eq('a blank password', thrown(function () use ($s) { $s['users']->findOrCreate('bob', ''); }), 'InvalidArgumentException');
is_eq('anything at all without User Manager', thrown(function () use ($s) { $s['users']->findOrCreate('bob', 'secret'); }), 'RuntimeException');
is_eq('and nothing was created', FreePBX::$core->added, null);

echo "\n  the client a device is provisioned as:\n";

$s = build();
$db = $s['app']->Database;
$db->answers = ['FROM devices WHERE id' => '1001'];
$db->insertId = 7;
$client = $s['clients']->findOrCreateForDevice('1001', 'bob:secret');

is_eq('none on an internal MAC: one is made', [$client['created'], $client['mac']], [true, Mac::internal(7)]);

$saved = array_values(array_filter($db->params, function ($p) {
	return strpos(ltrim($p[0]), 'UPDATE `oryk_provisioner_clients`') === 0;
}));

is_eq('with no profile and the credentials as its token',
	[$saved[0][1][':profile_id'] ?? null, password_verify('bob:secret', (string) ($saved[0][1][':token'] ?? ''))],
	[null, true]);

$s = build();
$db = $s['app']->Database;
$db->fetches = [Clients::INTERNAL_EXPR => [['id' => '5', 'mac' => Mac::internal(5), 'token' => password_hash('bob:old', PASSWORD_DEFAULT)]]];
$client = $s['clients']->findOrCreateForDevice('1001', 'bob:new');
$updated = array_values(array_filter($db->params, function ($p) {
	return strpos($p[0], 'SET token = :token') !== false;
}));

is_eq('one already there is found, not made', [$client['created'], $client['mac']], [false, Mac::internal(5)]);
is_eq('and given the password just logged in with when its own is stale',
	password_verify('bob:new', (string) ($updated[0][1][':token'] ?? '')), true);

$s = build();
$db = $s['app']->Database;
$db->fetches = [Clients::INTERNAL_EXPR => [['id' => '5', 'mac' => Mac::internal(5), 'token' => password_hash('bob:new', PASSWORD_DEFAULT)]]];
$s['clients']->findOrCreateForDevice('1001', 'bob:new');

is_eq('a token that still verifies is left alone',
	(bool) array_filter($db->seen, function ($q) { return strpos($q, 'SET token = :token') !== false; }), false);

echo "\ntranscoding by Accept:\n";

echo "\n  what a header asks for:\n";

foreach ([
	'(no header)' => ['', null],
	'*/*' => ['*/*', null],
	'text/*' => ['text/*', null],
	'json with a low-q wildcard' => ['application/json, */*;q=0.1', null],
	'text/html alone' => ['text/html', null],
	'json at q=0' => ['application/json;q=0', null],
	'application/json' => ['application/json', ['format' => Transcoder::JSON, 'type' => 'application/json']],
	'text/xml, as written' => ['Text/XML', ['format' => Transcoder::XML, 'type' => 'text/xml']],
	'application/xml' => ['application/xml', ['format' => Transcoder::XML, 'type' => 'application/xml']],
	'highest q wins' => ['application/json;q=0.5, text/plain', ['format' => Transcoder::PLAIN, 'type' => 'text/plain']],
	'a tie goes to the first' => ['text/plain, application/json', ['format' => Transcoder::PLAIN, 'type' => 'text/plain']],
] as $label => $case) {
	is_eq($label, Transcoder::target($case[0]), $case[1]);
}

$polycom = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
	. '<polycomConfig><reg reg.1.address="1001" reg.1.label="Front &amp; Back"/></polycomConfig>';
$grandstream = "\xEF\xBB\xBF<gs_provision version=\"1\"><config><P47>pbx.example</P47><P35>1001</P35></config></gs_provision>";
$yealink = "#!version:1.0.0.1\n# account\naccount.1.enable = 1\naccount.1.label = \"Front Desk\"\n\n[extra]\nlang=en";
$json = '{"account": {"1": {"enable": true, "label": "Front"}}, "lines": ["a", "b"]}';

echo "\n  what a config is written in:\n";

is_eq('Polycom XML', Transcoder::detect($polycom), Transcoder::XML);
is_eq('Grandstream XML behind a BOM', Transcoder::detect($grandstream), Transcoder::XML);
is_eq('Yealink key=value with #!version', Transcoder::detect($yealink), Transcoder::PLAIN);
is_eq('JSON', Transcoder::detect($json), Transcoder::JSON);
is_eq('broken XML is nothing', Transcoder::detect('<a><b></a>'), null);
is_eq('prose is nothing', Transcoder::detect("hello there\nthis is not a config"), null);
is_eq('empty is nothing', Transcoder::detect("  \n"), null);

echo "\n  rewritten:\n";

is_eq('XML to JSON keeps attributes as @',
	json_decode(Transcoder::transcode($polycom, Transcoder::XML, Transcoder::JSON), true),
	['polycomConfig' => ['reg' => ['@reg.1.address' => '1001', '@reg.1.label' => 'Front & Back']]]);
is_eq('XML to plain',
	Transcoder::transcode($polycom, Transcoder::XML, Transcoder::PLAIN),
	"polycomConfig.reg.reg.1.address=1001\npolycomConfig.reg.reg.1.label=Front & Back");
is_eq('Grandstream to plain',
	Transcoder::transcode($grandstream, Transcoder::XML, Transcoder::PLAIN),
	"gs_provision.version=1\ngs_provision.config.P47=pbx.example\ngs_provision.config.P35=1001");
is_eq('plain to JSON keeps dotted keys whole, drops comments',
	json_decode(Transcoder::transcode($yealink, Transcoder::PLAIN, Transcoder::JSON), true),
	['account.1.enable' => '1', 'account.1.label' => 'Front Desk', 'extra.lang' => 'en']);
is_eq('plain to XML',
	Transcoder::transcode("account.1.enable=1\n1st=x", Transcoder::PLAIN, Transcoder::XML),
	"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<config>\n  <account.1.enable>1</account.1.enable>\n  <item key=\"1st\">x</item>\n</config>");
is_eq('JSON to plain flattens, lists by index',
	Transcoder::transcode($json, Transcoder::JSON, Transcoder::PLAIN),
	"account.1.enable=true\naccount.1.label=Front\nlines.0=a\nlines.1=b");
is_eq('JSON to XML repeats a list',
	Transcoder::transcode('{"phone": {"line": ["a", "b"], "@id": "7"}}', Transcoder::JSON, Transcoder::XML),
	"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<phone id=\"7\">\n  <line>a</line>\n  <line>b</line>\n</phone>");
is_eq('XML to JSON to XML comes back',
	Transcoder::transcode(Transcoder::transcode($polycom, Transcoder::XML, Transcoder::JSON), Transcoder::JSON, Transcoder::XML),
	"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<polycomConfig>\n  <reg reg.1.address=\"1001\" reg.1.label=\"Front &amp; Back\"/>\n</polycomConfig>");
is_eq('XML output escapes', strpos(Transcoder::transcode('{"a": "<&\">"}', Transcoder::JSON, Transcoder::XML), '<a>&lt;&amp;"&gt;</a>') !== false, true);
is_eq('a DOCTYPE is refused',
	Transcoder::transcode('<!DOCTYPE a [<!ENTITY x "y">]><a>&x;</a>', Transcoder::XML, Transcoder::JSON), null);

echo "\n  what the endpoint answers with:\n";

$transcoded = new \ReflectionMethod(Endpoint::class, 'transcoded');
$transcoded->setAccessible(true);
$asked = function ($result, $accept) use ($transcoded, $endpoint) {
	return $transcoded->invoke($endpoint, $result, Transcoder::target($accept));
};
$template = ['status' => true, 'kind' => 'template', 'type' => 'template', 'resource' => 'phone.cfg', 'config' => $yealink];

is_eq('no header leaves it alone', $asked($template, ''), $template);
is_eq('already that format leaves it alone', $asked($template, 'text/plain'), $template);
is_eq('another format is rewritten', array_intersect_key($asked($template, 'application/json'), ['kind' => 1, 'contentType' => 1]),
	['kind' => 'template', 'contentType' => 'application/json']);
is_eq('one that cannot be read is a 406',
	$asked(['config' => 'not a config'] + $template, 'application/json')['code'] ?? null, 406);

$uploaded = scratch_file();
file_put_contents($uploaded, $polycom);
$file = ['status' => true, 'kind' => 'file', 'type' => 'file', 'resource' => 'phone.xml', 'path' => $uploaded];

is_eq('an uploaded file that is already that format streams as stored', $asked($file, 'text/xml'), $file);
is_eq('an uploaded file is rewritten too', $asked($file, 'text/plain')['config'] ?? null,
	"polycomConfig.reg.reg.1.address=1001\npolycomConfig.reg.reg.1.label=Front & Back\n");

file_put_contents($uploaded, "\x7fELF\0\0binary");
is_eq('a binary file is a 406', $asked($file, 'application/json')['code'] ?? null, 406);
is_eq('a log upload is never touched', $asked(['status' => true, 'kind' => 'log', 'path' => $uploaded], 'application/json')['kind'], 'log');

foreach ($TEMPORARY as $path) {
	@unlink($path);
}

printf("\n%d passed, %d failed\n", $passed, $failed);

exit($failed ? 1 : 0);
