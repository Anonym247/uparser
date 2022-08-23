<?php

namespace App\Console\Commands\Parser;

use App\Services\AutocomParser;
use Illuminate\Console\Command;

class UpdateCars extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'parser:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';
    /**
     * @var AutocomParser
     */
    private $parser;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(AutocomParser $parser)
    {
        $this->parser = $parser;

        parent::__construct();
    }

    public function handle()
    {
        ini_set('memory_limit', -1);

        $this->parser->fetchNewestCars();
    }
}
