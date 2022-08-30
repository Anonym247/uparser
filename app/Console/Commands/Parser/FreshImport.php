<?php

namespace App\Console\Commands\Parser;

use App\Services\AutocomParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FreshImport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'parser:import';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Parse from scratch';
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
        parent::__construct();

        $this->parser = $parser;
    }

    /**
     * Execute the console command.
     * @return void
     */
    public function handle(): void
    {
        try {
            $hasRanges = false;
            $tree = $this->makeBinaryTreeOfRanges($this->parser->getYearMin(), $this->parser->getYearMax());
            $this->parser->setRangesTree($tree);

            if (!$this->parser->checkRanges()) {
                $hasRanges = true;
                $this->info('Table vehicle_ranges is not empty! Update or continue!');
            }

            if (!$hasRanges) {
                $this->parser->checkPriceRanges();
                $this->parser->freshTables();
            }

            $this->parser->fetchAllFromRanges();
        } catch (\Exception $exception) {
            Log::error($exception->getMessage(), [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTrace(),
            ]);
        }
    }

    private function makeBinaryTreeOfRanges(int $left, int $right): array
    {
        $tree = [
            'value' => null,
            'left' => [
                'value' => $left
            ],
            'right' => [
                'value' => $right
            ],
        ];

        return $this->buildTree($tree);
    }

    function buildTree(array $node): array
    {
        $left = $node['left']['value'] ?? null;
        $right = $node['right']['value'] ?? null;

        $arrange = (int)floor(($right - $left) / 2);

        if (!isset($node['left']['left']) && ($left != $right)) {
            $leftNode = [
                'value' => $left,
                'left' => [
                    'value' => $left
                ],
                'right' => [
                    'value' => $left + $arrange
                ]
            ];

            $leftValue = $left + $arrange + 1;

            $rightNode = [
                'value' => $right,
                'left' => [
                    'value' => $leftValue > $right ? $right : $leftValue,
                ],
                'right' => [
                    'value' => $right
                ]
            ];

            $node['left'] = $leftNode;
            $node['right'] = $rightNode;

            $node['left'] = $arrange ? $this->buildTree($node['left']) : $node['left'];
            $node['right'] = $arrange ? $this->buildTree($node['right']) : $node['right'];
        }

        return $node;
    }
}
