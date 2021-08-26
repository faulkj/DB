<?php namespace FaulkJ\DB;
   /*
    * DB Control Class for SQLSRV v1.5
    *
    * Kopimi 2021 Joshua Faulkenberry
    * Unlicensed under The Unlicense
    * http://unlicense.org/
    */

   class DB {

      const     version      = "1.5";

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
      private   $host;
      private   $db;
      private   $user;
      private   $password;
      private   $connection;

      public function __construct($host, $db, $user, $password) {
         foreach(get_defined_vars() as $k => $v) if(!$v) trigger_error("Undefined $k", E_USER_ERROR);
         $this->host     = $host;
         $this->db       = $db;
         $this->user     = $user;
         $this->password = $password;
      }

      public function __get($item) {
         try {
            $field = new ReflectionProperty(get_class($this), $item);
         }
         catch(Exception $e) {
            trigger_error("'$item' is not a valid property", E_USER_ERROR);
         }
         if($field->isPrivate()) trigger_error("That's private", E_USER_ERROR);
         return $this->$item;
      }

      public function connect($persist = true) {
         sqlsrv_configure("WarningsReturnAsErrors", $this->debugging);
         $this->debug("Open connection...\n          \Server:    $this->host\n           \Database: $this->db\n", $persist ? "wrap" : "open");
         if($this->connection = sqlsrv_connect($this->host, [
            "Database"     => $this->db,
            "UID"          => $this->user,
            "PWD"          => $this->password,
            "CharacterSet" => "UTF-8"
         ])) {
            $this->server  = (object) sqlsrv_server_info($this->connection);
            $this->client  = (object) sqlsrv_client_info($this->connection);
            $this->persist = $persist;

            return true;
         }
         $this->checkErrors("Connection failed");
         return false;
      }

      public function close() {
         $this->debug("Close Connection.", $this->persist ? "wrap" : "close");
         $this->server  = null;
         $this->client  = null;
         $this->persist = false;
         if(sqlsrv_close($this->connection)) {
            $this->connection = null;
            return true;
         }
         return false;
      }

      public function query($sql, $singleRow = false, $singleCol = false) {
         $dbq = function($sql, $singleRow, $singleCol) {
            $fail     = false;
            $affected = -1;
            $results  = null;
            $err      = null;

            array_push($this->sql, $sql);
            $this->debug("Query...\n" . str_replace("<", "&lt;", $sql));
            $stmt = sqlsrv_query($this->connection, $sql);
            if($stmt) {
               if(is_bool($singleRow) && $singleRow) {
                  if($singleCol) {
                     $col = sqlsrv_fetch_array($stmt);
                     $results = isset($col[0]) ? $col[0] : null;
                  }
                  else $results = sqlsrv_fetch_object($stmt);
               }
               else {
                  $results = array();
                  while($obj = sqlsrv_fetch_object($stmt)) {
                     if($singleCol) {
                        foreach($obj as $a) {
                           if(is_string($singleRow) && isset($obj->$singleRow)) $results[$obj->$singleRow] = $a;
                           else array_push($results, $a);
                        }
                     }
                     else {
                        if(is_string($singleRow) && isset($obj->$singleRow)) $results[$obj->$singleRow] = $obj;
                        else array_push($results, $obj);
                     }
                  }
               }
               $affected = sqlsrv_rows_affected($stmt);
            }
            else {
               $err = $this->checkErrors("Query failed", $sql);
               $fail = true;
            }

            return new Response($fail, $results, $affected, $err);
         };

         if($this->transactive && $this->error !== null) $this->transfail = true;
         if($this->transfail) return new Response(true, null, null, array_merge(["Transaction Failed"], $this->error));
         if(!$this->connection) $this->connect(false);
         if(!$this->connection) return new Response(true, null, null, "Unable to establish connection to SQL server.");
         $dbq->bindTo($this);
         if(!is_array($this->sql)) $this->sql = array();
         $sql = (array) $sql;
         $list = array();
         foreach($sql as $qs) {
            $out = $dbq($qs, $singleRow, $singleCol);
            if($out->success) array_push($list, $out);
            else return new Response(true, null, null, array_merge((array) $this->error, count($sql) <= 1 ? [] : ["All previous queries executed successfully."]));
         }
         if(!$this->transactive && !$this->persist) $this->close();

         if($this->countdown !== null) {
            if($this->countdown == -1) {
               $this->debugging = true;
               $this->countdown = $this->andthen;
               $this->andthen = null;
            }
            else if($this->countdown < 0) $this->countdown++;
            else if($this->countdown == 1) $this->debug(false);
            else if($this->countdown > 0) $this->countdown--;
            else $this->debug(false);
         }

         return count($list) == 1 ? $list[0] : $list;
      }

      public function transaction($action = "begin") {
         switch($action) {
            default:
            case "begin":
               $this->transactive = true;
               $this->transfail = false;
               if($this->connect(false)) {
                  $this->debug("Begin transaction...", "close");
                  if(!sqlsrv_begin_transaction($this->connection)) {
                     $this->checkErrors("Trasaction init failed");
                     return false;
                  }
               }
               break;
            case "commit":
               if(!$this->transactive) $this->debug("No active transaction to commit!", "wrap");
               else {
                  $this->transactive = false;
                  if($this->transfail || $this->error !== null) $this->transaction("rollback");
                  else if($this->connection) {
                     $this->debug("Commit transaction.\n", "open");
                     if(sqlsrv_commit($this->connection)) $this->close();
                     else {
                        $this->checkErrors("Commit failed");
                        return false;
                     }
                  }
               }
               break;
            case "rollback":
               if(!$this->transactive) $this->debug("No active transaction to roll back!", "wrap");
               else {
                  $this->transactive = false;
                  $this->transfail = false;
                  if($this->connection) {
                     $this->debug("Rollback transaction.\n", "open");
                     if(sqlsrv_rollback($this->connection)) $this->close();
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

      public function transact($queries) {
         if($queries && count($queries) && $this->transaction("begin")) {
            $this->query($queries);
            if($this->error !== null) {
               if($this->transaction("rollback")) return false;
               else array_push($this->error, "Rollback failed.");
            }
            else return $this->transaction("commit");
         }
         else return false;
      }

      public function q($sql, $singleRow = false, $singleCol = false) {
         return $this->query($sql, $singleRow, $singleCol);
      }

      public function t($action = "begin") {
         return $this->transaction($action);
      }

      public function debug($on = true, $andthen = null) {
         if(is_string($on)) {
            if($this->debugging) {
               $log = date("H:i:s") . " $on";
               if($this->transactive || $this->persist || $andthen == "wrap") echo "<pre>$log</pre>\n\n";
               else echo ($andthen == "open" ? "<pre>" : "") . "\n$log" . ($andthen == "close" ? "</pre>\n\n" : "");
            }
         }
         else {
            $this->countdown = null;
            $this->andthen = null;
            $this->debugging = false;
            if(is_numeric($on)) {
               $this->countdown = round($on);
               if($on > 0) $this->debugging = true;
               else $this->andthen = round($andthen);
            }
            else if($on) $this->debugging = true;
         }
         return $this;
      }

      private function checkErrors($lbl, $sql = null) {
         if(is_array(sqlsrv_errors()) && ($errors = array_filter(sqlsrv_errors(), function($obj) {
            static $idList = array();
            if(in_array(json_encode($obj), $idList)) return false;
            $idList[] = json_encode($obj);
            return true;
         })) != null) {
            if(!is_array($this->error)) $this->error = [];
            $dbg = [];
            foreach($errors as $error) {
               $msg = $error["message"];
               if($sql) $msg .= "\nIn query:\n$sql";
               if($error['message']) {
                  array_push($this->error, $msg);
                  if(!in_array($error['message'], $dbg)) array_push($dbg, $error['message']);
               }
            }
            $this->debug("$lbl:\n          \\" . implode($dbg, "\n\n"), "close");
            return $this->error;
         }
      }

      static function sanitize($inp) {
         if(is_string($inp)) return str_replace("'", "''", $inp);
         else if(!is_array($inp) && !is_object($inp)) return $inp;
         foreach($inp as &$i) {
            if(is_string($i)) $i = str_replace("'", "''", $i);
            else if(is_array($i) || is_object($i)) $i = DB::sanitize($i);
         }
         return $inp;
      }
   }

?>