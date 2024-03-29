<?php

namespace ThinkShout\RoboDrupal;

use Dotenv\Dotenv;
use Drupal\Component\Utility\Crypt;
use Robo\Tasks as RoboTasks;
use Symfony\Component\Process\Process;

class Tasks extends RoboTasks {
  private $projectProperties;

  /**
   * Whether or not the project uses migration plugins instead of config.
   *
   * @var bool
   */
  protected $usesMigrationPlugins = FALSE;

  /**
   * {@inheritdoc}
   */
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
     * Determines the names of local config to enable during robo install.
     *
     * Each site can overwrite this if their config splits are not 'local'
     * or if they have multiple splits.
     *
     * @return mixed
     *   Return the list of config-split elements to activate on robo install.
     */
    protected function getConfigSplits() {
        return ['local'];
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

    $find_replaces = array(
      array(
        'source' => '.env.dist',
        'from' => '"SITE"',
        'to' => '"' . $git_repo . '"',
      ),
      array(
        'source' => '.env.dist',
        'from' => 'DRUSH_OPTIONS_URI=""',
        'to' => 'DRUSH_OPTIONS_URI="https://web.' . $git_repo . '.localhost"',
      )
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
  public function configure($opts = [
    'db-pass' => NULL,
    'db-user' => NULL,
    'db-name' => NULL,
    'db-host' => NULL,
    'branch' => NULL,
    'profile' => 'standard',
    'db-upgrade' => NULL,
    'prod-branch' => 'main',
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

    // Production Branch
    $prod_branch = $this->projectProperties['prod-branch'];

    // Terminus env
    $this->projectProperties['terminus_env'] = ($branch == $prod_branch) ? 'dev' : $branch;

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

    // If branch was specified, write it out to the .env file for future runs.
    $this->taskWriteToFile('.env')
      ->append()
      ->line('TS_PROD_BRANCH=' . $prod_branch)
      ->run();

    // If profile was specified, write it out to the .env file for future runs.
    $result = $this->taskWriteToFile('.env')
      ->append()
      ->line('TS_INSTALL_PROFILE=' . $this->projectProperties['install_profile'])
      ->run();

    return $result;
  }

  /**
   * Deploy code from our source repo/branch to our deployment repo/branch.
   *
   * @param $pantheon_branch This enables us to define the target branch name.
   * We use this to deploy code to a specific multidev for Pantheon deployments
   * which we need for automated visual regression testing. In that case, the
   * source (feature) branch gets deployed to the vr-dev branch/multidev.
   *
   * @deprecated deprecated since version 4.0. Use Pantheon Build Tools.
   */
  public function deploy($pantheon_branch = NULL) {

    $repo = $this->projectProperties['host_repo'];

    if (!$pantheon_branch) {$pantheon_branch = $this->projectProperties['branch'];}

    $webroot = $this->projectProperties['web_root'];

    $tmpDir = $this->getTmpDir();
    $hostDirName = $this->getFetchDirName();
    $this->stopOnFail();
    $fs = $this->taskFilesystemStack()
      ->mkdir($tmpDir)
      ->run();

    // Make sure we have an empty temp dir.
    $this->taskCleanDir([$tmpDir])
      ->run();

    $this->taskGitStack()
      ->stopOnFail()
      ->cloneRepo($repo, "$tmpDir/$hostDirName")
      ->run();

    // Git checkout of the matching remote branch.
    $this->taskGitStack()->dir("$tmpDir/$hostDirName")
      ->checkout($pantheon_branch)
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
      ->printOutput(FALSE)
      ->run();

    // Move host .git into our deployment directory.
    $this->taskRsync()
      ->fromPath("$tmpDir/$hostDirName/.git")
      ->toPath("$tmpDir/deploy")
      ->args('-a', '-v', '-z', '--no-group', '--no-owner')
      ->printOutput(FALSE)
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
      ->push('origin', $pantheon_branch)
      ->run();
  }

  /**
   * Install or re-install the Drupal site.
   *
   * @return \Robo\Result
   */
  public function install() {
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
      $install_cmd = 'site-install --existing-config' .
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
  public function info() {
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
  public function test($opts = ['feature' => NULL, 'profile' => 'local', 'tags' => NULL]) {
    $this->setUpBehat();

    $behat_cmd = $this->taskExec('behat')
      ->option('config', 'behat/behat.' . $opts['profile'] . '.yml')
      ->option('profile', $opts['profile'])
      ->option('format', 'progress')
      ->option('stop-on-failure');

    if ($opts['feature']) {
      $behat_cmd->rawArg($opts['feature']);
    }

    if ($opts['tags']) {
      $behat_cmd->option('tags', $opts['tags']);
    }

    $behat_result = $behat_cmd->run();

    if (!$behat_result->wasSuccessful()){
      // Print out a debugging message.
      $this->say('You just had a behat test fail! See https://library.thinkshoutlabs.com/articles/fixing-failing-behat-tests-locally-circleci for tips on how to fix this!');
    }

    return $behat_result;
  }

  /**
   * Ensure that the filesystem has everything Behat needs. At present, that's
   * only chromedriver, AKA "Headless Chrome".
   */
  public function setUpBehat() {
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
  public function run($opts = ['port' => 8088]) {
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
   * @option string pantheon-branch Use specified branch instead of source
   * branch name. This enables us to deploy source branches with more than 11
   * characters to Pantheon which we use for automated visual regression
   * testing.
   *
   * @return \Robo\Result
   * @deprecated deprecated since version 4.0. Use Pantheon Build Tools.
   */
  public function pantheonDeploy($opts = ['install' => FALSE, 'y' => FALSE, 'pantheon-branch' => NULL]) {
    $terminus_site     = getenv('TERMINUS_SITE');
    $terminus_env      = $opts['pantheon-branch'] ? $opts['pantheon-branch'] : getenv('TERMINUS_ENV');
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
      $made_the_multidev = $this->taskExec("terminus multidev:create $terminus_site.dev $terminus_env")
        ->run();

      if (!$made_the_multidev->wasSuccessful()) {
        // We assume we are out of multidevs.
        return FALSE;
      }
    }

    // Make sure our site is awake.
    $this->_exec("terminus env:wake $terminus_site_env");

    // Ensure we're in git mode.
    $this->_exec("terminus connection:set $terminus_site_env git");

    // Deployment
    // In this case, "master" is pantheon primary branch, not our local branch,
    // so we are unable to excise this archaic branch name.
    $pantheon_branch = $terminus_env == 'dev' ? 'master' : $terminus_env;
    $this->deploy($pantheon_branch);

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
   *
   * @deprecated deprecated since version 4.0. Use Pantheon Build Tools.
   */
  public function pantheonInstall() {
    $admin_name = $this->projectProperties['admin_name'];
    $install_cmd = 'site-install --existing-config --account-name=' . $admin_name . ' -y ';

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
    $this->_exec("terminus connection:set $terminus_site_env git --yes");

    if ($result->wasSuccessful()) {
      $this->say('Install complete');
    }

    $this->postInstall();

    return $result;
  }

  /**
   * Run tests against the Pantheon multidev.
   *
   * @option string feature Single feature file to run.
   *   Ex: --feature=features/user.feature.
   *
   * @return \Robo\Result
   *
   * @deprecated deprecated since version 4.0. Run tests on circle.
   */
  public function pantheonTest($opts = ['feature' => NULL]) {
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

  /**
   * Returns the project properties from the .env file.
   *
   * @return array
   */
  protected function getProjectProperties() {

    $properties = [
      'project' => '',
      'hash_salt' => '',
      'config_dir' => '',
      'host_repo' => '',
      'install_profile' => 'standard',
      'admin_name' => 'admin',
      'prod_branch' => 'main',
    ];

    $properties['working_dir'] = getcwd();

    // Load .env file from the local directory if it exists. Or use the .env.dist
    $env_file = (file_exists($properties['working_dir'] . '/.env')) ? '.env' : '.env.dist';


    $dotenv = Dotenv::createUnsafeImmutable($properties['working_dir'], $env_file);
    $dotenv->safeLoad();

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
      $process = Process::fromShellCommandline($command);
      $process->setTimeout(NULL);
      $process->setWorkingDirectory($properties['working_dir']);
      $process->run();

      $branch = $process->getOutput();

      $properties['branch'] = trim($branch);
    }

    if ($system_defaults = getenv('PRESSFLOW_SETTINGS')) {
      $db_settings = json_decode($system_defaults, TRUE)['databases']['default']['default'];
      $properties['db-name'] = $db_settings['database'];
      $properties['db-user'] = $db_settings['username'];
      $properties['db-pass'] = $db_settings['password'];
    }
    elseif ($db_name = getenv('TS_DB_NAME')) {
      $properties['db-name'] = $db_name;
    }
    else {
      $properties['db-name'] = $properties['project'] . '_' . $properties['branch'];

      // replace dashes with underscores:
      $properties['db-name'] = str_replace("-", "_", $properties['db-name']);
    }

    return $properties;
  }

  /**
   * Escapes the string for argument use.
   *
   * @param $string
   *
   * @return string
   * @see Symfony\Component\Console\Input.
   */
  protected function escapeArg($string) {
    return preg_match('{^[\w-]+$}', $string) ? $string : escapeshellarg($string);
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
   *
   * @deprecated deprecated since version 4.0. Use Pantheon Build Tools.
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
   *
   * @deprecated deprecated since version 4.0. Use Pantheon Build Tools.
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
   * Clean up state of Pantheon dev & develop environments after deploying.
   *
   * Run this by adding the line:
   * robo post:deploy
   *
   * right after this line:
   * robo pantheon:deploy --y
   *
   * in your .circleci/config.yml file.
   *
   * @deprecated deprecated since version 4.0. Use Pantheon Build Tools.
   */
  public function postDeploy() {
    $terminus_site_env = $this->getPantheonSiteEnv();
    $pantheon_prefix   = getenv('TERMINUS_SITE');
    if ($terminus_site_env == $pantheon_prefix . '.develop' || $terminus_site_env == $pantheon_prefix . '.dev') {
      $drush_commands = [
        'drush_partial_config_import' => "terminus remote:drush $terminus_site_env -- config-import --partial -y",
        'drush_cache_clear' => "terminus remote:drush $terminus_site_env -- cr",
        'drush_update_database' => "terminus remote:drush $terminus_site_env -- updb -y",
        'drush_full_config_import' => "terminus remote:drush $terminus_site_env -- config-import -y",
      ];
      // Run the installation.
      $result = $this->taskExec(implode(' && ', $drush_commands))
        ->run();
    }
    if ($terminus_site_env === $pantheon_prefix . '.autoupdate') {
      $drush_commands = [
        'drush_update_database' => "terminus remote:drush $terminus_site_env -- updb -y",
      ];
      $this->taskExec(implode(' && ', $drush_commands))
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
   * Afterwards, make a PR against production branch for these changes and merge
   * them.
   * Do this BEFORE merging develop branch into prod branch.
   */
  public function pullConfig() {
    $project_properties = $this->getProjectProperties();
    $terminus_site_env  = $this->getPantheonSiteEnv($this->databaseSourceOfTruth());

    $terminus_backup_timestamp = $this->taskExec('terminus backup:info ' . $terminus_site_env . ' --field="date"')
                                 ->dir($project_properties['web_root'])
                                 ->interactive(false)
                                 ->run();

    $this->say("To pull the latest config, you should use a current database backup.");
    if($terminus_backup_timestamp->wasSuccessful()) {
      $this->say("The most recent backup is from " . date('r', intval($terminus_backup_timestamp->getMessage())));
    }
    else {
      $this->say("A recent backup was not found.");
    }

    $grab_database = $this->confirm("Create a new backup now?");
    if ($grab_database == 'y') {
      $terminus_url_request = $this->taskExec('terminus backup:create ' . $terminus_site_env . ' --element="db"')
        ->dir($project_properties['web_root'])
        ->interactive(false)
        ->run();

      if (!$terminus_url_request->wasSuccessful()) {
        $this->yell('Could not make a Database backup of "'. $terminus_site_env . '"! See if you can make one manually.');
        return FALSE;
      }
    }

    $do_composer_install = $this->downloadPantheonBackup($this->databaseSourceOfTruth());

    if ($do_composer_install && $this->importLocal()) {
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

      $this->yell('"'. $this->databaseSourceOfTruth() . '" site config exported to your local. Commit this branch and make a PR against ' . $project_properties['prod_branch'] . '. Don\'t forget to `robo install` again before resuming development!');
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
    $grab_database = $this->confirm("Load a database backup?");
    if ($grab_database == 'y') {
      $do_composer_install = $this->getDatabaseOfTruth();
    }
    if ($do_composer_install) {
      $this->taskComposerInstall()
        ->optimizeAutoloader()
        ->run();
      $drush_commands = [
        'drush_clear_cache' => 'drush cr',
        'drush_update_database' => 'drush updb -y',
        'drush_grab_config_changes' => 'drush config-import -y',
      ];
      $config_splits = $this->getConfigSplits();
      foreach ($config_splits as $split) {
        $drush_commands[$split] =  'drush config-split:activate ' . $split . ' -y';
      }
      $drush_commands['drush_assert_configurations'] = 'drush cex -y';
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
  protected function getDatabaseOfTruth() {
    $project_properties = $this->getProjectProperties();
    $default_database = $this->databaseSourceOfTruth();

    if (file_exists('vendor/database.sql.gz')) {
      $default_database = 'local';
    }

    $this->say('This command will drop all tables in your local database and re-populate from a backup .sql.gz file.');
    $this->say('If you already have a database backup in your  vendor folder, the "local" option will be available.');
    $this->say('If you want to grab a more recent backup from Pantheon, type in the environment name (i.e. dev, test, live, my-multidev). This will be saved to your vendor folder for future re-installs.');
    $this->say('Backups are generated on Pantheon regularly, but might be old.');
    $this->say('If you need the very latest data from a Pantheon site, go create a new backup using either the Pantheon backend, or Terminus.');

    $which_database = $this->askDefault(
      'Which database backup should we load (i.e. local/develop/multidev/live)?', $default_database
    );

    $getDB = TRUE;
    if ($which_database !== 'local') {
      $getDB = $this->downloadPantheonBackup($which_database);
    }

    if ($getDB) {
      $this->say('Emptying existing database.');
      $this->taskExec('drush sql:create -y')->dir($project_properties['web_root'])->run();
      return $this->importLocal();
    }
    else {
      $this->yell('Failed to download a Pantheon backup. Database was not refreshed.');
      return false;
    }
  }

  /**
   * Grabs a backup from Pantheon.
   *
   * @param $env
   *   The environment to get the backup from.
   *
   * @return bool
   *   True if the backup was downloaded.
   */
  private function downloadPantheonBackup($env) {
    $project_properties = $this->getProjectProperties();
    $terminus_site_env  = $this->getPantheonSiteEnv($env);

    $terminus_url_request = $this->taskExec('terminus backup:get ' . $terminus_site_env . ' --element="db"')
      ->dir($project_properties['web_root'])
      ->interactive(false)
      ->run();

    if ($terminus_url_request->wasSuccessful()) {
      $terminus_url = $terminus_url_request->getMessage();
    }
    else {
      $this->yell('Failed to find a recent backup for the ' . $terminus_site_env . ' site. Does one exist?');
      return FALSE;
    }

    $wget_database = $this->taskExec('wget -O vendor/database.sql.gz "' . trim($terminus_url) . '"')->run();

    if (!$wget_database->wasSuccessful()) {
      $this->yell('Remote database sync failed.');
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Imports a local database.
   *
   * @return bool
   *   True if import succeeded.
   */
  public function importLocal() {
    if (!$this->taskExec('which pv')->run()->wasSuccessful()) {
      $this->yell("This project's database can take up to an hour to import. So that you can be informed of its progress, please install the pv tool with 'brew install pv', then run your command again.");
      return FALSE;
    }

    $project_properties = $this->getProjectProperties();

    $web_root = $project_properties['web_root'];

    // Empty out the old database so deleted tables don't stick around.
    $this->taskExec('drush sql:drop -y')->dir($web_root)->run();

    $import_commands    = [
      'drush_import_database' => "pv ../vendor/database.sql.gz | zcat | $(drush sql:connect) # Importing local copy of db."
    ];
    $database_import = $this->taskExec(implode(' && ', $import_commands))->dir($web_root)->run();

    if ($database_import->wasSuccessful()) {
      return TRUE;
    }
    else {
      $this->yell("Could not read vendor/database.sql.gz into your local database. See if the command 'pv vendor/database.sql.gz | zcat | $(drush sql:connect)' works outside of robo.");
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
    if ($opts['migrations'] && ($this->migrationSourceFolder() || $this->usesMigrationPlugins)) {
      $migrations = explode(',', $opts['migrations']);
      $project_properties = $this->getProjectProperties();

      foreach ($migrations as $migration) {
        $this->taskExec('drush mrs ' . $migration)
          ->dir($project_properties['web_root'])
          ->run();
      }
    }
    if ($this->migrationSourceFolder()) {
      $this->taskExec('drush mr --all && drush cim --partial --source=' . $this->migrationSourceFolder() . ' -y && drush ms')
        ->dir($project_properties['web_root'])
        ->run();
    }
    else if ($this->usesMigrationPlugins) {
      $this->taskExec('drush cr && drush mr --all && drush ms')
        ->dir($project_properties['web_root'])
        ->run();
    }
    else {
      $this->say('No migration sources configured.');
      $this->say('To use this command, you must either return a folder path from the migrationSourceFolder() method or set $usesMigrationPlugins to TRUE in your project\'s RoboFile.php.');
      return FALSE;
    }
  }


  /**
   * Runs a "composer update" and pushes results to the "autoupdate" branch.
   *
   * This command should be ran after checking out the default branch of a
   * project. If "composer update" results in any changed files, a force push
   * is used to ensure that the "autoupdate" branch is only one commit ahead
   * of the default branch.
   *
   * @param string $profile
   *   A specific profile to update, ex: "thinkshout/bene".
   *
   * @return \Robo\Result
   *   The last robo command's result.
   */
  public function ciUpdate($profile = NULL) {
    $exec = $this->taskComposerUpdate()
      ->option('with-dependencies');

    if ($profile) {
      $exec->arg($profile);
    }

    $result = $exec->run();

    if (!$result->wasSuccessful()) return FALSE;

    $result = $this->taskExec('git status -s')
      ->interactive(FALSE)
      ->run();

    if (!$result->wasSuccessful()) return FALSE;

    if (empty($result->getMessage())) {
      $this->say('No updates to commit.');
      return $result;
    }

    $result = $this->taskExec('git checkout -b autoupdate && git add . && git commit -m "Ran automatic updates." && git push --force -u origin autoupdate')
      ->run();

    if ($result->wasSuccessful()) {
      $this->say('Update complete');
      return $result;
    }

    return $result;
  }
}
