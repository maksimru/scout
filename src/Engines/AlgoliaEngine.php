<?php

namespace Laravel\Scout\Engines;

use AlgoliaSearch\Client as Algolia;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Scout\Builder;

class AlgoliaEngine extends Engine
{
    /**
     * The Algolia client.
     *
     * @var \AlgoliaSearch\Client
     */
    protected $algolia;

    /**
     * Create a new engine instance.
     *
     * @param  \AlgoliaSearch\Client  $algolia
     * @return void
     */
    public function __construct(Algolia $algolia)
    {
        $this->algolia = $algolia;
    }

    public function putSettings(){
    }

    /**
     * Update the given model in the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @throws \AlgoliaSearch\AlgoliaException
     * @return void
     */
    public function update($models, $searchable_index = null)
    {
        $index = $this->algolia->initIndex(is_null($searchable_index) ? $models->first()->searchableAs() : $searchable_index);

        $index->addObjects($models->map(function ($model) {
            $array = $model->toSearchableArray();

            if (empty($array)) {
                return;
            }

            return array_merge(['objectID' => $model->getKey()], $array);
        })->filter()->values()->all());
    }

    /**
     * Remove the given model from the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function delete($models, $searchable_index = null)
    {
        $index = $this->algolia->initIndex(is_null($searchable_index) ? $models->first()->searchableAs() : $searchable_index);

        $index->deleteObjects(
            $models->map(function ($model) {
                return $model->getKey();
            })->values()->all()
        );
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return mixed
     */
    public function search(Builder $builder)
    {
        return $this->performSearch($builder, array_filter([
            'numericFilters' => $this->filters($builder),
            'hitsPerPage' => $builder->limit,
        ]));
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  int  $perPage
     * @param  int  $page
     * @return mixed
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        return $this->performSearch($builder, [
            'numericFilters' => $this->filters($builder),
            'hitsPerPage' => $perPage,
            'page' => $page - 1,
        ]);
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  array  $options
     * @return mixed
     */
    protected function performSearch(Builder $builder, array $options = [])
    {
        $algolia = $this->algolia->initIndex(
            $builder->index ?: $builder->model->searchableAs()
        );

        if ($builder->callback) {
            return call_user_func(
                $builder->callback,
                $algolia,
                $builder->query,
                $options
            );
        }

        return $algolia->search($builder->query, $options);
    }

    /**
     * Get the filter array for the query.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return array
     */
    protected function filters(Builder $builder)
    {
        return collect($builder->wheres)->map(function ($value, $key) {
            return $key.'='.$value;
        })->values()->all();
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param  mixed  $results
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        return collect($results['hits'])->pluck('objectID')->values();
    }

    /**
     * Map the given results to instances of the given model.
     *
     * @param  mixed  $results
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function map($results, $model)
    {
        if (count($results['hits']) === 0) {
            return Collection::make();
        }

        $keys = collect($results['hits'])
                        ->pluck('objectID')->values()->all();

        $models = $model->whereIn(
            $model->getQualifiedKeyName(), $keys
        )->get()->keyBy($model->getKeyName());

        return Collection::make($results['hits'])->map(function ($hit) use ($model, $models) {
            $key = $hit['objectID'];

            if (isset($models[$key])) {
                return $models[$key];
            }
        })->filter();
    }

    /**
     * Get the total count from a raw result returned by the engine.
     *
     * @param  mixed  $results
     * @return int
     */
    public function getTotalCount($results)
    {
        return $results['nbHits'];
    }
}
