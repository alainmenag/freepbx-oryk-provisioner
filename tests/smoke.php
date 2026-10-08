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
use FreePBX\Modules\Oryk_Provisioner\BanEscalation;
use FreePBX\Modules\Oryk_Provisioner\BanSync;
use FreePBX\Modules\Oryk_Provisioner\Bans;
use FreePBX\Modules\Oryk_Provisioner\CdrHistory;
use FreePBX\Modules\Oryk_Provisioner\Clients;
use FreePBX\Modules\Oryk_Provisioner\DeviceStatus;
use FreePBX\Modules\Oryk_Provisioner\Endpoint;
use FreePBX\Modules\Oryk_Provisioner\EndpointSettings;
use FreePBX\Modules\Oryk_Provisioner\ExtensionManager;
use FreePBX\Modules\Oryk_Provisioner\ExtensionRenumberer;
use FreePBX\Modules\Oryk_Provisioner\Fail2ban;
use FreePBX\Modules\Oryk_Provisioner\FileRepo;
use FreePBX\Modules\Oryk_Provisioner\Freepbx as PbxDevices;
use FreePBX\Modules\Oryk_Provisioner\LogRepo;
use FreePBX\Modules\Oryk_Provisioner\Mac;
use FreePBX\Modules\Oryk_Provisioner\Navigator;
use FreePBX\Modules\Oryk_Provisioner\NumberAllocator;
use FreePBX\Modules\Oryk_Provisioner\Overview;
use FreePBX\Modules\Oryk_Provisioner\LobbyContext;
use FreePBX\Modules\Oryk_Provisioner\Notices;
use FreePBX\Modules\Oryk_Provisioner\Profiles;
use FreePBX\Modules\Oryk_Provisioner\RealtimeBridge;
use FreePBX\Modules\Oryk_Provisioner\SecurityLog;
use FreePBX\Modules\Oryk_Provisioner\Jobs;
use FreePBX\Modules\Oryk_Provisioner\ServiceEngine;
use FreePBX\Modules\Oryk_Provisioner\Services;
use FreePBX\Modules\Oryk_Provisioner\SignupRefused;
use FreePBX\Modules\Oryk_Provisioner\SignupSweep;
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
	FreePBX::$userman = new StubUserman();
	\FreePBX\Modules\Oryk_Provisioner\SecurityLog::$written = [];

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

is_eq('an extension, with its own pjsip device beside it when it has one', Users::FROM, "FROM users u LEFT JOIN devices d ON d.id = u.extension AND d.tech = 'pjsip' AND d.user = u.extension");
is_eq('unless its number is held by a device of another kind', Users::SHAPE, "NOT EXISTS (SELECT 1 FROM devices o WHERE o.id = u.extension AND NOT (o.tech = 'pjsip' AND o.user = u.extension))");
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

echo "\n  an extension whose device has gone is a user still:\n";

$s = build();
$s['app']->Database->answers = ['SELECT extension FROM users WHERE extension = ?' => '1001'];
$uid = $s['users']->store(['id' => '1001', 'extension' => '', 'name' => 'Back']);
is_eq('a save gives it a device back, on its own number', [$uid, FreePBX::$core->added['id'] ?? null], ['1001', '1001']);
is_eq('with no old device to delete first', FreePBX::$core->deleted, []);
$threw = false;
try {
	$s['users']->store(['id' => '1001', 'extension' => '1002', 'name' => 'Back']);
} catch (\Exception $e) {
	$threw = true;
}
is_eq('but not on another number in the same save', $threw, true);

$s = build();
$s['app']->Modules->active = ['cdr'];
$s['app']->Database->answers = ['SELECT extension FROM users WHERE extension = ?' => '1001'];
FreePBX::$core->users['1001'] = ['extension' => '1001'];
is_eq('deleting it deletes what is left', $s['users']->remove('1001'), true);
is_eq('the extension', isset(FreePBX::$core->users['1001']), false);
is_eq('and no device, there being none', FreePBX::$core->deleted, []);
$s = build();
is_eq('what is neither device nor extension is not deleted', $s['users']->remove('1001'), false);

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


echo "\nbans:\n";

echo "\n  a value is stored in one spelling, or refused:\n";

is_eq('an IPv6 address is spelled canonically', Bans::value('ip', '2001:DB8::0001'), '2001:db8::1');
is_eq('a range is not an address', Bans::value('ip', '10.0.0.0/8'), null);
is_eq('nor is a zone id', Bans::value('ip', 'fe80::1%eth0'), null);
is_eq('a MAC loses its separators and case', Bans::value('mac', '00:04:F2:82:E8:24'), '0004f282e824');
is_eq('eleven digits are not a MAC', Bans::value('mac', '0004f282e82'), null);
is_eq('a user is a number', [Bans::value('user', '1001'), Bans::value('user', '10a1')], ['1001', null]);
is_eq('a client is an id', [Bans::value('client', '5'), Bans::value('client', '0'), Bans::value('client', '-5')], ['5', null, null]);
is_eq('and so is a profile', [Bans::value('profile', '3'), Bans::value('profile', 'Polycom')], ['3', null]);
is_eq('an unknown subject is nothing', Bans::value('jail', 'asterisk'), null);

is_eq('subjects keep only what is one of its kind, most specific first',
	Bans::subjects(['ip' => '203.0.113.7', 'mac' => '', 'user' => ['1001', '', '1001'], 'client' => '5', 'jail' => 'x']),
	['client' => ['5'], 'user' => ['1001'], 'ip' => ['203.0.113.7']]);

echo "\n  the most specific row decides:\n";

/** A ban row with the subjects given and the rest empty. */
/** The last write to the bans table: [sql, params] -- reads made after it, like banRow(), are skipped. */
function ban_write($db)
{
	foreach (array_reverse($db->params) as $call) {
		if (preg_match('/^\s*(INSERT INTO|UPDATE) `oryk_provisioner_bans`/', $call[0])) {
			return $call;
		}
	}

	return [null, null];
}

function ban_row($id, $state, array $set)
{
	return ['id' => $id, 'state' => $state] + $set + ['client_id' => null, 'extension' => null, 'mac' => null, 'profile_id' => null, 'ip' => null,
		'user_device' => null, 'user_name' => null, 'client_mac' => null, 'client_description' => null, 'mac_client_id' => null, 'profile_name' => null];
}

$site = ban_row(1, 'banned', ['ip' => '203.0.113.7']);
$officeAllowed = ban_row(2, 'allow', ['ip' => '203.0.113.7']);
$macDenied = ban_row(3, 'deny', ['mac' => '0004f282e824']);
$userDenied = ban_row(4, 'deny', ['extension' => '1001']);
$userFromSite = ban_row(5, 'allow', ['extension' => '1001', 'ip' => '203.0.113.7']);
$clientAllowed = ban_row(6, 'allow', ['client_id' => '5']);

is_eq('nothing matched, nothing decides', Bans::decide([]), null);
is_eq('a denied MAC beats an allowed address', Bans::decide([$officeAllowed, $macDenied])['id'], 3);
is_eq('a user beats a MAC', Bans::decide([$macDenied, $userDenied, $site])['id'], 4);
is_eq('a user from one address beats the user alone', Bans::decide([$userDenied, $userFromSite])['id'], 5);
$profileDenied = ban_row(8, 'deny', ['profile_id' => '3']);
is_eq('a profile beats an address', Bans::decide([$officeAllowed, $profileDenied])['id'], 8);
is_eq('a MAC beats a profile', Bans::decide([ban_row(10, 'allow', ['mac' => '0004f282e824']), $profileDenied])['id'], 10);
is_eq('a client beats everything', Bans::decide([$site, $macDenied, $userFromSite, $clientAllowed])['id'], 6);
is_eq('a tie goes to the refusal', Bans::decide([$officeAllowed, $site])['id'], 1);
is_eq('a row with no subject never decides', Bans::decide([ban_row(9, 'deny', [])]), null);
is_eq('the refusal names every subject', Bans::refusal(ban_row(7, 'deny', ['extension' => '1001', 'ip' => '203.0.113.7'])),
	'Denied by ban #7 (user 1001, address 203.0.113.7).');
is_eq('and a temporary one says banned', Bans::refusal($site), 'Banned by ban #1 (address 203.0.113.7).');

echo "\n  check() asks for the rows that match every subject they name:\n";

$s = build();
$db = $s['app']->Database;
$bans = new Bans($s['app']);

$db->fetchAlls = ['oryk_provisioner_bans' => [$site, $macDenied]];
is_eq('a deny decides', $bans->check(['ip' => '203.0.113.7', 'mac' => '0004f282e824'])['id'] ?? null, 3);

$db->fetchAlls = ['oryk_provisioner_bans' => [$userDenied, $userFromSite]];
is_eq('an allow lets it through', $bans->check(['ip' => '203.0.113.7', 'user' => '1001']), null);

$db->fetchAlls = ['oryk_provisioner_bans' => []];
$bans->check(['ip' => '203.0.113.7', 'user' => ['1001', '1001']]);
$sql = end($db->seen);
is_eq('a subject the request has matches it or anything', strpos($sql, "b.ip = '' OR b.ip IN (:ip_0)") !== false, true);
is_eq('a subject it does not have matches only rows leaving it empty',
	[strpos($sql, "AND b.mac = ''") !== false, strpos($sql, 'b.client_id = 0 AND') !== false], [true, true]);
is_eq('and an expired temporary ban is not in force', strpos($sql, Bans::ACTIVE_EXPR) !== false, true);

$db->seen = [];
$_REQUEST = ['limit' => 10];
$bans->listBans();
$bans->banRow('1');
$_REQUEST = [];
is_eq('reading bans deletes nothing, expired or not',
	array_values(array_filter($db->seen, function ($q) { return stripos($q, 'DELETE FROM') !== false; })), []);
is_eq('and lists no row marked deleted', strpos($db->seen[0], 'WHERE b.deleted_at IS NULL') !== false, true);

echo "\n  what saveBan() refuses before writing:\n";

$db->fetchAlls = [];
$saved = function ($request) use ($bans) {
	return $bans->saveBan($request + ['ip' => '203.0.113.7', 'state' => 'banned', 'minutes' => '60'])['status'];
};

is_eq('no subject at all', $saved(['ip' => '']), false);
is_eq('a subject that is not one of its kind', $saved(['ip' => '10.0.0.0/8']), false);
is_eq('a profile that is not there', $saved(['profile' => '3']), false);
is_eq('even beside a good one', $saved(['user' => '1001', 'mac' => 'nope']), false);
is_eq('an unknown state', $saved(['state' => 'unban']), false);
is_eq('a temporary ban with no length', $saved(['minutes' => '']), false);
is_eq('or one too long', $saved(['minutes' => (string) (Bans::MAX_MINUTES + 1)]), false);
is_eq('a client that is not there', $saved(['client' => '5']), false);

$db->answers = ['FROM `oryk_provisioner_bans`' => '7', 'FROM `oryk_provisioner_bans` WHERE `id`' => '1'];
// What banRow() reads back: any row will do for an edit to find.
$db->fetches = ['WHERE b.id = :id' => [ban_row(3, 'deny', ['ip' => '203.0.113.7'])]];
$db->insertId = 7;
$db->seen = [];
is_eq('a new ban naming what a row already does reopens that row',
	$bans->saveBan(['ip' => '203.0.113.7', 'state' => 'deny']), ['status' => true, 'id' => 7, 'reopened' => true, 'escalated' => false]);
is_eq('through the unique key, so two saves at once still make one row',
	strpos(ban_write($db)[0], 'ON DUPLICATE KEY UPDATE') !== false, true);
is_eq('an existing ban edited onto another row\'s subjects is refused',
	$bans->saveBan(['id' => '3', 'ip' => '203.0.113.7', 'state' => 'deny'])['message'] ?? null,
	'Ban #7 already names exactly that. Open it instead, or delete one of the two.');

$db->answers = ['FROM `oryk_provisioner_bans` WHERE `id`' => '1'];
$db->fetches = ['WHERE b.id = :id' => [ban_row(1, 'banned', ['ip' => '198.51.100.4']) + ['note' => 'scanner', 'source' => 'fail2ban', 'jail' => 'pbx-gui']]];
$db->params = [];
is_eq('the State column changes the state and nothing else',
	[$bans->setBanState(['id' => '1', 'state' => 'deny'])['status'],
		array_intersect_key(ban_write($db)[1], array_flip([':ip', ':state', ':note', ':source', ':jail']))],
	[true, [':ip' => '198.51.100.4', ':state' => 'deny', ':note' => 'scanner', ':source' => 'fail2ban', ':jail' => 'pbx-gui']]);
is_eq('a Banned picked there still needs its length', $bans->setBanState(['id' => '1', 'state' => 'banned'])['status'], false);
$db->fetches = [];
is_eq('and a ban gone since the table was drawn says so', $bans->setBanState(['id' => '1', 'state' => 'deny'])['message'] ?? null, 'That ban has been deleted.');

$db->answers = [];
$db->insertId = 9;
$db->params = [];
is_eq('a deny needs no length, and is written', $bans->saveBan(['mac' => '00-04-F2-82-E8-24', 'user' => '1001', 'state' => 'deny', 'minutes' => '']), ['status' => true, 'id' => 9, 'reopened' => false, 'escalated' => false]);
is_eq('with each subject as it is stored, the rest as "any"',
	array_intersect_key(ban_write($db)[1], array_flip([':client_id', ':extension', ':mac', ':profile_id', ':ip'])),
	[':client_id' => 0, ':extension' => '1001', ':mac' => '0004f282e824', ':profile_id' => 0, ':ip' => '']);
is_eq('written from the tab, it is a manual ban with no jail',
	array_intersect_key(ban_write($db)[1], array_flip([':source', ':jail'])), [':source' => 'manual', ':jail' => null]);
is_eq('and a reopen leaves what created it alone',
	strpos(ban_write($db)[0], 'source = ') === false && strpos(ban_write($db)[0], 'jail = ') === false, true);
is_eq('a source and jail given are written, the source lowercased',
	[$bans->saveBan(['ip' => '198.51.100.4', 'state' => 'banned', 'minutes' => '60', 'source' => 'Fail2ban', 'jail' => 'asterisk'])['status'],
		array_intersect_key(ban_write($db)[1], array_flip([':source', ':jail']))],
	[true, [':source' => 'fail2ban', ':jail' => 'asterisk']]);
is_eq('a source or jail that is not a name is refused',
	[$bans->saveBan(['ip' => '198.51.100.4', 'state' => 'deny', 'source' => 'fail 2 ban'])['status'],
		$bans->saveBan(['ip' => '198.51.100.4', 'state' => 'deny', 'jail' => 'a/b'])['status']], [false, false]);
$db->params = [];
$db->seen = [];
$db->answers = ['FROM `oryk_provisioner_bans` WHERE `id`' => '1'];
$db->fetches = ['WHERE b.id = :id' => [ban_row(1, 'deny', ['ip' => '198.51.100.4'])]];
$bans->saveBan(['id' => '1', 'ip' => '198.51.100.4', 'state' => 'deny', 'source' => 'ratelimit', 'jail' => 'open-prov']);
is_eq('an edit writes them', [strpos(ban_write($db)[0], 'source = :source, jail = :jail') !== false,
	array_intersect_key(ban_write($db)[1], array_flip([':source', ':jail']))], [true, [':source' => 'ratelimit', ':jail' => 'open-prov']]);
$db->answers = [];
is_eq('and a row storing "any" as 0 or \'\' sets nothing there',
	Bans::summary(['client_id' => '0', 'extension' => '1001', 'mac' => '', 'profile_id' => '0', 'ip' => '']), 'user 1001');

echo "\n  the endpoint asks before it answers:\n";

$settings = new Settings($s['app']);
$template = new \FreePBX\Modules\Oryk_Provisioner\Template($s['app'], new PbxDevices($s['app']), $settings, new Services($s['app']));
$endpoint = new Endpoint(
	$s['app'], $s['clients'], new \FreePBX\Modules\Oryk_Provisioner\Matcher($s['app'], $template), $template,
	new FileRepo($s['app']), new LogRepo($s['app']), new \FreePBX\Modules\Oryk_Provisioner\ProvisioningLog($s['app']),
	new Profiles($s['app'], new FileRepo($s['app'])), $s['users'], $bans
);
$banned = new \ReflectionMethod(Endpoint::class, 'banned');
$banned->setAccessible(true);
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

$db->fetches = ['WHERE pc.mac = :mac' => [['id' => '5', 'device_id' => '1001', 'extension' => '1001']]];
$db->fetchAlls = ['oryk_provisioner_bans' => [$site]];
$db->params = [];
is_eq('a banned address is a 403', $banned->invoke($endpoint, '0004f282e824')['code'] ?? null, 403);
$asked = array_values(array_filter($db->params, function ($p) { return strpos($p[0], 'b.state') !== false; }));
$hits = array_values(array_filter($db->params, function ($p) { return strpos($p[0], 'hits = hits + 1') !== false; }));
is_eq('asked of the address, the MAC, the client and its user',
	end($asked)[1], [':client_0' => '5', ':user_0' => '1001', ':mac_0' => '0004f282e824', ':ip_0' => '203.0.113.7']);
is_eq('and the row that decided is counted a hit', end($hits)[1] ?? null, [':id' => 1]);

$db->params = [];
$banned->invoke($endpoint, '0004f282e824');
is_eq('once per request, however often it is asked',
	array_filter($db->params, function ($p) { return strpos($p[0], 'hits = hits + 1') !== false; }), []);

$db->fetches = ['WHERE pc.mac = :mac' => [['id' => '5', 'device_id' => '1001', 'extension' => '1001', 'profile_id' => '3']]];
$db->params = [];
$banned->invoke($endpoint, '0004f282e824');
is_eq('and the profile it is assigned', end($db->params)[1][':profile_0'] ?? null, '3');

$db->fetches = [
	'WHERE pc.mac = :mac' => [['id' => '5', 'device_id' => '1001', 'extension' => '1001', 'profile_id' => null]],
	'WHERE LOWER(name) = LOWER(:name)' => [['id' => '4', 'name' => 'Polycom', 'enabled' => '1']],
];
$_SERVER['HTTP_USER_AGENT'] = 'FileTransport PolycomVVX-VVX_411-UA/5.9.5.0614';
$db->params = [];
$banned->invoke($endpoint, '0004f282e824');
is_eq('or, with none, the vendor profile it is served', end($db->params)[1][':profile_0'] ?? null, '4');
unset($_SERVER['HTTP_USER_AGENT']);

$db->fetches = [];
$db->fetchAlls = ['oryk_provisioner_bans' => []];
is_eq('nothing matching, nothing refused', $banned->invoke($endpoint, '0004f282e824'), null);

$db->params = [];
$banned->invoke($endpoint, Mac::OPEN, 'bob1001');
is_eq('a username that is no number is not asked as a user', in_array('bob1001', end($db->params)[1], true), false);
$db->params = [];
$banned->invoke($endpoint, Mac::OPEN, '1001');
is_eq('which is a user when it is a number', in_array('1001', end($db->params)[1], true), true);

$db->fetches = [];
$db->fetchAlls = [];
unset($_SERVER['REMOTE_ADDR']);

echo "\nfail2ban sync:\n";

echo "\n  what the helper's answer to check means:\n";

is_eq('no helper installed is missing', Fail2ban::state('missing', null, 2)['state'], 'missing');
is_eq('no JSON at all is sudo refusing', Fail2ban::state(null, ['ok' => false, 'exit' => 1, 'error' => 'sudo: a password is required'], 2)['state'], 'sudo');
is_eq('another version installed is stale', Fail2ban::state(null, ['ok' => true, 'version' => 1, 'exit' => 0], 2)['state'], 'stale');
is_eq('a current helper with fail2ban down is fail2ban', Fail2ban::state(null, ['ok' => false, 'version' => 2, 'exit' => 69], 2)['state'], 'fail2ban');
is_eq('no deny jail is said, with which',
	array_intersect_key(Fail2ban::state(null, ['ok' => true, 'version' => 2, 'exit' => 0, 'jails' => ['asterisk', 'banned', 'pbx-gui']], 2), ['state' => 1, 'detail' => 1]),
	['state' => 'nojail', 'detail' => 'deny']);
is_eq('both of the module\'s jails there is ok, asterisk or not', Fail2ban::state(null, ['ok' => true, 'version' => 2, 'exit' => 0, 'jails' => ['banned', 'deny']], 2)['state'], 'ok');
is_eq('the managed jails are passed on', Fail2ban::state(null, ['ok' => true, 'version' => 2, 'exit' => 0, 'jails' => ['asterisk', 'banned', 'deny']], 2)['jails'], ['asterisk', 'banned', 'deny']);
is_eq('the shipped helper is version 5', Fail2ban::helperVersion(file_get_contents(__DIR__ . '/../bin/oryk-fail2ban')), 5);

echo "\n  what one run plans:\n";

/** fail2ban's answer to list, from bans as [jail, ip, banned_at, expires_at or null for permanent]. */
function listed(array $bans, array $ignore = [], array $jails = ['asterisk', 'banned', 'deny', 'pbx-gui'])
{
	return [
		'ok' => true,
		'jails' => $jails,
		'bans' => array_map(function ($b) {
			return ['jail' => $b[0], 'ip' => $b[1], 'banned_at' => $b[2], 'bantime' => $b[3] === null ? -1 : $b[3] - $b[2],
				'permanent' => $b[3] === null, 'expires_at' => $b[3]];
		}, $bans),
		'ignore' => $ignore,
	];
}

/** One IP-only row as BanSync reads it; a `fail2ban` one is managed by the sync. */
function sync_row($id, $state, $source = 'manual', $active = true, $synced = false, $expires = null, $started = null)
{
	return ['id' => $id, 'state' => $state, 'managed' => $source === 'fail2ban' ? 1 : 0, 'active' => $active ? 1 : 0,
		'synced' => $synced ? 1 : 0, 'expires_at' => $expires, 'started_at' => $started];
}

$p = BanSync::plan(listed([['asterisk', '203.0.113.7', 1000, 4600]]), []);
is_eq('a ban with no row is imported', [count($p['insert']), $p['insert'][0]['jail'] ?? null, $p['insert'][0]['permanent'] ?? null], [1, 'asterisk', false]);

$p = BanSync::plan(listed([['recidive', '203.0.113.7', 1000, null]], [], ['recidive', 'deny']), []);
is_eq('a permanent one comes in permanent', $p['insert'][0]['permanent'] ?? null, true);

$p = BanSync::plan(listed([['asterisk', '203.0.113.7', 1000, 4600], ['pbx-gui', '203.0.113.7', 900, 9000]]), []);
is_eq('an address in two jails is one row, the longer ban', [count($p['insert']), $p['insert'][0]['jail']], [1, 'pbx-gui']);

$p = BanSync::plan(listed([['asterisk', '203.0.113.7', 1000, 4600]]), ['203.0.113.7' => sync_row(4, 'banned', 'fail2ban', true, true, 4600, 1000)]);
is_eq('the same ban again refreshes its row, not counted', [$p['update'][0]['id'] ?? null, $p['update'][0]['times'] ?? null, $p['update'][0]['revive'] ?? null], [4, 0, false]);

$p = BanSync::plan(listed([['asterisk', '203.0.113.7', 5000, 8600]]), ['203.0.113.7' => sync_row(4, 'banned', 'fail2ban', true, true, 4600, 1000)]);
is_eq('banned again by fail2ban counts once more', $p['update'][0]['times'] ?? null, 1);

$p = BanSync::plan(listed([]), ['203.0.113.7' => sync_row(4, 'banned', 'fail2ban', true, true, 4600, 1000)]);
is_eq('a ban fail2ban let go expires its row', $p['expire'], [4]);

$p = BanSync::plan(listed([['asterisk', '203.0.113.7', 1000, 4600]]), ['203.0.113.7' => sync_row(5, 'deny', 'manual', true, true)]);
is_eq('a row in force made by hand is never imported over', [$p['insert'], $p['update']], [[], []]);

$p = BanSync::plan(listed([['asterisk', '203.0.113.7', 9000, 12600]]), ['203.0.113.7' => sync_row(5, 'banned', 'manual', false, false, 4600, 1000)]);
is_eq('an expired row is revived by a new fail2ban ban', [$p['update'][0]['id'] ?? null, $p['update'][0]['revive'] ?? null, $p['update'][0]['times'] ?? null], [5, true, 1]);

$p = BanSync::plan(listed([]), ['203.0.113.7' => ['managed' => 0] + sync_row(4, 'deny', 'fail2ban', true, false)]);
is_eq('a fail2ban ban a person made Deny is theirs: pushed to deny, not expired', [$p['ban'], $p['expire']], [[['deny', '203.0.113.7', 4]], []]);

$p = BanSync::plan(listed([]), ['203.0.113.7' => sync_row(6, 'banned')]);
is_eq('a Banned row made here is banned in the banned jail', $p['ban'], [['banned', '203.0.113.7', 6]]);

$p = BanSync::plan(listed([]), ['203.0.113.7' => sync_row(6, 'deny')]);
is_eq('a Deny row is banned in deny', $p['ban'], [['deny', '203.0.113.7', 6]]);

$p = BanSync::plan(listed([['deny', '203.0.113.7', 1000, null]]), ['203.0.113.7' => sync_row(6, 'deny', 'manual', true, true)]);
is_eq('one already there is only confirmed', [$p['ban'], $p['synced']], [[], [6]]);

$p = BanSync::plan(listed([['deny', '198.51.100.9', 1000, null]]), []);
is_eq('deny holds nothing the table does not', [$p['unban'], $p['insert']], [[['deny', '198.51.100.9', null]], []]);

$p = BanSync::plan(listed([['banned', '203.0.113.7', 1000, null]]), ['203.0.113.7' => sync_row(7, 'banned', 'manual', false, true, 2000, 1000)]);
is_eq('an expired row\'s copy is lifted from banned, and not imported', [$p['unban'], $p['unsynced'], $p['insert'], $p['update']], [[['banned', '203.0.113.7', 7]], [], [], []]);

$p = BanSync::plan(listed([['asterisk', '203.0.113.7', 3000, 6600]]), ['203.0.113.7' => sync_row(7, 'banned', 'manual', false, true, 2000, 1000)]);
is_eq('a ban fail2ban made in its own jail is its own', [$p['unban'], $p['unsynced'], $p['update'][0]['revive'] ?? null], [[], [7], true]);

$p = BanSync::plan(listed([['deny', '203.0.113.7', 1000, null]]), ['203.0.113.7' => sync_row(7, 'banned', 'manual', true, true, 9000, 1000)]);
is_eq('Deny turned Banned moves from deny to banned', [$p['ban'], $p['unban']], [[['banned', '203.0.113.7', 7]], [['deny', '203.0.113.7', null]]]);

$p = BanSync::plan(listed([['banned', '203.0.113.7', 1000, null]]), ['203.0.113.7' => sync_row(7, 'banned', 'manual', true, true, 9000, 1000)]);
is_eq('a Banned row in force already in banned is only confirmed', [$p['ban'], $p['unban'], $p['synced']], [[], [], [7]]);

$p = BanSync::plan(listed([['banned', '198.51.100.9', 1000, null]]), []);
is_eq('banned holds nothing the table does not either', $p['unban'], [['banned', '198.51.100.9', null]]);

echo "\n  a row marked deleted:\n";

$p = BanSync::plan(listed([['deny', '203.0.113.7', 1000, null], ['pbx-gui', '203.0.113.7', 1000, 4600]]), ['203.0.113.7' => ['deleted' => 1] + sync_row(10, 'deny', 'manual', false, true)]);
is_eq('its copy is lifted, it is purged, and nothing is imported onto it meanwhile',
	[$p['unban'], $p['purge'], $p['insert'], $p['update']], [[['deny', '203.0.113.7', 10]], [10], [], []]);

$p = BanSync::plan(listed([]), ['203.0.113.7' => ['deleted' => 1] + sync_row(10, 'allow', 'manual', false, true)]);
is_eq('an Allow comes off the ignore lists first', [$p['unignore'], $p['purge']], [[['203.0.113.7', 10]], [10]]);

$p = BanSync::plan(listed([['pbx-gui', '203.0.113.7', 1000, 4600]]), ['203.0.113.7' => ['deleted' => 1, 'jail' => 'pbx-gui'] + sync_row(10, 'banned', 'fail2ban', false, true)]);
is_eq('one the sync followed is lifted from fail2ban\'s jail', [$p['unban'], $p['purge']], [[['pbx-gui', '203.0.113.7', 10]], [10]]);

$p = BanSync::plan(listed([]), ['203.0.113.7' => ['deleted' => 1] + sync_row(10, 'deny', 'manual', false, false)]);
is_eq('one with no copy left is just purged', [$p['unban'], $p['unignore'], $p['purge']], [[], [], [10]]);

$p = BanSync::plan(listed([['pbx-gui', '203.0.113.7', 1000, 4600]], ['asterisk' => [], 'deny' => [], 'pbx-gui' => []]), ['203.0.113.7' => sync_row(8, 'allow')]);
is_eq('an allowed address is unbanned and put on the ignore list', [$p['unban'], $p['ignore'], $p['insert']], [[['pbx-gui', '203.0.113.7', null]], [['203.0.113.7', 8]], []]);

$everywhere = ['asterisk' => ['127.0.0.1/8', '203.0.113.7'], 'banned' => ['203.0.113.7'], 'deny' => ['203.0.113.7'], 'pbx-gui' => ['203.0.113.7']];
$p = BanSync::plan(listed([], $everywhere), ['203.0.113.7' => sync_row(8, 'allow')]);
is_eq('already on every list by someone else: left unmarked', [$p['ignore'], $p['synced']], [[], []]);

$p = BanSync::plan(listed([], $everywhere), ['203.0.113.7' => sync_row(8, 'allow', 'manual', true, true)]);
is_eq('ours and still there: confirmed', $p['synced'], [8]);

$p = BanSync::plan(listed([]), ['192.0.2.10' => sync_row(9, 'deny')], ['192.0.2.10']);
is_eq('the PBX\'s own address is never pushed', $p['ban'], []);

$p = BanSync::plan(listed([['asterisk', '203.0.113.7', 1000, 4600], ['asterisk', '198.51.100.9', 1000, 4600]]), [], [], '198.51.100.9');
is_eq('a run for one address looks at that one only', array_column($p['insert'], 'ip'), ['198.51.100.9']);

echo "\n  what a save sends to fail2ban at once:\n";

/** A helper that answers yes and remembers what it was asked. */
class StubFail2ban extends Fail2ban
{
	public $asked = [];
	public $listed = ['ok' => true, 'jails' => ['asterisk', 'banned', 'deny'], 'bans' => [], 'ignore' => []];
	public $on = true;

	public function enabled() { return $this->on; }
	public function listAll() { $this->asked[] = ['list']; return $this->listed; }
	public function ban($jail, $ip) { $this->asked[] = ['ban', $jail, $ip]; return ['ok' => true]; }
	public function unban($jail, $ip) { $this->asked[] = ['unban', $jail, $ip]; return ['ok' => true]; }
	public function ignore($ip) { $this->asked[] = ['ignore', $ip]; return ['ok' => true]; }
	public function unignore($ip) { $this->asked[] = ['unignore', $ip]; return ['ok' => true]; }
}

$s = build();
$f2b = new StubFail2ban($s['app'], new Settings($s['app']));
$sync = new BanSync($s['app'], $f2b);
$ban = ['id' => 3, 'client_id' => null, 'extension' => null, 'mac' => null, 'profile_id' => null, 'ip' => '203.0.113.7', 'state' => 'deny', 'synced_at' => '2026-10-03 12:00:00'];

is_eq('IP-only is an address and nothing else', [BanSync::ipOnly($ban), BanSync::ipOnly(['extension' => '1001'] + $ban)], [true, false]);

$sync->lift($ban);
is_eq('deleting a Deny lifts it from deny', $f2b->asked, [['unban', 'deny', '203.0.113.7']]);

$f2b->asked = [];
is_eq('a ban the sync follows is lifted from fail2ban\'s own jail',
	[$sync->lift(['state' => 'banned', 'managed' => 1, 'jail' => 'asterisk'] + $ban), $f2b->asked],
	[true, [['list'], ['unban', 'asterisk', '203.0.113.7']]]);

$f2b->asked = [];
is_eq('one from a jail the helper does not manage is let go, nothing asked of fail2ban',
	[$sync->lift(['state' => 'banned', 'managed' => 1, 'jail' => 'sshd'] + $ban), $f2b->asked],
	[true, [['list']]]);

$f2b->asked = [];
$sync->lift(['state' => 'allow'] + $ban);
is_eq('an Allow comes off the ignore lists', $f2b->asked, [['unignore', '203.0.113.7']]);

$f2b->asked = [];
$sync->lift(['synced_at' => null] + $ban);
is_eq('a row fail2ban never had from us is left alone', $f2b->asked, []);

$f2b->asked = [];
$sync->afterSave(['state' => 'banned'] + $ban, $ban);
is_eq('Banned to Deny: out of banned, then the address is synced', $f2b->asked, [['unban', 'banned', '203.0.113.7'], ['list']]);

$f2b->asked = [];
$f2b->on = false;
$sync->afterSave(['state' => 'banned'] + $ban, $ban);
is_eq('paused, a save asks fail2ban nothing', $f2b->asked, []);
is_eq('and a copy cannot be lifted, so a delete keeps the row', [$sync->lift($ban), $sync->lift(['synced_at' => null] + $ban)], [false, true]);

$db = $s['app']->Database;
$db->fetches = ['WHERE b.id = :id' => [ban_row(3, 'deny', ['ip' => '203.0.113.7']) + ['synced_at' => '2026-10-03 12:00:00']]];
$db->seen = [];
(new Bans($s['app'], $sync))->deleteBan('3');
is_eq('deleted while paused, a ban with a copy is marked deleted, not removed',
	[strpos(end($db->seen), 'SET deleted_at = NOW()') !== false, strpos(implode(' ', $db->seen), 'DELETE FROM') === false], [true, true]);

$f2b->on = true;
$f2b->asked = [];
$db->seen = [];
(new Bans($s['app'], $sync))->deleteBan('3');
is_eq('with the sync on, it is lifted and removed', [$f2b->asked, strpos(end($db->seen), 'DELETE FROM') !== false], [[['unban', 'deny', '203.0.113.7']], true]);
$db->fetches = [];
$f2b->on = false;
is_eq('and a run does nothing', $sync->run(), ['ok' => true, 'paused' => true]);

$f2b->on = true;
$f2b->listed = ['ok' => false, 'error' => 'fail2ban is not running'];
is_eq('fail2ban unreadable, a run does nothing', $sync->run(), ['ok' => false, 'error' => 'fail2ban is not running']);

echo "\n  loopback is refused, and a save starts a period:\n";

$bans = new Bans($s['app']);
is_eq('a loopback ban is refused', $bans->saveBan(['ip' => '127.0.0.2', 'state' => 'deny'])['status'], false);
is_eq('and so is the unspecified address', $bans->saveBan(['ip' => '::', 'state' => 'banned', 'minutes' => '5'])['status'], false);
is_eq('allowing it is not', $bans->saveBan(['ip' => '127.0.0.1', 'state' => 'allow'])['status'], true);
$db = $s['app']->Database;
$db->seen = [];
$bans->saveBan(['ip' => '203.0.113.7', 'state' => 'deny']);
is_eq('a reopen counts a return to force and starts a new period',
	strpos(ban_write($db)[0], 'times = times + IF(') !== false && strpos(ban_write($db)[0], 'started_at = IF(') !== false, true);
is_eq('and a ban saved on the tab is no longer the sync\'s to manage', strpos(ban_write($db)[0], 'managed = 0') !== false, true);

echo "\n  a repeat Banned ban made Deny (ORYK_BAN_DENY_AFTER):\n";

$s = build();
$settings = new Settings($s['app']);
$escalation = new BanEscalation($s['app'], $settings);
$db = $s['app']->Database;

is_eq('blank is off', [$settings->set(Settings::BAN_DENY_AFTER, ''), $escalation->threshold()], [null, 0]);
is_eq('1 is refused: every ban is in force once', $settings->set(Settings::BAN_DENY_AFTER, '1') !== null, true);
is_eq('3 is taken', [$settings->set(Settings::BAN_DENY_AFTER, '3'), $escalation->threshold()], [null, 3]);

$db->seen = [];
$db->fetchAlls = ['b.times >= :after' => [5 => '203.0.113.7']];
is_eq('a Banned row in force 3 times is made Deny, and said', $escalation->apply([5, 6]), [5 => '203.0.113.7']);
is_eq('asked of those rows only, Banned and in force', [strpos($db->seen[0], 'IN (5, 6)') !== false, strpos($db->seen[0], "b.state = 'banned'") !== false,
	strpos($db->seen[0], Bans::ACTIVE_EXPR) !== false, end($db->params)[1][':after'] ?? null], [true, true, true, 3]);
$db->fetchAlls = [];
$db->seen = [];
is_eq('none there, nothing written', [$escalation->apply([7]), count($db->seen)], [[], 1]);
$settings->set(Settings::BAN_DENY_AFTER, '');
$db->seen = [];
is_eq('off, nothing is asked', [$escalation->apply([5]), $db->seen], [[], []]);

echo "\nopen provisioning:\n";

echo "\n  what openClient() answers before a user is found:\n";

$s = build();
$settings = new Settings($s['app']);
$template = new \FreePBX\Modules\Oryk_Provisioner\Template($s['app'], new PbxDevices($s['app']), $settings, new Services($s['app']));
$endpoint = new Endpoint(
	$s['app'], $s['clients'], new \FreePBX\Modules\Oryk_Provisioner\Matcher($s['app'], $template), $template,
	new FileRepo($s['app']), new LogRepo($s['app']), new \FreePBX\Modules\Oryk_Provisioner\ProvisioningLog($s['app']),
	new Profiles($s['app'], new FileRepo($s['app'])), $s['users'], new Bans($s['app'])
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

echo "\n  what findLogin() refuses before asking User Manager anything:\n";

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

is_eq('a blank username', thrown(function () use ($s) { $s['users']->findLogin('', 'secret'); }), 'InvalidArgumentException');
is_eq('a username with a space around it', thrown(function () use ($s) { $s['users']->findLogin(' bob', 'secret'); }), 'InvalidArgumentException');
is_eq('a blank password', thrown(function () use ($s) { $s['users']->findLogin('bob', ''); }), 'InvalidArgumentException');
is_eq('anything at all without User Manager', thrown(function () use ($s) { $s['users']->findLogin('bob', 'secret'); }), 'RuntimeException');
is_eq('and nothing was created', FreePBX::$core->added, null);

echo "\n  what a new open-provisioning username may be:\n";

is_eq('a UUID', Users::signupUsername('26f557af-a431-4b1c-939c-aa9f83e12f9e'), true);
is_eq('a name with . _ @ -', Users::signupUsername('j.smith_2@site-a.com'), true);
is_eq('only digits is refused', Users::signupUsername('2001'), false);
is_eq('an IPv4 address is refused', Users::signupUsername('203.0.113.9'), false);
is_eq('a dotted name that is not one is not', Users::signupUsername('203.0.113'), true);
is_eq('an email is allowed', Users::signupUsername('bob@site.com'), true);
is_eq('a space is refused', Users::signupUsername('x from 203.0.113.9'), false);
is_eq('a quote or bracket is refused', Users::signupUsername('a"<b>'), false);
is_eq('a plus is refused', Users::signupUsername('bob+1@site.com'), false);
is_eq('a trailing newline is refused', Users::signupUsername("bob\n"), false);
is_eq('65 characters is refused', Users::signupUsername(str_repeat('a', 65)), false);

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

echo "\n  a token on save:\n";

$s = build();
$db = $s['app']->Database;
$db->insertId = 8;
$created = $s['clients']->saveClient(['mac' => '', 'token' => '']);
$written = array_values(array_filter($db->params, function ($p) {
	return strpos(ltrim($p[0]), 'UPDATE `oryk_provisioner_clients`') === 0;
}));

is_eq('a new client left empty is given one, returned in the clear',
	[(bool) preg_match('/^[0-9a-f]{8}:[0-9a-f]{32}$/', (string) ($created['token'] ?? '')),
		password_verify((string) ($created['token'] ?? ''), (string) ($written[0][1][':token'] ?? ''))],
	[true, true]);

$s = build();
$db = $s['app']->Database;
$db->insertId = 8;
$created = $s['clients']->saveClient(['mac' => '', 'token' => 'bob:secret']);

is_eq('one typed on a new client is used, and not echoed', isset($created['token']), false);

$s = build();
$db = $s['app']->Database;
$db->fetches = [];
$updated = $s['clients']->saveClient(['id' => '8', 'mac' => '', 'token' => '']);
$written = array_values(array_filter($db->params, function ($p) {
	return strpos(ltrim($p[0]), 'UPDATE `oryk_provisioner_clients`') === 0;
}));

is_eq('an update may leave it empty',
	[isset($updated['token']), array_key_exists(':token', $written[0][1] ?? []) ? $written[0][1][':token'] : 'unset'],
	[false, null]);

$s = build();
$db = $s['app']->Database;
$db->insertId = 9;
$created = $s['clients']->saveClient(['mac' => '00:04:F2:82:E8:24', 'token' => '']);
$inserted = array_values(array_filter($db->params, function ($p) {
	return strpos(ltrim($p[0]), 'INSERT INTO `oryk_provisioner_clients` (mac') === 0;
}));

is_eq('a new client with a MAC typed in is inserted with it',
	[$created['status'] ?? null, $created['id'] ?? null, $inserted[0][1][':mac'] ?? null, isset($created['token'])],
	[true, 9, '0004f282e824', true]);

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

echo "\n  what a phone's log upload keeps:\n";

$logs = new LogRepo($s['app']);
$logDir = sys_get_temp_dir() . '/oryk-log-' . getmypid();
$logPath = $logDir . '/phone-boot.log';
$body = scratch_file();

file_put_contents($body, "one\ntwo\n");
$stored = $logs->storeLog($logPath, $body, null);
is_eq('a small log is kept whole', file_get_contents($logPath), "one\ntwo\n");

$lines = '';
for ($i = 0; $i < 150000; $i++) {
	$lines .= sprintf("line %07d\n", $i);
}
file_put_contents($body, $lines);
$stored = $logs->storeLog($logPath, $body, strlen($lines));
$kept = file_get_contents($logPath);
is_eq('a large one keeps no more than MAX_KEPT', strlen($kept) <= LogRepo::MAX_KEPT, true);
is_eq('starting on a whole line', substr($kept, 0, 5), 'line ');
is_eq('and ending with the newest', substr($kept, -13), "line 0149999\n");
is_eq('the count says what was sent and kept', [$stored['bytes'], $stored['kept']], [strlen($lines), strlen($kept)]);

$refused = $logs->storeLog($logPath, $body, LogRepo::MAX_BODY + 1);
is_eq('a body declared over MAX_BODY is a 413', $refused['code'] ?? null, 413);
is_eq('and nothing is written', file_get_contents($logPath), $kept);

@unlink($logPath);
@rmdir($logDir);

echo "\nopen sign-ups:\n";

echo "\n  reserved usernames:\n";

$own = ['pbx.example.com', 'example.com', '203.0.113.5'];

foreach (['admin', 'Admin', 'ADMINISTRATOR', 'support', 'reception', 'front.desk', 'lobby', 'guest'] as $name) {
	is_eq("$name is reserved", Users::reservedUsername($name, $own), true);
}

is_eq('the part before @ counts at any domain', Users::reservedUsername('support@gmail.com', $own), true);
is_eq('admin2 is reserved', Users::reservedUsername('admin2', $own), true);
is_eq('admin.ny is reserved', Users::reservedUsername('admin.ny', $own), true);
is_eq('root_1 is reserved', Users::reservedUsername('root_1', $own), true);
is_eq('rootsmith is not', Users::reservedUsername('rootsmith', $own), false);
is_eq('administrators is not', Users::reservedUsername('administrators', $own), false);
is_eq('anything at the own domain is', Users::reservedUsername('jane@example.com', $own), true);
is_eq('and at a subdomain of it', Users::reservedUsername('jane@ny.example.com', $own), true);
is_eq('a domain that merely ends the same is not', Users::reservedUsername('jane@notexample.com', $own), false);
is_eq('an own "domain" that is an address is skipped', Users::reservedUsername('jane@203.0.113.5', $own), false);
is_eq('an ordinary address passes', Users::reservedUsername('alice@gmail.com', $own), false);
is_eq('an ordinary name passes', Users::reservedUsername('j.smith_2', $own), false);

echo "\n  what a sign-up's address is counted under:\n";

is_eq('an IPv4 address as it is', Clients::signupKey('203.0.113.9'), '203.0.113.9');
is_eq('an IPv6 address as its /64', Clients::signupKey('2001:db8:1:2:aaaa:bbbb:cccc:dddd'), '2001:db8:1:2::/64');
is_eq('so two in one /64 count together',
	Clients::signupKey('2001:db8:1:2::1') === Clients::signupKey('2001:db8:1:2:ffff::9'), true);
is_eq('and one in the next /64 does not',
	Clients::signupKey('2001:db8:1:2::1') === Clients::signupKey('2001:db8:1:3::1'), false);

echo "\n  when a lobby user is expired:\n";

is_eq('off, never', Users::expired(null, 99999999, 0), false);
is_eq('seen recently, not', Users::expired(3600, 99999999, 7), false);
is_eq('seen long ago, yes', Users::expired(8 * 86400, 1, 7), true);
is_eq('never seen, signed up recently, not', Users::expired(null, 3600, 7), false);
is_eq('never seen, signed up long ago, yes', Users::expired(null, 8 * 86400, 7), true);
is_eq('nothing known, not', Users::expired(null, null, 7), false);

echo "\n  the settings it adds:\n";

$s = build();
$settings = new Settings($s['app']);
is_eq('sign-ups go to lobby by default', $settings->get(Settings::OPEN_CONTEXT), 'lobby');
is_eq('one a minute', $settings->get(Settings::OPEN_PER_MINUTE), '1');
is_eq('five a day', $settings->get(Settings::OPEN_PER_DAY), '5');
is_eq('no PBX cap', $settings->get(Settings::OPEN_PER_DAY_TOTAL), '0');
is_eq('a context that is not a name is refused',
	$settings->saveSettings(['settings' => [Settings::OPEN_CONTEXT => 'lobby; DROP']])['status'], false);
is_eq('an emergency caller id that is not a number is refused',
	$settings->saveSettings(['settings' => [Settings::OPEN_EMERGENCY_CID => '555-1212']])['status'], false);
$saved = $settings->saveSettings(['settings' => [Settings::OPEN_EMERGENCY_CID => '+15551234567']]);
is_eq('a leading + is fine', $saved['status'], true);
$saved = $settings->saveSettings(['settings' => [Settings::OPEN_CONTEXT => 'from-internal']]);
is_eq('a from- context saves', $saved['status'], true);
is_eq('with a warning', count($saved['warnings']), 1);

echo "\n  bans: an allow is read as one:\n";

$allow = ['id' => 9, 'client_id' => '0', 'extension' => '', 'mac' => '', 'profile_id' => '0', 'ip' => '203.0.113.7', 'state' => 'allow'];
$s = build();
$bans = new Bans($s['app']);
$s['app']->Database->fetchAlls = ['oryk_provisioner_bans' => [$allow]];
is_eq('decision() hands the allow back', $bans->decision(['ip' => '203.0.113.7'])['state'] ?? null, 'allow');
is_eq('check() reads it as nothing refusing', $bans->check(['ip' => '203.0.113.7']), null);

echo "\n  a sign-up, made:\n";

/** A build with User Manager on, and the next number 9990000013. */
function signup_build()
{
	$s = build();
	$s['app']->Modules->active = ['userman'];
	$s['app']->Database->answers = ['MAX(CAST(id' => '9990000012'];

	return $s;
}

$s = signup_build();
FreePBX::$config[Settings::OPEN_EMERGENCY_CID] = '+15551234567';
FreePBX::$conf = new class extends StubConfig {
	public function conf_setting_exists($keyword)
	{
		return $keyword === Settings::OPEN_EMERGENCY_CID || parent::conf_setting_exists($keyword);
	}
};
$admitted = 0;
$made = $s['users']->signUp('bob', 'secret', function () use (&$admitted) {
	$admitted++;
});
$settings = FreePBX::$core->added['settings'];
$account = FreePBX::Userman()->getUserByDefaultExtension('9990000013');

is_eq('a user is made', $made, ['extension' => '9990000013', 'created' => true]);
is_eq('the limits were asked once', $admitted, 1);
is_eq('in the lobby', $settings['context']['value'] ?? null, 'lobby');
is_eq('with the lobby emergency caller id', $settings['emergency_cid']['value'] ?? null, '+15551234567');
is_eq('one contact, replacing the last', [$settings['max_contacts']['value'] ?? null, $settings['remove_existing']['value'] ?? null], ['1', 'yes']);
is_eq('its account is the username', $account['username'] ?? null, 'bob');
is_eq('with UCP login switched off', FreePBX::Userman()->getModuleSettingByID($account['id'], 'ucp|Global', 'allowLogin'), false);
is_eq('and transfer off on its endpoint', strpos((string) file_get_contents($s['conf']), 'allow_transfer=no') !== false, true);
FreePBX::$config = ['ASTSPOOLDIR' => '/var/spool/asterisk'];
FreePBX::$conf = new StubConfig();

$s = signup_build();
is_eq('a reserved name is refused', thrown(function () use ($s) { $s['users']->signUp('support', 'pw'); }), SignupRefused::class);
is_eq('before anything is written', FreePBX::$core->added, null);

$s = signup_build();
is_eq('a refusal from the limits stops it', thrown(function () use ($s) {
	$s['users']->signUp('carol', 'pw', function () {
		throw new SignupRefused('per-minute', 429, 'slow down', 30);
	});
}), SignupRefused::class);
is_eq('before anything is written either', FreePBX::$core->added, null);

$s = signup_build();
FreePBX::Userman()->processQuickCreate('pjsip', '1001', ['name' => 'Taken']);
FreePBX::Userman()->users[1]['username'] = 'dave';
is_eq('a username already held is not made again', $s['users']->signUp('dave', 'pw'), null);

echo "\n  what the editor may not set:\n";

$s = build();
$s['users']->saveUser(['id' => '', 'extension' => '1001', 'name' => 'Desk', 'context' => 'from-trunk', 'lobby' => '1']);
is_eq('a posted context is ignored', FreePBX::$core->added['settings']['context']['value'] ?? null, null);
is_eq('and so is lobby', FreePBX::$core->added['settings']['max_contacts']['value'] ?? null, null);
is_eq('a save says Apply Config is pending', $s['users']->saveUser(['id' => '', 'extension' => '1002'])['reload'] ?? null, true);

echo "\n  what openClient() answers a sign-up:\n";

/** The endpoint, over a build. */
function signup_endpoint(array $s)
{
	$settings = new Settings($s['app']);
	$template = new \FreePBX\Modules\Oryk_Provisioner\Template($s['app'], new PbxDevices($s['app']), $settings, new Services($s['app']));

	return new Endpoint(
		$s['app'], $s['clients'], new \FreePBX\Modules\Oryk_Provisioner\Matcher($s['app'], $template), $template,
		new FileRepo($s['app']), new LogRepo($s['app']), new \FreePBX\Modules\Oryk_Provisioner\ProvisioningLog($s['app']),
		new Profiles($s['app'], new FileRepo($s['app'])), $s['users'], new Bans($s['app'])
	);
}

$s = signup_build();
// The retry is asked with the same WHERE, so it is listed first
$s['app']->Database->answers = ['TIMESTAMPDIFF(SECOND, NOW(), MIN(created_at)' => 42] + $s['app']->Database->answers;
$s['app']->Database->answers['signup_ip = :ip AND created_at'] = 1;
$result = signup_endpoint($s)->openClient('erin', 'pw', '203.0.113.9');
is_eq('a second sign-up in the minute is a 429', $result['code'] ?? null, 429);
is_eq('told when to come back', $result['retry'] ?? null, 42);
is_eq('nothing is made', FreePBX::$core->added, null);
is_eq('and the security log says why', SecurityLog::$written, ['Open provisioning sign-up refused (per-minute) for erin from 203.0.113.9']);

$s = signup_build();
$s['app']->Database->answers['signup_ip = :ip AND created_at'] = 1;
$s['app']->Database->insertId = 7;
$s['app']->Database->answers['FROM devices WHERE id'] = '9990000013';
$result = signup_endpoint($s)->openClient('erin', 'pw', '203.0.113.9', true);
is_eq('an Allow lifts the limits', [$result['status'], $result['extension'] ?? null], [true, '9990000013']);
is_eq('not the context', FreePBX::$core->added['settings']['context']['value'] ?? null, 'lobby');
is_eq('the sign-up is logged, marked allowed',
	SecurityLog::$written, ['Open provisioning sign-up: user erin extension 9990000013 context lobby from 203.0.113.9 (allowed)']);
$marked = array_values(array_filter($s['app']->Database->params, function ($p) {
	return strpos($p[0], "SET state = 'created'") !== false;
}));
is_eq('its client is created, counted under its address', $marked[0][1][':ip'] ?? null, '203.0.113.9');

$s = signup_build();
$result = signup_endpoint($s)->openClient('Admin', 'pw', '203.0.113.9', true);
is_eq('a reserved name is a 400 even from an Allow', $result['code'] ?? null, 400);
is_eq('logged as reserved', SecurityLog::$written, ['Open provisioning sign-up refused (reserved) for Admin from 203.0.113.9']);

$s = signup_build();
FreePBX::Userman()->processQuickCreate('pjsip', '1001', ['name' => 'Taken']);
FreePBX::Userman()->users[1]['username'] = 'dave';
$result = signup_endpoint($s)->openClient('dave', 'wrong', "203.0.113.9\n");
is_eq('a held name under another password is a 401', $result['code'] ?? null, 401);
is_eq('the one line FreePBX\'s jail bans for, scrubbed', SecurityLog::$written, ['Authentication failure for dave from 203.0.113.9?']);

$lines = ['Open provisioning sign-up: user x extension 1 context lobby from 1.2.3.4',
	'Open provisioning sign-up refused (per-day) for x from 1.2.3.4',
	'Open provisioning rebuilt extension 1 for user x context lobby from 1.2.3.4'];
is_eq('no other line looks like a login failure', array_filter($lines, function ($line) {
	return stripos($line, 'authentication failure') !== false;
}), []);

echo "\n  a login that outlived its extension gets it back:\n";

$s = signup_build();
FreePBX::Userman()->processQuickCreate('pjsip', '9990000020', ['name' => 'Gina']);
FreePBX::Userman()->users[1]['username'] = 'gina';
FreePBX::Userman()->logins['gina:pw'] = 1;
$found = $s['users']->findLogin('gina', 'pw');
is_eq('findLogin() says it rebuilt it', $found, ['extension' => '9990000020', 'created' => false, 'rebuilt' => true]);
is_eq('a device on the account\'s own number', FreePBX::$core->added['id'] ?? null, '9990000020');
is_eq('in the lobby, whatever it was', FreePBX::$core->added['settings']['context']['value'] ?? null, 'lobby');
is_eq('and the extension with it', isset(FreePBX::$core->users['9990000020']), true);
is_eq('the account is the same one', count(FreePBX::Userman()->users), 1);

$s = signup_build();
FreePBX::Userman()->processQuickCreate('pjsip', 'none', ['name' => 'Admin']);
FreePBX::Userman()->users[1]['username'] = 'boss';
FreePBX::Userman()->logins['boss:pw'] = 1;
is_eq('an account that names no number is not given one', thrown(function () use ($s) { $s['users']->findLogin('boss', 'pw'); }), 'RuntimeException');
is_eq('and nothing is made for it', FreePBX::$core->added, null);

echo "\n  a context is not this module's to change:\n";

$s = signup_build();
$s['users']->signUp('frank', 'pw');
FreePBX::$core->devices['9990000013'] = FreePBX::$core->added['settings'] ? array_map(function ($setting) {
	return $setting['value'];
}, FreePBX::$core->added['settings']) + ['id' => '9990000013', 'tech' => 'pjsip'] : [];
$s['users']->store(['id' => '9990000013', 'name' => 'Frank', 'context' => 'from-internal', 'promote' => true]);
is_eq('a save of an existing user does not take a context it is handed', (FreePBX::$core->added['settings']['context']['value'] ?? null) === 'from-internal', false);
is_eq('and there is no promote', method_exists($s['users'], 'promote'), false);

echo "\n  the lobby's dialplan:\n";

is_eq('only the routes flagged emergency', LobbyContext::emergencyContexts([
	['route_id' => '1', 'emergency_route' => ''],
	['route_id' => '2', 'emergency_route' => 'YES'],
	['route_id' => 'x', 'emergency_route' => 'YES'],
]), ['outrt-2']);

$s = build();
$s['app']->Database->fetchAlls = ["keyword = 'context'" => ['9990000013']];
$ext = new StubExtensions();
(new LobbyContext($s['app'], new Settings($s['app'])))->generate($ext);
is_eq('lobby goes on to lobby-dial', isset($ext->added['lobby']['_[0-9*#+].']), true);
is_eq('which searches the allowed contexts, deny last',
	$ext->includes['lobby-dial'] ?? null, ['ext-local', 'ext-meetme', 'app-vmmain', 'app-dialvm', 'lobby-deny']);
is_eq('lobby-dial has an extension of its own, or FreePBX never writes it',
	isset($ext->added['lobby-dial']['i']), true);
is_eq('and an unmatched number gets no service, not a dropped call',
	$ext->added['lobby-dial']['i'][1][1]->args ?? null, ['ss-noservice']);
is_eq('no outbound route is included',
	array_filter($ext->includes['lobby-dial'] ?? [], function ($include) { return strpos($include, 'outrt-') === 0; }), []);
is_eq('and lobby-deny says no service', $ext->added['lobby-deny']['_[0-9*#+].'][1][1]->args ?? null, ['ss-noservice']);
is_eq('calls are counted against ORYK_OPEN_CALLS',
	strpos($ext->added['lobby']['_[0-9*#+].'][2][1]->args[0] ?? '', '> 1]') !== false, true);
is_eq('a call to a lobby extension is forwarded in the lobby',
	[$ext->spliced[0][0] ?? null, $ext->spliced[0][1] ?? null, $ext->spliced[0][3]->args ?? null],
	['ext-local', '9990000013', ['__FORWARD_CONTEXT', 'lobby']]);

echo "\n  the Realtime bridge:\n";

$rows = RealtimeBridge::rows([
	'id' => '9990000013', 'description' => 'Bob "B" <x>', 'secret' => 's3cret', 'context' => 'lobby',
	'media_encryption' => 'sdes', 'max_contacts' => '1', 'transport' => '0.0.0.0-udp',
], 'lobby');
is_eq('named as FreePBX names them', [$rows['endpoint']['id'], $rows['endpoint']['auth'], $rows['auth']['id'], $rows['aor']['id']],
	['9990000013', '9990000013-auth', '9990000013-auth', '9990000013']);
is_eq('the device\'s own context and secret', [$rows['endpoint']['context'], $rows['auth']['password']], ['lobby', 's3cret']);
is_eq('one contact', $rows['aor']['max_contacts'], '1');
is_eq('no transfer from the lobby', $rows['endpoint']['allow_transfer'], 'no');
is_eq('a caller id that cannot break out of its quotes', $rows['endpoint']['callerid'], '"Bob B x" <9990000013>');
is_eq('every column is one the table has', array_diff(array_keys($rows['endpoint']), array_merge(['id'], RealtimeBridge::COLUMNS['endpoint'])), []);
$outside = RealtimeBridge::rows(['id' => '1001', 'context' => 'from-internal'], 'lobby');
is_eq('transfer stays on outside it', $outside['endpoint']['allow_transfer'], 'yes');

$text = "[settings]\nfoo => bar\n";
$block = RealtimeBridge::block('settings', ['ps_endpoints => odbc,x,y'], true);
is_eq('the block adds to a section the file has', strpos($block, "[settings](+)\n") !== false, true);
is_eq('and comes back out leaving the rest', trim(RealtimeBridge::withoutBlock($text . "\n" . $block)), trim($text));

echo "\n  a generated number steps over one Apply Config still has:\n";

$etc = sys_get_temp_dir() . '/oryk-numbers-' . getmypid();
@mkdir($etc);
file_put_contents($etc . '/pjsip.endpoint.conf', "[9990000013]\ntype=endpoint\n[9990000013-auth]\ntype=auth\n[9990000014]\ntype=endpoint\n");
$s = build();
$s['app']->Database->answers = ['MAX(CAST(id' => '9990000012'];
is_eq('deleted but not applied: skipped', (new NumberAllocator($s['app'], $s['userman'], $etc))->generate(), '9990000015');
file_put_contents($etc . '/pjsip.endpoint.conf', "[9990000001]\ntype=endpoint\n");
is_eq('applied away: reused, as in any PBX', (new NumberAllocator($s['app'], $s['userman'], $etc))->generate(), '9990000013');
@unlink($etc . '/pjsip.endpoint.conf');
@rmdir($etc);

echo "\n  the minute sweep:\n";

$etc = sys_get_temp_dir() . '/oryk-etc-' . getmypid();
@mkdir($etc);
file_put_contents($etc . '/pjsip.endpoint.conf', "[9990000013]\ntype=endpoint\n");

/** A sweep over a build, with Notifications, reading $etc. */
function sweep_build($etc)
{
	$s = build();
	$s['app']->Notifications = new StubNotifications();
	$s['bridge'] = new RealtimeBridge($s['app'], $etc);
	$s['sweep'] = new SignupSweep($s['app'], $s['clients'], $s['bridge'], new Settings($s['app']), new Notices($s['app']), $etc);
	FreePBX::$core->devices['9990000013'] = ['id' => '9990000013'];
	FreePBX::$core->devices['9990000014'] = ['id' => '9990000014'];

	return $s;
}

$pending = [['id' => '7', 'device_id' => '9990000013', 'age' => '30'], ['id' => '8', 'device_id' => '9990000014', 'age' => '30']];

$s = sweep_build($etc);
$s['app']->Database->fetchAlls = ["WHERE state = 'created'" => $pending];
$s['app']->Database->answers = ["variable = 'need_reload'" => 'true'];
is_eq('nothing moves while Apply Config is pending', $s['sweep']->run(), ['provisioned' => 0, 'pending' => 2]);

$s = sweep_build($etc);
$s['app']->Database->fetchAlls = ["WHERE state = 'created'" => $pending];
$s['app']->Database->answers = ["variable = 'need_reload'" => 'false'];
is_eq('applied: only the one the file has is done', $s['sweep']->run(), ['provisioned' => 1, 'pending' => 1]);
$marked = array_values(array_filter($s['app']->Database->params, function ($p) {
	return strpos($p[0], "SET state = 'provisioned'") !== false;
}));
is_eq('and that one is marked', $marked[0][1] ?? null, [':id_0' => '7']);

$s = sweep_build($etc);
$s['app']->Database->fetchAlls = ["WHERE state = 'created'" => [['id' => '8', 'device_id' => '9990000014', 'age' => '90000']]];
$s['app']->Database->answers = ["variable = 'need_reload'" => 'true'];
$s['sweep']->run();
is_eq('a day waiting raises BRIDGE_STALE', isset($s['app']->Notifications->up['oryk_provisioner/BRIDGE_STALE']), true);
$writes = $s['app']->Notifications->writes;
$s['sweep']->run();
is_eq('and the next run does not write it again', $s['app']->Notifications->writes, $writes);
$s['app']->Database->fetchAlls = [];
$s['sweep']->run();
is_eq('it comes down when nothing waits', isset($s['app']->Notifications->up['oryk_provisioner/BRIDGE_STALE']), false);

@unlink($etc . '/pjsip.endpoint.conf');
@rmdir($etc);

echo "\n  Overview:\n";

is_eq('a user scope is a target', Overview::target(['user' => '1001']), ['user' => '1001']);
is_eq('so is a client', Overview::target(['client' => '5']), ['client' => '5']);
is_eq('a profile is not', Overview::target(['profile' => '2']), []);
is_eq('nor an id in another spelling, which Navigator would not scope', Overview::target(['client' => '05']), []);
is_eq('nor a new row', Overview::target(['user' => 'new']), []);
is_eq('its address', Navigator::overviewHref('user', '1001'), '?display=oryk_provisioner&tab=overview&scope=user:1001');

$subject = ['user' => '1001', 'clients' => [5, 6], 'macs' => ['0004f282e824']];
is_eq('a ban on its client names it', Overview::names(ban_row(1, 'deny', ['client_id' => '5']), $subject), true);
is_eq('a ban on its extension names it', Overview::names(ban_row(2, 'deny', ['extension' => '1001', 'ip' => '203.0.113.7']), $subject), true);
is_eq('a ban on its MAC names it', Overview::names(ban_row(3, 'deny', ['mac' => '0004f282e824']), $subject), true);
is_eq('a ban stored with "any" as Bans::ANY reads the same', Overview::names(['client_id' => 0, 'extension' => '', 'mac' => '0004f282e824'], $subject), true);
is_eq('an address ban only applies', Overview::names(ban_row(4, 'banned', ['ip' => '203.0.113.7']), $subject), false);
is_eq('a profile ban only applies', Overview::names(ban_row(5, 'deny', ['profile_id' => '2']), $subject), false);
is_eq('another user\'s ban does not', Overview::names(ban_row(6, 'deny', ['extension' => '1002']), $subject), false);
is_eq('a client scope has no extension to name', Overview::names(ban_row(7, 'deny', ['extension' => '1001']), ['user' => null, 'clients' => [5], 'macs' => []]), false);

/** Overview over stubs, with one client (5, on user 1001) and whatever bans are given. */
function overview_build(array $bans)
{
	$s = build();
	$app = $s['app'];
	$files = new FileRepo($app);
	$bans = array_map(function ($ban) {
		return $ban + ['note' => '', 'active' => '1'];
	}, $bans);
	$profiles = new Profiles($app, $files);
	$requestLog = new \FreePBX\Modules\Oryk_Provisioner\ProvisioningLog($app);
	$banRepo = new Bans($app);
	$navigator = new Navigator($app, $s['clients'], $profiles, new \FreePBX\Modules\Oryk_Provisioner\Resources($app, $profiles, $files), $s['users'], $requestLog, $banRepo, new \FreePBX\Modules\Oryk_Provisioner\Services($app));
	$client = ['id' => '5', 'mac' => '0004f282e824', 'device_id' => '1001', 'profile_id' => '2', 'public_ip' => '203.0.113.7', 'description' => 'Desk'];

	$app->Database->fetches = [
		'WHERE pc.id = :id' => [$client + ['token' => null, 'enabled' => '1', 'last_seen' => null, 'private_ip' => null, 'last_seen_age' => null]],
		'WHERE b.id = :id' => $bans,
	];
	$app->Database->fetchAlls = [
		'ORDER BY pc.mac' => [$client],
		'ORDER BY b.created_at DESC' => $bans,
	];

	return $s + ['navigator' => $navigator, 'overview' => new Overview($app, $navigator, $s['users'], $s['clients'], $banRepo, $requestLog, new LogRepo($app))];
}

/** The statements that deleted from a table: [sql, params]. */
function overview_deletes($db, $table)
{
	return array_values(array_filter($db->params, function ($call) use ($table) {
		return preg_match('/^\s*DELETE FROM `' . $table . '`/', $call[0]) === 1;
	}));
}

$s = overview_build([ban_row(11, 'deny', ['mac' => '0004f282e824']), ban_row(12, 'banned', ['ip' => '203.0.113.7'])]);
$found = $s['overview']->inventory(['client' => '5']);
is_eq('a client\'s inventory is its own', [$found['kind'], $found['clients'], $found['macs']], ['client', [5], ['0004f282e824']]);
is_eq('the MAC ban names it', $found['named'], [11]);
is_eq('the address ban applies too', $found['applying'], [11, 12]);
is_eq('nothing for a client that is not there', $s['overview']->inventory(['client' => 'x']), null);
is_eq('nor for a profile', $s['overview']->inventory(['profile' => '2']), null);

$s = overview_build([ban_row(11, 'deny', ['mac' => '0004f282e824'])]);
$purged = $s['overview']->purge(['client' => '5']);
is_eq('Delete all on a client', $purged, ['status' => true, 'bans' => 1, 'clients' => 1]);
$deleted = overview_deletes($s['app']->Database, 'oryk_provisioner_bans');
is_eq('deletes the ban naming it by id', $deleted[0][1] ?? null, [':id' => 11]);
is_eq('and the client', count(overview_deletes($s['app']->Database, 'oryk_provisioner_clients')), 1);
is_eq('and never its user', FreePBX::$core->deleted, []);

$s = overview_build([ban_row(11, 'deny', ['mac' => '0004f282e824']), ban_row(13, 'deny', ['extension' => '1001']), ban_row(12, 'banned', ['ip' => '203.0.113.7'])]);
$s['app']->Database->fetches['WHERE u.extension = :id'] = [['extension' => '1001', 'name' => 'Desk', 'context' => 'lobby', 'clients' => '1', 'last_seen' => null]];
FreePBX::$core->devices['1001'] = ['id' => '1001', 'user' => '1001', 'tech' => 'pjsip'];
$found = $s['overview']->inventory(['user' => '1001']);
is_eq('a user\'s inventory has its clients', [$found['kind'], $found['clients'], $found['macs']], ['user', [5], ['0004f282e824']]);
is_eq('the bans on its extension and its client\'s MAC name it', $found['named'], [11, 13]);
$s['app']->Database->fetchAlls['FROM devices WHERE user = ? AND id <> ?'] = ['1001-cell'];
$purged = $s['overview']->purge(['user' => '1001']);
is_eq('Delete all on a user', $purged, ['status' => true, 'reload' => true, 'bans' => 2, 'clients' => 1]);
is_eq('deletes its device, and the other one on its extension', array_column(FreePBX::$core->deleted, 0), ['1001', '1001-cell']);
is_eq('and the extension all the same', isset(FreePBX::$core->users['1001']), false);
$byId = array_values(array_filter(array_map(function ($call) {
	return $call[1][':id'] ?? null;
}, overview_deletes($s['app']->Database, 'oryk_provisioner_bans'))));
is_eq('and the two bans naming it, not the address one', $byId, [11, 13]);

$s['app']->Database->fetchAlls['LIMIT :limit OFFSET :offset'] = [['id' => '5', 'mac' => '0004f282e824', 'extension' => '1001']];
$listed = $s['overview']->listClients(['client' => '5']);
is_eq('its Clients table says what each has stored', [$listed['rows'][0]['stored_files'], $listed['rows'][0]['stored_bytes']], [0, 0]);
$listed = $s['overview']->listUsers(['client' => '5']);
is_eq('its Users table says which account its user has', [$listed['rows'][0]['account'], $listed['rows'][0]['account_id']], ['', 0]);
is_eq('neither lists anything for a profile', [$s['overview']->listClients(['profile' => '2']), $s['overview']->listUsers(['profile' => '2'])], [['total' => 0, 'rows' => []], ['total' => 0, 'rows' => []]]);

is_eq('a client has no call history to list', $s['overview']->listCalls(['client' => '5']), ['total' => 0, 'rows' => [], 'available' => false]);
is_eq('nor to clear', $s['overview']->clearHistory(['client' => '5'])['status'], false);
$d = overview_build([]);
$d['app']->Database->fetches['WHERE u.extension = :id'] = [['extension' => '1001', 'name' => 'Desk', 'context' => 'lobby', 'clients' => '1', 'last_seen' => null]];
$d['app']->Database->fetchAlls['FROM devices WHERE user = ?'] = [['id' => '1001', 'tech' => 'pjsip', 'description' => 'Desk'], ['id' => '1001-cell', 'tech' => 'pjsip', 'description' => 'Cell']];
$devices = $d['overview']->listDevices(['user' => '1001']);
is_eq('a user\'s devices say which is its own', array_column($devices['rows'], 'own', 'id'), ['1001' => 1, '1001-cell' => 0]);
is_eq('a device that is not on its extension is not deleted', $d['overview']->deleteDevice(['user' => '1001'], '2002')['status'], false);
$d['app']->Database->answers = ['FROM devices WHERE id = ? AND user = ?' => 1];
is_eq('another device on it is', $d['overview']->deleteDevice(['user' => '1001'], '1001-cell'), ['status' => true, 'reload' => true]);
is_eq('through Core, and only that one', FreePBX::$core->deleted, [['1001-cell', false]]);
$unassigned = array_values(array_filter($d['app']->Database->params, function ($call) {
	return strpos($call[0], "SET device_id = '' WHERE device_id = :id") !== false;
}));
is_eq('a client on it is unassigned', $unassigned[0][1] ?? null, [':id' => '1001-cell']);
is_eq('and not deleted', count(overview_deletes($d['app']->Database, 'oryk_provisioner_clients')), 0);
$d['app']->Database->fetchAlls["WHERE device_id = :id"] = [['id' => '5']];
is_eq('asked for its clients too, it goes the same way', $d['overview']->deleteDevice(['user' => '1001'], '1001-cell', true)['status'], true);
is_eq('and they are deleted, not unassigned', [count(overview_deletes($d['app']->Database, 'oryk_provisioner_clients')), count(array_filter($d['app']->Database->params, function ($call) {
	return strpos($call[0], "SET device_id = ''") !== false;
}))], [1, 1]);
unset($d['app']->Database->fetchAlls["WHERE device_id = :id"]);
FreePBX::$core->deleted = [];
$d['app']->Database->answers['SELECT user FROM devices WHERE id = ?'] = '1001';
is_eq('a client deleted with its device', $d['overview']->deleteClientWithDevice('5'), ['status' => true, 'reload' => true]);
is_eq('takes the device it was using', FreePBX::$core->deleted, [['1001', false]]);
unset($d['app']->Database->answers['SELECT user FROM devices WHERE id = ?']);
FreePBX::$core->deleted = [];
FreePBX::$core->users['1001'] = ['extension' => '1001'];
is_eq('its own device is deleted as a device too', $d['overview']->deleteDevice(['user' => '1001'], '1001'), ['status' => true, 'reload' => true]);
is_eq('and the extension is left standing', [FreePBX::$core->deleted[0] ?? null, isset(FreePBX::$core->users['1001'])], [['1001', false], true]);
is_eq('a client has no device to delete', $d['overview']->deleteDevice(['client' => '5'], '1001-cell')['status'], false);
is_eq('with nothing to ask Asterisk, a device\'s status is unknown', $devices['rows'][0]['status']['state'], 'unknown');

$aor = "      Aor:  <Aor..............................................>  <MaxContact>\n"
	. "    Contact:  <Aor/ContactUri............................> <Hash....> <Status> <RTT(ms)..>\n"
	. "==========================================================================================\n\n"
	. "      Aor:  1001                                                 1\n"
	. "    Contact:  1001/sip:1001@203.0.113.7:5062;transport=tls 4ea6b7c2d1 Avail        23.512\n";
is_eq('a reachable contact is registered, with where and how fast', DeviceStatus::parse($aor), ['state' => 'registered', 'contacts' => 1, 'address' => '203.0.113.7:5062', 'rtt' => 23.5]);
is_eq('an unqualified one is registered too', DeviceStatus::parse("    Contact:  1001/sip:1001@10.0.0.9:5060 abc123 NonQual         nan\n")['state'], 'registered');
is_eq('only unreachable contacts is unreachable', DeviceStatus::parse("    Contact:  1001/sip:1001@10.0.0.9:5060 abc123 Unavail         nan\n")['state'], 'unreachable');
is_eq('one reachable among them is enough', DeviceStatus::parse("    Contact:  1001/sip:a@10.0.0.9 h1 Unavail nan\n    Contact:  1001/sip:b@10.0.0.8 h2 Avail 4.0\n"), ['state' => 'registered', 'contacts' => 2, 'address' => '10.0.0.8', 'rtt' => 4.0]);
is_eq('an AOR with no contact is not registered', DeviceStatus::parse("      Aor:  1001                                                 1\n")['state'], 'unregistered');
is_eq('nor is no such AOR', DeviceStatus::parse('Unable to find object 1001.')['state'], 'unregistered');
$asked = new DeviceStatus($d['app']);
is_eq('a device that does not register has none', $asked->of('1001', 'dahdi')['state'], 'none');
is_eq('an id that is not one is never put in a command', $asked->of('1001; core stop now', 'pjsip')['state'], 'unknown');
is_eq('a client has no devices to list', $d['overview']->listDevices(['client' => '5']), ['total' => 0, 'rows' => []]);

$c = overview_build([]);
$c['app']->Database->fetches['WHERE u.extension = :id'] = [['extension' => '1001', 'name' => 'Desk', 'context' => 'lobby', 'clients' => '1', 'last_seen' => null]];
is_eq('with no CDR module there is none to read', $c['overview']->listCalls(['user' => '1001'])['available'], false);
$c['app']->Modules->active = ['cdr'];
$_REQUEST['sort'] = 'calldate; DROP TABLE cdr';
$calls = $c['overview']->listCalls(['user' => '1001']);
unset($_REQUEST['sort']);
is_eq('a user\'s is read from cdr', [$calls['available'], $calls['total']], [true, 0]);
$listing = array_values(array_filter(FreePBX::$cdr->handle->statements, function ($q) {
	return strpos($q, 'SELECT `calldate`') === 0;
}));
is_eq('by src or dst, bound, sorted by a column it has', strpos($listing[0] ?? '', 'FROM `cdr` WHERE (`src` = :m0 OR `dst` = :m1) ORDER BY `calldate` DESC LIMIT 10 OFFSET 0') !== false, true);
$cleared = $c['overview']->clearHistory(['user' => '1001']);
is_eq('clearing it purges and keeps the user', [$cleared['status'], $cleared['rows'] > 0, FreePBX::$core->deleted], [true, true, []]);
is_eq('what is not a number lists nothing', $c['cdr']->listCalls('1001 OR 1=1')['available'], false);

$box = sys_get_temp_dir() . '/oryk-vm-' . getmypid();
@mkdir($box . '/INBOX', 0700, true);
@mkdir($box . '/Old', 0700, true);
file_put_contents($box . '/unavail.wav', 'greeting');
file_put_contents($box . '/INBOX/msg0000.txt', "[message]\ncallerid=\"Front Desk\" <1002>\norigtime=1700000000\nduration=12\n");
file_put_contents($box . '/INBOX/msg0000.wav', 'audio');
file_put_contents($box . '/Old/msg0000.txt', "[message]\ncallerid=5551234\norigtime=1700000500\nduration=3\n");
file_put_contents($box . '/Old/msg0000.WAV', 'audio');
file_put_contents($box . '/Old/notes.txt', 'not a message');
$messages = $c['voicemail']->messagesIn($box);
is_eq('a mailbox\'s messages are listed newest first', array_column($messages, 'id'), ['Old/msg0000', 'INBOX/msg0000']);
is_eq('with who left each and how long it is', [$messages[1]['callerid'], $messages[1]['duration'], $messages[1]['folder']], ['"Front Desk" <1002>', 12, 'INBOX']);
foreach (['msg0001', 'msg0002'] as $more) {
	file_put_contents($box . '/INBOX/' . $more . '.txt', "origtime=17000001" . substr($more, -2) . "\ncallerid=" . $more . "\n");
	file_put_contents($box . '/INBOX/' . $more . '.wav', 'audio');
}
is_eq('an id that is a path somewhere else deletes nothing', $c['voicemail']->deleteIn($box, '../INBOX/msg0000'), false);
is_eq('nor one that is not listed', $c['voicemail']->deleteIn($box, 'INBOX/msg0009'), false);
is_eq('one message is deleted', $c['voicemail']->deleteIn($box, 'INBOX/msg0001'), true);
is_eq('and the ones after it close the gap, audio with them', array_map('basename', glob($box . '/INBOX/msg*')), ['msg0000.txt', 'msg0000.wav', 'msg0001.txt', 'msg0001.wav']);
is_eq('keeping what they were', strpos((string) file_get_contents($box . '/INBOX/msg0001.txt'), 'callerid=msg0002') !== false, true);
is_eq('the other folder is left alone', is_file($box . '/Old/msg0000.txt'), true);
is_eq('a client has no message to delete', $c['overview']->deleteVoicemail(['client' => '5'], 'INBOX/msg0000')['status'], false);
is_eq('clearing removes the messages', $c['voicemail']->clearIn($box), 3);
is_eq('audio included', glob($box . '/*/msg*'), []);
is_eq('and leaves the greeting and what is not a message', [is_file($box . '/unavail.wav'), is_file($box . '/Old/notes.txt')], [true, true]);
is_eq('no mailbox lists nothing', $c['voicemail']->messagesIn(''), []);
is_eq('and a client has no voicemail to clear', $c['overview']->clearVoicemail(['client' => '5'])['status'], false);
is_eq('a user with no mailbox clears nothing', $c['overview']->clearVoicemail(['user' => '1001']), ['status' => true, 'removed' => 0]);
@unlink($box . '/unavail.wav');
@unlink($box . '/Old/notes.txt');
@rmdir($box . '/INBOX');
@rmdir($box . '/Old');
@rmdir($box);

$levels = [];
foreach ($s['navigator']->levels(['client' => '5'], 'overview') as $level) {
	$levels[$level['key']] = $level;
}
is_eq('on Overview a client option re-opens Overview', $levels['client']['options'][0]['href'], '?display=oryk_provisioner&tab=overview&scope=client:5');
is_eq('while the crumb still names the client\'s page', $levels['client']['href'], '?display=oryk_provisioner&client=5');
$levels = [];
foreach ($s['navigator']->levels(['client' => '5']) as $level) {
	$levels[$level['key']] = $level;
}
is_eq('anywhere else it opens the client', $levels['client']['options'][0]['href'], '?display=oryk_provisioner&client=5');
$bar = array_column(array_column($s['navigator']->sections('users', ['user' => '1001']), null, 'key')['logs']['items'], 'href', 'key');
is_eq('from a user\'s page the bar\'s Overview opens on it', $bar['overview'], '?display=oryk_provisioner&tab=overview&scope=user:1001');
$bar = array_column(array_column($s['navigator']->sections('users', ['user' => 'new']), null, 'key')['logs']['items'], 'href', 'key');
is_eq('but not from a new one', $bar['overview'], '?display=oryk_provisioner&tab=overview');
is_eq('Users is still where a bare URL lands', $s['navigator']->section(''), 'users');
$bar = $s['navigator']->sections('clients');
is_eq('a group is one bar entry: its active section, else its first', array_column($bar, 'active', 'key'), ['clients' => true, 'logs' => false, 'settings' => false]);
is_eq('which goes where that section does', [$bar[0]['href'], $bar[1]['href']], ['?display=oryk_provisioner&tab=clients', '?display=oryk_provisioner&tab=logs']);
is_eq('and lists the whole group in order', array_column($bar[0]['items'], 'active', 'key'), ['users' => false, 'clients' => true, 'profiles' => false, 'services' => false]);
is_eq('an ungrouped section has no menu', isset($bar[2]['items']), false);

$s = overview_build([]);
$s['app']->Database->fetches = [];
is_eq('Delete all on a row that has gone is refused', $s['overview']->purge(['client' => '5'])['status'], false);
is_eq('and deletes nothing', count(overview_deletes($s['app']->Database, 'oryk_provisioner_clients')), 0);

$s = build();
$log = new \FreePBX\Modules\Oryk_Provisioner\ProvisioningLog($s['app']);
$log->clearFor([]);
$cleared = overview_deletes($s['app']->Database, 'oryk_provisioner_logs');
is_eq('clearing no MACs can match no row', strpos($cleared[0][0], 'WHERE 1 = 0') !== false, true);
$log->clearFor(['0004f282e824']);
$cleared = overview_deletes($s['app']->Database, 'oryk_provisioner_logs');
is_eq('clearing a MAC binds it', $cleared[1][1], [':mac_0' => '0004f282e824']);

$logs = new LogRepo($s['app']);
is_eq('a client that sent nothing has nothing stored', $logs->clientLogStats(987654321), ['files' => 0, 'bytes' => 0]);

echo "\n  a service and what it is under:\n";

// [parent, child], by slug: basic and advanced are both over vm; vm is over greeting.
$links = [['basic', 'vm'], ['advanced', 'vm'], ['vm', 'greeting']];
is_eq('a service is under more than one parent', Services::ancestors($links, 'vm'), ['basic', 'advanced']);
is_eq('and everything over those, at any depth', Services::ancestors($links, 'greeting'), ['vm', 'basic', 'advanced']);
is_eq('everything under a service, at any depth', Services::descendants($links, 'basic'), ['vm', 'greeting']);
is_eq('a service linked to nothing', [Services::ancestors($links, 'fax'), Services::descendants($links, 'fax')], [[], []]);
is_eq('a second parent closes no loop', Services::loops($links, 'greeting', ['vm', 'fax'], []), false);
is_eq('nor does a child two parents already share', Services::loops($links, 'fax', [], ['vm']), false);
is_eq('a service under itself does', Services::loops($links, 'vm', ['vm'], []), true);
is_eq('so does a parent that is already under it', Services::loops($links, 'basic', ['greeting'], ['vm']), true);
is_eq('and one service on both sides', Services::loops($links, 'fax', ['basic'], ['basic']), true);
is_eq('a new service is checked through what it is given', Services::loops($links, '', ['greeting'], ['basic']), true);
is_eq('its own old links are not held against it', Services::loops($links, 'vm', ['greeting'], []), false);
is_eq('a loop in the data is walked once, not for ever', Services::descendants([['a', 'b'], ['b', 'a']], 'a'), ['b']);
is_eq('a slug of digits is still a string', Services::descendants([['100', '200']], '100'), ['200']);
is_eq('a user has what it is assigned and everything under it', Services::held($links, ['basic']), ['basic', 'greeting', 'vm']);
is_eq('each once, however many packs it comes through', Services::held($links, ['vm', 'advanced', 'basic']), ['advanced', 'basic', 'greeting', 'vm']);
is_eq('and a user assigned nothing has nothing', Services::held($links, []), []);
is_eq('slugs arrive as one string', Services::slugs('vm, Basic,vm,not ok,,-x,'), ['vm', 'basic']);
is_eq('or as an array', Services::slugs(['fax', 'fax', [], 'call-recording']), ['fax', 'call-recording']);
is_eq('and nothing is no slugs', Services::slugs(''), []);

is_eq('a slug is made from a name', Services::slugify('  On-Demand  Recording! '), 'on-demand-recording');
is_eq('and from one with nothing usable in it', Services::slugify('***'), 'service');
is_eq('never longer than the column', strlen(Services::slugify(str_repeat('ab ', 40))) <= Services::SLUG_MAX, true);
is_eq('a default is the module\'s', [Services::managed('voicemail'), Services::managed('my-pack'), Services::managed(null)], [true, false, false]);

$defaultLinks = [];
$unknown = [];
foreach (Services::DEFAULTS as $slug => $default) {
	if (!preg_match(Services::SLUG_PATTERN, (string) $slug) || strlen($slug) > Services::SLUG_MAX || trim((string) ($default['name'] ?? '')) === '') {
		$unknown[] = $slug;
	}
	foreach ($default['services'] ?? [] as $child) {
		if (!isset(Services::DEFAULTS[$child])) {
			$unknown[] = $slug . ' > ' . $child;
		}
		$defaultLinks[] = [(string) $slug, (string) $child];
	}
}
is_eq('every default is a slug with a name, over defaults only', $unknown, []);
is_eq('no two defaults share a name', count(array_unique(array_column(Services::DEFAULTS, 'name'))), count(Services::DEFAULTS));
$looped = [];
foreach ($defaultLinks as $link) {
	if ($link[0] === $link[1] || in_array($link[0], Services::descendants($defaultLinks, $link[1]), true)) {
		$looped[] = $link[0] . ' > ' . $link[1];
	}
}
is_eq('and the defaults close no loop', $looped, []);

$s = build();
$services = new Services($s['app']);
is_eq('a service needs a name', $services->saveService(['name' => '  '])['status'], false);
is_eq('and one that fits the column', $services->saveService(['name' => str_repeat('x', 192)])['status'], false);

/** Services, answering for one user without a table. */
class GuestServices extends Services
{
	public function userSlugs($extension)
	{
		return (string) $extension === '9990000001' ? ['guest-user', 'support'] : [];
	}
}

$template = new \FreePBX\Modules\Oryk_Provisioner\Template($s['app'], new PbxDevices($s['app']), new Settings($s['app']), new GuestServices($s['app']));
$render = function (array $client) use ($template) {
	return $template->renderTemplate('SERVICES={{extension.services}}', $template->provisioningValues($client));
};
is_eq('a template is given them as one value', $render(['mac' => '0004f282e824', 'extension' => '9990000001']), 'SERVICES=guest-user,support');
is_eq('a user with none renders empty', $render(['mac' => '0004f282e824', 'extension' => '1001']), 'SERVICES=');
is_eq('and so does a client with no user', $render(['mac' => '0004f282e824']), 'SERVICES=');

echo "\n  what a change to services makes a job of:\n";

// gold is over fax and sms; silver over fax.
$packs = [['gold', 'fax'], ['gold', 'sms'], ['silver', 'fax']];
$change = function (array $before, array $after, ?array $linksAfter = null) use ($packs) {
	return array_map(function ($step) {
		return $step['event'][0] . ':' . $step['service'] . ($step['via'] !== null ? '<' . $step['via'] : '');
	}, ServiceEngine::changes($packs, $before, $linksAfter ?? $packs, $after));
};
is_eq('assigning a pack grants it and all under it', $change([], ['gold']), ['g:fax<gold', 'g:gold', 'g:sms<gold']);
is_eq('nothing already held is granted again', $change(['silver'], ['silver', 'gold']), ['g:gold', 'g:sms<gold']);
is_eq('nothing still held another way is revoked', $change(['silver', 'gold'], ['silver']), ['r:gold', 'r:sms<gold']);
is_eq('revokes come before grants', $change(['silver'], ['gold']), ['r:silver', 'g:gold', 'g:sms<gold']);
is_eq('the same state again is nothing', $change(['gold'], ['gold']), []);
is_eq('a pack losing a service revokes it', $change(['gold'], ['gold'], [['gold', 'fax'], ['silver', 'fax']]), ['r:sms<gold']);
is_eq('one gaining a service grants it', $change(['silver'], ['silver'], [['gold', 'fax'], ['gold', 'sms'], ['silver', 'fax'], ['silver', 'sms']]), ['g:sms<silver']);
is_eq('a deleted service goes, with what came only through it', $change(['gold', 'silver'], ['silver'], [['silver', 'fax']]), ['r:gold', 'r:sms<gold']);
is_eq('several services at once are one change', Services::assignedAfter(['silver', 'fax'], ['gold', 'fax'], ['silver', 'sms']), [['fax', 'gold'], ['gold'], ['silver']]);
is_eq('and a pack swapped for another never revokes what both give', $change(['silver'], Services::assignedAfter(['silver'], ['gold'], ['silver'])[0]), ['r:silver', 'g:gold', 'g:sms<gold']);
is_eq('naming what is already so changes nothing', Services::assignedAfter(['gold'], ['gold'], ['sms']), [['gold'], [], []]);
is_eq('a save of several is titled as one', Jobs::title(['name' => '', 'reason' => 'changed']), 'Several services');
is_eq('a renamed default goes from a slug DEFAULTS dropped to one it has', array_filter(Services::RENAMED, function ($slug, $was) { return isset(Services::DEFAULTS[$was]) || !isset(Services::DEFAULTS[$slug]); }, ARRAY_FILTER_USE_BOTH), []);
is_eq('each of the module\'s own jobs says what it does, both ways', array_values(array_filter(array_keys(\FreePBX\Modules\Oryk_Provisioner\Reactions::jobs()), function ($slug) { return \FreePBX\Modules\Oryk_Provisioner\Reactions::effect($slug, 'granted') === '' || \FreePBX\Modules\Oryk_Provisioner\Reactions::effect($slug, 'revoked') === ''; })), []);
is_eq('and a service with no job says nothing', \FreePBX\Modules\Oryk_Provisioner\Reactions::effect('support', 'revoked'), '');
is_eq('a filter is one of its values, or all', Jobs::filters(['state' => 'failed', 'reason' => 'x', 'source' => 'upgrade']), ['state' => 'failed', 'reason' => 'all', 'source' => 'upgrade']);
is_eq('every value has a label', [array_keys(Jobs::labels()['state']), array_keys(Jobs::labels()['reason']), array_keys(Jobs::labels()['source'])], [Jobs::STATES, Jobs::REASONS, Jobs::SOURCES]);
is_eq('an upgrade\'s job is titled as one', [Jobs::title(['name' => '']), Jobs::title(['name' => 'Gold'])], ['Module upgrade', 'Gold']);
is_eq('a job is a scope', Navigator::scopeAt('job:12'), ['job' => '12']);
is_eq('the Jobs list is narrowed when its scope is', [Navigator::narrows('jobs', ['jobs' => ['extensions' => ['100']]]), Navigator::narrows('jobs', ['jobs' => null])], [true, false]);
$ownJobs = \FreePBX\Modules\Oryk_Provisioner\Reactions::jobs();
is_eq('every job in src/Jobs/ is found, by the services it names', array_keys($ownJobs), ['call-recording', 'find-me-follow', 'on-demand-recording', 'voicemail']);
is_eq('and each is one of the module\'s own services', array_values(array_filter(array_keys($ownJobs), function ($slug) { return !Services::managed($slug); })), []);
is_eq('a listener with no services is for every one', ServiceEngine::onlyFor(['module' => 'Mymodule']), null);
is_eq('one with services is for those slugs', ServiceEngine::onlyFor(['services' => 'voicemail, Call-Recording,,bad slug']), ['voicemail', 'call-recording']);
is_eq('the done_by list reads back', Jobs::doneBy(['done_by' => 'oryk_provisioner,testmod']), ['oryk_provisioner', 'testmod']);

// The module class is not loaded here (it needs FreePBX), so its methods are read off the file.
$hooks = simplexml_load_file(dirname(__DIR__) . '/module.xml');
$moduleSource = file_get_contents(dirname(__DIR__) . '/Oryk_provisioner.class.php');
$hooked = [];
foreach ($hooks->hooks->children() as $module => $methods) {
	foreach ($methods->method as $method) {
		$hooked[] = $module . '::' . $method['callingMethod'] . ' -> ' . $method . (preg_match('/public function ' . preg_quote((string) $method, '/') . '\\(/', $moduleSource) ? '' : ' (missing)');
	}
}
is_eq('module.xml hooks Core\'s delUser, and itself for its own jobs, to methods that exist', $hooked, ['core::delUser -> coreDelUser', 'oryk_provisioner::serviceGranted -> runOwnJobGranted', 'oryk_provisioner::serviceRevoked -> runOwnJobRevoked']);
is_eq('its own hook goes before a module that does not say', (string) $hooks->hooks->oryk_provisioner['priority'], '100');

echo "\n  the module class imports every class it builds:\n";

// Nothing above loads Oryk_provisioner.class.php, so a `new Overview(...)`
// without its `use` fatals only on a PBX.
$module = file_get_contents(dirname(__DIR__) . '/Oryk_provisioner.class.php');
preg_match_all('/^use\s+(?:[A-Za-z0-9_\\\\]+\\\\)?([A-Za-z0-9_]+);/m', $module, $imports);
preg_match_all('/\bnew\s+([A-Z][A-Za-z0-9_]*)\s*\(|\b([A-Z][A-Za-z0-9_]*)::/', $module, $named);
$missing = array_values(array_diff(array_unique(array_filter(array_merge($named[1], $named[2]))), $imports[1], ['Oryk_provisioner']));
is_eq('none is missing its use', $missing, []);


foreach ($TEMPORARY as $path) {
	@unlink($path);
}

printf("\n%d passed, %d failed\n", $passed, $failed);

exit($failed ? 1 : 0);
