<?php
require dirname(__FILE__) . '/access.php';
$data = json_decode(file_get_contents('/config/usg-services/server-public.json'), true);
usgJson($data, 200);
