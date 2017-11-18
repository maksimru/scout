<?php

namespace Laravel\Scout\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;

class MakeSearchable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * The models to be made searchable.
     *
     * @var \Illuminate\Database\Eloquent\Collection
     */
    public $models;
    public $searchable_index;

    /**
     * Create a new job instance.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @param string $searchable_index
     * @return void
     */
    public function __construct($models, $searchable_index = null)
    {
        $this->models = $models;
        $this->searchable_index = $searchable_index;
    }

    /**
     * Handle the job.
     *
     * @return void
     */
    public function handle()
    {
        if (count($this->models) === 0) {
            return;
        }

        $this->models->first()->searchableUsing()->update($this->models, $this->searchable_index);
    }
}
