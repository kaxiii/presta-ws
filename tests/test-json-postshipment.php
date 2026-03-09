<?php 

require_once __DIR__ . '/../functions/postshipment.php';

//echo getPostshipmentDataAsJson();

$result = savePostshipmentDataToJsonFile();

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

?>