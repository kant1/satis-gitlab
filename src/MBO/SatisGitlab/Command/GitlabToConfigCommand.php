<?php

namespace MBO\SatisGitlab\Command;

use Composer\Composer;
use Composer\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Generate SATIS configuration scanning gitlab repositories
 *
 * @author MBorne
 */
class GitlabToConfigCommand extends Command {

    const PER_PAGE = 50;
    const MAX_PAGES = 10000;

    protected $groups = [];

    protected function configure() {
        $templatePath = realpath( dirname(__FILE__).'/../Resources/default-template.json' );
        
        $this
            // the name of the command (the part after "bin/console")
            ->setName('gitlab-to-config')

            // the short description shown while running "php bin/console list"
            ->setDescription('generate satis configuration scanning gitlab repositories')
            ->setHelp('look for composer.json in default gitlab branche, extract project name and register them in SATIS configuration')
            ->addArgument('gitlab-url', InputArgument::REQUIRED)
            ->addArgument('gitlab-token')
            
            // deep customization : template file extended with default configuration
            ->addOption('template', null, InputOption::VALUE_REQUIRED, 'template satis.json extended with gitlab repositories', $templatePath)
            // filter by groups
            ->addOption('groups', 'G', InputOption::VALUE_REQUIRED, 'GitLab Groups seperate by ","', null)

            // simple customization
            ->addOption('homepage', null, InputOption::VALUE_REQUIRED, 'satis homepage', 'http://localhost/satis/')
            ->addOption('archive', null, InputOption::VALUE_NONE, 'enable archive mirroring')

            ->addOption('no-token', null, InputOption::VALUE_NONE, 'disable token writing in output configuration')

            // output configuration
            ->addOption('output', 'O', InputOption::VALUE_REQUIRED, 'output config file', 'satis.json')     
        ;
    }

    protected function isFiltered(&$project) {
        if (empty($this->groups) || !isset($project['namespace']['name'])) {
            return false;
        }
        return !in_array($project['namespace']['name'], $this->groups);
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        /*
         * parameters
         */
        $gitlabUrl = $input->getArgument('gitlab-url');
        $gitlabAuthToken = $input->getArgument('gitlab-token');
        $outputFile = $input->getOption('output');

        /*
         * load groups
         */
        $this->groups = is_string($input->getOption('groups')) ? explode(',', $input->getOption('groups')) : [];

        /*
         * load template satis.json file
         */
        $templatePath = $input->getOption('template');
        $output->writeln(sprintf("<info>Loading template %s...</info>", $templatePath));
        $satis = json_decode( file_get_contents($templatePath), true) ;
        
        /*
         * customize according to command line options
         */
        $satis['homepage'] = $input->getOption('homepage');
        // mirroring
        if ( $input->getOption('archive') ){
            $satis['require-dependencies'] = true;
            $satis['archive'] = array(
                'directory' => 'dist',
                'format' => 'tar',
                'skip-dev' => true
            );
        }

        /*
         * Register gitlab domain to enable composer gitlab-* authentications
         */
        $gitlabDomain = parse_url($gitlabUrl, PHP_URL_HOST);
        if ( ! isset($satis['config']) ){
            $satis['config'] = array();
        }
        if ( ! isset($satis['config']['gitlab-domains']) ){
            $satis['config']['gitlab-domains'] = array($gitlabDomain);
        }else{
            $satis['config']['gitlab-domains'][] = $gitlabDomain ;
        }

        if ( ! $input->getOption('no-token') ){
            if ( ! isset($satis['config']['gitlab-token']) ){
                $satis['config']['gitlab-token'] = array();
            }
            $satis['config']['gitlab-token'][$gitlabDomain] = $gitlabAuthToken;
        }

        /*
         * SCAN gitlab projects to find composer.json file in default branch
         */
        $output->writeln(sprintf("<info>Listing gitlab repositories from %s...</info>", $gitlabUrl));
        $client = $this->createGitlabClient($gitlabUrl, $gitlabAuthToken);
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $projects = $client->projects()->all(array(
                'page' => $page, 
                'per_page' => self::PER_PAGE
            ));
            if ( empty($projects) ){
                break;
            }
            foreach ($projects as $project) {
                if ($this->isFiltered($project)){
                    continue;
                }
                $projectUrl = $project['http_url_to_repo'];
                try {
                    $json = $client->repositoryFiles()->getRawFile($project['id'], 'composer.json', $project['default_branch']);
                    $composer = json_decode($json, true);

                    $projectName = isset($composer['name']) ? $composer['name'] : null;
                    if (is_null($projectName)) {
                        $this->displayProjectInfo($output,$project,'<error>name not defined in composer.json</error>');
                        continue;
                    }

                    $satis['repositories'][] = array(
                        'type' => 'vcs',
                        'url' => $projectUrl,
                        //TODO improve SSL management
                        'options' => [
                            "ssl" => [
                                "verify_peer" => false,
                                "verify_peer_name" => false,
                                "allow_self_signed" => true
                            ]
                        ]
                    );
                    $satis['require'][$projectName] = '*';
                    $this->displayProjectInfo($output,$project,
                        "<info>$projectName:*</info>"
                    );
                } catch (\Exception $e) {
                    $this->displayProjectInfo($output,$project,
                        'composer.json not found',
                        OutputInterface::VERBOSITY_VERBOSE
                    );
                }
            }
        }

        $output->writeln("<info>generate satis configuration file : $outputFile</info>");
        $result = json_encode($satis, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($outputFile, $result);
    }

    /**
     * display project information
     */
    protected function displayProjectInfo(
        OutputInterface $output, 
        array $project, 
        $message,
        $verbosity = OutputInterface::VERBOSITY_NORMAL
    ){
        $output->writeln(sprintf(
            '%s (branch %s) : %s',
            $project['name_with_namespace'],
            $project['default_branch'],
            $message
        ),$verbosity);
    }
    
    
    /**
     * Create gitlab client
     * @param string $gitlabUrl
     * @param string $gitlabAuthToken
     * @return \Gitlab\Client
     */
    protected function createGitlabClient($gitlabUrl, $gitlabAuthToken) {
        /*
         * create client with ssl verify disabled
         */
        $guzzleClient = new \GuzzleHttp\Client(array(
            'verify' => false
        ));
        $httpClient = new \Http\Adapter\Guzzle6\Client($guzzleClient);
        $httpClientBuilder = new \Gitlab\HttpClient\Builder($httpClient);

        $client = new \Gitlab\Client($httpClientBuilder);
        $client->setUrl($gitlabUrl);

        /*
         * gitlab authentification
         */
        $client
            ->authenticate($gitlabAuthToken, \Gitlab\Client::AUTH_URL_TOKEN)
        ;
        
        return $client;
    }

}
