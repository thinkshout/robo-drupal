<?php

namespace ThinkShout\RoboDrupal;

use Drupal\Component\Utility\Crypt;
use Symfony\Component\Process\Process;

class Tasks extends \Robo\Tasks
{
  private $projectProperties;

  function __construct() {
    $this->projectProperties = $this->getProjectProperties();
  }

  /**
   * Determines the database to start from when doing new work on this project.
   *
   * @return mixed
   *   Return the Pantheon environment you want to pull in on install (live,
   *   dev, etc), or FALSE to install from scratch.
   */
  protected function databaseSourceOfTruth() {
    return 'live';
  }

  /**
   * Determines the migration folder to pull config from.
   *
   * @return mixed
   *   Return the path to the folder from the Drupal root
   *   (example: 'modules/custom/my_migration/config/install'), or FALSE if you
   *   are running no ongoing migrations.
   */
  protected function migrationSourceFolder() {
    return FALSE;
  }

  /**
   * Initialize the project for the first time.
   *
   * @return \Robo\Result
   */
  public function init() {
    $git_repo = exec('basename `git rev-parse --show-toplevel`');

    // Remove instructions for creating a new repo, because we've got one now.
    $readme_contents = file_get_contents('README.md');
    $start_string = '### Initial build (new repo)';
    $end_string = '### Initial build (existing repo)';
    $from = $this->findAllTextBetween($start_string, $end_string, $readme_contents);

    $find_replaces = array(
      array(
        'source' => 'composer.json',
        'from' => '"name": "thinkshout/drupal-project",',
        'to' => '"name": "thinkshout/' . $git_repo . '",',
      ),
      array(
        'source' => '.env.dist',
        'from' => 'TS_PROJECT="SITE"',
        'to' => 'TS_PROJECT="' . $git_repo . '"',
      ),
      array(
        'source' => 'README.md',
        'from' => array($from, 'new-project-name'),
        'to' => array($end_string, $git_repo),
      ),
    );

    foreach ($find_replaces as $find_replace) {
      $this->taskReplaceInFile($find_replace['source'])
        ->from($find_replace['from'])
        ->to($find_replace['to'])
        ->run();
    }
  }

  /**
   * Generate configuration in your .env file.
   *
   * @option array $opts Contains the following key options:
   *   db-pass:    Database password.
   *   db-user:    Database user.
   *   db-name:    Database name.
   *   db-host:    Database host.
   *   branch:     Git Branch.
   *   profile:    install profile.
   *   db-upgrade: local migration database name.
   */
  function configure($opts = [
    'db-pass' => NULL,
    'db-user' => NULL,
    'db-name' => NULL,
    'db-host' => NULL,
    'branch' => NULL,
    'profile' => 'standard',
    'db-upgrade' => NULL,
  ]) {

    $settings = $this->getDefaultPressflowSettings();

    // Use user environment settings if we have them.
    if ($system_defaults = getenv('DEFAULT_PRESSFLOW_SETTINGS')) {
      $settings = json_decode($system_defaults, TRUE);
    }

    // Loop through project properties and replace with command line arguments
    // if we have them.
    foreach ($opts as $opt => $value) {
      if ($value !== NULL) {
        // Ugly method to allow an empty param to be passed for the password.
        if ($value == 'NULL') {
          $value = '';
        }
        $this->projectProperties[$opt] = $value;
      }
    }

    // DB Name
    $settings['databases']['default']['default']['database'] = $this->projectProperties['db-name'];

    // Override DB username from project properties.
    if (isset($this->projectProperties['db-user'])) {
      $settings['databases']['default']['default']['username'] = $this->projectProperties['db-user'];
    }

    // Override DB password from project properties.
    if (isset($this->projectProperties['db-pass'])) {
      $settings['databases']['default']['default']['password'] = $this->projectProperties['db-pass'];
    }

    // Override DB host from project properties.
    if (isset($this->projectProperties['db-host'])) {
      $settings['databases']['default']['default']['host'] = $this->projectProperties['db-host'];
    }

    // Set Upgrade database.
    if (isset($this->projectProperties['db-upgrade']) && $this->projectProperties['db-upgrade'] != $this->projectProperties['db-name']) {
      $settings['databases']['upgrade'] = $settings['databases']['default'];
      $settings['databases']['upgrade']['default']['database'] = $this->projectProperties['db-upgrade'];
    }

    // Hash Salt.
    if (empty($this->projectProperties['hash_salt'])) {

      // If we don't have a salt, we generate one.
      $hash_salt = Crypt::randomBytesBase64(55);
      $this->projectProperties['hash_salt'] = $hash_salt;
      $this->taskWriteToFile('.env.dist')
        ->append()
        ->line('TS_HASH_SALT="' . $hash_salt . '"')
        ->run();
    }

    $settings['drupal_hash_salt'] = $this->projectProperties['hash_salt'];

    // Config Directory
    $settings['config_directory_name'] = $this->projectProperties['config_dir'];

    // Branch
    $branch = $this->projectProperties['branch'];

    // Terminus env
    $this->projectProperties['terminus_env'] = ($branch == 'master') ? 'dev' : $branch;

    $json_settings = json_encode($settings);

    // Start with the dist env file.
    $this->_remove('.env');
    $this->_copy('.env.dist', '.env');

    $this->taskWriteToFile('.env')
      ->append()
      ->line('# Generated configuration')
      ->line('PRESSFLOW_SETTINGS=' . $json_settings)
      ->line('TERMINUS_ENV=' . $this->projectProperties['terminus_env'])
      ->run();

    // If branch was specified, write it out to the .env file for future runs.
    $this->taskWriteToFile('.env')
      ->append()
      ->line('TS_BRANCH=' . $branch)
      ->run();

    // If profile was specified, write it out to the .env file for future runs.
    $result = $this->taskWriteToFile('.env')
      ->append()
      ->line('TS_INSTALL_PROFILE=' . $this->projectProperties['install_profile'])
      ->run();

    return $result;
  }

  /**
   * Perform git checkout of host files.
   */
  function deploy() {

    $repo = $this->projectProperties['host_repo'];

    $branch = $this->projectProperties['branch'];

    $webroot = $this->projectProperties['web_root'];

    $tmpDir = $this->getTmpDir();
    $hostDirName = $this->getFetchDirName();
    $this->stopOnFail();
    $fs = $this->taskFilesystemStack()
      ->mkdir($tmpDir)
      ->mkdir("$tmpDir/$hostDirName")
      ->run();

    // Make sure we have an empty temp dir.
    $this->taskCleanDir([$tmpDir])
      ->run();

    // Git checkout of the matching remote branch.
    $this->taskGitStack()
      ->stopOnFail()
      ->cloneRepo($repo, "$tmpDir/$hostDirName")
      ->dir("$tmpDir/$hostDirName")
      ->checkout($branch)
      ->run();

    // Get the last commit from the remote branch.
    $last_remote_commit = $this->taskExec('git --no-pager log -1 --date=short --pretty=format:%ci')
      ->dir("$tmpDir/$hostDirName")
      ->run();
    $last_commit_date = trim($last_remote_commit->getMessage());

    $commit_message = $this->taskExec("git --no-pager log --pretty=format:'%h %s' --no-merges --since='$last_commit_date'")->run()->getMessage();

    $commit_message = "Combined commits: \n" . $commit_message;

    // Copy webroot to our deploy directory.
    $this->taskRsync()
      ->fromPath("./")
      ->toPath("$tmpDir/deploy")
      ->args('-a', '-v', '-z', '--no-group', '--no-owner')
      ->excludeVcs()
      ->exclude('.gitignore')
      ->exclude('sites/default/settings.local.php')
      ->exclude('sites/default/files')
      ->printed(FALSE)
      ->run();

    // Move host .git into our deployment directory.
    $this->taskRsync()
      ->fromPath("$tmpDir/$hostDirName/.git")
      ->toPath("$tmpDir/deploy")
      ->args('-a', '-v', '-z', '--no-group', '--no-owner')
      ->printed(FALSE)
      ->run();

    // Rerun composer install for optimization and no dev items.
    $this->taskComposerInstall()
      ->dir("$tmpDir/deploy")
      ->optimizeAutoloader()
      ->noDev()
      ->preferDist()
      ->run();

    $this->taskGitStack()
      ->stopOnFail()
      ->dir("$tmpDir/deploy")
      ->add('-A')
      ->commit($commit_message)
      ->push('origin', $branch)
      ->run();
  }

  /**
   * Install or re-install the Drupal site.
   *
   * @return \Robo\Result
   */
  function install() {
    if(getenv('CIRCLECI')) {
      // Do nothing custom here.
      return $this->trueFreshInstall();
    }
    elseif ($this->databaseSourceOfTruth()) {
      $this->prepareLocal();
    }
    else {
      $this->trueFreshInstall();
      $this->postInstall();
    }
  }

  /**
   * Install or re-install the Drupal site.
   *
   * @return \Robo\Result
   */
  private function trueFreshInstall() {
    // Use user environment settings if we have them.
    if ($system_defaults = getenv('PRESSFLOW_SETTINGS')) {
      $settings = json_decode($system_defaults, TRUE);
      $admin_name = $this->projectProperties['admin_name'];
      $db_settings = $settings['databases']['default']['default'];
      $install_cmd = 'site-install ' . $this->projectProperties['install_profile'] .
        ' --db-url=mysql://' . $db_settings['username'] .
        ':' . $db_settings['password'] .
        '@' . $db_settings['host'] .
        ':' . $db_settings['port'] .
        '/' . $db_settings['database'] .
        ' -y  --account-name=' . $admin_name;
    }
    else {
      $install_cmd = 'site-install standard -y';
    }

    // Install dependencies. Only works locally.
    $this->taskComposerInstall()
      ->optimizeAutoloader()
      ->run();

    $this->_chmod($this->projectProperties['web_root'] . '/sites/default', 0755);
    if (file_exists($this->projectProperties['web_root'] . '/sites/default/settings.php')) {
      $this->_chmod($this->projectProperties['web_root'] . '/sites/default/settings.php', 0755);
    }

    $install_cmd = 'drush ' . $install_cmd;

    // Run the installation.
    $result = $this->taskExec($install_cmd)
      ->dir($this->projectProperties['web_root'])
      ->run();

    if ($result->wasSuccessful()) {
      $this->say('Install complete');
    }

    return $result;
  }

  /**
   * Output PHP info.
   */
  function info() {
    phpinfo();
  }

  /**
   * Run tests for this site. Currently just Behat.
   *
   * @option string feature Single feature file to run.
   *   Ex: --feature=features/user.feature.
   * @option string profile which behat profile to run.
   *   Ex: --profile default, --profile local, --profile ci
   *
   * @return \Robo\Result
   */
  function test($opts = ['feature' => NULL, 'profile' => 'local', 'tags' => NULL]) {
    $this->setUpBehat();

    $behat_cmd = $this->taskExec('behat')
      ->option('config', 'behat/behat.' . $opts['profile'] . '.yml')
      ->option('profile', $opts['profile'])
      ->option('format', 'progress');

    if ($opts['feature']) {
      $behat_cmd->rawArg($opts['feature']);
    }

    if ($opts['tags']) {
      $behat_cmd->option('tags', $opts['tags']);
    }

    $behat_result = $behat_cmd->run();

    return $behat_result;

    // @TODO consider adding unit tests back in. These are slow and aren't working great right now.
//    $unit_result = $this->taskPHPUnit('../vendor/bin/phpunit')
//      ->dir('core')
//      ->run();
//
//    // @TODO will need to address multiple results when we enable other tests as well.
//    return $behat_result->merge($unit_result);
  }

  /**
   * Ensure that the filesystem has everything Behat needs. At present, that's
   * only chromedriver, AKA "Headless Chrome".
   */
  function setUpBehat() {
    // Ensure that this system has headless Chrome.
    if (!$this->taskExec('which chromedriver')->run()->wasSuccessful()) {
      $os = exec('uname');
      // Here we assume either OS X (a dev's env) or not (a CI env).
      if ($os == 'Darwin') {
        $this->taskExec('brew install chromedriver')
          ->run();
      }
      else {
        $version = exec('curl http://chromedriver.storage.googleapis.com/LATEST_RELEASE');
        $this->taskExec("wget http://chromedriver.storage.googleapis.com/{$version}/chromedriver_linux64.zip")
          ->run();
        $this->taskExec('unzip chromedriver_linux64.zip')
          ->run();
        $this->taskFilesystemStack()
          ->rename('chromedriver', 'vendor/bin/chromedriver')
          ->run();
        $this->taskFilesystemStack()
          ->remove('chromedriver_linux64.zip')
          ->run();
      }
    }
  }

  /**
   * Run php's built in webserver at localhost:PORT.
   *
   * @option int port Port number of listen on. Defaults to 8088.
   */
  function run($opts = ['port' => 8088]) {
    // execute server in background
    $this->taskServer($opts['port'])
      ->background()
      ->run();
  }

  /**
   * Prepare a Pantheon multidev for this project/branch.
   *
   * @option boolean install Trigger an install on Pantheon.
   * @option boolean y Answer prompts with y.
   *
   * @return \Robo\Result
   */
  function pantheonDeploy($opts = ['install' => FALSE, 'y' => FALSE]) {
    $terminus_site     = getenv('TERMINUS_SITE');
    $terminus_env      = getenv('TERMINUS_ENV');
    $terminus_site_env = $this->getPantheonSiteEnv($terminus_env);
    $result            = $this->taskExec("terminus env:info $terminus_site_env")
                              ->run();

    // Check for existing multidev and prompt to create.
    if (!$result->wasSuccessful()) {
      if (!$opts['y']) {
        if (!$this->confirm('No matching multidev found. Create it?')) {
          return FALSE;
        }
      }
      if (strlen($terminus_env) > 11) {
        $this->say('Couldn\'t create multidev: Pantheon environment names are restricted to 11 characters.');
        return FALSE;
      }
      $this->taskExec("terminus multidev:create $terminus_site.dev $terminus_env")
        ->run();
    }

    // Make sure our site is awake.
    $this->_exec("terminus env:wake $terminus_site_env");

    // Ensure we're in git mode.
    $this->_exec("terminus connection:set $terminus_site_env git");

    // Deployment
    $this->deploy();

    // Trigger remote install.
    if ($opts['install']) {
      $this->_exec("terminus env:wipe $terminus_site_env --yes");
      return $this->pantheonInstall();
    }
  }

  /**
   * Install site on Pantheon.
   *
   * @return \Robo\Result
   */
  function pantheonInstall() {
    $admin_name = $this->projectProperties['admin_name'];
    $install_cmd = 'site-install ' . $this->projectProperties['install_profile'] . ' --account-name=' . $admin_name . ' -y';

    $terminus_site_env = $this->getPantheonSiteEnv();
    $install_cmd = "terminus remote:drush $terminus_site_env -- $install_cmd";
    // Pantheon wants the site in SFTP for installs.
    $this->_exec("terminus connection:set $terminus_site_env sftp");

    // Even in SFTP mode, the settings.php file might have too restrictive
    // permissions. We use SFTP to chmod the settings file before installing.
    $sftp_command = trim(exec("terminus connection:info --field=sftp_command $terminus_site_env"));
    $sftp_command = str_replace('sftp', 'sftp -b -', $sftp_command);
    // Use webroot to find settings.php assume  webroot is the gitroot if no
    // webroot is specified.
    if (getenv('TS_WEB_ROOT')) {
      $web_root = getenv('TS_WEB_ROOT') . '/';
    }
    else {
      $web_root = '';
    }
    $default_dir = 'code/' . $web_root . 'sites/default';
    // We use 755 instead of 644 so settings.php is executable, and the
    // directory is stat-able (otherwise we can't chmod the php file)
    $sftp_command .= ' << EOF
chmod 755 ' . $default_dir . '
chmod 755 ' . $default_dir . '/settings.php';
    // Note that we don't use $this->_exec on purpose. SFTP command fails
    // with that operation: a fix would be great but this actually works.
    exec($sftp_command);

    // Run the installation.
    $result = $this->taskExec($install_cmd)
      ->run();
    // Put the site back into git mode.
    $this->_exec("terminus connection:set $terminus_site_env git");

    if ($result->wasSuccessful()) {
      $this->say('Install complete');
    }

    return $result;
  }

  /**
   * Run tests against the Pantheon multidev.
   *
   * @option string feature Single feature file to run.
   *   Ex: --feature=features/user.feature.
   *
   * @return \Robo\Result
   */
  function pantheonTest($opts = ['feature' => NULL]) {
    $project     = getenv('TERMINUS_SITE');
    $env         = $this->projectProperties['branch'];
    $url         = "https://$env-$project.pantheonsite.io";
    $alias       = "pantheon.$project.$env";
    $drush_param = '"alias":"' . $alias . '"';

    $root = $this->projectProperties['web_root'];

    // Add the specific behat config to our environment.
    putenv('BEHAT_PARAMS={"extensions":{"Behat\\\\MinkExtension":{"base_url":"' . $url . '"},"Drupal\\\\DrupalExtension":{"drupal":{"drupal_root":"' . $root . '"},"drush":{' . $drush_param . '}}}}');

    return $this->test(['profile' => 'pantheon', 'feature' => $opts['feature']]);
  }

  protected function getProjectProperties() {

    $properties = ['project' => '', 'hash_salt' => '', 'config_dir' => '', 'host_repo' => '', 'install_profile' => 'standard', 'admin_name' => 'admin'];

    $properties['working_dir'] = getcwd();

    // Load .env file from the local directory if it exists. Or use the .env.dist
    $env_file = (file_exists($properties['working_dir'] . '/.env')) ? '.env' : '.env.dist';

    $dotenv = new \Dotenv\Dotenv($properties['working_dir'], $env_file);
    $dotenv->load();

    array_walk($properties, function(&$var, $key) {
      $env_var = strtoupper('TS_' . $key);
      if ($value = getenv($env_var)) {
        $var = $value;
      }
    });

    if ($web_root = getenv('TS_WEB_ROOT')) {
      $properties['web_root'] = $properties['working_dir'] . '/' . $web_root;
    }
    else {
      $properties['web_root'] = $properties['working_dir'];
    }

    $properties['escaped_web_root_path'] = $this->escapeArg($properties['web_root']);

    if (!isset($properties['branch'])) {
      // Get the current branch using the simple exec command.
      $command = 'git symbolic-ref --short -q HEAD';
      $process = new Process($command);
      $process->setTimeout(NULL);
      $process->setWorkingDirectory($properties['working_dir']);
      $process->run();

      $branch = $process->getOutput();

      $properties['branch'] = trim($branch);
    }

    if ($db_name = getenv('TS_DB_NAME')) {
      $properties['db-name'] = $db_name;
    }
    else {
      $properties['db-name'] = $properties['project'] . '_' . $properties['branch'];
    }

    return $properties;
  }

  // See Symfony\Component\Console\Input.
  protected function escapeArg($string) {
    return preg_match('{^[\w-]+$}', $string) ? $string : escapeshellarg($string);
  }

  /**
   * Use regex to replace a 'key' => 'value', pair in a file like a settings file.
   *
   * @param $file
   * @param $key
   * @param $value
   */
  protected function replaceArraySetting($file, $key, $value) {
    $this->taskReplaceInFile($file)
      ->regex("/'$key' => '[^'\\\\]*(?:\\\\.[^'\\\\]*)*',/s")
      ->to("'$key' => '". $value . "',")
      ->run();
  }

  /**
   * Get "<site>.<env>" commonly used in terminus commands.
   */
  protected function getPantheonSiteEnv($env = '') {
    $site = getenv('TERMINUS_SITE');

    if (!$env) {
      $env = getenv('TERMINUS_ENV');
    }

    return join('.', [$site, $env]);
  }

  /**
   * Build temp folder path for the task.
   *
   * @return string
   */
  protected function getTmpDir() {
    return realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'drupal-deploy-' . time();
  }

  /**
   * Decide what our fetch directory should be named
   * (temporary location to stash scaffold files before
   * moving them to their final destination in the project).
   *
   * @return string
   */
  protected function getFetchDirName() {
    return 'host';
  }

  /**
   * Return the default array of pressflow settings.
   * @return array
   */
  protected function getDefaultPressflowSettings() {
    return array (
      'databases' =>
        array (
          'default' =>
            array (
              'default' =>
                array (
                  'driver' => 'mysql',
                  'prefix' => '',
                  'database' => '',
                  'username' => 'root',
                  'password' => 'root',
                  'host' => '127.0.0.1',
                  'port' => 3306,
                ),
            ),
        ),
      'conf' =>
        array (
          'pressflow_smart_start' => true,
          'pantheon_binding' => NULL,
          'pantheon_site_uuid' => NULL,
          'pantheon_environment' => 'local',
          'pantheon_tier' => 'local',
          'pantheon_index_host' => 'localhost',
          'pantheon_index_port' => 8983,
          'redis_client_host' => '',
          'redis_client_port' => 6379,
          'redis_client_password' => '',
          'file_public_path' => 'sites/default/files',
          'file_private_path' => 'sites/default/files/private',
          'file_directory_path' => 'site/default/files',
          'file_temporary_path' => '/tmp',
          'file_directory_temp' => '/tmp',
          'css_gzip_compression' => false,
          'js_gzip_compression' => false,
          'page_compression' => false,
        ),
      'hash_salt' => '',
      'config_directory_name' => '../config',
    );
  }

  /**
   * Finds the text between two strings within a third string.
   *
   * @param $beginning
   * @param $end
   * @param $string
   *
   * @return string
   *   String containing $beginning, $end, and everything in between.
   */
  private function findAllTextBetween($beginning, $end, $string) {
    $beginningPos = strpos($string, $beginning);
    $endPos = strpos($string, $end);
    if ($beginningPos === false || $endPos === false) {
      return '';
    }

    $textToDelete = substr($string, $beginningPos, ($endPos + strlen($end)) - $beginningPos);

    return $textToDelete;
  }

  /**
   * Clean up state of Pantheon dev & develop environments after deploying.
   *
   * Run this by adding the line:
   * robo post:deploy
   *
   * right after this line:
   * robo pantheon:deploy --y
   *
   * in your .circleci/config.yml file.
   */
  public function postDeploy() {
    $terminus_site_env = $this->getPantheonSiteEnv();
    $pantheon_prefix   = getenv('TERMINUS_SITE');
    if ($terminus_site_env == $pantheon_prefix . '.develop' || $terminus_site_env == $pantheon_prefix . '.dev') {
      $drush_commands = [
        'drush_partial_config_import' => "terminus remote:drush $terminus_site_env -- config-import --partial -y",
        'drush_cache_clear' => "terminus remote:drush $terminus_site_env -- cr",
        'drush_entity_updates' => "terminus remote:drush $terminus_site_env -- entity-updates -y",
        'drush_update_database' => "terminus remote:drush $terminus_site_env -- updb -y",
        'drush_full_config_import' => "terminus remote:drush $terminus_site_env -- config-import -y",
      ];
      // Run the installation.
      $result = $this->taskExec(implode(' && ', $drush_commands))
        ->run();
    }
  }

  /**
   * Pull the config from the live site down to your local.
   *
   * Run this command from a branch based off the last release tag on github.
   * For example:
   *   git checkout my_last_release
   *   git checkout -b this_release_date
   *   robo pull:config
   *   git commit .
   *   git push
   *
   * Afterwards, make a PR against master for these changes and merge them.
   * Do this BEFORE merging develop into master.
   */
  public function pullConfig() {
    $project_properties = $this->getProjectProperties();
    $do_composer_install = $this->getDatabaseOfTruth();
    if ($do_composer_install) {
      $this->taskComposerInstall()
        ->optimizeAutoloader()
        ->run();
      $drush_commands = [
        'drush_clear_cache_again' => 'drush cr',
        'drush_grab_config_changes' => 'drush config-export -y',
      ];
      $this->taskExec(implode(' && ', $drush_commands))
        ->dir($project_properties['web_root'])
        ->run();

      // Ignore config-local changes -- the $database_of_truth site doesn't know about them.
      $this->taskGitStack()
        ->stopOnFail()
        ->checkout('config-local')
        ->run();

      $this->yell('"'. $this->databaseSourceOfTruth() . '" site config exported to your local. Commit this branch and make a PR against master. Don\'t forget to `robo install` again before resuming development!');
    }
  }

  /**
   * Prepare your local machine for development.
   *
   * Pulls the database of truth, brings the database in line with local config,
   * and enables local development modules, including config suite.
   */
  public function prepareLocal() {
    $do_composer_install = TRUE;
    $project_properties = $this->getProjectProperties();
    $grab_database = $this->confirm("Grab a fresh database?");
    if ($grab_database == 'y') {
      $do_composer_install = $this->getDatabaseOfTruth();
    }
    if ($do_composer_install) {
      $this->taskComposerInstall()
        ->optimizeAutoloader()
        ->run();
      $drush_commands = [
        'drush_clear_cache' => 'drush cr',
        'drush_update_database' => 'drush updb',
        'drush_grab_config_changes' => 'drush config-import -y',
        'drush_grab_config_local_changes' => 'drush config-split-import local -y',
      ];
      $this->taskExec(implode(' && ', $drush_commands))
        ->dir($project_properties['web_root'])
        ->run();
    }
  }

  /**
   * Prepare a freshly-installed site with some dummy data for site editors.
   *
   * This is sample code, not intended to be used as-is, but intended to be
   * implemented in your RoboFile.php. It's not an abstract function, because
   * its implementation shouldn't be required, and I wanted to add sample code.
   */
  public function postInstall() {
    // Sample method code with instructions for use.
    $this->say("The post:install command should be customized per project. Copy the postInstall() method from the `/vendor/robo-drupal/src/Tasks.php` folder into your RoboFile.php file and alter it to suit your needs.");
    return TRUE;

    // Code you'll want to use starts below.
    $terminus_site_env  = $this->getPantheonSiteEnv();
    $project_properties = $this->getProjectProperties();
    $pantheon_prefix    = getenv('TERMINUS_SITE');
    $needs_directory    = FALSE;
    // These are the remote domains we want to run the migration on.
    $install_domains = [
      $pantheon_prefix . '.develop',
      $pantheon_prefix . '.dev',
    ];

    if ((in_array($terminus_site_env, $install_domains)) && getenv('CIRCLECI')) {
      $cmd_prefix = "terminus remote:drush $terminus_site_env --";
      $needs_directory = FALSE;
    }
    elseif (!getenv('CIRCLECI')) {
      $cmd_prefix = "drush";
      $needs_directory = TRUE;
    }
    if (!isset($cmd_prefix)) {
      return;
    }
    if (in_array($terminus_site_env, $install_domains)) {
      $drush_commands = [
        'wake_old_multidev' => "terminus env:wake wfw8.d7database",
        'drush_create_admin' => "$cmd_prefix ucrt admin@test.org --mail=admin@test.org --password=admin",
        'drush_assign_admin' => "$cmd_prefix urol administrator --mail=admin@test.org",
        'drush_migrate_prep' => "$cmd_prefix mim --group=migrate_drupal_7 --limit=50",
      ];
      // Run the commands you listed.
      $query = $this->taskExec(implode(' && ', $drush_commands));
      if ($needs_directory) {
        $query->dir($project_properties['web_root']);
      }
      $query->run();
    }
  }

  /**
   * Helper function to pull the database of truth to your local machine.
   *
   * @return bool
   *   If the remote database was reached and downloaded, return TRUE.
   */
  private function getDatabaseOfTruth() {
    if (!$this->databaseSourceOfTruth()) {
      $this->say('No source database configured.');
      $this->say('To use this command, you must return a string from the databaseSourceOfTruth() method in your project RoboFile.php.');
      return FALSE;
    }

    $current_command    = $this->input()->getArgument('command');
    $project_properties = $this->getProjectProperties();
    $terminus_site_env  = $this->getPantheonSiteEnv($this->databaseSourceOfTruth());

    $drush_commands    = [
      'drush_drop_database'   => 'drush sql-drop -y @self',
      'drush_import_database' => 'terminus remote:drush ' . $terminus_site_env . ' -- sql-dump | drush @self sqlc',
    ];
    $database_download = $this->taskExec(implode(' && ', $drush_commands))->dir($project_properties['web_root'])->run();
    if ($database_download->wasSuccessful()) {
      return TRUE;
    }
    else {
      $this->yell('Remote database sync failed. Please run `robo ' . $current_command . '` again.');
      return FALSE;
    }
  }

  /**
   * Cleanup migrations.
   *
   * @option string migrations
   *   Optional list of migrations to reset, separated by commmas.
   */
  public function migrateCleanup($opts = ['migrations' => '']) {
    if ($this->migrationSourceFolder()) {
      $migrations = explode(',', $opts['migrations']);
      $project_properties = $this->getProjectProperties();
      foreach ($migrations as $migration) {
        $this->taskExec('drush mrs ' . $migration)
          ->dir($project_properties['web_root'])
          ->run();
      }
      $this->taskExec('drush mr --all && drush cim --partial --source=' . $this->migrationSourceFolder() . ' -y && drush ms')
        ->dir($project_properties['web_root'])
        ->run();
    }
    else {
      $this->say('No migration source folder configured.');
      $this->say('To use this command, you must return a folder path string within the migrationSourceFolder() method in your project RoboFile.php.');
      return FALSE;
    }
  }
}
