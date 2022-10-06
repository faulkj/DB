# DB

A wrapper class for SQLSRV (MS SQL) in PHP


```php
use FaulkJ\DB;

$db = new DB("myhost", "mydb", "username", "password");

$data = $db->query("SELECT * FROM mytable");
if(!$list->success) echo(implode("\n", $list->error));
else echo(json_encode($list->result));
```