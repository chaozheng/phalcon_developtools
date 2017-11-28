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

namespace Phalcon\Commands\Builtin;

use Phalcon\Builder;
use Phalcon\Script\Color;
use Phalcon\Commands\Command;
use Phalcon\Migrations;
use Phalcon\Config;

/**
 * Migration Command
 *
 * Generates/Run a migration
 *
 * @package Phalcon\Commands\Builtin
 */
class Migration extends Command
{
    /**
     * {@inheritdoc}
     *
     * @return array
     */
    public function getPossibleParams()
    {
        return [
            'config=s'          => 'Configuration file',
            'migrations=s'      => 'Migrations directory',
            'directory=s'       => 'Directory where the project was created',
            'file=s'            => 'Generates a Migration file',
            'batch=s'           => 'Migrations batch number',
        ];
    }

    /**
     * {@inheritdoc}
     *
     * @param array $parameters
     *
     * @return mixed
     */
    public function run(array $parameters)
    {
        $path = $this->isReceivedOption('directory') ? $this->getOption('directory') : '';
        $path = realpath($path) . DIRECTORY_SEPARATOR;

        if ($this->isReceivedOption('config')) {
            $config = $this->loadConfig($path . $this->getOption('config'));
        } else {
            $config = $this->getConfig($path);
        }

        if ($this->isReceivedOption('migrations')) {
            $migrationsDir = $path . $this->getOption('migrations');
        } elseif (isset($config['application']['migrationsDir'])) {
            $migrationsDir = $config['application']['migrationsDir'];
            if (!$this->path->isAbsolutePath($migrationsDir)) {
                $migrationsDir = $path . $migrationsDir;
            }
        } elseif (file_exists($path . 'app')) {
            $migrationsDir = $path . 'app/migrations';
        } elseif (file_exists($path . 'apps')) {
            $migrationsDir = $path . 'apps/migrations';
        } else {
            $migrationsDir = $path . 'migrations';
        }

        $action = $this->getOption(['action', 1]);
        $file = $this->getOption('file');
        $batch = $this->getOption('batch');

        switch ($action) {
            case 'run':
                Migrations::run([
                    'directory'      => $path,
                    'migrationsDir'  => $migrationsDir,
                    'config'         => $config,
                ]);
                break;
            case 'migrate':
                Migrations::migrate([
                    'migrationsDir'  => $migrationsDir,
                    'config'         => $config,
                    'file'           => $file
                ]);
                break;
            case 'install':
                Migrations::install([
                    'migrationsDir'  => $migrationsDir,
                    'config'         => $config,
                ]);
                break;
            case 'rollback':
                Migrations::rollback([
                    'migrationsDir'  => $migrationsDir,
                    'config'         => $config,
                    'batch'          => $batch,
                    'file'           => $file
                ]);
                break;
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return array
     */
    public function getCommands()
    {
        return ['migration', 'create-migration'];
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function getHelp()
    {
        print Color::head('Install Migration') . PHP_EOL;
        print Color::colorize('  phalcon migration install', Color::FG_GREEN) . PHP_EOL . PHP_EOL;

        print Color::head('Usage: Generate a Migration') . PHP_EOL;
        print Color::colorize('  phalcon migration migrate ', Color::FG_GREEN) . PHP_EOL;
        $this->printParameters([
            'config=s'          => 'Configuration file',
            'migrations=s'      => 'Migrations directory',
            'directory=s'       => 'Directory where the project was created',
            'file=s'            => 'Generates a Migration file, is a necessary parameter.',
        ]);
        print Color::colorize('                   Example: phalcon migration migrate --file=create_user_table') . PHP_EOL;
        print Color::colorize('                            phalcon migration migrate --file=add_column_email_to_user_table') . PHP_EOL;
        print PHP_EOL . PHP_EOL;

        print Color::head('Usage: Run a Migration') . PHP_EOL;
        print Color::colorize('  phalcon migration run', Color::FG_GREEN) . PHP_EOL;
        $this->printParameters([
            'config=s'          => 'Configuration file',
            'migrations=s'      => 'Migrations directory',
            'directory=s'       => 'Directory where the project was created',
            'file=s'            => 'Execute Migration file. No this parameter, execute all.',
        ]);
        print PHP_EOL . PHP_EOL;

        print Color::head('Usage: Rollback a Migration') . PHP_EOL;
        print Color::colorize('  phalcon migration rollback', Color::FG_GREEN) . PHP_EOL;
        $this->printParameters([
            'config=s'          => 'Configuration file',
            'migrations=s'      => 'Migrations directory',
            'directory=s'       => 'Directory where the project was created',
            'file=s'            => 'Execute Migration file. No this parameter, Rollback the latest batch.',
            'batch=s'           => 'Rollback batch.Unnecessary parameters.',
        ]);
        print PHP_EOL . PHP_EOL;

        print Color::head('Help:') . PHP_EOL;
        print Color::colorize(' phalcon migration help', Color::FG_GREEN);
        print Color::colorize("\tShows this help text") . PHP_EOL . PHP_EOL;

    }

    /**
     * {@inheritdoc}
     *
     * @return integer
     */
    public function getRequiredParams()
    {
        return 1;
    }
}
