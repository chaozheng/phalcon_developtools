<?php

/*
  +------------------------------------------------------------------------+
  | Phalcon Developer Tools                                                |
  +------------------------------------------------------------------------+
  | Copyright (c) 2011-2016 Phalcon Team (https://www.phalconphp.com)      |
  +------------------------------------------------------------------------+
  | This source file is subject to the New BSD License that is bundled     |
  | with this package in the file LICENSE.txt.                             |
  |                                                                        |
  | If you did not receive a copy of the license and are unable to         |
  | obtain it through the world-wide-web, please send an email             |
  | to license@phalconphp.com so we can send you a copy immediately.       |
  +------------------------------------------------------------------------+
  | Authors: Andres Gutierrez <andres@phalconphp.com>                      |
  |          Eduar Carvajal <eduar@phalconphp.com>                         |
  +------------------------------------------------------------------------+
*/

namespace Phalcon\Mvc\Model;

use Phalcon\Db;
use Phalcon\Text;
use Phalcon\Utils;
use DirectoryIterator;
use Phalcon\Db\Column;
use Phalcon\Migrations;
use Phalcon\Generator\Snippet;
use Phalcon\Version\ItemInterface;
use Phalcon\Mvc\Model\Migration\Profiler;
use Phalcon\Db\Exception as DbException;
use Phalcon\Events\Manager as EventsManager;
use Phalcon\Exception\Db\UnknownColumnTypeException;
use Phalcon\Version\ItemCollection as VersionCollection;

/**
 * Phalcon\Mvc\Model\Migration
 *
 * Migrations of DML y DDL over databases
 *
 * @package Phalcon\Mvc\Model
 */
class Migration
{
    const DIRECTION_FORWARD = 1;
    const DIRECTION_BACK = -1;

    /**
     * Migration database connection
     * @var \Phalcon\Db\AdapterInterface
     */
    protected static $_connection;

    /**
     * Database configuration
     * @var \Phalcon\Config
     */
    private static $_databaseConfig;

    /**
     * Path where to save the migration
     * @var string
     */
    private static $_migrationPath = null;

    /**
     * Skip auto increment
     * @var bool
     */
    private static $_skipAI = false;

    /**
     * Version of the migration file
     *
     * @var string
     */
    protected $_version = null;

    /**
     * Prepares component
     *
     * @param \Phalcon\Config $database Database config
     *
     * @throws \Phalcon\Db\Exception
     */
    public static function setup($database)
    {
        if (!isset($database->adapter)) {
            throw new DbException('Unspecified database Adapter in your configuration!');
        }

        $adapter = '\\Phalcon\\Db\\Adapter\\Pdo\\' . $database->adapter;

        if (!class_exists($adapter)) {
            throw new DbException('Invalid database Adapter!');
        }

        $configArray = $database->toArray();
        unset($configArray['adapter']);
        self::$_connection = new $adapter($configArray);
        self::$_databaseConfig = $database;

        if ($database->adapter == 'Mysql') {
            self::$_connection->query('SET FOREIGN_KEY_CHECKS=0');
        }

        if (Migrations::isConsole()) {
            $profiler = new Profiler();

            $eventsManager = new EventsManager();
            $eventsManager->attach(
                'db',
                function ($event, $connection) use ($profiler) {
                    if ($event->getType() == 'beforeQuery') {
                        $profiler->startProfile($connection->getSQLStatement());
                    }
                    if ($event->getType() == 'afterQuery') {
                        $profiler->stopProfile();
                    }
                }
            );

            self::$_connection->setEventsManager($eventsManager);
        }
    }

    /**
     * Set the skip auto increment value
     *
     * @param string $skip
     */
    public static function setSkipAutoIncrement($skip)
    {
        self::$_skipAI = $skip;
    }

    /**
     * Set the migration directory path
     *
     * @param string $path
     */
    public static function setMigrationPath($path)
    {
        self::$_migrationPath = rtrim($path, '\\/') . DIRECTORY_SEPARATOR;
    }

    /**
     * Generates all the class migration definitions for certain database setup
     *
     * @param  ItemInterface $version
     * @param  string $exportData
     *
     * @return array
     */
    public static function generateAll($exportData = null)
    {
        $classDefinition = [];
        $schema = Utils::resolveDbSchema(self::$_databaseConfig);

        foreach (self::$_connection->listTables($schema) as $table) {
            if ($table != 'migrations') {
                $classDefinition[$table] = self::generate($table, $exportData);
            }
        }

        return $classDefinition;
    }

    /**
     * Returns database name
     *
     * @return mixed
     */
    public static function getDbName()
    {
        return self::$_databaseConfig->get('dbname');
    }

    /**
     * Generate specified table migration
     *
     * @param ItemInterface $version
     * @param string $table
     * @param mixed $exportData
     *
     * @return string
     * @throws \Phalcon\Db\Exception
     */
    public static function generate($table, $exportData = null)
    {
        if ($table == 'migrations') {
            return false;
        }

        $oldColumn = null;
        $allFields = [];
        $numericFields = [];
        $tableDefinition = [];
        $snippet = new Snippet();

        $defaultSchema = Utils::resolveDbSchema(self::$_databaseConfig);
        $description = self::$_connection->describeColumns($table, $defaultSchema);

        foreach ($description as $field) {
            /** @var \Phalcon\Db\ColumnInterface $field */
            $fieldDefinition = [];
            switch ($field->getType()) {
                case Column::TYPE_INTEGER:
                    $fieldDefinition[] = "'type' => Column::TYPE_INTEGER";
                    $numericFields[$field->getName()] = true;
                    break;
                case Column::TYPE_VARCHAR:
                    $fieldDefinition[] = "'type' => Column::TYPE_VARCHAR";
                    break;
                case Column::TYPE_CHAR:
                    $fieldDefinition[] = "'type' => Column::TYPE_CHAR";
                    break;
                case Column::TYPE_DATE:
                    $fieldDefinition[] = "'type' => Column::TYPE_DATE";
                    break;
                case Column::TYPE_DATETIME:
                    $fieldDefinition[] = "'type' => Column::TYPE_DATETIME";
                    break;
                case Column::TYPE_TIMESTAMP:
                    $fieldDefinition[] = "'type' => Column::TYPE_TIMESTAMP";
                    break;
                case Column::TYPE_DECIMAL:
                    $fieldDefinition[] = "'type' => Column::TYPE_DECIMAL";
                    $numericFields[$field->getName()] = true;
                    break;
                case Column::TYPE_TEXT:
                    $fieldDefinition[] = "'type' => Column::TYPE_TEXT";
                    break;
                case Column::TYPE_BOOLEAN:
                    $fieldDefinition[] = "'type' => Column::TYPE_BOOLEAN";
                    break;
                case Column::TYPE_FLOAT:
                    $fieldDefinition[] = "'type' => Column::TYPE_FLOAT";
                    break;
                case Column::TYPE_DOUBLE:
                    $fieldDefinition[] = "'type' => Column::TYPE_DOUBLE";
                    break;
                case Column::TYPE_TINYBLOB:
                    $fieldDefinition[] = "'type' => Column::TYPE_TINYBLOB";
                    break;
                case Column::TYPE_BLOB:
                    $fieldDefinition[] = "'type' => Column::TYPE_BLOB";
                    break;
                case Column::TYPE_MEDIUMBLOB:
                    $fieldDefinition[] = "'type' => Column::TYPE_MEDIUMBLOB";
                    break;
                case Column::TYPE_LONGBLOB:
                    $fieldDefinition[] = "'type' => Column::TYPE_LONGBLOB";
                    break;
                case Column::TYPE_JSON:
                    $fieldDefinition[] = "'type' => Column::TYPE_JSON";
                    break;
                case Column::TYPE_JSONB:
                    $fieldDefinition[] = "'type' => Column::TYPE_JSONB";
                    break;
                case Column::TYPE_BIGINTEGER:
                    $fieldDefinition[] = "'type' => Column::TYPE_BIGINTEGER";
                    break;
                default:
                    throw new UnknownColumnTypeException($field);
            }

            if ($field->hasDefault() && !$field->isAutoIncrement()) {
                $default = $field->getDefault();
                $fieldDefinition[] = "'default' => \"$default\"";
            }
            //if ($field->isPrimary()) {
            //	$fieldDefinition[] = "'primary' => true";
            //}

            if ($field->isUnsigned()) {
                $fieldDefinition[] = "'unsigned' => true";
            }

            if ($field->isNotNull()) {
                $fieldDefinition[] = "'notNull' => true";
            }

            if ($field->isAutoIncrement()) {
                $fieldDefinition[] = "'autoIncrement' => true";
            }

            if (self::$_databaseConfig->adapter == 'Postgresql' &&
                in_array($field->getType(), [Column::TYPE_BOOLEAN, Column::TYPE_INTEGER, Column::TYPE_BIGINTEGER])
            ) {
                // nothing
            } else {
                if ($field->getSize()) {
                    $fieldDefinition[] = "'size' => " . $field->getSize();
                } else {
                    $fieldDefinition[] = "'size' => 1";
                }
            }

            if ($field->getScale()) {
                $fieldDefinition[] = "'scale' => " . $field->getScale();
            }

            if ($oldColumn != null) {
                $fieldDefinition[] = "'after' => '" . $oldColumn . "'";
            } else {
                $fieldDefinition[] = "'first' => true";
            }

            $oldColumn = $field->getName();
            $tableDefinition[] = $snippet->getColumnDefinition($field->getName(), $fieldDefinition);
            $allFields[] = "'" . $field->getName() . "'";
        }

        $indexesDefinition = [];
        $indexes = self::$_connection->describeIndexes($table, $defaultSchema);
        foreach ($indexes as $indexName => $dbIndex) {
            /** @var \Phalcon\Db\Index $dbIndex */
            $indexDefinition = [];
            foreach ($dbIndex->getColumns() as $indexColumn) {
                $indexDefinition[] = "'" . $indexColumn . "'";
            }
            $indexesDefinition[] = $snippet->getIndexDefinition($indexName, $indexDefinition, $dbIndex->getType());
        }

        $referencesDefinition = [];
        $references = self::$_connection->describeReferences($table, $defaultSchema);
        foreach ($references as $constraintName => $dbReference) {
            $columns = [];
            foreach ($dbReference->getColumns() as $column) {
                $columns[] = "'" . $column . "'";
            }

            $referencedColumns = [];
            foreach ($dbReference->getReferencedColumns() as $referencedColumn) {
                $referencedColumns[] = "'" . $referencedColumn . "'";
            }

            $referenceDefinition = [];
            $referenceDefinition[] = "'referencedTable' => '" . $dbReference->getReferencedTable() . "'";
            $referenceDefinition[] = "'columns' => [" . join(",", array_unique($columns)) . "]";
            $referenceDefinition[] = "'referencedColumns' => [" . join(",", array_unique($referencedColumns)) . "]";
            $referenceDefinition[] = "'onUpdate' => '" . $dbReference->getOnUpdate() . "'";
            $referenceDefinition[] = "'onDelete' => '" . $dbReference->getOnDelete() . "'";

            $referencesDefinition[] = $snippet->getReferenceDefinition($constraintName, $referenceDefinition);
        }

        $optionsDefinition = [];
        $tableOptions = self::$_connection->tableOptions($table, $defaultSchema);
        foreach ($tableOptions as $optionName => $optionValue) {
            if (self::$_skipAI && strtoupper($optionName) == "AUTO_INCREMENT") {
                $optionValue = '';
            }
            $optionsDefinition[] = "'" . strtoupper($optionName) . "' => '" . $optionValue . "'";
        }

        $className = Text::camelize('create') . Text::camelize($table) . 'Migration';
        // morph()
        $classData = $snippet->getMigrationMorph($className, $table, $tableDefinition);

        if (count($indexesDefinition)) {
            $classData .= $snippet->getMigrationDefinition('indexes', $indexesDefinition);
        }

        if (count($referencesDefinition)) {
            $classData .= $snippet->getMigrationDefinition('references', $referencesDefinition);
        }

        if (count($optionsDefinition)) {
            $classData .= $snippet->getMigrationDefinition('options', $optionsDefinition);
        }

        $classData .= "            ]\n        );\n    }\n";

        // up()
        $classData .= $snippet->getMigrationUp();

        if ($exportData == 'always') {
            $classData .= $snippet->getMigrationBatchInsert($table, $allFields);
        }

        $classData .= "\n    }\n";

        // down()
        $classData .= $snippet->getMigrationDown();

        if ($exportData == 'always') {
            $classData .= $snippet->getMigrationBatchDelete($table);
        }

        $classData .= "\n    }\n";

        // afterCreateTable()
        if ($exportData == 'oncreate') {
            $classData .= $snippet->getMigrationAfterCreateTable($table, $allFields);
        }

        // end of class
        $classData .= "\n}\n";

        // dump data
        if ($exportData == 'always' || $exportData == 'oncreate') {
            $fileHandler = fopen(self::$_migrationPath . DIRECTORY_SEPARATOR . $table . '.dat', 'w');
            $cursor = self::$_connection->query('SELECT * FROM ' . self::$_connection->escapeIdentifier($table));
            $cursor->setFetchMode(Db::FETCH_ASSOC);
            while ($row = $cursor->fetchArray()) {
                $data = [];
                foreach ($row as $key => $value) {
                    if (isset($numericFields[$key])) {
                        if ($value === '' || is_null($value)) {
                            $data[] = 'NULL';
                        } else {
                            $data[] = addslashes($value);
                        }
                    } else {
                        $data[] = is_null($value) ? "NULL" : addslashes($value);
                    }

                    unset($value);
                }

                fputcsv($fileHandler, $data);
                unset($row);
                unset($data);
            }

            fclose($fileHandler);
        }
        return $classData;
    }

    /**
     * @param $fileName
     * @param $migrationsDir
     * @param $batch
     * @throws Exception
     */
    public static function migrate($fileName, $migrationsDir, $batch)
    {

        if (!self::checkMigrateFile($fileName)) {
            $migration = self::createClass($fileName, $migrationsDir);


            if (is_object($migration)) {
                // morph the table structure
                if (method_exists($migration, 'morph')) {
                    $migration->morph();
                }

                // modify the datasets
                if (method_exists($migration, 'up')) {
                    try{
                        $migration->up();
                        self::updateMigrateBatch($fileName, $batch);
                    }catch (Exception $exception) {
                        if (method_exists($migration, 'down')) {
                            $migration->down();
                        }
                        self::rollback($migrationsDir,$fileName, $batch);
                    }

                    if (method_exists($migration, 'afterUp')) {
                        $migration->afterUp();
                    }
                }
            }
        }
    }

    /**
     * rollback
     * @param $migrationsDir
     * @param $fileName
     * @param $batch
     * @throws Exception
     */
    public static function rollback($migrationsDir,$fileName,$batch)
    {
        $where = " WHERE batch=$batch";
        if( !empty($fileName) ) {
            $where .= " AND migration='{$fileName}'";
        }

        $data = self::$_connection->fetchAll("SELECT migration FROM migrations ".$where);

        foreach ($data as $datnum) {
            $migration = self::createClass($datnum['migration'], $migrationsDir);
            if (is_object($migration)) {
                if (method_exists($migration, 'down')) {
                    $migration->down();
                }
            }
            self::$_connection->delete('migrations',"migration='".$datnum['migration']."'");
        }

    }

    /**
     * Create migration object for specified version
     *
     * @param ItemInterface $version
     * @param string $tableName
     *
     * @return null|\Phalcon\Mvc\Model\Migration
     *
     * @throws Exception
     */
    private static function createClass($fileName, $migrationsDir)
    {
        if (!file_exists($migrationsDir . $fileName)) {
            return null;
        }

        $_fileName = str_replace('.php', '', $fileName);
        $tableNames = explode('_', $_fileName);

        $num = count($tableNames);
        $fileOject = [];
        for ($i = 4; $i < $num; $i++) {
            $fileOject[] = Text::camelize($tableNames[$i]);
        }

        $className = join('',$fileOject);

        include_once $migrationsDir . $fileName;
        if (!class_exists($className)) {
            throw new Exception('Migration class cannot be found ' . $className . ' at ' . $fileName);
        }

        $migration = new $className();

        return $migration;
    }

    /**
     * Initialization Migration
     * @return bool|Db\ResultInterface
     */
    public static function setupMigration()
    {
        return self::$_connection->query("CREATE TABLE `migrations` (
	`migration` VARCHAR(255) NOT NULL COLLATE 'utf8_unicode_ci',
	`batch` INT(10) NOT NULL
)
COLLATE='utf8_unicode_ci'
ENGINE=InnoDB;
");
    }

    /**
     * check whether the migrate File exists
     * @param $fileName
     * @return array
     */
    public static function checkMigrateFile($fileName)
    {
        return self::$_connection->fetchOne("SELECT migration FROM migrations WHERE migration='{$fileName}'");
    }

    /**
     * check whether the db table exists
     * @param $fileName
     * @return array
     */
    public static function checkMigration()
    {
        return self::$_connection->tableExists('migrations');
    }

    /**
     * Get the maximum batch
     * @return int
     */
    public static function getLastBatch()
    {
        $lastBatch = self::$_connection->fetchOne("SELECT batch FROM migrations ORDER BY batch DESC");
        return !empty($lastBatch) ? ($lastBatch['batch'] * 1 + 1) : 1;
    }

    /**
     * Get the current batch
     * @return int
     */
    public static function getCurrentBatch()
    {
        $lastBatch = self::$_connection->fetchOne("SELECT batch FROM migrations ORDER BY batch DESC");
        return !empty($lastBatch) ? ($lastBatch['batch'] * 1 ) : 1;
    }

    /**
     * update Migrate Batch
     * @param $fileName
     * @param $batch
     * @return mixed
     */
    public static function updateMigrateBatch($fileName, $batch)
    {
        return self::$_connection->insert('migrations',
            [$fileName, $batch],
            ['migration', 'batch']);
    }

    /**
     * Look for table definition modifications and apply to real table
     *
     * @param $tableName
     * @param $definition
     *
     * @throws \Phalcon\Db\Exception
     */
    public function morphTable($tableName, $definition)
    {
        $defaultSchema = Utils::resolveDbSchema(self::$_databaseConfig);
        $tableExists = self::$_connection->tableExists($tableName, $defaultSchema);
        $tableSchema = null;
        if (isset($definition['columns'])) {
            if (count($definition['columns']) == 0) {
                throw new DbException('Table must have at least one column');
            }
            $fields = [];
            foreach ($definition['columns'] as $tableColumn) {
                if (!is_object($tableColumn)) {
                    throw new DbException('Table must have at least one column');
                }
                /**
                 * @var \Phalcon\Db\ColumnInterface   $tableColumn
                 * @var \Phalcon\Db\ColumnInterface[] $fields
                 */
                $fields[$tableColumn->getName()] = $tableColumn;
                if (empty($tableSchema)) {
                    $tableSchema = $tableColumn->getSchemaName();
                }
            }
            if ($tableExists == true) {
                $localFields = [];
                /**
                 * @var \Phalcon\Db\ColumnInterface[] $description
                 * @var \Phalcon\Db\ColumnInterface[] $localFields
                 */
                $description = self::$_connection->describeColumns($tableName, $defaultSchema);
                foreach ($description as $field) {
                    $localFields[$field->getName()] = $field;
                }
                foreach ($fields as $fieldName => $tableColumn) {
                    /**
                     * @var \Phalcon\Db\ColumnInterface   $tableColumn
                     * @var \Phalcon\Db\ColumnInterface[] $localFields
                     */
                    if (!isset($localFields[$fieldName])) {
                        self::$_connection->addColumn($tableName, $tableColumn->getSchemaName(), $tableColumn);
                    } else {
                        $changed = false;
                        if ($localFields[$fieldName]->getType() != $tableColumn->getType()) {
                            $changed = true;
                        }
                        if ($localFields[$fieldName]->getSize() != $tableColumn->getSize()) {
                            $changed = true;
                        }
                        if ($tableColumn->isNotNull() != $localFields[$fieldName]->isNotNull()) {
                            $changed = true;
                        }
                        if ($tableColumn->getDefault() != $localFields[$fieldName]->getDefault()) {
                            $changed = true;
                        }
                        if ($changed == true) {
                            self::$_connection->modifyColumn($tableName, $tableColumn->getSchemaName(), $tableColumn, $tableColumn);
                        }
                    }
                }
                foreach ($localFields as $fieldName => $localField) {
                    if (!isset($fields[$fieldName])) {
                        self::$_connection->dropColumn($tableName, null, $fieldName);
                    }
                }
            } else {
                self::$_connection->createTable($tableName, $defaultSchema, $definition);
                if (method_exists($this, 'afterCreateTable')) {
                    $this->afterCreateTable();
                }
            }
        }
        if (isset($definition['references'])) {
            if ($tableExists == true) {
                $references = [];
                foreach ($definition['references'] as $tableReference) {
                    $references[$tableReference->getName()] = $tableReference;
                }
                $localReferences = [];
                $activeReferences = self::$_connection->describeReferences($tableName, $defaultSchema);
                foreach ($activeReferences as $activeReference) {
                    $localReferences[$activeReference->getName()] = [
                        'referencedTable'   => $activeReference->getReferencedTable(),
                        "referencedSchema"  => $activeReference->getReferencedSchema(),
                        'columns'           => $activeReference->getColumns(),
                        'referencedColumns' => $activeReference->getReferencedColumns(),
                    ];
                }
                foreach ($definition['references'] as $tableReference) {
                    if (!isset($localReferences[$tableReference->getName()])) {
                        self::$_connection->addForeignKey(
                            $tableName,
                            $tableReference->getSchemaName(),
                            $tableReference
                        );
                    } else {
                        $changed = false;
                        if ($tableReference->getReferencedTable() != $localReferences[$tableReference->getName(
                            )]['referencedTable']
                        ) {
                            $changed = true;
                        }
                        if ($changed == false) {
                            if (count($tableReference->getColumns()) != count(
                                    $localReferences[$tableReference->getName()]['columns']
                                )
                            ) {
                                $changed = true;
                            }
                        }
                        if ($changed == false) {
                            if (count($tableReference->getReferencedColumns()) != count(
                                    $localReferences[$tableReference->getName()]['referencedColumns']
                                )
                            ) {
                                $changed = true;
                            }
                        }
                        if ($changed == false) {
                            foreach ($tableReference->getColumns() as $columnName) {
                                if (!in_array($columnName, $localReferences[$tableReference->getName()]['columns'])) {
                                    $changed = true;
                                    break;
                                }
                            }
                        }
                        if ($changed == false) {
                            foreach ($tableReference->getReferencedColumns() as $columnName) {
                                if (!in_array(
                                    $columnName,
                                    $localReferences[$tableReference->getName()]['referencedColumns']
                                )
                                ) {
                                    $changed = true;
                                    break;
                                }
                            }
                        }
                        if ($changed == true) {
                            self::$_connection->dropForeignKey(
                                $tableName,
                                $tableReference->getSchemaName(),
                                $tableReference->getName()
                            );
                            self::$_connection->addForeignKey(
                                $tableName,
                                $tableReference->getSchemaName(),
                                $tableReference
                            );
                        }
                    }
                }
                foreach ($localReferences as $referenceName => $reference) {
                    if (!isset($references[$referenceName])) {
                        self::$_connection->dropForeignKey($tableName, null, $referenceName);
                    }
                }
            }
        }
        if (isset($definition['indexes'])) {
            if ($tableExists == true) {
                $indexes = [];
                foreach ($definition['indexes'] as $tableIndex) {
                    $indexes[$tableIndex->getName()] = $tableIndex;
                }
                $localIndexes = [];
                $actualIndexes = self::$_connection->describeIndexes($tableName, $defaultSchema);
                foreach ($actualIndexes as $actualIndex) {
                    $localIndexes[$actualIndex->getName()] = $actualIndex->getColumns();
                }
                foreach ($definition['indexes'] as $tableIndex) {
                    if (!isset($localIndexes[$tableIndex->getName()])) {
                        if ($tableIndex->getName() == 'PRIMARY') {
                            self::$_connection->addPrimaryKey($tableName, $tableSchema, $tableIndex);
                        } else {
                            self::$_connection->addIndex($tableName, $tableSchema, $tableIndex);
                        }
                    } else {
                        $changed = false;
                        if (count($tableIndex->getColumns()) != count($localIndexes[$tableIndex->getName()])) {
                            $changed = true;
                        } else {
                            foreach ($tableIndex->getColumns() as $columnName) {
                                if (!in_array($columnName, $localIndexes[$tableIndex->getName()])) {
                                    $changed = true;
                                    break;
                                }
                            }
                        }
                        if ($changed == true) {
                            if ($tableIndex->getName() == 'PRIMARY') {
                                self::$_connection->dropPrimaryKey($tableName, $tableSchema);
                                self::$_connection->addPrimaryKey(
                                    $tableName,
                                    $tableSchema,
                                    $tableIndex
                                );
                            } else {
                                self::$_connection->dropIndex(
                                    $tableName,
                                    $tableSchema,
                                    $tableIndex->getName()
                                );
                                self::$_connection->addIndex($tableName, $tableSchema, $tableIndex);
                            }
                        }
                    }
                }
                foreach ($localIndexes as $indexName => $indexColumns) {
                    if (!isset($indexes[$indexName])) {
                        self::$_connection->dropIndex($tableName, null, $indexName);
                    }
                }
            }
        }
    }

    /**
     * Generating file template
     * @param $fileName
     * @return string
     */
    public static function migrationFileTemplate($fileName)
    {
        $fileNames = explode('_', $fileName);

        $num = count($fileNames);
        $fileOject = [];
        for ($i = 4; $i < $num; $i++) {
            $fileOject[] = Text::camelize($fileNames[$i]);
        }

        $className = join('',$fileOject);

        $snippet = new Snippet();

        $classData  = <<<EOD
use Phalcon\Db\Column;
use Phalcon\Db\Index;
use Phalcon\Db\Reference;
use Phalcon\Mvc\Model\Migration;

/**
 * Class $className
 */
class $className extends Migration
{
EOD;

        $classData .= $snippet->getMigrationUp();

        $classData .= "\n    }\n";

        $classData .= $snippet->getMigrationDown();

        $classData .= "\n    }\n";

        // end of class
        $classData .= "\n}\n";
        return $classData;
    }
}
