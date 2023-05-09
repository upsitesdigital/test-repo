<?php

/**
 * Description of Installer
 *
 * @author ALFASOFT
 */
class Installer
{
    static $PluginName;
    static $MigrationsTable;

    public static function Run($pluginName)
    {
        global $wpdb;

        self::$PluginName = $pluginName;

        require_once(plugin_dir_path(__FILE__) . 'functions.php');
        $pluginName::$LogPath =  jobconvo_log_setup('install');
        log_info('Installing Jobconvo API Plugin');


        self::$MigrationsTable = tablePrefix() . "Migrations";

        // check if migration table already exists and execute initialization if it does not
        self::CheckDatabaseInitialization();

        self::RunMigrations();
        self::RunSeeds();
    }

    private static function CheckDatabaseInitialization()
    {
        log_info("Running Database Initialization check for plugin");

        if (!tableExists(self::$MigrationsTable)) {
            log_info("Migrations Table does not exist, creating...");

            $sql = "CREATE TABLE `" . self::$MigrationsTable . "` ( ";
            $sql .= "  `id`  int(11) NOT NULL auto_increment, ";
            $sql .= "  `name` text NOT NULL, ";
            $sql .= "  `timestamp` datetime NOT NULL, ";
            $sql .= "  PRIMARY KEY (`id`) ";
            $sql .= ") DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ; ";
            
            createTable($sql);

            log_info("Migrations Table created.");
        }
    }


    /**
     * Create plugin tables with sql queries
     * @global type $wpdb
     */
    public static function RunMigrations()
    {
        global $wpdb;

        // get all migration files
        $files = list_files(plugin_dir_path(__FILE__) . 'SQL/Migrations/');

        // run each migration if it hasn't run yet
        foreach ($files as $file) {

            if (!self::MigrationExecuted($file)) {
                log_info("Running Migration {$file}");

                require_once($file);

                // save migration into database
                $wpdb->insert(self::$MigrationsTable, array('name' => $file, 'timestamp' => date("Y-m-d H:i:s")) );


                log_info("Migration executed successfully.");
            }
            else {
                log_info("Migration {$file} already Executed.");
            }
        }
    }

    // returns true if a migration has already been executed
    private static function MigrationExecuted($migration) {
        return recordExists(self::$MigrationsTable, "name", $migration);
    }

    /**
     * Insert default values
     */
    public static function RunSeeds()
    {
        // get all seed files
        $files = list_files(plugin_dir_path(__FILE__) . 'SQL/Seeds/');

        // run each seed
        foreach ($files as $file) {
            log_info("Running Seed {$file}");
            require_once($file);
            log_info("Seed ran successfully.");
        }
    }
}
