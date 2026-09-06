<?php
/**
 * Engine tests: template rendering, parameter resolution, device lifecycle and
 * the behaviour of the provisioning endpoint.
 */

require_once __DIR__ . '/bootstrap.php';

use Oryk\Provisioner\Model\Device;
use Oryk\Provisioner\Template\Renderer;

echo "Engine\n";

$provisioner = oryk_test_container();

// ------------------------------------------------------------------- renderer
echo "\nTemplate engine\n";
$renderer = new Renderer();
$context = array(
    'sip.username' => '1001',
    'sip.password' => 'p@ss"word',
    'sip.transport' => 'tls',
    'sip.secure' => true,
    'sip.port' => 5061,
    'user.display_name' => 'Alain <Ops>',
    'device.mac' => '001565AABBCC',
    'directory' => array(
        array('extension' => '1002', 'name' => 'Support'),
        array('extension' => '1003', 'name' => 'Sales'),
    ),
);

oryk_check(
    'variable substitution',
    $renderer->render('user={{sip.username}}', $context) === 'user=1001'
);

oryk_check(
    'json escaping',
    $renderer->render('"pw": "{{sip.password}}"', $context, 'application/json') === '"pw": "p@ss\"word"',
    $renderer->render('"pw": "{{sip.password}}"', $context, 'application/json')
);

oryk_check(
    'xml escaping',
    $renderer->render('<n>{{user.display_name}}</n>', $context, 'application/xml') === '<n>Alain &lt;Ops&gt;</n>',
    $renderer->render('<n>{{user.display_name}}</n>', $context, 'application/xml')
);

oryk_check(
    'raw triple brace',
    $renderer->render('{{{user.display_name}}}', $context, 'application/xml') === 'Alain <Ops>'
);

oryk_check(
    'filters with arguments',
    $renderer->render('{{sip.missing|default:5060}}/{{device.mac|mac:colon}}', $context) === '5060/00:15:65:AA:BB:CC',
    $renderer->render('{{sip.missing|default:5060}}/{{device.mac|mac:colon}}', $context)
);

oryk_check(
    'if/else blocks',
    $renderer->render('t={{#if sip.secure}}2{{else}}0{{/if}}', $context) === 't=2'
);

oryk_check(
    'unless blocks',
    $renderer->render('{{#unless sip.proxy}}noproxy{{/unless}}', $context) === 'noproxy'
);

oryk_check(
    'nested blocks',
    $renderer->render('{{#if sip.secure}}[{{#unless sip.proxy}}x{{/unless}}]{{/if}}', $context) === '[x]'
);

oryk_check(
    'each with metadata',
    $renderer->render('{{#each directory}}{{@number}}:{{name}}/{{extension}};{{/each}}', $context)
        === '1:Support/1002;2:Sales/1003;',
    $renderer->render('{{#each directory}}{{@number}}:{{name}}/{{extension}};{{/each}}', $context)
);

oryk_check(
    'each else branch',
    $renderer->render('{{#each missing}}x{{else}}empty{{/each}}', $context) === 'empty'
);

oryk_check('comments are dropped', $renderer->render('a{{! note }}b', $context) === 'ab');

oryk_check(
    'missing parameters are reported',
    in_array('sip.nothing', (function () use ($renderer, $context) {
        $renderer->render('{{sip.nothing}}', $context);
        return $renderer->missing();
    })(), true)
);

$strict = new Renderer();
$strict->setStrict(true);

try {
    $strict->render('{{nope}}', $context);
    oryk_check('strict mode throws', false);
} catch (\Oryk\Provisioner\Exception\RenderException $e) {
    oryk_check('strict mode throws', true);
}

try {
    $renderer->render('{{#if a}}x', $context);
    oryk_check('unclosed block throws', false);
} catch (\Oryk\Provisioner\Exception\RenderException $e) {
    oryk_check('unclosed block throws', true);
}

oryk_check(
    'newlines are stripped in key/value output',
    $renderer->render('pw={{v}}', array('v' => "a\nb=c"), 'text/plain') === 'pw=a b=c'
);

// ------------------------------------------------------------------- seeding
echo "\nSeeding\n";
$result = $provisioner->seeder()->seed();
oryk_check('bundled templates created', count($result['created']) === 4, json_encode($result));

$yealink = $provisioner->templates()->findBySlug('yealink-t5x');
oryk_check('yealink template loaded', $yealink !== null);
oryk_check('yealink has two outputs', $yealink !== null && count($yealink->outputs) === 2);
oryk_check('schema parsed', $yealink !== null && $yealink->schema->has('sip.password'));
oryk_check('secret flagged', $yealink !== null && in_array('sip.password', $yealink->schema->secrets(), true));

$seedAgain = $provisioner->seeder()->seed();
oryk_check('re-seeding skips existing', count($seedAgain['skipped']) === 4 && count($seedAgain['created']) === 0);

// ------------------------------------------------------------------- devices
echo "\nDevices\n";
$device = Device::fromArray(array(
    'name' => 'Office T54W',
    'templateId' => $yealink->id,
    'extension' => '1001',
    'mac' => '00:15:65:aa:bb:cc',
    'parameters' => array('sip.transport' => 'tls', 'sip.password' => 'secret123', 'sip.domain' => 'pbx.example.com'),
));
$device = $provisioner->devices()->save($device);

oryk_check('identifier generated', $device->identifier === 'office-t54w', $device->identifier);
oryk_check('mac normalised', $device->mac === '001565AABBCC', $device->mac);
oryk_check('token issued', preg_match('/^[a-f0-9]{32}$/', $device->token) === 1, $device->token);
oryk_check('template hydrated', $device->template !== null);

$dupe = Device::fromArray(array('name' => 'Office T54W', 'templateId' => $yealink->id));
$dupe = $provisioner->devices()->save($dupe);
oryk_check('duplicate identifiers are made unique', $dupe->identifier === 'office-t54w-2', $dupe->identifier);

try {
    $provisioner->devices()->save(Device::fromArray(array('name' => '', 'templateId' => 0)));
    oryk_check('validation rejects empty devices', false);
} catch (\Oryk\Provisioner\Exception\ValidationException $e) {
    oryk_check('validation rejects empty devices', count($e->getErrors()) >= 2, json_encode($e->getErrors()));
}

// ------------------------------------------------------------- resolution
echo "\nParameter resolution\n";
$context = $provisioner->engine()->contextFor($device);

oryk_check('device override wins', $context->get('sip.transport') === 'tls', $context->get('sip.transport'));
oryk_check('template default applies', (string) $context->get('sip.register_expires') === '300');
oryk_check('device intrinsics present', $context->get('device.mac') === '001565AABBCC');
oryk_check('derived mac_colon', $context->get('device.mac_colon') === '00:15:65:AA:BB:CC');
oryk_check('sip.domain override', $context->get('sip.domain') === 'pbx.example.com');
oryk_check('secure derived from transport', $context->get('sip.secure') === true);
oryk_check('password marked secret', $context->isSecret('sip.password'));

$sources = $context->sources();
oryk_check('source tracking', $sources['sip.transport'] === 'override', $sources['sip.transport']);

$rows = $context->toRows(false);
$masked = '';

foreach ($rows as $row) {
    if ($row['parameter'] === 'sip.password') {
        $masked = $row['value'];
    }
}

oryk_check('secrets masked in preview', strpos($masked, 'secret123') === false && $masked !== '', $masked);

// ------------------------------------------------------------------ rendering
echo "\nRendering\n";
$files = $provisioner->engine()->renderAll($device);
oryk_check('two files rendered', count($files) === 2, count($files));
oryk_check('filename template expanded', $files[0]->filename === '001565AABBCC.cfg', $files[0]->filename);
oryk_check(
    'credentials rendered',
    strpos($files[0]->content, 'account.1.password = secret123') !== false,
    substr($files[0]->content, 0, 200)
);
oryk_check(
    'tls transport branch',
    strpos($files[0]->content, 'account.1.sip_server.1.transport_type = 2') !== false
);
oryk_check('directory file is xml', $files[1]->contentType === 'application/xml');

$json = $provisioner->templates()->findBySlug('generic-softphone');
$softphoneDevice = $provisioner->devices()->save(Device::fromArray(array(
    'name' => 'Alain Softphone',
    'templateId' => $json->id,
    'extension' => '1001',
    'parameters' => array('sip.password' => 'a"b\\c', 'sip.domain' => 'pbx.example.com'),
)));
$softphoneFiles = $provisioner->engine()->renderAll($softphoneDevice);
oryk_check('softphone renders one file', count($softphoneFiles) === 1);
oryk_check('softphone output is valid json', json_decode($softphoneFiles[0]->content) !== null, $softphoneFiles[0]->content);

// ---------------------------------------------------------------- provisioning
echo "\nProvisioning endpoint behaviour\n";
$request = array('ip' => '192.168.1.50', 'userAgent' => 'Yealink SIP-T54W', 'secure' => true);

$served = $provisioner->engine()->provision($device->token, '001565AABBCC.cfg', $request);
oryk_check('valid token serves the file', strpos($served->content, 'account.1.enable = 1') !== false);

try {
    $provisioner->engine()->provision('deadbeef' . str_repeat('0', 24), '001565AABBCC.cfg', $request);
    oryk_check('unknown token is refused', false);
} catch (\Oryk\Provisioner\Exception\NotFoundException $e) {
    oryk_check('unknown token is refused', true);
}

try {
    $provisioner->engine()->provision($device->token, 'passwd', $request);
    oryk_check('unknown filename is refused', false);
} catch (\Oryk\Provisioner\Exception\NotFoundException $e) {
    oryk_check('unknown filename is refused', true);
}

try {
    $provisioner->engine()->provision($device->token, '../../etc/passwd', $request);
    oryk_check('traversal is refused', false);
} catch (\Oryk\Provisioner\Exception\NotFoundException $e) {
    oryk_check('traversal is refused', true);
}

$provisioner->devices()->setEnabled($device->id, false);

try {
    $provisioner->engine()->provision($device->token, '001565AABBCC.cfg', $request);
    oryk_check('disabled device is refused', false);
} catch (\Oryk\Provisioner\Exception\NotFoundException $e) {
    oryk_check('disabled device is refused', true);
}

$provisioner->devices()->setEnabled($device->id, true);

$old = $device->token;
$rotated = $provisioner->devices()->regenerateToken($device->id);
oryk_check('token changed', $rotated->token !== $old);

try {
    $provisioner->engine()->provision($old, '001565AABBCC.cfg', $request);
    oryk_check('old token stops working', false);
} catch (\Oryk\Provisioner\Exception\NotFoundException $e) {
    oryk_check('old token stops working', true);
}

$logs = $provisioner->logs()->recent(array('limit' => 50));
oryk_check('requests are logged', count($logs) >= 5, count($logs));

$hasSecret = false;

foreach ($logs as $row) {
    if (strpos(json_encode($row), 'secret123') !== false) {
        $hasSecret = true;
    }
}

oryk_check('log never contains credentials', !$hasSecret);
oryk_check('device last provisioned recorded', $provisioner->devices()->find($device->id, false)->lastProvisionedIp === '192.168.1.50');

// ------------------------------------------------------------------ templates
echo "\nTemplate management\n";
$copy = $provisioner->templates()->duplicate($yealink->id);
oryk_check('duplicate slug is unique', $copy->slug === 'yealink-t5x-copy', $copy->slug);
oryk_check('duplicate keeps outputs', count($copy->outputs) === 2);

try {
    $provisioner->templates()->delete($yealink->id);
    oryk_check('template in use cannot be deleted', false);
} catch (\Oryk\Provisioner\Exception\ValidationException $e) {
    oryk_check('template in use cannot be deleted', true);
}

oryk_check('unused template deletes', $provisioner->templates()->delete($copy->id) === true);

$export = $yealink->toExportArray();
oryk_check('export has no ids', !isset($export['id']) && !isset($export['outputs'][0]['id']));
