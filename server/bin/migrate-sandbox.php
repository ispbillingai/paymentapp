<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/src/Db.php';
$sql=file_get_contents(dirname(__DIR__).'/sandbox.sql');
if(!in_array('--apply',$argv,true)){echo $sql;echo "\nDry run. Use --apply to create sandbox tables. No live payment tables are changed.\n";exit;}
foreach(array_filter(array_map('trim',explode(';',$sql))) as $statement)Db::pdo()->exec($statement);
echo "Sandbox schema ready. Existing records preserved.\n";
