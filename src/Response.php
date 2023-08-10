<?php namespace FaulkJ\DB;
   /*
    * DB Response Class for SQLSRV v1.7
    *
    * Kopimi 2023 Joshua Faulkenberry
    * Unlicensed under The Unlicense
    * http://unlicense.org/
    */

   class Response {
      private $success;
      private $_data;

      public function __construct($fail, $results, $affected = 0, $err = null) {
         $this->success = !$fail;
         $this->_data["result"] = $results && count((array) $results) ? $results : null;
         if($affected >= 0) $this->_data["rowsAffected"] = $affected;
         if($err) $this->_data["error"] = $err;
      }

      public function __get($prop) {
         if(property_exists($this, $prop)) return $this->$prop;
         if(array_key_exists($prop, $this->_data)) return $this->_data[$prop];
         else throw new \Exception("'$prop' does not exist");
      }

      public function __isset($prop) {
         return property_exists($this, $prop) || array_key_exists($prop, $this->_data);
      }

      public function __set($prop, $value) {
         throw new \Exception("Can't modify a response");
      }
   }

?>