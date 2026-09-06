<?php
/**
 * Admin AJAX tests: every command answers with the shared response contract.
 */

require_once __DIR__ . '/bootstrap.php';

use Oryk\Provisioner\Admin\AjaxController;
use Oryk\Provisioner\Admin\View;
use Oryk\Provisioner\Model\Device;

echo "\nAdmin AJAX\n";

$provisioner = oryk_test_container();
$provisioner->seeder()->seed();

$template = $provisioner->templates()->findBySlug('generic-softphone');
$device = $provisioner->devices()->save(Device::fromArray(array(
    'name' => 'Alain Softphone',
    'templateId' => $template->id,
    'extension' => '1001',
)));

$controller = new AjaxController($provisioner, new View(dirname(__DIR__) . '/views'));

$cases = array(
    array('getDevices', array()),
    array('getDevice', array('id' => $device->id)),
    array('deviceForm', array('id' => 0)),
    array('deviceForm', array('id' => $device->id)),
    array('saveDevice', array('payload' => json_encode(array(
        'name' => 'Second Phone', 'templateId' => $template->id, 'extension' => '1002', 'enabled' => 1,
        'parameters' => array('sip.transport' => 'tls'),
    )))),
    array('previewDevice', array('id' => $device->id)),
    array('previewDevice', array('id' => $device->id, 'reveal' => 1)),
    array('deviceUrls', array('id' => $device->id)),
    array('toggleDevice', array('id' => $device->id, 'enabled' => 0)),
    array('toggleDevice', array('id' => $device->id, 'enabled' => 1)),
    array('regenerateToken', array('id' => $device->id)),
    array('getTemplates', array()),
    array('getTemplate', array('id' => $template->id)),
    array('templateForm', array('id' => 0)),
    array('templateForm', array('id' => $template->id)),
    array('templateSchema', array('id' => $template->id)),
    array('exportTemplate', array('id' => $template->id)),
    array('saveTemplate', array('payload' => json_encode(array(
        'name' => 'Test Template', 'vendor' => 'acme',
        'defaults' => array('sip.port' => 5060),
        'parameters' => array('sip.username' => array('type' => 'string', 'required' => true)),
        'outputs' => array(array('filename' => 'cfg.txt', 'contentType' => 'text/plain', 'template' => 'u={{sip.username}}')),
    )))),
    array('cloneTemplate', array('id' => $template->id)),
    array('importTemplate', array('json' => json_encode(array(
        'name' => 'Imported', 'slug' => 'imported', 'outputs' => array(
            array('filename' => 'a.json', 'contentType' => 'application/json', 'template' => '{}'),
        ),
    )))),
    array('seedTemplates', array()),
    array('getLogs', array()),
    array('clearLogs', array()),
    array('getSettings', array()),
    array('saveSettings', array('payload' => json_encode(array(
        'server_address' => 'pbx.example.com', 'sip_transport' => 'tls', 'log_enabled' => 1,
    )))),
    array('getExtensions', array()),
    array('getSummary', array()),
    array('deleteDevice', array('id' => 2)),
    array('nonExistentCommand', array()),
);

foreach ($cases as $case) {
    list($command, $request) = $case;
    $controller->setRequest(array_merge(array('command' => $command), $request));
    $response = $controller->handle($command);

    $expectFailure = ($command === 'nonExistentCommand');
    $ok = is_array($response) && array_key_exists('status', $response)
        && ($expectFailure ? $response['status'] === false : $response['status'] === true);

    oryk_check('command ' . $command, $ok, json_encode($response));
}

// Settings really persisted?
$saved = $provisioner->settings()->refresh();
$persisted = $saved['server_address'] === 'pbx.example.com' && $saved['sip_transport'] === 'tls';
oryk_check('settings persisted', $persisted);

// Unchecked boolean settings must come back off.
$controller->setRequest(array('command' => 'saveSettings', 'payload' => json_encode(array('server_address' => 'x'))));
$controller->handle('saveSettings');
$after = $provisioner->settings()->refresh();
oryk_check('unchecked booleans clear', $after['log_enabled'] === false);
