<?php

namespace FaulkJ;
/*
    * DB Control Class for SQLSRV v1.8
    *
    * Kopimi 2024 Joshua Faulkenberry
    * Unlicensed under The Unlicense
    * http://unlicense.org/
    */

class DB {

   const     version      = "1.8";

   protected $server      = null;
   protected $client      = null;
   protected $error       = null;
   protected $sql         = null;
   protected $transactive = false;
   protected $transfail   = false;
   protected $debugging   = false;

   private   $countdown   = null;
   private   $andthen     = null;
   private   $persist     = false;
   private   string $host;
   private   string $db;
   private   string $user;
   private   string $password;
   private   $connection;

   /**
    * Constructs a new DB instance.
    * @param string $host The database host.
    * @param string $db The database name.
    * @param string $user The username for authentication.
    * @param string $password The password for authentication.
    */

   public function __construct(string $host, string $db, string $user, string $password) {
      foreach (get_defined_vars() as $k => $v) if (!$v) trigger_error("Undefined $k", E_USER_ERROR);
      $this->host     = $host;
      $this->db       = $db;
      $this->user     = $user;
      $this->password = $password;
   }

   public function __get($item) {
      try {
         $field = new \ReflectionProperty(get_class($this), $item);
      } catch (\Exception $e) {
         throw new \Exception("'$item' is not a valid property");
      }
      if ($field->isPrivate()) throw new \Exception("That's private");
      return $this->$item;
   }

   public function connect(bool $persist = true, array $options = []): bool {
      sqlsrv_configure("WarningsReturnAsErrors", $this->debugging);
      $this->debug("Open connection...\n          \Server:    $this->host\n           \Database: $this->db\n", $persist ? "wrap" : "open");
      if ($this->connection = sqlsrv_connect($this->host, array_merge([
         "Database"             => $this->db,
         "UID"                  => $this->user,
         "PWD"                  => $this->password,
         "CharacterSet"         => "UTF-8",
         "ReturnDatesAsStrings" => true
      ], $options))) {
         $this->server  = (object) sqlsrv_server_info($this->connection);
         $this->client  = (object) sqlsrv_client_info($this->connection);
         $this->persist = $persist;

         return true;
      }
      $this->checkErrors("Connection failed");
      return false;
   }

   public function close(): bool {
      $this->debug("Close Connection.", $this->persist ? "wrap" : "close");
      $this->server  = null;
      $this->client  = null;
      $this->persist = false;
      if (sqlsrv_close($this->connection)) {
         $this->connection = null;
         return true;
      }
      return false;
   }

   /**
    * Executes a SQL query or multiple queries on the database.
    *
    * @param mixed $sql         The SQL query string or an array of query strings.
    * @param bool  $singleRow   If true, returns only the first row of the result set.
    * @param bool  $singleCol   If true, returns only the first column of the result set.
    * @param bool  $singleSet   If true, returns only the last result set if multiple are returned.
    *
    * @return \FaulkJ\DB\Response|\FaulkJ\DB\Response[]
    *         - \FaulkJ\DB\Response object if a single query is executed.
    *         - An array of \FaulkJ\DB\Response objects if multiple queries are executed.
    *         - Each \FaulkJ\DB\Response object contains:
    *           - success (bool): Indicates whether the query was successful.
    *           - result (mixed): The result of the query, can be null, an object, or an array.
    *           - rowsAffected (int, optional): The number of rows affected by the query.
    *           - error (mixed, optional): Error information if the query failed.
    *
    * @throws \Exception If the provided SQL is not a string or array of strings.
    */

   public function query($sql, bool $singleRow = false, bool $singleCol = false, bool $singleSet = false): \FaulkJ\DB\Response|array {
      $dbq = function ($sql, $singleRow, $singleCol, $singleSet) {
         $fail     = false;
         $affected = -1;
         $results  = [];
         $err      = null;

         array_push($this->sql, $sql);

         if (gettype($sql) == 'resource' && get_resource_type($sql) === 'SQL Server Statement') $stmt = $sql;
         else if (is_string($sql)) {
            $this->debug("Query...\n" . str_replace("<", "&lt;", $sql));
            $stmt = sqlsrv_query($this->connection, $sql);
         } else return new \FaulkJ\DB\Response(true, null, -1, (array) "Invalid query type");

         if ($stmt) {
            do {
               if ($singleRow === true) {
                  if ($singleCol) {
                     $col = sqlsrv_fetch_array($stmt);
                     $results[] = isset($col[0]) ? $col[0] : null;
                  } else $results[] = sqlsrv_fetch_object($stmt);
               } else {
                  $subResults = [];
                  while ($obj = sqlsrv_fetch_object($stmt)) {
                     if ($singleCol) {
                        foreach ($obj as $a) {
                           if (is_string($singleRow) && isset($obj->$singleRow)) $subResults[$obj->$singleRow] = $a;
                           else $subResults[] = $a;
                        }
                     } else {
                        if (is_string($singleRow) && isset($obj->$singleRow)) $subResults[$obj->$singleRow] = $obj;
                        else $subResults[] = $obj;
                     }
                  }
                  $results[] = $subResults;
               }
            } while (sqlsrv_next_result($stmt));
            if ($singleSet) $results = $results[count($results) - 1];
            if (is_array($results) && count($results) == 1) $results = $results[0];

            $affected = sqlsrv_rows_affected($stmt);
         } else {
            $err = $this->checkErrors("Query failed", $sql);
            $fail = true;
         }

         return new DB\Response($fail, $results, $affected, $err);
      };

      if ($this->transactive && $this->error !== null) $this->transfail = true;
      if ($this->transfail) return new DB\Response(true, null, -1, array_merge(["Transaction Failed"], $this->error));
      if (!$this->connection) $this->connect(false);
      if (!$this->connection) return new DB\Response(true, null, -1, (array) "Unable to establish connection to SQL server.");
      $dbq->bindTo($this);
      if (!is_array($this->sql)) $this->sql = [];
      $sql = (array) $sql;
      $list = [];
      foreach ($sql as $qs) {
         $out = $dbq($qs, $singleRow, $singleCol, $singleSet);
         if ($out->success) $list[] = $out;
         else return new DB\Response(true, null, 0, array_merge((array) $this->error, count($sql) <= 1 ? [] : ["All previous queries executed successfully."]));
      }
      if (!$this->transactive && !$this->persist) $this->close();

      if ($this->countdown !== null) {
         if ($this->countdown == -1) {
            $this->debugging = true;
            $this->countdown = $this->andthen;
            $this->andthen = null;
         } else if ($this->countdown < 0) $this->countdown++;
         else if ($this->countdown == 1) $this->debug(false);
         else if ($this->countdown > 0) $this->countdown--;
         else $this->debug(false);
      }

      return count($list) == 1 ? $list[0] : $list;
   }

   /**
    * Executes a parameterized SQL query on the database.
    *
    * @param string $sql          The SQL query string with placeholders for parameters.
    * @param array  $params       An array of parameters to be bound to the query.
    * @param bool   $singleRow    If true, returns only the first row of the result set.
    * @param bool   $singleCol    If true, returns only the first column of the result set.
    * @param bool   $singleSet    If true, returns only the last result set if multiple are returned.
    *
    * @return \FaulkJ\DB\Response|\FaulkJ\DB\Response[]
    *         - \FaulkJ\DB\Response object if the query is executed successfully.
    *         - An array of \FaulkJ\DB\Response objects if multiple queries are executed.
    *         - Each \FaulkJ\DB\Response object contains:
    *           - success (bool): Indicates whether the query was successful.
    *           - result (mixed): The result of the query, can be null, an object, or an array.
    *           - rowsAffected (int, optional): The number of rows affected by the query.
    *           - error (mixed, optional): Error information if the query failed.
    *
    * @throws \Exception If the provided SQL is not a string or if the query execution fails.
    */

   public function pQuery(string $sql, array $params = [], bool $singleRow = false, bool $singleCol = false, bool $singleSet = false): \FaulkJ\DB\Response|array {
      if (!$this->connection) $this->connect(false);
      if (!$this->connection) return new DB\Response(true, null, -1, (array) "Unable to establish connection to SQL server.");
      $stmt = sqlsrv_prepare($this->connection, $sql, $params);
      ob_start();
      var_dump($params);
      $dump = ob_get_contents();
      ob_end_clean();
      $this->debug("Unprepared SQL: $sql\n\n         Params: $dump");
      if ($stmt === false) {
         $errorDetails = $this->checkErrors("Preparing statement failed");
         return new \FaulkJ\DB\Response(true, null, -1, $errorDetails);
      }
      if (sqlsrv_execute($stmt)) return $this->query($stmt, $singleRow, $singleCol, $singleSet);
      else {
         $errorDetails = $this->checkErrors("Preparing statement failed");
         return new \FaulkJ\DB\Response(true, null, -1, $errorDetails);
      }
   }

   public function transaction(string $action = "begin"): bool {
      switch ($action) {
         default:
         case "begin":
            $this->transactive = true;
            $this->transfail = false;
            if ($this->connect(false)) {
               $this->debug("Begin transaction...", "close");
               if (!sqlsrv_begin_transaction($this->connection)) {
                  $this->checkErrors("Trasaction init failed");
                  return false;
               }
            }
            break;
         case "commit":
            if (!$this->transactive) $this->debug("No active transaction to commit!", "wrap");
            else {
               $this->transactive = false;
               if ($this->transfail || $this->error !== null) $this->transaction("rollback");
               else if ($this->connection) {
                  $this->debug("Commit transaction.\n", "open");
                  if (sqlsrv_commit($this->connection)) $this->close();
                  else {
                     $this->checkErrors("Commit failed");
                     return false;
                  }
               }
            }
            break;
         case "rollback":
            if (!$this->transactive) $this->debug("No active transaction to roll back!", "wrap");
            else {
               $this->transactive = false;
               $this->transfail = false;
               if ($this->connection) {
                  $this->debug("Rollback transaction.\n", "open");
                  if (sqlsrv_rollback($this->connection)) $this->close();
                  else {
                     $this->checkErrors("Rollback failed");
                     return false;
                  }
               }
            }
            break;
      }
      return true;
   }

   public function transact(array $queries): bool {
      if ($queries && count($queries) && $this->transaction("begin")) {
         $this->query($queries);
         if ($this->error !== null) {
            if ($this->transaction("rollback")) return false;
            else array_push($this->error, "Rollback failed.");
         } else return $this->transaction("commit");
      } else return false;
   }

   public function debug($on = true, $andthen = null): self {
      if (is_string($on)) {
         if ($this->debugging) {
            $log = date("H:i:s") . " $on";
            if ($this->transactive || $this->persist || $andthen == "wrap") echo "<pre>$log</pre>\n\n";
            else echo ($andthen == "open" ? "<pre>" : "") . "\n$log" . ($andthen == "close" ? "</pre>\n\n" : "");
         }
      } else {
         $this->countdown = null;
         $this->andthen = null;
         $this->debugging = false;
         if (is_numeric($on)) {
            $this->countdown = round($on);
            if ($on > 0) $this->debugging = true;
            else $this->andthen = round($andthen);
         } else if ($on) $this->debugging = true;
      }
      return $this;
   }

   private function checkErrors(string $lbl, $sql = null): ?array {
      if (is_array(sqlsrv_errors()) && ($errors = array_filter(sqlsrv_errors(), function ($obj) {
         static $idList = [];
         if (in_array(json_encode($obj), $idList)) return false;
         $idList[] = json_encode($obj);
         return true;
      })) != null) {
         if (!is_array($this->error)) $this->error = [];
         $dbg = [];
         foreach ($errors as $error) {
            $msg = $error["message"];
            if ($sql) $msg .= "\nIn query:\n$sql";
            if ($error['message']) {
               array_push($this->error, $msg);
               if (!in_array($error['message'], $dbg)) array_push($dbg, $error['message']);
            }
         }
         $this->debug("$lbl:\n          \\" . implode("\n\n", (array) $dbg), "close");
         return $this->error;
      }
   }

   /**
    * Sanitizes the input to prevent SQL injection.
    * @param mixed $inp The input to sanitize.
    * @return mixed The sanitized input.
    */

   static function sanitize(mixed $inp): mixed {
      if (is_string($inp)) return str_replace(["\\", "'", "\x00", "\n", "\r", "\x1a"], ["\\\\", "''", "\\0", "\\n", "\\r", "\\Z"], $inp);
      else if (is_array($inp) || is_object($inp)) {
         foreach ($inp as &$i) $i = self::sanitize($i);
         return $inp;
      } else return $inp;
   }
}
