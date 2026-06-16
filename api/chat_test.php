<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
echo json_encode(['reply' => 'FILE_REACHED_OK - chat.php is loading correctly', 'suggests_whatsapp' => false, 'quick_replies' => ['It works!']]);
