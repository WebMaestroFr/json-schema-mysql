<?php
use PHPUnit\Framework\TestCase;

final class JsonSchemaSqlTest extends TestCase
{
    public $c;
    public $className = "Bumip\JsonSchema\JsonSchemaSql";
    public function setUp():void
    {
        $pdo = new PDO("sqlite:tests/database/dbtest.db");
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);//Error Handling
        $this->c = new $this->className($pdo);
    }
    /** @test */
    public function testClassisCorrect()
    {
        $this->assertEquals(get_class($this->c), $this->className);
    }
    public function testCreateTableFromJSON()
    {
        $file = "tests/schemas/person.json";
        $json = $this->c->createTableFromFile($file, null, true);
        $this->assertTrue(is_object($json));
    }
    public function testGetSqlDefinitionsFromJson()
    {
        $file = "tests/schemas/person.json";
        $json = $this->c->createTableFromFile($file, null, true);
        $sql = $this->c->getSqlDefinitions($json, 'persons');
        $this->assertTrue(is_array($sql));
    }
    public function testGetSqlTableFromJson()
    {
        $file = "tests/schemas/person.json";
        $json = $this->c->createTableFromFile($file, null, true);
        $sql = $this->c->createTable($json, 'persons', true);
        $this->assertTrue(is_string($sql));
    }
    public function testInsertSqlTableFromJson()
    {
        $file = "tests/schemas/person.json";
        $json = $this->c->createTableFromFile($file, null, true);
        $sql = $this->c->createTable($json, 'persons');
        $this->assertTrue(is_object($sql));
    }
    public function testGetSqlTableFromFile()
    {
        $file = "tests/schemas/person.json";
        $sql = $this->c->createTableFromFile($file, null, 'getSql');
        $this->assertTrue(is_string($sql));
    }
}
