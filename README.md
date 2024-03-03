# DB

A wrapper class for SQLSRV (MS SQL) in PHP


```php
use FaulkJ\DB;

$db = new DB("myhost", "mydb", "username", "password");

$respose = $db->query("SELECT * FROM mytable");
if(!$respose->success) echo(implode("\n", $respose->error));
else echo(json_encode($respose->result));


$params = [
   "apples",
   "oranges",
   12.34,
   5,
   true,
   null,
   321
];

if($dp->pQuery("
   UPDATE mytable
      SET favorite = ?
      , leastFavorite = ?
      , cost = ?,
      , count = ?
      , active = ?
      , nullable = ?
      WHERE id = ?
", $params)->success) {
   echo "Table updated";
 }
```