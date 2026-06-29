<?php
$schemaBlocks = [];
foreach ($_POST['global_schema_blocks'] ?? [] as $type => $blockData) {
    $type = preg_replace('/[^A-Za-z]/', '', (string)$type);
    if (!$type) continue;
    $enabled = !empty($blockData['enabled']);
    $json    = trim($blockData['json'] ?? '');
    if ($json !== '' && json_decode($json) === null) {
        $json = $data['seo']['global_schema_blocks'][$type]['json'] ?? '';
        if ($message === '') $message = 'error:Invalid JSON in ' . $type . ' schema. Other changes were saved.';
    }
    $schemaBlocks[$type] = ['enabled' => $enabled, 'json' => $json];
}
$data['seo']['global_schema_blocks'] = $schemaBlocks;
$activeTab = 'seo';
