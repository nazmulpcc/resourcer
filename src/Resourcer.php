<?php
namespace nazmulpcc;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Resourcer extends Command
{
    /**
     * Input
     * @var InputInterface
     */
    protected $input;
    /**
     * Output
     * @var OutputInterface
     */
    protected $output;

    /**
     * The proc retured process
     * @var resource
     */
    protected $process;

    /**
     * Whether the process is running
     * @var bool
     */
    protected $isRunning;

    /**
     * When running started
     * @var double
     */
    protected $start_at;
    /**
     * Elapsed time since starting
     * @var double
     */
    protected $elapsed;
    /**
     * Time limit for the command
     * @var double
     */
    protected $time;
    /**
     * The Memory Limit
     * @var integer
     */
    protected $memory;
    /**
     * Used Memory
     * @var integer
     */
    protected $rss;
    /**
     * Whether time limit was reached
     * @var boolean
     */
    protected $isTimeLimit = false;
    /**
     * Whether memory limit was reached
     * @var boolean
     */
    protected $isMemoryLimit = false;
    /**
     * The command exit status
     * @var integer
     */
    protected $exit;
    /**
     * Options
     *
     * @var array
     */
    protected $options;

    /**
     * The file where the meta data will be written
     * @var string
     */
    protected $meta = false;

    protected function configure()
    {
        $this
            ->setName('limit')
            ->setHelp("resourcer -t 1 -m 1024 -i /path/input -o /path/output -d /path/checkFile cmd")
            ->setDescription('Limit Resources and run a command.')
            ->setDefinition(
                new InputDefinition([
                    new InputOption('time', 't', InputOption::VALUE_OPTIONAL, 'Time Limit', 1),
                    new InputOption('mem', 'm', InputOption::VALUE_OPTIONAL, 'Memory Limit', 1024*256),
                    new InputOption('in', 'i', InputOption::VALUE_OPTIONAL, 'Input File', "/dev/null"),
                    new InputOption('out', 'o', InputOption::VALUE_OPTIONAL, 'Output File', "/dev/null"),
                    new InputOption('err', 'e', InputOption::VALUE_OPTIONAL, 'Error File', "/dev/null"),
                    new InputOption('diff', 'd', InputOption::VALUE_OPTIONAL, 'Check diff with output', false),
                    new InputOption('cwd', 'w', InputOption::VALUE_OPTIONAL, 'Current Working Directory', null),
                    new InputOption('meta', 'M', InputOption::VALUE_OPTIONAL, 'The meta file', null),
                    new InputArgument('cmd', InputArgument::REQUIRED, 'The command to run')
                ])
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->setUp($input, $output);
        $this->runCommand();
        $output = $this->getOutputForUser();
        if($this->meta && file_exists(dirname($this->meta)))
            file_put_contents($this->meta, $output);
        else
            $this->output->writeln($output);
    }

    public function setUp($input, $output)
    {
        $this->input = $input;
        $this->output = $output;
        $this->options = $this->input->getOptions();
        $this->options['cmd'] = $this->input->getArgument('cmd');
        return $this;
    }

    public function runCommand()
    {
        $this->startRunning();
        while($this->isRunning) {
            $this->updateRunningStatus();
            $this->updateElapsed();
            $this->updatedMemory();
        }
        return $this;
    }

    public function getOutputForUser()
    {
        $data = [
            'time' => $this->elapsed,
            'memory' => $this->rss,
            'timeLimit' => $this->isTimeLimit,
            'memoryLimit' => $this->isMemoryLimit
        ];
        return json_encode($data);
    }
    
    public function processRunTimeError()
    {
        $this->isRunning = false;
        $this->output->writeln("<error>Run time error!</error>");
    }

    /**
     * Proc Open Descriptor
     * @return array
     */
    protected function getDescriptor()
    {
        return [
            0 => array("file" , $this->input->getOption('in'), "r"),
            1 => array("file", $this->input->getOption('out'), "w"),
            2 => array("file", $this->input->getOption('err'), "w"),
        ];
    }

    public function startRunning()
    {
        $this->time = $this->input->getOption('time');
        $this->memory = $this->getMemory();
        $this->elapsed = 0;
        $this->process = proc_open($this->options['cmd'], $this->getDescriptor(), $pipes, $this->options['cwd']);
        $this->firstTimeUpdate();
        return $this;
    }

    public function firstTimeUpdate()
    {
        $status = proc_get_status($this->process);
        if($status == false){
            $this->processRunTimeError();
        }
        $this->pid = $status['pid'];
        $this->start_at = microtime(true);
        $this->rss = 0;
        $this->isRunning = $status['running'];
        return $this;
    }

    public function updateElapsed()
    {
        $this->elapsed = microtime(true) - $this->start_at;
        if($this->elapsed >= $this->time){
            $this->processTimeLimitException();
        }
        return $this;
    }

    public function updatedMemory()
    {
        $rss = $this->getMemoryUsage();
        if($rss > $this->memory){
            $this->processMemoryLimitException();
        }elseif($rss > $this->rss){
            $this->rss = $rss;
        }
        return $this;
    }

    public function getMemoryUsage()
    {
        $cmd = "ps -p {$this->pid} -o rss";
        list($dump, $rss) = explode("\n", shell_exec($cmd));
        return (int) $rss;
    }

    public function updateRunningStatus()
    {
        $status = proc_get_status($this->process);
        $this->isRunning = $status['running'];
        if(!$this->isRunning){
            $this->exit = $status['exitcode'];
        }
        return $this;
    }

    public function getMemory()
    {
        return $this->input->getOption('mem'); // TODO: parse unit
    }

    public function processMemoryLimitException()
    {
        proc_close($this->process);
        $this->isRunning = false;
        $this->isMemoryLimit = true;
        return $this;
    }

    public function processTimeLimitException()
    {
        proc_terminate($this->process);
        $this->isRunning = false;
        $this->isTimeLimit = true;
        return $this;
    }
}