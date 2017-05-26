<?php

namespace Laravel\Scout;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Laravel\Scout\Events\ModelsImported;

class SearchableScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $builder
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    public function apply(EloquentBuilder $builder, Model $model)
    {
        //
    }

    /**
     * Extend the query builder with the needed functions.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $builder
     * @return void
     */
    public function extend(EloquentBuilder $builder)
    {
        $builder->macro('searchable', function (EloquentBuilder $builder) {
            $searchable_index = $builder->getModel()->searchableAs();
            $builder->chunk(500, function ($models) use ($builder, $searchable_index) {
                $models->searchable($searchable_index);
                event(new ModelsImported($models));
            });
        });

        $builder->macro('unsearchable', function (EloquentBuilder $builder) {
            $searchable_index = $builder->getModel()->searchableAs();
            $builder->chunk(500, function ($models) use ($builder, $searchable_index) {
                $models->unsearchable($searchable_index);
            });
        });
    }
}
