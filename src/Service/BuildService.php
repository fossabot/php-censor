<?php

namespace PHPCensor\Service;

use PHPCensor\Config;
use Pheanstalk\Pheanstalk;
use Pheanstalk\PheanstalkInterface;
use PHPCensor\BuildFactory;
use PHPCensor\Model\Build;
use PHPCensor\Model\Project;
use PHPCensor\Store\BuildStore;
use PHPCensor\Store\ProjectStore;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * The build service handles the creation, duplication and deletion of builds.
 */
class BuildService
{
    /**
     * @var BuildStore
     */
    protected $buildStore;

    /**
     * @var ProjectStore
     */
    protected $projectStore;

    /**
     * @var boolean
     */
    public $queueError = false;

    /**
     * @param BuildStore   $buildStore
     * @param ProjectStore $projectStore
     */
    public function __construct(
        BuildStore $buildStore,
        ProjectStore $projectStore
    )
    {
        $this->buildStore   = $buildStore;
        $this->projectStore = $projectStore;
    }

    /**
     * @param Project     $project
     * @param string      $environment
     * @param string      $commitId
     * @param string|null $branch
     * @param string|null $tag
     * @param string|null $committerEmail
     * @param string|null $commitMessage
     * @param integer     $source
     * @param integer     $userId
     * @param array|null  $extra
     *
     * @return \PHPCensor\Model\Build
     */
    public function createBuild(
        Project $project,
        $environment,
        $commitId = '',
        $branch = null,
        $tag = null,
        $committerEmail = null,
        $commitMessage = null,
        $source = Build::SOURCE_UNKNOWN,
        $userId = 0,
        $extra = null
    ) {
        $build = new Build();
        $build->setCreateDate(new \DateTime());
        $build->setProjectId($project->getId());
        $build->setStatus(Build::STATUS_PENDING);
        $build->setEnvironment($environment);

        if (!is_null($extra)) {
            $build->setExtra($extra);
        }

        $branches = $project->getBranchesByEnvironment($environment);
        $build->addExtraValue('branches', $branches);

        $build->setSource($source);
        $build->setUserId($userId);
        $build->setCommitId((string)$commitId);

        if (!empty($branch)) {
            $build->setBranch($branch);
        } else {
            $build->setBranch($project->getBranch());
        }

        if (!empty($tag)) {
            $build->setTag($tag);
        }

        if (!empty($committerEmail)) {
            $build->setCommitterEmail($committerEmail);
        }

        if (!empty($commitMessage)) {
            $build->setCommitMessage($commitMessage);
        }

        /** @var Build $build */
        $build   = $this->buildStore->save($build);
        $buildId = $build->getId();

        if (!empty($buildId)) {
            $build = BuildFactory::getBuild($build);
            $build->sendStatusPostback();
            $this->addBuildToQueue($build);
        }

        return $build;
    }

    public function createPeriodicalBuilds()
    {
        if (file_exists(APP_DIR . 'periodical.yml')) {
            $parser = new Yaml();
            $config = (array)$parser->parse(
                file_get_contents(APP_DIR . 'periodical.yml')
            );

            if ($config && !empty($config['projects'])) {
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
                        $latestBuild = $this->buildStore->getLatestBuildByProjectAndBranch($projectId, $branch);

                        if ($latestBuild) {
                            $status = (integer)$latestBuild->getStatus();
                            if ($status === Build::STATUS_RUNNING || $status === Build::STATUS_PENDING) {
                                continue;
                            }

                            if ($date < $latestBuild->getFinishDate()) {
                                continue;
                            }
                        }

                        $this->createBuild(
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
     * @param Build $copyFrom
     *
     * @return \PHPCensor\Model\Build
     */
    public function createDuplicateBuild(Build $copyFrom)
    {
        $build = new Build();
        $build->setProjectId($copyFrom->getProjectId());
        $build->setCommitId($copyFrom->getCommitId());
        $build->setBranch($copyFrom->getBranch());
        $build->setTag($copyFrom->getTag());
        $build->setCommitterEmail($copyFrom->getCommitterEmail());
        $build->setCommitMessage($copyFrom->getCommitMessage());
        $build->setExtra($copyFrom->getExtra());
        $build->setEnvironment($copyFrom->getEnvironment());
        $build->setSource($copyFrom->getSource());
        $build->setUserId($copyFrom->getUserId());
        $build->setCreateDate(new \DateTime());
        $build->setStatus(Build::STATUS_PENDING);

        /** @var Build $build */
        $build   = $this->buildStore->save($build);
        $buildId = $build->getId();

        if (!empty($buildId)) {
            $build = BuildFactory::getBuild($build);
            $build->sendStatusPostback();
            $this->addBuildToQueue($build);
        }

        return $build;
    }

    /**
     * @param int $projectId
     *
     * @throws \PHPCensor\Exception\HttpException
     */
    public function deleteOldByProject($projectId)
    {
        $keepBuilds = (int)Config::getInstance()->get('php-censor.build.keep_builds', 100);
        $builds     = $this->buildStore->getOldByProject((int)$projectId, $keepBuilds);
        
        /** @var Build $build */
        foreach ($builds['items'] as $build) {
            $build->removeBuildDirectory(true);
            $this->buildStore->delete($build);
        }
    }

    /**
     * @param int $projectId
     */
    public function deleteAllByProject($projectId)
    {
        $this->buildStore->deleteAllByProject((int)$projectId);

        try {
            $projectPaths = [
                RUNTIME_DIR . 'builds/' . $projectId . '/',
                PUBLIC_DIR . 'artifacts/pdepend/' . $projectId . '/',
                PUBLIC_DIR . 'artifacts/phpunit/' . $projectId . '/',
            ]; 

            $fileSystem = new Filesystem();

            foreach ($projectPaths as $projectPath) {
                if (is_link($projectPath)) {
                    // Remove the symlink without using recursive.
                    exec(sprintf('rm "%s"', $projectPath));
                } else {
                    $fileSystem->remove($projectPath);
                }
            }
        } catch (\Exception $e) {

        }
    }

    /**
     * Delete a given build.
     *
     * @param Build $build
     *
     * @return boolean
     */
    public function deleteBuild(Build $build)
    {
        $build->removeBuildDirectory(true);

        return $this->buildStore->delete($build);
    }

    /**
     * Takes a build and puts it into the queue to be run (if using a queue)
     * @param Build $build
     */
    public function addBuildToQueue(Build $build)
    {
        $buildId = $build->getId();

        if (empty($buildId)) {
            return;
        }

        $config   = Config::getInstance();
        $settings = $config->get('php-censor.queue', []);

        if (!empty($settings['host']) && !empty($settings['name'])) {
            try {
                $jobData = [
                    'type'     => 'php-censor.build',
                    'build_id' => $build->getId(),
                ];

                $pheanstalk = new Pheanstalk($settings['host']);
                $pheanstalk->useTube($settings['name']);
                $pheanstalk->put(
                    json_encode($jobData),
                    PheanstalkInterface::DEFAULT_PRIORITY,
                    PheanstalkInterface::DEFAULT_DELAY,
                    $config->get('php-censor.queue.lifetime', 600)
                );
            } catch (\Exception $ex) {
                $this->queueError = true;
            }
        }
    }
}
