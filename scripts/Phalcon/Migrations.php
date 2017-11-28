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
  |          Serghei Iakovlev <serghei@phalconphp.com>                     |
  +------------------------------------------------------------------------+
*/

namespace Phalcon;

use Phalcon\Db\Index;
use DirectoryIterator;
use Phalcon\Db\Column;
use Phalcon\Db\Adapter;
use Phalcon\Script\Color;
use Phalcon\Db\AdapterInterface;
use Phalcon\Version\ItemInterface;
use Phalcon\Script\ScriptException;
use Phalcon\Db\Exception as DbException;
use Phalcon\Mvc\Model\Exception as ModelException;
use Phalcon\Mvc\Model\Migration as ModelMigration;
use Phalcon\Version\IncrementalItem as IncrementalVersion;
use Phalcon\Version\ItemCollection as VersionCollection;

/**
 * Migrations Class
 *
 * @package Phalcon
 */
class Migrations
{
    /**
     * name of the migration table
     */
    const MIGRATION_LOG_TABLE = 'phalcon_migrations';

    /**
     * Filename or db connection to store migrations log
     * @var mixed|Adapter\Pdo
     */
    protected static $_storage;

    /**
     * Check if the script is running on Console mode
     *
     * @return boolean
     */
    public static function isConsole()
    {
        return PHP_SAPI === 'cli';
    }

    /**
     * Generate migrations
     *
     * @param array $options
     *
     * @throws \Exception
     * @throws \LogicException
     * @throws \RuntimeException
     */
    public static function generate(array $options)
    {
        $tableName = $options['tableName'];
        $exportData = $options['exportData'];
        $migrationsDir = $options['migrationsDir'];
        $config = $options['config'];
        $noAutoIncrement = isset($options['noAutoIncrement']) ? $options['noAutoIncrement'] : null;

        // Migrations directory
        if ($migrationsDir && !file_exists($migrationsDir)) {
            mkdir($migrationsDir, 0755, true);
        }

        // Path to migration dir
        $migrationPath = rtrim($migrationsDir, '\\/') . DIRECTORY_SEPARATOR;
        if (!file_exists($migrationPath)) {
            if (is_writable(dirname($migrationPath))) {
                mkdir($migrationPath);
            } else {
                throw new \RuntimeException("Unable to write '{$migrationPath}' directory. Permission denied");
            }
        }

        // Try to connect to the DB
        if (!isset($config->database)) {
            throw new \RuntimeException('Cannot load database configuration');
        }
        ModelMigration::setup($config->database);
        ModelMigration::setSkipAutoIncrement($noAutoIncrement);
        ModelMigration::setMigrationPath($migrationsDir);
        $batch = ModelMigration::getLastBatch();
        $wasMigrated = false;

        if ($tableName === '@') {
            $migrations = ModelMigration::generateAll($exportData);
            foreach ($migrations as $tableName => $migration) {
                if ($tableName === self::MIGRATION_LOG_TABLE) {
                    continue;
                }
                $fileName = date("Y_m_d") . '_' . substr(time(), 4, 10) . '_create_' . $tableName . '_migration';
                if (!ModelMigration::checkMigrateFile($fileName)) {
                    ModelMigration::updateMigrateBatch($fileName.'.php', $batch);
                    $tableFile = $migrationPath . DIRECTORY_SEPARATOR . $fileName . '.php';
                    $wasMigrated = file_put_contents(
                            $tableFile,
                            '<?php ' . PHP_EOL . PHP_EOL . $migration
                        ) || $wasMigrated;
                }

            }
        } else {
            $tables = explode(',', $tableName);
            foreach ($tables as $table) {
                $fileName = date("Y_m_d") . '_' . substr(time(), 4, 10) . '_create_' . $table . '_migration';
                if (!ModelMigration::checkMigrateFile($fileName)) {
                    $migration = ModelMigration::generate($table, $exportData);
                    ModelMigration::updateMigrateBatch($fileName, $batch);
                    $tableFile = $migrationPath . DIRECTORY_SEPARATOR . $fileName . '.php';
                    $wasMigrated = file_put_contents(
                        $tableFile,
                        '<?php ' . PHP_EOL . PHP_EOL . $migration
                    );
                }
            }
        }

        if (self::isConsole() && $wasMigrated) {
            print Color::success('Generated successfully!') . PHP_EOL;
        } elseif (self::isConsole()) {
            print Color::info('Nothing to generate. You should create tables first.') . PHP_EOL;
        }
    }

    public static function install(array $options)
    {
        $migrationsDir = $options['migrationsDir'];
        $config = $options['config'];

        // Migrations directory
        if ($migrationsDir && !file_exists($migrationsDir)) {
            mkdir($migrationsDir, 0755, true);
        }


        // Path to migration dir
        $migrationPath = rtrim($migrationsDir, '\\/') . DIRECTORY_SEPARATOR;
        if (!file_exists($migrationPath)) {
            if (is_writable(dirname($migrationPath))) {
                mkdir($migrationPath);
            } else {
                throw new \RuntimeException("Unable to write '{$migrationPath}' directory. Permission denied");
            }
        }

        // Try to connect to the DB
        if (!isset($config->database)) {
            throw new \RuntimeException('Cannot load database configuration');
        }
        ModelMigration::setup($config->database);
        if (!ModelMigration::checkMigration()) {
            ModelMigration::setupMigration();
        }
    }

    public static function migrate(array $options)
    {
        $migrationsDir = rtrim($options['migrationsDir'], '\\/');
        if (!file_exists($migrationsDir)) {
            throw new ModelException('Migrations directory was not found.');
        }

        /** @var Config $config */
        $config = $options['config'];
        if (!$config instanceof Config) {
            throw new ModelException('Internal error. Config should be an instance of ' . Config::class);
        }

        // Init ModelMigration
        if (!isset($config->database)) {
            throw new ScriptException('Cannot load database configuration');
        }


        if (!isset($options['file'])) {
            throw new ModelException('Migration File was not empty.');
        }

        $fileName = strtolower($options['file']);

        $fileName = date("Y_m_d") . '_' . substr(time(), 4, 10) . '_' . $fileName . '_migration';
        $wasMigrated = false;

        $migration = ModelMigration::migrationFileTemplate($fileName);
        $wasMigrated = file_put_contents(
            $migrationsDir.DIRECTORY_SEPARATOR.$fileName.'.php',
                '<?php ' . PHP_EOL . PHP_EOL . $migration
            ) || $wasMigrated;

        if (self::isConsole() && $wasMigrated) {
            print Color::success('Generated successfully!') . PHP_EOL;
        } elseif (self::isConsole()) {
            print Color::info('Nothing to generate. You should create tables first.') . PHP_EOL;
        }

    }

    /**
     * Run migrations
     *
     * @param array $options
     *
     * @throws Exception
     * @throws ModelException
     * @throws ScriptException
     *
     */
    public static function run(array $options)
    {
        $migrationsDir = rtrim($options['migrationsDir'], '\\/');
        if (!file_exists($migrationsDir)) {
            throw new ModelException('Migrations directory was not found.');
        }

        /** @var Config $config */
        $config = $options['config'];
        if (!$config instanceof Config) {
            throw new ModelException('Internal error. Config should be an instance of ' . Config::class);
        }

        // Init ModelMigration
        if (!isset($config->database)) {
            throw new ScriptException('Cannot load database configuration');
        }

        $fileName = '@';
        if (isset($options['file'])) {
            $fileName = $options['file'];
        }

        ModelMigration::setup($config->database);
        ModelMigration::setMigrationPath($migrationsDir);
        $batch = ModelMigration::getLastBatch();

        if ($fileName === '@') {
            // Directory depends on Forward or Back Migration
            $iterator = new DirectoryIterator(
                $migrationsDir . DIRECTORY_SEPARATOR
            );

            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile() || 0 !== strcasecmp($fileInfo->getExtension(), 'php')) {
                    continue;
                }

                ModelMigration::migrate($fileInfo->getFilename(), $migrationsDir . DIRECTORY_SEPARATOR, $batch);
            }
        } else {
            ModelMigration::migrate($fileName, $migrationsDir . DIRECTORY_SEPARATOR, $batch);
        }

        print Color::success('Migrated successfully');

    }

    public static function rollback(array $options)
    {
        $migrationsDir = rtrim($options['migrationsDir'], '\\/');
        if (!file_exists($migrationsDir)) {
            throw new ModelException('Migrations directory was not found.');
        }

        /** @var Config $config */
        $config = $options['config'];
        if (!$config instanceof Config) {
            throw new ModelException('Internal error. Config should be an instance of ' . Config::class);
        }

        // Init ModelMigration
        if (!isset($config->database)) {
            throw new ScriptException('Cannot load database configuration');
        }

        $fileName = '';
        if (isset($options['file'])) {
            $fileName = $options['file'];
        }


        ModelMigration::setup($config->database);
        ModelMigration::setMigrationPath($migrationsDir);
        $batch = ModelMigration::getCurrentBatch();

        if (isset($options['batch'])) {
            $batch = $options['batch'];
        }
        ModelMigration::rollback($migrationsDir . DIRECTORY_SEPARATOR,$fileName,$batch);

        print Color::success('Migrated successfully');
    }
}
