<?php

namespace App\Command;

use phpseclib\Net\SFTP;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class WasatchJobsQueueManagerCommand extends Command
{
    protected static $defaultName = 'wasatch:jobs:queue-manager';

    protected $arguments;
    protected $options;
    protected $logger;
    protected $params;
    protected $sftp;

    public function __construct(LoggerInterface $logger, ParameterBagInterface $params, string $name = null)
    {
        parent::__construct($name);
        $this->logger = $logger;
        $this->params = $params;
    }

    protected function configure()
    {
        $this
            ->setDescription('Enqueue files for Wasatch Hotfolder')
            ->addArgument(
                'source', InputArgument::REQUIRED,
                'Location containing files'
            )
            ->addArgument('destination', InputArgument::REQUIRED, 'Wasatch Hotfolder')
            ->addOption(
                'local-source', null, InputOption::VALUE_OPTIONAL,
                'Source is a local directory, if false (default) command looks' .
                'for it in a remote server (SFTP)',
                0
            )
            ->addOption(
                'wasatch-file-limit', null, InputOption::VALUE_OPTIONAL,
                'File Limit', 5
            )
            ->addOption(
                'wasatch-file-extension', null, InputOption::VALUE_OPTIONAL,
                'Wasatch valid file extension', '*.xml'
            )
            ->addOption(
                'hosonsoft-path', null, InputOption::VALUE_OPTIONAL,
                'Hosonsoft PrintExp installation directory',
                'C:\Program Files (x86)\PrintExp_V5.6.2.56.R'
            )
            ->addOption(
                'hosonsoft-threshold', null, InputOption::VALUE_OPTIONAL,
                'Hosonsoft print jobs threshold', 2
            )
            ->addOption(
                'clean-first', null, InputOption::VALUE_OPTIONAL,
                'Clean Hosonsoft empty temporary folders and old printing jobs', 1
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $this->arguments = [
            'source' => $input->getArgument('source'),
            'destination' => $input->getArgument('destination')
        ];
        $this->options = [
            'hosonsoft-path' => $input->getOption('hosonsoft-path'),
            'hosonsoft-threshold' => $input->getOption('hosonsoft-threshold'),
            'wasatch-file-extension' => $input->getOption('wasatch-file-extension'),
            'wasatch-file-limit' => $input->getOption('wasatch-file-limit'),
            'clean-first' => $input->getOption('clean-first')
        ];

        $this->logger->notice(
            sprintf('Wasatch Jobs Queue Manager started'),
            ['arguments' => $this->arguments, 'options' => $this->options]
        );

        if ($this->options['clean-first'] == 1) {
            $io->warning('Cleaning print jobs folder...');
            $this->_cleanPrintJobsFolder();
        }

        if ($this->$this->_getPrintJobsFolders()->count() <= $this->options['hosonsoft-threshold']) {
            return $this->_moveFilesToWasatchHotfolder($io);
        } else {
            $io->warning('Files not moved, threshold not reached');
        }

        return Command::SUCCESS;
    }

    private function _cleanPrintJobsFolder()
    {
        $fileSystem = new Filesystem();
        $fileSystem->remove($this->_getPrintJobsFolders(true)->getIterator());
    }

    private function _getPrintJobsFolders($empty = false)
    {
        $fileFinder = new Finder();
        return $fileFinder->in(
        $this->options['hosonsoft-path'] . DIRECTORY_SEPARATOR . 'temp')
        ->filter(
            function (SplFileInfo $file) use ($empty) {
                $fileFinder = new Finder();
                if ($file->isDir() && $fileFinder->in($file->getRealPath())->files()->count() > 0) {
                    return !$empty;
                }
                return $empty;
            }
        );
    }

    private function _moveFilesToWasatchHotfolder(SymfonyStyle $io)
    {
        $fileFinder = new Finder();
        $fileSystem = new Filesystem();
        $filesToMove = $fileFinder->in($this->arguments['source'])->sortByName()
            ->files()->name($this->options['wasatch-file-extension']);

        if($filesToMove->count() > 0) {
            $i = 0;
            foreach ($filesToMove as $fileToMove) {
                if ($i >=$this->options['wasatch-file-limit'] ) {
                    break;
                }
                $fileSystem->rename(
                    $fileToMove->getRealPath(), $this->arguments['destination'] . '/' . $fileToMove->getFilename(), true
                );
                $msg = sprintf(
                    'File %s moved from %s to %s',
                    $fileToMove->getFilename(),
                    $this->arguments['source'], $this->arguments['destination']
                );

                $this->logger->info($msg);
                $io->success($msg);
                $i++;
            }
        }

        return Command::SUCCESS;
    }
}
