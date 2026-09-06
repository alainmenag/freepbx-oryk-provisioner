<?php
/**
 * View tests: every admin view renders and produces balanced markup.
 */

require_once __DIR__ . '/bootstrap.php';

use Oryk\Provisioner\Admin\View;
use Oryk\Provisioner\Model\Device;

echo "\nViews\n";

$provisioner = oryk_test_container();
$provisioner->seeder()->seed();

$template = $provisioner->templates()->findBySlug('yealink-t5x');
$device = $provisioner->devices()->save(Device::fromArray(array(
    'name' => 'Office T54W',
    'templateId' => $template->id,
    'extension' => '1001',
    'mac' => '001565AABBCC',
    'parameters' => array('sip.transport' => 'tls'),
)));

$view = new View(dirname(__DIR__) . '/views');
$context = $provisioner->engine()->contextFor($device);
$files = $provisioner->engine()->renderAll($device, $context);

$renders = array(
    'main' => array('main', array(
        'settings' => $provisioner->settings()->all(),
        'templates' => $provisioner->templates()->all(),
        'vendors' => $provisioner->templates()->vendors(),
        'friendly' => array('path' => '/var/www/html/provisioner', 'installed' => false, 'writable' => true, 'enabled' => false),
        'baseUrl' => 'https://pbx.example.com',
        'moduleName' => 'oryk_provisioner',
    )),
    'devices/form' => array('devices/form', array(
        'device' => $device,
        'templates' => $provisioner->templates()->all(),
        'selected' => $template,
        'extensions' => array(array('extension' => '1001', 'name' => 'Alain')),
        'schema' => $template->schema->toList(),
        'urls' => array(array(
            'filename' => '001565AABBCC.cfg',
            'template' => '{{device.mac}}.cfg',
            'contentType' => 'text/plain',
            'urls' => array('direct' => 'https://pbx/x', 'config' => 'https://pbx/y'),
        )),
    )),
    'devices/parameters' => array('devices/parameters', array(
        'schema' => $template->schema->toList(),
        'parameters' => $device->parameters,
        'template' => $template,
    )),
    'devices/preview' => array('devices/preview', array(
        'device' => $device,
        'rows' => $context->toRows(false),
        'files' => $files,
        'urls' => array(),
        'errors' => array(),
        'validation' => array('sip.domain' => 'sip.domain is required'),
        'reveal' => false,
    )),
    'templates/form' => array('templates/form', array(
        'template' => $template,
        'contentTypes' => \Oryk\Provisioner\Model\Output::CONTENT_TYPES,
        'schemaRows' => $template->schema->toList(),
    )),
    'settings/form' => array('settings/form', array(
        'settings' => $provisioner->settings()->all(),
        'friendly' => array('path' => '/var/www/html/provisioner', 'installed' => true, 'writable' => true, 'enabled' => true),
        'baseUrl' => 'https://pbx.example.com',
    )),
);


foreach ($renders as $label => $spec) {
    $html = $view->render($spec[0], $spec[1]);
    $problems = array();

    if (strpos($html, 'Missing view') !== false) {
        $problems[] = 'view file not found';
    }

    if (trim($html) === '') {
        $problems[] = 'empty output';
    }

    foreach (array('div', 'form', 'table', 'select', 'ul') as $tag) {
        $open = preg_match_all('/<' . $tag . '[\s>]/i', $html);
        $close = preg_match_all('/<\/' . $tag . '>/i', $html);

        if ($open !== $close) {
            $problems[] = "$tag unbalanced ($open open / $close close)";
        }
    }

    oryk_check('view ' . $label, empty($problems), implode('; ', $problems));
}

// The device parameter view must not leak a secret's stored value into markup.
$secretDevice = $provisioner->devices()->save(Device::fromArray(array(
    'name' => 'Secret Holder',
    'templateId' => $template->id,
    'parameters' => array('sip.password' => 'topsecret'),
)));
$html = $view->render('devices/parameters', array(
    'schema' => $template->schema->toList(),
    'parameters' => $secretDevice->parameters,
));

oryk_check('secret inputs use type=password', strpos($html, 'type="password"') !== false);
