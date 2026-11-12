<?php

namespace SolutionForest\Recommender\Eloquent;

use Illuminate\Database\Eloquent\Model;
use SolutionForest\Recommender\Contracts\DiversityGroups;

/**
 * Default diversity groups for feeds with a `model`: each `diversity` key is
 * read as a model attribute, e.g. `'diversity' => ['author_id' => 2]`
 * allows at most two posts by the same author per page.
 */
final class ModelGroups implements DiversityGroups
{
    /**
     * @param  class-string<Model>  $model
     * @param  list<string>  $attributes
     */
    public function __construct(
        private readonly string $model,
        private readonly array $attributes,
    ) {
    }

    public function groups(array $ids): array
    {
        if ($this->attributes === []) {
            return [];
        }

        $out = [];
        foreach ($this->model::query()->whereKey($ids)->get() as $item) {
            /** @var Model $item */
            $groups = [];
            foreach ($this->attributes as $attribute) {
                $value = $item->getAttribute($attribute);
                $groups[$attribute] = is_int($value) || is_string($value) ? $value : (is_scalar($value) ? (string) $value : null);
            }
            $out[$item->getKey()] = $groups;
        }

        return $out;
    }
}
