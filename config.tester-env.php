<?php  // Tester-env Moodle configuration (generated into ./config.php at deploy).
// Trimmed adaptation of moodlehq/moodle-docker config.docker-template.php:
// pgsql service "db", dataroot on the moodledata volume, deterministic
// test-friendly settings. No behat/phpunit extras (single manual-test site).

unset($CFG);
global $CFG;
$CFG = new stdClass();

$CFG->dbtype    = 'pgsql';
$CFG->dblibrary = 'native';
$CFG->dbhost    = 'db';
$CFG->dbname    = 'moodle';
$CFG->dbuser    = 'moodle';
$CFG->dbpass    = 'm@0dl3ing';
$CFG->prefix    = 'm_';
$CFG->dboptions = [];

$host = getenv('MOODLE_DOCKER_WEB_HOST') ?: 'localhost';
$port = getenv('MOODLE_DOCKER_WEB_PORT') ?: '8000';
$CFG->wwwroot = "http://{$host}:{$port}";

$CFG->dataroot  = '/var/www/moodledata';
$CFG->admin     = 'admin';
$CFG->directorypermissions = 0777;

$CFG->passwordpolicy = 0;
$CFG->cronclionly = 0;
$CFG->pathtophp = '/usr/local/bin/php';
// Avoid slow mail delivery attempts with no SMTP service in this stack.
$CFG->smtphosts = '';
$CFG->noreplyaddress = 'noreply@example.com';

require_once(__DIR__ . '/lib/setup.php');
