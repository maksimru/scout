<?php

namespace Laravel\Scout\Engines;

use Elasticsearch\Client as Elasticsearch;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Laravel\Scout\Builder;

class ElasticsearchEngine extends Engine
{
    /**
     * The Elasticsearch client instance.
     *
     * @var \Elasticsearch\Client
     */
    protected $elasticsearch;

    /**
     * The index name.
     *
     * @var string
     */
    protected $index;

    /**
     * Create a new engine instance.
     *
     * @param  \Elasticsearch\Client  $elasticsearch
     * @return void
     */
    public function __construct(Elasticsearch $elasticsearch, $index)
    {
        $this->elasticsearch = $elasticsearch;

        $this->index = $index;
    }

    public function putSettings(){
        $this->elasticsearch->indices()->close([
            'index' => $this->index,
        ]);

        $this->elasticsearch->indices()->putSettings([
            'index' => $this->index,
            'body' => [
                'settings' => [
                    'index.mapping.ignore_malformed' => true,
                ]
            ]
        ]);

        $this->elasticsearch->indices()->open([
            'index' => $this->index,
        ]);
    }

    /**
     * Update the given model in the index.
     *
     * @param  Collection  $models
     * @return void
     */
    public function update($models, $searchable_index = null)
    {
        $body = new BaseCollection();

        $models->each(function ($model) use ($body, $searchable_index) {

            if(is_object($model)) {
                $array = $model->toSearchableArray();

                if (empty($array)) {
                    return;
                }

                $body->push([
                    'index' => [
                        '_index' => $this->index,
                        '_type' => is_null($searchable_index) ? $model->searchableAs() : $searchable_index,
                        '_id' => $model->getKey(),
                    ],
                ]);
            } else {
                $array = $model;

                if (empty($array)) {
                    return;
                }

                $body->push([
                    'index' => [
                        '_index' => $this->index,
                        '_type' => is_null($searchable_index) ? $model['__as'] : $searchable_index,
                        '_id' => $model['__key'],
                    ],
                ]);
            }

            $body->push($array);
        });

        $this->elasticsearch->bulk([
            'refresh' => true,
            'body' => $body->all(),
        ]);
    }

    /**
     * Remove the given model from the index.
     *
     * @param  Collection  $models
     * @return void
     */
    public function delete($models, $searchable_index = null)
    {
        $body = new BaseCollection();

        $models->each(function ($model) use ($body, $searchable_index) {
            if(is_object($model)) {
                $body->push([
                    'delete' => [
                        '_index' => $this->index,
                        '_type' => is_null($searchable_index) ? $model->searchableAs() : $searchable_index,
                        '_id' => $model->getKey(),
                    ],
                ]);
            }
            else {
                $body->push([
                    'delete' => [
                        '_index' => $this->index,
                        '_type' => is_null($searchable_index) ? $model['__as'] : $searchable_index,
                        '_id' => $model['__key'],
                    ],
                ]);
            }
        });

        $this->elasticsearch->bulk([
            'refresh' => true,
            'body' => $body->all(),
        ]);
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  Builder  $query
     * @return mixed
     */
    public function search(Builder $query)
    {
        return $this->performSearch($query, [
            'filters' => $this->filters($query),
            'size' => $query->limit ?: 10000,
        ]);
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  Builder  $query
     * @param  int  $perPage
     * @param  int  $page
     * @return mixed
     */
    public function paginate(Builder $query, $perPage, $page)
    {
        $result = $this->performSearch($query, [
            'filters' => $this->filters($query),
            'size' => $perPage,
            'from' => (($page * $perPage) - $perPage),
        ]);

        $result['nbPages'] = (int) ceil($result['hits']['total'] / $perPage);

        return $result;
    }

    private function buildColumnFilter($column,$value){
        return is_array($value) ? [
            'terms' => [
                $column => $value,
            ]
        ] : [
            'match_phrase' => [
                $column => $value,
            ]
        ];
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  Builder  $builder
     * @param  array  $options
     * @return mixed
     */
    protected function performSearch(Builder $builder, array $options = [])
    {
        $filters = $must = $mustNot = $ranges = $should = [];

        $must[] = [
            'query_string' => [
                'query' => "{$builder->query}",
            ]
        ];

        if (array_key_exists('filters', $options) && $options['filters']) {
            foreach ($options['filters'] as $column => $value) {
                if(is_numeric($value)) {
                    $filters[] = $this->buildColumnFilter($column, $value);
                } elseif(is_string($value)) {
                    $must[] = $this->buildColumnFilter($column, $value);
                }
            }
        }

        if (collect($builder->whereWithOperators)->count() > 0) {
            foreach ($builder->whereWithOperators as $key => $item) {
                $column = $item['column'];
                $value = $item['value'];
                $operator = str_replace('<>', '!=', strtolower($item['operator']));
                switch ($operator) {
                    case "=":
                        if(is_numeric($value)) {
                            $filters[] = $this->buildColumnFilter($column, $value);
                        } elseif(is_string($value)) {
                            $must[] = $this->buildColumnFilter($column, $value);
                        }
                        break;
                    case "!=":
                        $mustNot[] = $this->buildColumnFilter($column, $value);
                        break;
                    case ">":
                        //gt
                        $ranges[$column]['gt'] = $value;
                        break;
                    case ">=":
                        //gte
                        $ranges[$column]['gte'] = $value;
                        break;
                    case "<":
                        //lt
                        $ranges[$column]['lt'] = $value;
                        break;
                    case "<=":
                        //lte
                        $ranges[$column]['lte'] = $value;
                        break;
                    case "like":
                        //type phrase
                        $must[] = [
                            'match' => [
                                $column => [
                                    'query' => $value,
                                    'operator' => 'and'
                                ]
                            ]
                        ];
                        break;
                }
            }
        }

        collect($ranges)->count() > 0 && $must[]['range'] = $ranges;

        if (collect($builder->orWheres)->count() > 0) {
            foreach ($builder->orWheres as $key => $item) {
                $column = $item['column'];
                $value = $item['value'];
                $operator = str_replace('<>', '!=', strtolower($item['operator']));
                switch ($operator) {
                    case "=":
                        $should[] = ['match' => [$item['column'] => $item['value']]];
                        break;
                    case ">":
                        //gt
                        $should[]['range'][$column]['gt'] = $value;
                        break;
                    case ">=":
                        //gte
                        $should[]['range'][$column]['gte'] = $value;
                        break;
                    case "<":
                        //lt
                        $should[]['range'][$column]['lt'] = $value;
                        break;
                    case "<=":
                        //lte
                        $should[]['range'][$column]['lte'] = $value;
                        break;
                    case "like":
                        $should[] = ['match' => [$item['column'] => $item['value']]];
                        break;
                }
            }
        }

        if (collect($builder->whereIn)->count() > 0) {
            foreach ($builder->whereIn as $key => $item) {
                $values = $item['values'];
                if (! is_array($values)) {
                    $values = explode(',', $values);
                }
                $should[] = $this->buildColumnFilter($item['column'], $values);
            }
        }

        $sorts = [];

        if (collect($builder->orders)->count() > 0) {
            foreach ($builder->orders as $value) {
                $sorts[] = [$value['column'] => $value['direction']];
            }
        }

        $query = [
            'index' =>  $this->index,
            'type'  =>  $builder->model->searchableAs(),
            'body' => [
                'sort' => $sorts,
                'query' => [
                    'bool' => [
                        'filter' => $filters,
                        'must' => $must,
                        'must_not' => $mustNot,
                        'should' => $should
                    ]
                ],
            ],
        ];

        if (collect($should)->count() > 0) {
            $query['body']['query']['bool']['minimum_should_match'] = 1;
        }

        if (array_key_exists('size', $options)) {
            $query['size'] = $options['size'];
        }

        if (array_key_exists('from', $options)) {
            $query['from'] = $options['from'];
        }

        if ($builder->callback) {
            return call_user_func(
                $builder->callback,
                $this->elasticsearch,
                $query
            );
        }

        return $this->elasticsearch->search($query);
    }

    /**
     * Get the filter array for the query.
     *
     * @param  Builder  $query
     * @return array
     */
    protected function filters(Builder $query)
    {
        return $query->wheres;
    }

    /**
     * Map the given results to instances of the given model.
     *
     * @param  mixed  $results
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return Collection
     */
    public function map($results, $model)
    {
        if (count($results['hits']) === 0)
            return Collection::make();
        elseif($model->isVirtualIndex())
            return Collection::make(array_column($results['hits']['hits'],'_source'));
        else
        {
            $keys = collect($results['hits']['hits'])
                ->pluck('_id')
                ->values()
                ->all();

            $models = $model->whereIn(
                $model->getQualifiedKeyName(), $keys
            )->get()->keyBy($model->getKeyName());

            return Collection::make($results['hits']['hits'])->map(function ($hit) use ($model, $models) {
                return isset($models[$hit['_source'][$model->getKeyName()]])
                    ? $models[$hit['_source'][$model->getKeyName()]] : null;
            })->filter()->values();

        }
    }

    /**
     * Get the total count from a raw result returned by the engine.
     *
     * @param  mixed  $results
     * @return int
     */
    public function getTotalCount($results)
    {
        return $results['hits']['total'];
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param  mixed $results
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        return collect($results['hits'])->pluck('objectID')->values();
    }
}
