<?php

namespace Laravel\Scout;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Laravel\Scout\Jobs\MakeSearchable;

trait Searchable
{
    private $search_index = null;

    /**
     * Boot the trait.
     *
     * @return void
     */
    public static function bootSearchable()
    {
        static::addGlobalScope(new SearchableScope);

        static::observe(new ModelObserver);

        (new static)->registerSearchableMacros();
    }

    public function scopeWithSearchIndex($query, $index)
    {
        $this->search_index = $index;
        return $query;
    }

    public function setSearchIndex($search_index)
    {
        $this->search_index = $search_index;
    }

    public function isVirtualIndex(){
        return $this->getSearchIndex() != $this->getTable();
    }

    public function getSearchIndex()
    {
        return is_null($this->search_index) ? $this->getTable() : $this->search_index;
    }

    public function searchableAs()
    {
        return config('scout.prefix').$this->getSearchIndex();
    }

    /**
     * Register the searchable macros.
     *
     * @return void
     */
    public function registerSearchableMacros()
    {
        $self = $this;

        BaseCollection::macro('searchable', function ($searchable_index = null) use ($self) {
            $self->queueMakeSearchable($this, $searchable_index);
        });

        BaseCollection::macro('unsearchable', function ($searchable_index = null) use ($self) {
            $self->queueRemoveFromSearch($this, $searchable_index);
        });
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return mixed
     */
    public function initEngineFromCollection($models){
        $model_sample = $models->first();
        return $model_sample->searchableUsing();
    }

    /**
     * Dispatch the job to make the given models searchable.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function queueMakeSearchable($models, $searchable_index = null)
    {
        if ($models->isEmpty()) {
            return;
        }

        $model_sample = $models->first();
        $model_class = get_class($model_sample);

        if (! config('scout.queue')) {
            return $this->initEngineFromCollection($models)->update($models, $searchable_index);
        }

        //if search index is used replace model to array to keep custom data
        if(!is_null($searchable_index)){
            $model_array_collection = new BaseCollection();
            $models->each(function($model) use ($model_array_collection){
                $model_array_collection->push(array_merge($model->toSearchableArray(),['__key' => $model->getKey(),'__as' => $model->searchableAs()]));
            });
            $models = $model_array_collection;
        }

        dispatch((new MakeSearchable($models, $searchable_index, $model_class))
            ->onQueue($model_sample->syncWithSearchUsingQueue())
            ->onConnection($model_sample->syncWithSearchUsing()));
    }

    /**
     * Dispatch the job to make the given models unsearchable.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function queueRemoveFromSearch($models, $searchable_index = null)
    {
        if ($models->isEmpty()) {
            return;
        }

        return $this->initEngineFromCollection($models)->delete($models, $searchable_index);
    }

    /**
     * Perform a search against the model's indexed data.
     *
     * @param  string  $query
     * @param  Closure  $callback
     * @return \Laravel\Scout\Builder
     */
    public static function search($query, $callback = null)
    {
        return new Builder(new static, $query, $callback);
    }

    /**
     * Make all instances of the model searchable.
     *
     * @return void
     */
    public static function makeAllSearchable()
    {
        $self = new static();

        $self->newQuery()
            ->orderBy($self->getKeyName())
            ->searchable();
    }

    /**
     * Make the given model instance searchable.
     *
     * @return void
     */
    public function searchable()
    {
        Collection::make([$this])->searchable();
    }

    /**
     * Remove all instances of the model from the search index.
     *
     * @return void
     */
    public static function removeAllFromSearch()
    {
        $self = new static();

        $self->newQuery()
            ->orderBy($self->getKeyName())
            ->unsearchable();
    }

    /**
     * Remove the given model instance from the search index.
     *
     * @return void
     */
    public function unsearchable()
    {
        Collection::make([$this])->unsearchable();
    }

    /**
     * Enable search syncing for this model.
     *
     * @return void
     */
    public static function enableSearchSyncing()
    {
        ModelObserver::enableSyncingFor(get_called_class());
    }

    /**
     * Disable search syncing for this model.
     *
     * @return void
     */
    public static function disableSearchSyncing()
    {
        ModelObserver::disableSyncingFor(get_called_class());
    }

    /**
     * Temporarily disable search syncing for the given callback.
     *
     * @param  callable  $callback
     * @return void
     */
    public static function withoutSyncingToSearch($callback)
    {
        static::disableSearchSyncing();

        try {
            $callback();
        } finally {
            static::enableSearchSyncing();
        }
    }

    /**
     * Get the indexable data array for the model.
     *
     * @return array
     */
    public function toSearchableArray()
    {
        return $this->toArray();
    }

    /**
     * Get the Scout engine for the model.
     *
     * @return mixed
     */
    public function searchableUsing()
    {
        return app(EngineManager::class)->engine();
    }

    /**
     * Get the queue connection that should be used when syncing.
     *
     * @return string
     */
    public function syncWithSearchUsing()
    {
        return config('queue.default');
    }

    /**
     * Get the queue that should be used with syncing
     *
     * @return  string|null
     */
    public function syncWithSearchUsingQueue()
    {
        return null;
    }
}
