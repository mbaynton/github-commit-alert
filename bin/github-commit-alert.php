#!/usr/bin/env php
<?php
$proj_root = __DIR__ . DIRECTORY_SEPARATOR . '..';
require "$proj_root/vendor/autoload.php";

use Cache\Adapter\Filesystem\FilesystemCachePool;
use Github\Client;
use mbaynton\GithubCommitAlert\Persistence;
use Ulrichsg\Getopt;

$ts_format = DateTime::W3C;
$client = new Client();
$persistence = new Persistence("$proj_root/data/db.sqlite");

$config_file = "$proj_root/config.yml";
if (! is_readable($config_file)) {
  fwrite(STDERR, "Missing configuration file.\nMake sure $config_file exists and is readable.\nDo you need to copy it from config.yml.dist?\n");
  exit(1);
}
try {
  $config = Symfony\Component\Yaml\Yaml::parse(file_get_contents($config_file));
} catch (\Symfony\Component\Yaml\Exception\ParseException $e) {
  fwrite(STDERR, "Error parsing configuration file:\n" . $e->getMessage() . "\n");
  exit(1);
}

/* Fire up a simple cache so as to take advantage of ETag-optimized polling */
$fs = new League\Flysystem\Filesystem(new \League\Flysystem\Adapter\Local($proj_root . DIRECTORY_SEPARATOR));
$cache_pool = new FilesystemCachePool($fs);
if (rand(1, 100) == 25) {
  $cache_pool->clear(); // keep cache a reasonable size
}
$client->addCache($cache_pool);

// What repos do we want to check?
$opts = new Getopt\Getopt();
$banner = <<<BANNER
%1\$s: Generates mail when new commits are pushed to any designated public github repositories.
Usage:
  %1\$s [options] [repository [repository ...]]
Where repository is a github account, a slash, and a repository name, such as
EnterpriseQualityCoding/FizzBuzzEnterpriseEdition to add to the watched
repositories.

With no options or operands, repositories previously added are watched.

BANNER;

$opts->setBanner($banner);
$opts->addOptions([
  (new Getopt\Option('l', 'list'))
    ->setDescription('List the repositories being watched.'),
  (new Getopt\Option('r', 'remove'))
    ->setDescription('Repositories specified are removed from those watched.'),
  (new Getopt\Option('v', 'verbose'))
    ->setDescription('Generate output for repositories with no new commits.'),
  (new Getopt\Option('h', 'help'))
    ->setDescription('Print this help text.'),
]);
try {
  $opts->parse();
} catch (\UnexpectedValueException $e) {
  fwrite(STDERR, "Error: " . $e->getMessage() . "\n\n");
  echo $opts->getHelpText();
  exit(1);
}

if ($opts['h']) {
  echo $opts->getHelpText();
  exit(0);
}

$repositories = [];
if ($opts['r']) {
  foreach ($opts->getOperands() as $operand) {
    $success = $persistence->removeRepo($operand);
    if ($success == 0) {
      fwrite(STDERR, "Operand \"$operand\" was not being watched, so not removed.\n");
    } else {
      echo ("\"$operand\" will not be watched.\n");
    }
  }
} else {
  foreach ($opts->getOperands() as $operand) {
    $split = explode('/', $operand);
    if (count($split) != 2) {
      fwrite(STDERR, "Ignoring operand \"$operand\": All operands must be in the form [github_user]/[repository]\n");
    }
    $repositories[] = $operand;
  }
}

$repositories = array_unique(array_merge($repositories, $persistence->getRepoList()));

if ($opts['l']) {
  echo implode("\n", $repositories) . "\n";
  exit(0);
}

if (count($repositories) == 0) {
  fwrite(STDERR, "Error: No repositories to watch!\n\n");
  print $opts->getHelpText();
  exit(1);
}

$error_count = 0;
/**
 * @var \Github\Api\Repository\Commits $commits_obj
 */
foreach ($repositories as $repository_string) {
  $repository = explode("/", $repository_string, 2);
  $commits_obj = $client->api('repo')->commits();
  $options = [];
  $last_ts = $persistence->getRepoLastSeenCommitTime($repository_string);
  if ($last_ts) {
    // Add 1 second to the last commit
    $last_ts = new DateTime($last_ts);
    $last_ts->add(new DateInterval('PT1S'));
    $options = ['since' => $last_ts->format($ts_format)];
  }
  $args = array_merge($repository, [$options]);
  try {
    $commits_data = $commits_obj->all(...$args);
  } catch (Github\Exception\RuntimeException $e) {
    $error_count++;
    fwrite(STDERR, "Failed checking '$repository_string': " . $e->getMessage() . "\n");
    continue;
  }

  if (count($commits_data)) {
    $latest_time = new DateTime($commits_data[0]['commit']['committer']['date']);
    $commits_string = '';
    $commits_count = 1;
    foreach ($commits_data as $commit) {
      $committer = $commit['commit']['committer'];
      $msg = explode("\n", $commit['commit']['message']);
      $commits_string .= sprintf("%2d: %s", $commits_count, implode("\n    ", $msg)) . "\n";
      $commits_count++;
      $time = new DateTime($committer['date']);
      $diff = $latest_time->diff($time);
      if ($diff->invert == 0) {
        $latest_time = $time;
      }
    }

    mail(
      implode(", ", $config['mail']['to']),
      sprintf($config['mail']['subject'], $repository_string),
      sprintf($config['mail']['body'], $repository_string, $commits_string)
    );
    $persistence->setRepoLastSeenCommitTime($repository_string, $latest_time->format($ts_format));
  } else {
    if ($opts['verbose']) {
      echo "No new commits in $repository_string\n";
    }
  }
}

exit($error_count);
