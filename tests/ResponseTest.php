<?php 
use PHPUnit\Framework\TestCase;

/**
*  Corresponding Class to test Response class
*
*  For each class in your library, there should be a corresponding Unit-Test for it
*  Unit-Tests should be as much as possible independent from other test going on.
*
*  @author yourname
*/
class ResponseTest extends TestCase
{
	
  /**
  * Just check if the Response has no syntax error 
  *
  * This is just a simple check to make sure your library has no syntax error. This helps you troubleshoot
  * any typo before you even use this library in a real project.
  *
  */
  public function testIsThereAnySyntaxError()
  {
	$var = new FaulkJ\DB\Response(false, "This is a test", 1);
	$this->assertTrue(is_object($var));
	unset($var);
  }
  
  /**
  * Just check if the Response has no syntax error 
  *
  * This is just a simple check to make sure your library has no syntax error. This helps you troubleshoot
  * any typo before you even use this library in a real project.
  *
  */
  public function testRetrieveResult()
  {
	$var = new FaulkJ\DB\Response(false, "This is a test", 1);
	$this->assertTrue($var->result == "This is a test");
	unset($var);
  } 
}
