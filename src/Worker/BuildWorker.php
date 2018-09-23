<?php

namespace PHPCensor\Worker;

use PHPCensor\Service\BuildService;
use PHPCensor\Store\BuildStore;
use PHPCensor\Store\Factory;
use Monolog\Logger;
use Pheanstalk\Job;
use Pheanstalk\Pheanstalk;
use PHPCensor\Builder;
use PHPCensor\BuildFactory;
use PHPCensor\Logging\BuildDBLogHandler;
use PHPCensor\Model\Build;
use PHPCensor\Store\ProjectStore;
use Symfony\Component\Yaml\Yaml;

/**
 * Class BuildWorker
 * @package PHPCensor\Worker
 */
class BuildWorker
{
    /**
     * If this variable changes to false, the worker will stop after the current build.
     *
     * @var bool
     */
    protected $run = true;

    /**
     * The logger for builds to use.
     *
     * @var \Monolog\Logger
     */
    protected $logger;

    /**
     * beanstalkd host
     *
     * @var string
     */
    protected $host;

    /**
     * beanstalkd queue to watch
     *
     * @var string
     */
    protected $queue;

    /**
     * @var \Pheanstalk\Pheanstalk
     */
    protected $pheanstalk;

    /**
     * @var int
     */
    protected $totalJobs = 0;

    /**
     * @var int
     */
    protected $lastPeriodical;

    /**
     * @param string $host
     * @param string $queue
     */
    public function __construct($host, $queue)
    {
        $this->lastPeriodical = 0;
        $this->host           = $host;
        $this->queue          = $queue;
        $this->pheanstalk     = new Pheanstalk($this->host);
    }

    /**
     * @param Logger $logger
     */
    public function setLogger(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Start the worker.
     */
    public function startWorker()
    {
        
        $buildStore = Factory::getStore('Build');

        while ($this->run) {
            $this->createPeriodicalBuilds();

            $job = $this->pheanstalk
                ->watch($this->queue)
                ->ignore('default')
                ->reserve();

            // Get the job data and run the job:
            $jobData = json_decode($job->getData(), true);

            if (!$this->verifyJob($job, $jobData)) {
                continue;
            }

            $this->logger->addInfo('Received build #'.$jobData['build_id'].' from Beanstalkd');

            $build = BuildFactory::getBuildById($jobData['build_id']);
            if (!$build) {
                $this->logger->addWarning('Build #' . $jobData['build_id'] . ' does not exist in the database.');
                $this->pheanstalk->delete($job);
                continue;
            }

            // Logging relevant to this build should be stored
            // against the build itself.
            $buildDbLog = new BuildDBLogHandler($build, Logger::INFO);
            $this->logger->pushHandler($buildDbLog);

            try {
                $builder = new Builder($build, $this->logger);
                $builder->execute();
            } catch (\PDOException $ex) {
                // If we've caught a PDO Exception, it is probably not the fault of the build, but of a failed
                // connection or similar. Release the job and kill the worker.
                $this->run = false;
                $this->pheanstalk->release($job);
                unset($job);
            } catch (\Exception $ex) {
                $this->logger->addError($ex->getMessage());

                $build->setStatus(Build::STATUS_FAILED);
                $build->setFinishDate(new \DateTime());
                $build->setLog($build->getLog() . PHP_EOL . PHP_EOL . $ex->getMessage());
                $buildStore->save($build);
                $build->sendStatusPostback();
            }

            // After execution we no longer want to record the information
            // back to this specific build so the handler should be removed.
            $this->logger->popHandler();
            // destructor implicitly call flush
            unset($buildDbLog);

            // Delete the job when we're done:
            if (!empty($job)) {
                $this->pheanstalk->delete($job);
            }
        }
    }

    /**
     * Stops the worker after the current build.
     */
    public function stopWorker()
    {
        $this->run = false;
    }

    protected function createPeriodicalBuilds()
    {
        $currentTime = time();
        if (($this->lastPeriodical + 60) > $currentTime) {
            return;
        }

        $this->lastPeriodical = ($currentTime - 1);

        if (file_exists(APP_DIR . 'periodical.yml')) {
            $parser = new Yaml();
            $yml    = file_get_contents(APP_DIR . 'periodical.yml');
            $config = (array)$parser->parse($yml);

            if ($config && !empty($config['projects'])) {
                /** @var BuildStore $buildStore */
                $buildStore   = Factory::getStore('Build');
                $buildService = new BuildService($buildStore);

                /** @var ProjectStore $projectStore */
                $projectStore = Factory::getStore('Project');

                foreach ($config['projects'] as $projectId => $projectConfig) {
                    $project = $projectStore->getById($projectId);

                    if (!$project || empty($projectConfig['interval']) || empty($projectConfig['branches'])) {
                        continue;
                    }

                    $date     = new \DateTime('now');
                    $interval = new \DateInterval($projectConfig['interval']);
                    $date->sub($interval);

                    foreach ($projectConfig['branches'] as $branch) {
                        $latestBuild = $buildStore->getLatestBuildByProjectAndBranch($projectId, $branch);

                        if ($latestBuild) {
                            $status = (integer)$latestBuild->getStatus();
                            if ($status === Build::STATUS_RUNNING || $status === Build::STATUS_PENDING) {
                                continue;
                            }

                            if ($date < $latestBuild->getFinishDate()) {
                                continue;
                            }
                        }

                        $buildService->createBuild(
                            $project,
                            null,
                            '',
                            $branch,
                            null,
                            null,
                            null,
                            Build::SOURCE_PERIODICAL
                        );
                    }
                }
            }
        }
    }

    /**
     * Checks that the job received is actually, and has a valid type.
     *
     * @param Job   $job
     * @param array $jobData
     *
     * @return boolean
     */
    protected function verifyJob(Job $job, $jobData)
    {
        if (empty($jobData) || !is_array($jobData)) {
            $this->pheanstalk->delete($job);
            return false;
        }

        if (!array_key_exists('type', $jobData) || $jobData['type'] !== 'php-censor.build') {
            $this->pheanstalk->delete($job);
            return false;
        }

        return true;
    }
}
