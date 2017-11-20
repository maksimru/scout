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
     * @var \Illuminate\Support\Collection
     */
    public $models;
    public $searchable_index;
    public $model_class;

    /**
     * Create a new job instance.
     *
     * @param  \Illuminate\Support\Collection  $models
     * @param string $searchable_index
     * @return void
     */
    public function __construct($models, $searchable_index = null, $model_class = null)
    {
        $this->models = $models;
        $this->searchable_index = $searchable_index;
        $this->model_class = $model_class;
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

        (resolve($this->model_class))->searchableUsing()->update($this->models, $this->searchable_index);
    }
}
