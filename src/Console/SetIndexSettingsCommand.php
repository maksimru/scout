<?php

namespace Laravel\Scout\Console;

use Illuminate\Console\Command;
use Laravel\Scout\EngineManager;

class SetIndexSettingsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scout:settings';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import settings to default index';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        app(EngineManager::class)->engine()->putSettings();
    }
}
